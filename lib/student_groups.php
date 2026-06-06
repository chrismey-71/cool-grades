<?php
require_once __DIR__.'/helpers.php';

function teacher_student_group_name(string $name): string {
  $name = preg_replace('/\s+/u', ' ', trim($name));
  return is_string($name) ? $name : '';
}

function teacher_student_group_note(string $note): string {
  $note = trim($note);
  return is_string($note) ? $note : '';
}

function load_teacher_student_groups(PDO $pdo, int $teacherId, int $classId, int $subjectId): array {
  $st = $pdo->prepare("SELECT *
                       FROM teacher_student_groups
                       WHERE teacher_id=? AND class_id=? AND subject_id=?
                       ORDER BY name, id");
  $st->execute([$teacherId, $classId, $subjectId]);
  $groups = $st->fetchAll();
  if(!$groups) return [];

  $ids = array_map(static fn(array $row): int => (int)$row['id'], $groups);
  $in = '('.implode(',', array_fill(0, count($ids), '?')).')';
  $st = $pdo->prepare("SELECT gm.group_id, gm.student_id, gm.sort,
                              s.first_name, s.last_name
                       FROM teacher_student_group_members gm
                       JOIN students s ON s.id=gm.student_id
                       WHERE gm.group_id IN $in
                       ORDER BY gm.group_id, gm.sort, s.last_name, s.first_name");
  $st->execute($ids);
  $membersByGroup = [];
  foreach($st->fetchAll() as $row){
    $groupId = (int)$row['group_id'];
    if(!isset($membersByGroup[$groupId])) $membersByGroup[$groupId] = [];
    $membersByGroup[$groupId][] = [
      'student_id' => (int)$row['student_id'],
      'first_name' => (string)$row['first_name'],
      'last_name' => (string)$row['last_name'],
      'sort' => (int)($row['sort'] ?? 0),
    ];
  }

  foreach($groups as &$group){
    $groupId = (int)$group['id'];
    $group['members'] = $membersByGroup[$groupId] ?? [];
    $group['member_ids'] = array_map(static fn(array $row): int => (int)$row['student_id'], $group['members']);
    $group['member_count'] = count($group['member_ids']);
  }
  unset($group);

  return $groups;
}

function load_teacher_student_group(PDO $pdo, int $teacherId, int $groupId): ?array {
  $st = $pdo->prepare("SELECT * FROM teacher_student_groups WHERE id=? AND teacher_id=? LIMIT 1");
  $st->execute([$groupId, $teacherId]);
  $group = $st->fetch();
  if(!$group) return null;

  $groups = load_teacher_student_groups($pdo, $teacherId, (int)$group['class_id'], (int)$group['subject_id']);
  foreach($groups as $row){
    if((int)$row['id'] === $groupId) return $row;
  }
  return null;
}

function teacher_student_group_assigned_student_ids(PDO $pdo, int $teacherId, int $classId, int $subjectId, int $excludeGroupId = 0): array {
  $sql = "SELECT DISTINCT gm.student_id
          FROM teacher_student_group_members gm
          JOIN teacher_student_groups g ON g.id=gm.group_id
          WHERE g.teacher_id=? AND g.class_id=? AND g.subject_id=?";
  $params = [$teacherId, $classId, $subjectId];
  if($excludeGroupId > 0){
    $sql .= " AND g.id<>?";
    $params[] = $excludeGroupId;
  }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function save_teacher_student_group(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $name, string $note, array $studentIds, int $groupId = 0): int {
  $name = teacher_student_group_name($name);
  $note = teacher_student_group_note($note);
  $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0)));

  if($name === '') throw new InvalidArgumentException('Bitte einen Gruppennamen eingeben.');
  if(!$studentIds) throw new InvalidArgumentException('Bitte mindestens eine Schülerin bzw. einen Schüler auswählen.');

  $dup = $pdo->prepare("SELECT id
                        FROM teacher_student_groups
                        WHERE teacher_id=? AND class_id=? AND subject_id=? AND name=? AND id<>?
                        LIMIT 1");
  $dup->execute([$teacherId, $classId, $subjectId, $name, $groupId]);
  if($dup->fetch()) throw new RuntimeException('Dieser Gruppenname existiert bereits.');

  if($groupId > 0){
    $st = $pdo->prepare("UPDATE teacher_student_groups
                         SET name=?, note=?, updated_at=?
                         WHERE id=? AND teacher_id=? AND class_id=? AND subject_id=?");
    $st->execute([$name, $note !== '' ? $note : null, now_iso(), $groupId, $teacherId, $classId, $subjectId]);
    if($st->rowCount()===0){
      $check = $pdo->prepare("SELECT id FROM teacher_student_groups WHERE id=? AND teacher_id=? AND class_id=? AND subject_id=?");
      $check->execute([$groupId, $teacherId, $classId, $subjectId]);
      if(!$check->fetch()) throw new RuntimeException('Gruppe nicht gefunden.');
    }
  } else {
    $st = $pdo->prepare("INSERT INTO teacher_student_groups
                         (teacher_id,class_id,subject_id,name,note,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?)");
    $st->execute([$teacherId, $classId, $subjectId, $name, $note !== '' ? $note : null, now_iso(), now_iso()]);
    $groupId = (int)$pdo->lastInsertId();
  }

  $pdo->prepare("DELETE FROM teacher_student_group_members WHERE group_id=?")->execute([$groupId]);
  $ins = $pdo->prepare("INSERT INTO teacher_student_group_members (group_id,student_id,sort) VALUES (?,?,?)");
  $sort = 10;
  foreach($studentIds as $studentId){
    $ins->execute([$groupId, $studentId, $sort]);
    $sort += 10;
  }

  return $groupId;
}

function delete_teacher_student_group(PDO $pdo, int $teacherId, int $groupId): ?array {
  $st = $pdo->prepare("SELECT * FROM teacher_student_groups WHERE id=? AND teacher_id=? LIMIT 1");
  $st->execute([$groupId, $teacherId]);
  $group = $st->fetch();
  if(!$group) return null;

  $pdo->prepare("DELETE FROM teacher_student_groups WHERE id=? AND teacher_id=?")->execute([$groupId, $teacherId]);
  return $group;
}

function teacher_student_group_partitions(array $studentIds, int $desiredSize): array {
  $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn(int $id): bool => $id > 0)));
  $total = count($studentIds);
  if($total === 0) return [];

  if($desiredSize < 1) $desiredSize = 1;
  if($desiredSize > $total) $desiredSize = $total;

  $groupCount = (int)ceil($total / $desiredSize);
  if($groupCount < 1) $groupCount = 1;

  $baseSize = (int)floor($total / $groupCount);
  $remainder = $total % $groupCount;

  $chunks = [];
  $offset = 0;
  for($i=0; $i<$groupCount; $i++){
    $currentSize = $baseSize + ($i < $remainder ? 1 : 0);
    $chunks[] = array_slice($studentIds, $offset, $currentSize);
    $offset += $currentSize;
  }

  return array_values(array_filter($chunks));
}

function teacher_student_group_next_name(string $prefix, array &$usedNames): string {
  $prefix = teacher_student_group_name($prefix);
  if($prefix === '') $prefix = 'Gruppe';
  $i = 1;
  $lower = static function(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
  };
  do{
    $name = $prefix . ' ' . $i;
    $i++;
  }while(in_array($lower($name), $usedNames, true));
  $usedNames[] = $lower($name);
  return $name;
}
