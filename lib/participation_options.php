<?php
require_once __DIR__.'/helpers.php';

function participation_option_label_key(string $label): string {
  $label = trim($label);
  if (function_exists('mb_strtolower')) return mb_strtolower($label, 'UTF-8');
  return strtolower($label);
}

function participation_option_target_subject_id(int $subject_id): ?int {
  return $subject_id > 0 ? $subject_id : null;
}

function participation_option_source_label(string $source_key): string {
  switch ($source_key) {
    case 'teacher_subject': return 'deine fachbezogenen Optionen';
    case 'teacher_general': return 'deine allgemeinen Optionen für alle Fächer';
    case 'subject_default': return 'die fachbezogenen Standardoptionen';
    default: return 'die globalen Standardoptionen';
  }
}

function participation_option_teacher_context_exists(PDO $pdo, int $teacher_id, int $subject_id, string $type): bool {
  $target_subject_id = participation_option_target_subject_id($subject_id);
  if ($target_subject_id === null) {
    $st = $pdo->prepare("SELECT 1 FROM participation_options
                         WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id IS NULL
                         LIMIT 1");
    $st->execute([$type, $teacher_id]);
  } else {
    $st = $pdo->prepare("SELECT 1 FROM participation_options
                         WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id=?
                         LIMIT 1");
    $st->execute([$type, $teacher_id, $target_subject_id]);
  }
  return (bool)$st->fetchColumn();
}

function participation_option_exact_rows(PDO $pdo, string $type, string $scope, ?int $teacher_id, ?int $subject_id): array {
  $sql = "SELECT id,opt_type,scope,subject_id,teacher_id,label,pedagogical_hint_mode,active,sort,IFNULL(archived,0) AS archived
          FROM participation_options
          WHERE opt_type=? AND scope=?";
  $params = [$type, $scope];

  if ($scope === 'teacher') {
    $sql .= " AND teacher_id=?";
    $params[] = (int)$teacher_id;
    if ($subject_id === null) {
      $sql .= " AND subject_id IS NULL";
    } else {
      $sql .= " AND subject_id=?";
      $params[] = (int)$subject_id;
    }
  } elseif ($scope === 'subject') {
    $sql .= " AND subject_id=?";
    $params[] = (int)$subject_id;
  } else {
    $sql .= " AND subject_id IS NULL";
  }

  $sql .= " ORDER BY IFNULL(archived,0) ASC, active DESC, sort ASC, label ASC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function participation_option_contexts(int $teacher_id, int $subject_id): array {
  $contexts = [];
  $target_subject_id = participation_option_target_subject_id($subject_id);
  if ($target_subject_id !== null) {
    $contexts[] = [
      'source_key' => 'teacher_subject',
      'scope' => 'teacher',
      'teacher_id' => $teacher_id,
      'subject_id' => $target_subject_id,
    ];
  }
  $contexts[] = [
    'source_key' => 'teacher_general',
    'scope' => 'teacher',
    'teacher_id' => $teacher_id,
    'subject_id' => null,
  ];
  if ($target_subject_id !== null) {
    $contexts[] = [
      'source_key' => 'subject_default',
      'scope' => 'subject',
      'teacher_id' => null,
      'subject_id' => $target_subject_id,
    ];
  }
  $contexts[] = [
    'source_key' => 'global',
    'scope' => 'global',
    'teacher_id' => null,
    'subject_id' => null,
  ];
  return $contexts;
}

function participation_option_sort_rows(array $rows): array {
  usort($rows, function(array $a, array $b): int {
    $archA = (int)($a['archived'] ?? 0);
    $archB = (int)($b['archived'] ?? 0);
    if ($archA !== $archB) return $archA <=> $archB;

    $activeA = (int)($a['active'] ?? 0);
    $activeB = (int)($b['active'] ?? 0);
    if ($activeA !== $activeB) return $activeB <=> $activeA;

    $sortA = (int)($a['sort'] ?? 0);
    $sortB = (int)($b['sort'] ?? 0);
    if ($sortA !== $sortB) return $sortA <=> $sortB;

    return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
  });
  return $rows;
}

function participation_option_effective_bundle(PDO $pdo, int $teacher_id, int $subject_id, string $type, bool $include_archived = false): array {
  $rows = [];
  $taken = [];
  $contexts_used = [];
  $primary_source_key = null;

  foreach (participation_option_contexts($teacher_id, $subject_id) as $ctx) {
    $ctx_rows = participation_option_exact_rows($pdo, $type, $ctx['scope'], $ctx['teacher_id'], $ctx['subject_id']);
    if (!$ctx_rows) continue;
    if ($primary_source_key === null) $primary_source_key = $ctx['source_key'];
    $contexts_used[] = $ctx['source_key'];

    foreach ($ctx_rows as $row) {
      $label_key = participation_option_label_key((string)($row['label'] ?? ''));
      if ($label_key === '' || isset($taken[$label_key])) continue;
      $taken[$label_key] = true;
      if (!$include_archived && (int)($row['archived'] ?? 0) === 1) continue;
      $row['source_key'] = $ctx['source_key'];
      $row['is_inherited'] = !($ctx['scope'] === 'teacher' && (int)($ctx['teacher_id'] ?? 0) === $teacher_id && $ctx['subject_id'] === participation_option_target_subject_id($subject_id));
      $rows[] = $row;
    }
  }

  return [
    'rows' => participation_option_sort_rows($rows),
    'primary_source_key' => $primary_source_key ?? 'global',
    'primary_source_label' => participation_option_source_label($primary_source_key ?? 'global'),
    'contexts_used' => $contexts_used,
    'has_exact_teacher_context' => participation_option_teacher_context_exists($pdo, $teacher_id, $subject_id, $type),
  ];
}

function participation_option_find_exact_by_label(PDO $pdo, int $teacher_id, int $subject_id, string $type, string $label): ?array {
  $target_subject_id = participation_option_target_subject_id($subject_id);
  if ($target_subject_id === null) {
    $st = $pdo->prepare("SELECT id,label,active,IFNULL(archived,0) AS archived,sort
                         FROM participation_options
                         WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id IS NULL AND label=?
                         LIMIT 1");
    $st->execute([$type, $teacher_id, $label]);
  } else {
    $st = $pdo->prepare("SELECT id,label,active,IFNULL(archived,0) AS archived,sort
                         FROM participation_options
                         WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id=? AND label=?
                         LIMIT 1");
    $st->execute([$type, $teacher_id, $target_subject_id, $label]);
  }
  $row = $st->fetch();
  return $row ?: null;
}

function materialize_teacher_participation_options(PDO $pdo, int $teacher_id, int $subject_id, string $type): array {
  $target_subject_id = participation_option_target_subject_id($subject_id);
  $bundle = participation_option_effective_bundle($pdo, $teacher_id, $subject_id, $type, true);
  $target_rows = participation_option_exact_rows($pdo, $type, 'teacher', $teacher_id, $target_subject_id);
  $existing_by_label = [];
  $map = [];

  foreach ($target_rows as $row) {
    $label_key = participation_option_label_key((string)$row['label']);
    $existing_by_label[$label_key] = (int)$row['id'];
    $map[(int)$row['id']] = (int)$row['id'];
  }

  $insert = $pdo->prepare("INSERT INTO participation_options
    (opt_type,scope,teacher_id,subject_id,label,pedagogical_hint_mode,sort,active,archived,created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $now = now_iso();
  $created = 0;

  foreach ($bundle['rows'] as $row) {
    $source_id = (int)$row['id'];
    $label_key = participation_option_label_key((string)$row['label']);
    if (isset($existing_by_label[$label_key])) {
      $map[$source_id] = $existing_by_label[$label_key];
      continue;
    }

    $insert->execute([
      $type,
      'teacher',
      $teacher_id,
      $target_subject_id,
      (string)$row['label'],
      (string)($row['pedagogical_hint_mode'] ?? ''),
      (int)($row['sort'] ?? 0),
      (int)($row['active'] ?? 1),
      (int)($row['archived'] ?? 0),
      $now,
    ]);
    $new_id = (int)$pdo->lastInsertId();
    $existing_by_label[$label_key] = $new_id;
    $map[$source_id] = $new_id;
    $created++;
  }

  return [
    'map' => $map,
    'created' => $created,
  ];
}

function next_teacher_participation_option_sort(PDO $pdo, string $type, int $teacher_id, int $subject_id): int {
  $target_subject_id = participation_option_target_subject_id($subject_id);
  if ($target_subject_id === null) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(sort),0) AS m
                         FROM participation_options
                         WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id IS NULL");
    $st->execute([$type, $teacher_id]);
  } else {
    $st = $pdo->prepare("SELECT COALESCE(MAX(sort),0) AS m
                         FROM participation_options
                         WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id=?");
    $st->execute([$type, $teacher_id, $target_subject_id]);
  }
  $m = (int)($st->fetch()['m'] ?? 0);
  return $m + 10;
}

function load_participation_options(PDO $pdo, int $teacher_id, int $subject_id, string $type): array {
  $bundle = participation_option_effective_bundle($pdo, $teacher_id, $subject_id, $type, false);
  $rows = array_values(array_filter($bundle['rows'], function(array $row): bool {
    return (int)($row['active'] ?? 0) === 1 && (int)($row['archived'] ?? 0) === 0;
  }));
  return array_map(function(array $row): array {
    return [
      'id' => (int)$row['id'],
      'label' => (string)$row['label'],
      'pedagogical_hint_mode' => (string)($row['pedagogical_hint_mode'] ?? ''),
    ];
  }, $rows);
}
