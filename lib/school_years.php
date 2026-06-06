<?php
require_once __DIR__.'/settings.php';
require_once __DIR__.'/assessment_systems.php';

function school_year_current_id(PDO $pdo): int {
  try{
    $st = $pdo->query("SELECT id FROM school_period_sets WHERE is_current=1 LIMIT 1");
    $id = (int)($st->fetchColumn() ?: 0);
    if($id > 0) return $id;
  }catch(Exception $e){ /* ignore */ }

  $periods = app_school_period_sets(false);
  return $periods ? (int)$periods[0]['id'] : 0;
}

function school_year_set_current(PDO $pdo, int $periodId): void {
  if($periodId <= 0) return;
  $pdo->beginTransaction();
  try{
    $pdo->exec("UPDATE school_period_sets SET is_current=0");
    $st = $pdo->prepare("UPDATE school_period_sets SET is_current=1, archived=0, updated_at=? WHERE id=?");
    $st->execute([now_iso(), $periodId]);
    $pdo->commit();
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function school_year_label_for_id(PDO $pdo, int $periodId): string {
  if($periodId <= 0) return '';
  $st = $pdo->prepare("SELECT label FROM school_period_sets WHERE id=?");
  $st->execute([$periodId]);
  return (string)($st->fetchColumn() ?: '');
}

function class_status_label(array $class): string {
  if((int)($class['is_departed'] ?? 0) === 1) return 'ausgeschieden';
  if((int)($class['is_archived'] ?? 0) === 1) return 'archiviert';
  return 'aktiv';
}

function class_is_readonly(array $class): bool {
  return (int)($class['is_archived'] ?? 0) === 1 || (int)($class['is_departed'] ?? 0) === 1;
}

function class_archive_badge(array $class): string {
  $status = class_status_label($class);
  if($status === 'aktiv') return '<span class="badge ok">aktiv</span>';
  if($status === 'ausgeschieden') return '<span class="badge off">ausgeschieden</span>';
  return '<span class="badge">Archiv</span>';
}

function class_display_name(array $class): string {
  $name = (string)($class['name'] ?? '');
  $year = trim((string)($class['school_year_label'] ?? $class['period_label'] ?? ''));
  return $year !== '' ? $name.' · '.$year : $name;
}

function load_school_years(PDO $pdo, bool $includeArchived = true): array {
  $sql = "SELECT id,label,semester1_from,semester1_to,semester2_from,semester2_to,archived,is_current,created_at,updated_at
          FROM school_period_sets";
  if(!$includeArchived) $sql .= " WHERE archived=0";
  $sql .= " ORDER BY semester1_from DESC, id DESC";
  try{
    $rows = $pdo->query($sql)->fetchAll();
  }catch(Exception $e){
    $rows = app_school_period_sets($includeArchived);
  }
  foreach($rows as &$row){
    $row['id'] = (int)$row['id'];
    $row['archived'] = (int)($row['archived'] ?? 0);
    $row['is_current'] = (int)($row['is_current'] ?? 0);
  }
  unset($row);
  return $rows;
}

function load_classes_for_admin(PDO $pdo, int $schoolYearId = 0, bool $includeDeparted = true): array {
  $sql = "SELECT c.*, sp.label AS school_year_label,
                 pc.name AS predecessor_name,
                 COUNT(DISTINCT ce.student_id) AS student_count
          FROM classes c
          LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
          LEFT JOIN classes pc ON pc.id=c.predecessor_class_id
          LEFT JOIN class_enrollments ce ON ce.class_id=c.id AND ce.status IN ('active','repeated','transferred')
          WHERE 1=1";
  $params = [];
  if($schoolYearId > 0){ $sql .= " AND c.school_period_set_id=?"; $params[] = $schoolYearId; }
  if(!$includeDeparted){ $sql .= " AND c.is_departed=0"; }
  $sql .= " GROUP BY c.id ORDER BY sp.semester1_from DESC, c.school_type, c.year, c.name";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function load_teacher_classes(PDO $pdo, int $teacherId, int $schoolYearId = 0, bool $includeArchived = false, bool $includeDeparted = false): array {
  $sql = "SELECT DISTINCT c.*, sp.label AS school_year_label, sp.archived AS school_year_archived
          FROM teacher_assignments ta
          JOIN classes c ON c.id=ta.class_id
          LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
          WHERE ta.teacher_id=?";
  $params = [$teacherId];
  if($schoolYearId > 0){ $sql .= " AND c.school_period_set_id=?"; $params[] = $schoolYearId; }
  if(!$includeArchived){ $sql .= " AND c.is_archived=0"; }
  if(!$includeDeparted){ $sql .= " AND c.is_departed=0"; }
  $sql .= " ORDER BY sp.semester1_from DESC, c.school_type, c.year, c.name";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function load_teacher_subjects(PDO $pdo, int $teacherId, int $classId = 0): array {
  $sql = "SELECT DISTINCT s.id,s.code,s.name
          FROM teacher_assignments ta
          JOIN subjects s ON s.id=ta.subject_id
          WHERE ta.teacher_id=?";
  $params = [$teacherId];
  if($classId > 0){ $sql .= " AND ta.class_id=?"; $params[] = $classId; }
  $sql .= " ORDER BY s.code";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function load_class_students(PDO $pdo, int $classId, bool $includeInactive = false): array {
  $class = class_context($pdo, $classId);
  $sql = "SELECT s.id,s.first_name,s.last_name,s.is_active,ce.status AS enrollment_status,ce.entry_date,ce.exit_date
          FROM class_enrollments ce
          JOIN students s ON s.id=ce.student_id
          WHERE ce.class_id=?";
  $params = [$classId];
  if(!$includeInactive){
    if($class && class_is_readonly($class)){
      $sql .= " AND s.is_active=1";
    } else {
      $sql .= " AND s.is_active=1 AND ce.status IN ('active','repeated','transferred')";
    }
  }
  $sql .= " ORDER BY s.last_name,s.first_name";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function class_context(PDO $pdo, int $classId): ?array {
  if($classId <= 0) return null;
  $st = $pdo->prepare("SELECT c.*, sp.label AS school_year_label, sp.archived AS school_year_archived, sp.is_current AS school_year_is_current
                       FROM classes c
                       LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
                       WHERE c.id=? LIMIT 1");
  $st->execute([$classId]);
  $row = $st->fetch();
  return $row ?: null;
}

function require_class_writable(PDO $pdo, int $classId): void {
  $class = class_context($pdo, $classId);
  if($class && class_is_readonly($class)){
    http_response_code(403);
    exit('Diese Klasse gehört zu einem archivierten oder ausgeschiedenen Schuljahr und ist im Lesemodus.');
  }
}

function enrollment_status_label(string $status): string {
  return [
    'active' => 'aktiv',
    'repeated' => 'wiederholt / bleibt',
    'transferred' => 'Klassenwechsel',
    'left' => 'verlässt Schule',
    'archived' => 'archiviert',
  ][$status] ?? $status;
}

function ensure_student_enrollment(PDO $pdo, int $studentId, int $classId, string $status = 'active'): void {
  if($studentId <= 0 || $classId <= 0) return;
  $st = $pdo->prepare("SELECT school_period_set_id FROM classes WHERE id=?");
  $st->execute([$classId]);
  $periodId = (int)($st->fetchColumn() ?: 0);
  $now = now_iso();
  $ins = $pdo->prepare("INSERT INTO class_enrollments(student_id,class_id,school_period_set_id,status,entry_date,created_at,updated_at)
                        VALUES(?,?,?,?,CURDATE(),?,?)
                        ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=VALUES(updated_at)");
  $ins->execute([$studentId,$classId,$periodId,$status,$now,$now]);
}
