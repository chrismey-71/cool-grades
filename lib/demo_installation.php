<?php

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/final_assessments.php';

function demo_installation_is_active(PDO $pdo): bool {
  return demo_installation_setting_get($pdo, 'demo_installation_active', '0') === '1';
}

function demo_installation_default_year_label(?DateTimeImmutable $now = null): string {
  $now = $now ?? new DateTimeImmutable('now');
  $year = (int)$now->format('Y');
  $month = (int)$now->format('n');
  $startYear = ($month >= 8) ? $year : ($year - 1);
  return sprintf('%04d/%02d', $startYear, ($startYear + 1) % 100);
}

function demo_installation_period_from_label(string $label): array {
  $label = trim($label);
  if(!preg_match('/^(\d{4})\/(\d{2}|\d{4})$/', $label, $m)){
    throw new RuntimeException('Bitte das Demoschuljahr im Format 2025/26 eingeben.');
  }
  $startYear = (int)$m[1];
  $endRaw = (string)$m[2];
  if(strlen($endRaw) === 2){
    $endYear = ((int)floor($startYear / 100) * 100) + (int)$endRaw;
    if($endYear <= $startYear) $endYear += 100;
  } else {
    $endYear = (int)$endRaw;
  }
  if($endYear !== $startYear + 1){
    throw new RuntimeException('Das Demoschuljahr muss zwei aufeinanderfolgende Jahre enthalten, z. B. 2025/26.');
  }
  return [
    'label' => sprintf('%04d/%02d', $startYear, $endYear % 100),
    'semester1_from' => sprintf('%04d-09-01', $startYear),
    'semester1_to' => sprintf('%04d-01-31', $endYear),
    'semester2_from' => sprintf('%04d-02-01', $endYear),
    'semester2_to' => sprintf('%04d-07-10', $endYear),
  ];
}

function demo_installation_table_exists(PDO $pdo, string $table): bool {
  try{
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }catch(Throwable $e){
    return false;
  }
}

function demo_installation_setting_get(PDO $pdo, string $key, string $default = ''): string {
  try{
    $st = $pdo->prepare("SELECT value FROM app_settings WHERE `key`=? LIMIT 1");
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return $value === false || $value === null ? $default : (string)$value;
  }catch(Throwable $e){
    return $default;
  }
}

function demo_installation_setting_set(PDO $pdo, string $key, string $value): void {
  $now = now_iso();
  $st = $pdo->prepare("INSERT INTO app_settings(`key`,`value`,updated_at,created_at) VALUES(?,?,?,?)
                       ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)");
  $st->execute([$key, $value, $now, $now]);
}

function demo_installation_school_date(array $period, string $monthDay): string {
  $startYear = (int)substr((string)$period['semester1_from'], 0, 4);
  $endYear = (int)substr((string)$period['semester2_to'], 0, 4);
  $month = (int)substr($monthDay, 0, 2);
  $year = $month >= 9 ? $startYear : $endYear;
  return sprintf('%04d-%s', $year, $monthDay);
}

function demo_installation_clear(PDO $pdo, array $preserveUserIds = []): void {
  $tables = [
    'final_assessment_history',
    'final_assessments',
    'assessment_weight_settings',
    'teacher_student_group_members',
    'teacher_student_groups',
    'teacher_participation_presets',
    'participation_event_criteria',
    'participation_event_options',
    'participation_event_lbvo',
    'participation_events',
    'exam_grades',
    'exams',
    'oral_assessments',
    'lesson_sessions',
    'teacher_assignments',
    'class_enrollments',
    'criteria',
    'criteria_sets',
    'criteria_suggestion_school_forms',
    'criteria_suggestions',
    'subject_school_forms',
    'students',
    'classes',
    'school_period_sets',
    'school_forms',
    'subjects',
    'teacher_schools',
    'schools',
    'participation_options',
    'events',
    'login_attempts',
  ];

  $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
  try{
    foreach($tables as $table){
      if(demo_installation_table_exists($pdo, $table)){
        $pdo->exec("DELETE FROM `$table`");
      }
    }

    if(demo_installation_table_exists($pdo, 'users')){
      $preserveUserIds = array_values(array_filter(array_map('intval', $preserveUserIds), static fn($id) => $id > 0));
      if($preserveUserIds){
        $placeholders = implode(',', array_fill(0, count($preserveUserIds), '?'));
        $st = $pdo->prepare("DELETE FROM users WHERE id NOT IN ($placeholders)");
        $st->execute($preserveUserIds);
      } else {
        $pdo->exec("DELETE FROM users");
      }
    }
  } finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
  }
}

function demo_installation_create_user(PDO $pdo, string $username, string $firstName, string $lastName, string $role, string $password, bool $mustChange = false, bool $replaceExisting = false): int {
  $errors = password_policy_errors($password);
  if($errors){
    throw new RuntimeException('Das Passwort erfüllt die Regeln nicht: '.implode(', ', $errors).'.');
  }
  $hash = password_hash($password, PASSWORD_DEFAULT);
  if($replaceExisting){
    $st = $pdo->prepare("INSERT INTO users(username,first_name,last_name,role,pass_hash,is_active,must_change_password,created_at)
                         VALUES(?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           first_name=VALUES(first_name),
                           last_name=VALUES(last_name),
                           role=VALUES(role),
                           pass_hash=VALUES(pass_hash),
                           is_active=VALUES(is_active),
                           must_change_password=VALUES(must_change_password)");
    $st->execute([
      $username,
      $firstName,
      $lastName,
      $role,
      $hash,
      1,
      $mustChange ? 1 : 0,
      now_iso(),
    ]);
    $lookup = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $lookup->execute([$username]);
    return (int)$lookup->fetchColumn();
  }
  $st = $pdo->prepare("INSERT INTO users(username,first_name,last_name,role,pass_hash,is_active,must_change_password,created_at)
                       VALUES(?,?,?,?,?,?,?,?)");
  $st->execute([
    $username,
    $firstName,
    $lastName,
    $role,
    $hash,
    1,
    $mustChange ? 1 : 0,
    now_iso(),
  ]);
  return (int)$pdo->lastInsertId();
}

function demo_installation_set_teacher_preferences(PDO $pdo, int $teacherId): void {
  if($teacherId <= 0) return;
  $pdo->prepare("UPDATE users
                 SET pref_simple_participation_entry=0,
                     pref_quick_entry_ui='buttons',
                     pref_participation_quick_pick_enabled=1,
                     pref_participation_quick_pick_limit=7,
                     pref_compact_forms_enabled=1,
                     pref_visual_contrast='high',
                     pref_theme='light',
                     pref_legal_hints_enabled=1
                 WHERE id=? AND role='teacher'")
      ->execute([$teacherId]);
}

function demo_installation_upsert_school(PDO $pdo, string $name, string $address): int {
  $now = now_iso();
  $st = $pdo->prepare("INSERT INTO schools(name,address,active,created_at,updated_at)
                       VALUES(?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE address=VALUES(address), active=1, updated_at=VALUES(updated_at)");
  $st->execute([$name, $address, 1, $now, $now]);
  $lookup = $pdo->prepare("SELECT id FROM schools WHERE name=? LIMIT 1");
  $lookup->execute([$name]);
  return (int)$lookup->fetchColumn();
}

function demo_installation_upsert_school_form(PDO $pdo, int $schoolId, string $code, string $name): int {
  $now = now_iso();
  $st = $pdo->prepare("INSERT INTO school_forms(school_id,code,name,active,created_at,updated_at)
                       VALUES(?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE name=VALUES(name), active=1, updated_at=VALUES(updated_at)");
  $st->execute([$schoolId, $code, $name, 1, $now, $now]);
  $lookup = $pdo->prepare("SELECT id FROM school_forms WHERE school_id=? AND code=? LIMIT 1");
  $lookup->execute([$schoolId, $code]);
  return (int)$lookup->fetchColumn();
}

function demo_installation_upsert_period_set(PDO $pdo, int $schoolId, array $period): int {
  $now = now_iso();
  $lookup = $pdo->prepare("SELECT id FROM school_period_sets WHERE school_id=? AND label=? ORDER BY id LIMIT 1");
  $lookup->execute([$schoolId, $period['label']]);
  $id = (int)$lookup->fetchColumn();
  if($id > 0){
    $pdo->prepare("UPDATE school_period_sets
                   SET semester1_from=?, semester1_to=?, semester2_from=?, semester2_to=?, archived=0, is_current=1, updated_at=?
                   WHERE id=?")
        ->execute([$period['semester1_from'], $period['semester1_to'], $period['semester2_from'], $period['semester2_to'], $now, $id]);
    return $id;
  }
  $pdo->prepare("INSERT INTO school_period_sets(school_id,label,semester1_from,semester1_to,semester2_from,semester2_to,archived,is_current,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?)")
      ->execute([$schoolId, $period['label'], $period['semester1_from'], $period['semester1_to'], $period['semester2_from'], $period['semester2_to'], 0, 1, $now, $now]);
  return (int)$pdo->lastInsertId();
}

function demo_installation_normalize_school_year_scope(PDO $pdo, int $schoolId, int $periodSetId, array $period): void {
  // Demo installations should be self-contained. A legacy/global fallback school
  // year can otherwise become the current year in menus that still use defaults.
  if($schoolId <= 0 || $periodSetId <= 0) return;
  $now = now_iso();

  $pdo->prepare("UPDATE school_period_sets SET is_current=0 WHERE school_id=? AND id<>?")
      ->execute([$schoolId, $periodSetId]);
  $pdo->prepare("UPDATE school_period_sets SET archived=0, is_current=1, updated_at=? WHERE id=?")
      ->execute([$now, $periodSetId]);

  try{
    $pdo->exec("DELETE FROM school_period_sets WHERE school_id IS NULL");
  }catch(Throwable $e){
    // Non-critical: the school-bound demo year remains current and authoritative.
  }

  demo_installation_setting_set($pdo, 'semester1_from', (string)$period['semester1_from']);
  demo_installation_setting_set($pdo, 'semester1_to', (string)$period['semester1_to']);
  demo_installation_setting_set($pdo, 'semester2_from', (string)$period['semester2_from']);
  demo_installation_setting_set($pdo, 'semester2_to', (string)$period['semester2_to']);
}

function demo_installation_upsert_class(PDO $pdo, int $periodSetId, int $schoolFormId): int {
  $lookup = $pdo->prepare("SELECT id FROM classes WHERE school_period_set_id=? AND school_form_id=? AND name=? LIMIT 1");
  $lookup->execute([$periodSetId, $schoolFormId, '2HLW']);
  $id = (int)$lookup->fetchColumn();
  if($id > 0){
    $pdo->prepare("UPDATE classes
                   SET school_type='HLW', year=2, label='2HLW - Demo', assessment_system='yearly', predecessor_class_id=NULL, is_archived=0, is_departed=0
                   WHERE id=?")
        ->execute([$id]);
    return $id;
  }
  $pdo->prepare("INSERT INTO classes(school_period_set_id,name,school_type,school_form_id,year,label,assessment_system,is_archived,is_departed)
                 VALUES(?,?,?,?,?,?,?,?,?)")
      ->execute([$periodSetId, '2HLW', 'HLW', $schoolFormId, 2, '2HLW - Demo', 'yearly', 0, 0]);
  return (int)$pdo->lastInsertId();
}

function demo_installation_upsert_subject(PDO $pdo, string $code, string $name, int $isSchularbeit): int {
  $st = $pdo->prepare("INSERT INTO subjects(code,name,is_schularbeit_subject)
                       VALUES(?,?,?)
                       ON DUPLICATE KEY UPDATE name=VALUES(name), is_schularbeit_subject=VALUES(is_schularbeit_subject)");
  $st->execute([$code, $name, $isSchularbeit]);
  $lookup = $pdo->prepare("SELECT id FROM subjects WHERE code=? LIMIT 1");
  $lookup->execute([$code]);
  return (int)$lookup->fetchColumn();
}

function demo_installation_delete_by_ids(PDO $pdo, string $sqlPrefix, array $ids): void {
  $ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
  if(!$ids) return;
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare($sqlPrefix." ($placeholders)");
  $st->execute($ids);
}

function demo_installation_clean_context(PDO $pdo, int $classId, array $subjectIds, int $teacherId): void {
  $subjectIds = array_values(array_filter(array_map('intval', $subjectIds), static fn($id) => $id > 0));
  $eventIds = [];
  $st = $pdo->prepare("SELECT id FROM participation_events WHERE class_id=?");
  $st->execute([$classId]);
  foreach($st->fetchAll() as $row) $eventIds[] = (int)$row['id'];
  demo_installation_delete_by_ids($pdo, 'DELETE FROM participation_event_criteria WHERE event_id IN', $eventIds);
  demo_installation_delete_by_ids($pdo, 'DELETE FROM participation_event_options WHERE event_id IN', $eventIds);
  demo_installation_delete_by_ids($pdo, 'DELETE FROM participation_event_lbvo WHERE event_id IN', $eventIds);
  $pdo->prepare("DELETE FROM participation_events WHERE class_id=?")->execute([$classId]);

  $examIds = [];
  $st = $pdo->prepare("SELECT id FROM exams WHERE class_id=?");
  $st->execute([$classId]);
  foreach($st->fetchAll() as $row) $examIds[] = (int)$row['id'];
  demo_installation_delete_by_ids($pdo, 'DELETE FROM exam_grades WHERE exam_id IN', $examIds);
  $pdo->prepare("DELETE FROM exams WHERE class_id=?")->execute([$classId]);

  $finalIds = [];
  $st = $pdo->prepare("SELECT id FROM final_assessments WHERE class_id=?");
  $st->execute([$classId]);
  foreach($st->fetchAll() as $row) $finalIds[] = (int)$row['id'];
  demo_installation_delete_by_ids($pdo, 'DELETE FROM final_assessment_history WHERE final_assessment_id IN', $finalIds);
  $pdo->prepare("DELETE FROM final_assessments WHERE class_id=?")->execute([$classId]);

  $groupIds = [];
  $st = $pdo->prepare("SELECT id FROM teacher_student_groups WHERE teacher_id=? AND class_id=?");
  $st->execute([$teacherId, $classId]);
  foreach($st->fetchAll() as $row) $groupIds[] = (int)$row['id'];
  demo_installation_delete_by_ids($pdo, 'DELETE FROM teacher_student_group_members WHERE group_id IN', $groupIds);
  $pdo->prepare("DELETE FROM teacher_student_groups WHERE teacher_id=? AND class_id=?")->execute([$teacherId, $classId]);

  $pdo->prepare("DELETE FROM assessment_weight_settings WHERE teacher_id=? AND class_id=?")->execute([$teacherId, $classId]);
  $pdo->prepare("DELETE FROM oral_assessments WHERE teacher_id=? AND class_id=?")->execute([$teacherId, $classId]);
  $pdo->prepare("DELETE FROM lesson_sessions WHERE teacher_id=? AND class_id=?")->execute([$teacherId, $classId]);
  $pdo->prepare("DELETE FROM class_enrollments WHERE class_id=?")->execute([$classId]);
  $pdo->prepare("DELETE FROM students WHERE class_id=?")->execute([$classId]);

  if($subjectIds){
    $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
    $st = $pdo->prepare("SELECT id FROM criteria_sets WHERE subject_id IN ($placeholders)");
    $st->execute($subjectIds);
    $criteriaSetIds = [];
    foreach($st->fetchAll() as $row) $criteriaSetIds[] = (int)$row['id'];
    demo_installation_delete_by_ids($pdo, 'DELETE FROM criteria WHERE criteria_set_id IN', $criteriaSetIds);
    demo_installation_delete_by_ids($pdo, 'DELETE FROM criteria_sets WHERE id IN', $criteriaSetIds);
  }
}

function demo_installation_insert_option(PDO $pdo, string $type, int $teacherId, string $label, int $sort, ?string $mode = null, ?string $impactKind = null): int {
  $st = $pdo->prepare("INSERT INTO participation_options(opt_type,scope,subject_id,teacher_id,label,pedagogical_hint_mode,impact_kind,active,sort,created_at)
                       VALUES(?,?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE
                         pedagogical_hint_mode=VALUES(pedagogical_hint_mode),
                         impact_kind=VALUES(impact_kind),
                         active=1,
                         sort=VALUES(sort)");
  $st->execute([$type, 'teacher', null, $teacherId, $label, $mode, $impactKind, 1, $sort, now_iso()]);
  $lookup = $pdo->prepare("SELECT id FROM participation_options
                           WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id IS NULL AND label=?
                           LIMIT 1");
  $lookup->execute([$type, $teacherId, $label]);
  return (int)$lookup->fetchColumn();
}

function demo_installation_seed(PDO $pdo, string $schoolYearLabel): array {
  $period = demo_installation_period_from_label($schoolYearLabel);
  $password = 'DemoZugang47!';
  $pdo->beginTransaction();
  try{
    demo_installation_clear($pdo);

    // Idempotent: reset can recover from partially completed demo installs
    // where the demo accounts already exist but later seed steps failed.
    $adminId = demo_installation_create_user($pdo, 'demoadmin', 'Demo', 'Admin', 'admin', $password, false, true);
    $teacherId = demo_installation_create_user($pdo, 'demolehrer', 'Demo', 'Lehrer:in', 'teacher', $password, false, true);
    demo_installation_set_teacher_preferences($pdo, $teacherId);

    $now = now_iso();
    $schoolId = demo_installation_upsert_school($pdo, 'Demoschule HLW', "Beispielstraße 1\n3500 Musterstadt");
    $schoolFormId = demo_installation_upsert_school_form($pdo, $schoolId, 'HLW', 'Höhere Lehranstalt für wirtschaftliche Berufe');
    $periodSetId = demo_installation_upsert_period_set($pdo, $schoolId, $period);
    $period['id'] = $periodSetId;
    demo_installation_normalize_school_year_scope($pdo, $schoolId, $periodSetId, $period);

    $classId = demo_installation_upsert_class($pdo, $periodSetId, $schoolFormId);

    $subjects = [
      'BW' => ['name' => 'Betriebswirtschaft', 'sa' => 1],
      'APM' => ['name' => 'Angewandtes Projektmanagement', 'sa' => 0],
    ];
    $subjectIds = [];
    foreach($subjects as $code => $subject){
      $subjectIds[$code] = demo_installation_upsert_subject($pdo, $code, $subject['name'], (int)$subject['sa']);
      $pdo->prepare("INSERT IGNORE INTO subject_school_forms(subject_id,school_form_id) VALUES(?,?)")
          ->execute([$subjectIds[$code], $schoolFormId]);
      $pdo->prepare("INSERT INTO teacher_assignments(teacher_id,class_id,subject_id,status)
                     VALUES(?,?,?,'active')
                     ON DUPLICATE KEY UPDATE status='active', ended_at=NULL, ended_by=NULL, end_note=NULL")
          ->execute([$teacherId, $classId, $subjectIds[$code]]);
    }

    $pdo->prepare("INSERT IGNORE INTO teacher_schools(teacher_id,school_id) VALUES(?,?)")->execute([$adminId, $schoolId]);
    $pdo->prepare("INSERT IGNORE INTO teacher_schools(teacher_id,school_id) VALUES(?,?)")->execute([$teacherId, $schoolId]);
    demo_installation_clean_context($pdo, $classId, $subjectIds, $teacherId);

    $studentNames = [
      ['Anna','Adler'], ['Ben','Berger'], ['Clara','Cerny'], ['Daniel','Dorn'],
      ['Eva','Eder'], ['Felix','Falk'], ['Greta','Gruber'], ['Hugo','Haller'],
      ['Iris','Iser'], ['Jonas','Janda'], ['Klara','Krenn'], ['Lukas','Leitner'],
    ];
    $studentIds = [];
    foreach($studentNames as $index => $name){
      $pdo->prepare("INSERT INTO students(class_id,first_name,last_name,is_active) VALUES(?,?,?,1)")
          ->execute([$classId, $name[0], $name[1]]);
      $studentId = (int)$pdo->lastInsertId();
      $studentIds[] = $studentId;
      $pdo->prepare("INSERT INTO class_enrollments(student_id,class_id,school_period_set_id,status,entry_date,created_at,updated_at)
                     VALUES(?,?,?,?,?,?,?)")
          ->execute([$studentId, $classId, $periodSetId, 'active', $period['semester1_from'], $now, $now]);
    }

    $reasonOptions = [
      'discussion' => demo_installation_insert_option($pdo, 'reason', $teacherId, 'Diskussion', 10, 'formative', null),
      'exercise' => demo_installation_insert_option($pdo, 'reason', $teacherId, 'Übungsphase', 20, 'formative', null),
      'homework' => demo_installation_insert_option($pdo, 'reason', $teacherId, 'Hausübung / Sicherung', 30, 'summative', null),
      'group' => demo_installation_insert_option($pdo, 'reason', $teacherId, 'Gruppenarbeit / Projekt', 40, 'formative', null),
      'presentation' => demo_installation_insert_option($pdo, 'reason', $teacherId, 'Präsentation / Kurzbeitrag', 50, 'summative', null),
    ];
    $impactOptions = [
      'positive' => demo_installation_insert_option($pdo, 'impact', $teacherId, 'positiv (+)', 10, null, 'positive'),
      'strong' => demo_installation_insert_option($pdo, 'impact', $teacherId, 'stark positiv (++)', 20, null, 'positive'),
      'neutral' => demo_installation_insert_option($pdo, 'impact', $teacherId, 'unauffällig (~)', 30, null, 'neutral'),
      'negative' => demo_installation_insert_option($pdo, 'impact', $teacherId, 'negativ (-)', 40, null, 'negative'),
      'weak' => demo_installation_insert_option($pdo, 'impact', $teacherId, 'kaum nachweisbar (-)', 50, null, 'negative'),
    ];
    $performanceOptions = [
      demo_installation_insert_option($pdo, 'performance', $teacherId, 'mündlich', 10),
      demo_installation_insert_option($pdo, 'performance', $teacherId, 'schriftlich', 20),
      demo_installation_insert_option($pdo, 'performance', $teacherId, 'praktisch', 30),
    ];
    $groupOptions = [
      demo_installation_insert_option($pdo, 'observation_group', $teacherId, 'Verstehen / Erfassen', 10),
      demo_installation_insert_option($pdo, 'observation_group', $teacherId, 'Anwenden / Transfer', 20),
      demo_installation_insert_option($pdo, 'observation_group', $teacherId, 'Argumentieren / Erklären', 30),
      demo_installation_insert_option($pdo, 'observation_group', $teacherId, 'Kooperation / Selbstständigkeit', 40),
    ];

    $criteriaBySubject = [
      'BW' => ['Fachbegriffe korrekt verwenden', 'Fallbeispiele betriebswirtschaftlich analysieren', 'Unterlagen vollständig führen', 'Entscheidungen begründen'],
      'APM' => ['Projektauftrag klären', 'Zeitplan realistisch erstellen', 'Teambeitrag sichtbar machen', 'Ergebnisse nachvollziehbar dokumentieren'],
    ];
    $criteriaIdsBySubject = [];
    foreach($criteriaBySubject as $code => $labels){
      $pdo->prepare("INSERT INTO criteria_sets(name,scope,subject_id,teacher_id) VALUES(?,?,?,NULL)")
          ->execute([$code.' Demo-Kriterien', 'subject', $subjectIds[$code]]);
      $setId = (int)$pdo->lastInsertId();
      $criteriaIdsBySubject[$code] = [];
      foreach($labels as $label){
        $pdo->prepare("INSERT INTO criteria(criteria_set_id,label,category,active) VALUES(?,?,?,1)")
            ->execute([$setId, $label, 'Demo']);
        $criteriaIdsBySubject[$code][] = (int)$pdo->lastInsertId();
      }
    }

    foreach($subjectIds as $code => $subjectId){
      $weights = $code === 'BW' ? [40, 10, 50] : [50, 25, 25];
      $pdo->prepare("INSERT INTO assessment_weight_settings(teacher_id,class_id,subject_id,school_period_set_id,assessment_model,participation_weight,special_oral_weight,special_written_weight,first_semester_to_annual_weight,current_year_to_annual_weight,created_at,updated_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       assessment_model=VALUES(assessment_model),
                       participation_weight=VALUES(participation_weight),
                       special_oral_weight=VALUES(special_oral_weight),
                       special_written_weight=VALUES(special_written_weight),
                       first_semester_to_annual_weight=VALUES(first_semester_to_annual_weight),
                       current_year_to_annual_weight=VALUES(current_year_to_annual_weight),
                       updated_at=VALUES(updated_at)")
          ->execute([$teacherId, $classId, $subjectId, $periodSetId, 'yearly', $weights[0], $weights[1], $weights[2], 40, 60, $now, $now]);
    }

    demo_installation_seed_lessons_and_participation($pdo, $teacherId, $classId, $subjectIds, $studentIds, $reasonOptions, $impactOptions, $performanceOptions, $groupOptions, $criteriaIdsBySubject, $period);
    demo_installation_seed_oral_assessments($pdo, $teacherId, $classId, $subjectIds, $studentIds, $impactOptions, $period);
    demo_installation_seed_written_assessments($pdo, $teacherId, $classId, $subjectIds, $studentIds, $period);
    demo_installation_seed_final_drafts($pdo, $teacherId, $classId, $subjectIds, $period);

    demo_installation_setting_set($pdo, 'installation_mode', 'demo');
    demo_installation_setting_set($pdo, 'demo_installation_active', '1');
    demo_installation_setting_set($pdo, 'demo_installation_school_year', $period['label']);
    demo_installation_setting_set($pdo, 'demo_installation_last_reset_at', now_iso());
    demo_installation_setting_set($pdo, 'demo_installation_seed_json', json_encode([
      'school_id' => $schoolId,
      'school_form_id' => $schoolFormId,
      'school_period_set_id' => $periodSetId,
      'class_id' => $classId,
      'teacher_id' => $teacherId,
      'admin_id' => $adminId,
      'subject_ids' => $subjectIds,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $pdo->commit();
    return [
      'school_year' => $period['label'],
      'admin_username' => 'demoadmin',
      'teacher_username' => 'demolehrer',
      'password' => $password,
      'school' => 'Demoschule HLW',
      'class' => '2HLW',
      'subjects' => array_keys($subjectIds),
      'students' => count($studentIds),
      'admin_id' => $adminId,
      'teacher_id' => $teacherId,
    ];
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function demo_installation_seed_lessons_and_participation(PDO $pdo, int $teacherId, int $classId, array $subjectIds, array $studentIds, array $reasonOptions, array $impactOptions, array $performanceOptions, array $groupOptions, array $criteriaIdsBySubject, array $period): void {
  $lessonDates = [
    'BW' => ['09-16','10-07','10-28','11-25','12-16','01-13','02-24','03-17','04-21','05-12','06-09'],
    'APM' => ['09-18','10-09','11-06','11-27','12-18','01-15','02-26','03-19','04-23','05-21','06-11'],
  ];
  $topics = [
    'BW' => ['Unternehmen und Umwelt','Marketing-Mix','Kaufvertrag','Finanzierung','Rechnungswesen-Grundlagen','Sicherung 1. Semester','Personal und Organisation','Fallstudie Finanzierung','Controlling','Unternehmensentscheidung','Jahreswiederholung'],
    'APM' => ['Projektauftrag','Stakeholder','Projektstrukturplan','Rollen im Team','Meilensteinplanung','Sicherung 1. Semester','Risikoanalyse','Projektkommunikation','Dokumentation','Präsentationsvorbereitung','Projektabschluss'],
  ];
  foreach($subjectIds as $code => $subjectId){
    foreach($lessonDates[$code] as $idx => $monthDay){
      $date = demo_installation_school_date($period, $monthDay);
      $pdo->prepare("INSERT INTO lesson_sessions(teacher_id,class_id,subject_id,lesson_date,lesson_unit,topic,created_at) VALUES(?,?,?,?,?,?,?)")
          ->execute([$teacherId, $classId, $subjectId, $date, (string)(($idx % 4) + 1), $topics[$code][$idx] ?? 'Demo-Unterricht', now_iso()]);
      $lessonId = (int)$pdo->lastInsertId();
      foreach($studentIds as $pos => $studentId){
        if((($pos + $idx) % 3) === 0) continue;
        $profile = $pos % 4;
        if($profile === 0 || ($profile === 1 && $idx % 2 === 0)){
          $impactKey = $idx % 5 === 0 ? 'strong' : 'positive';
        } elseif($profile === 2){
          $impactKey = $idx % 4 === 0 ? 'negative' : 'neutral';
        } else {
          $impactKey = $idx % 3 === 0 ? 'weak' : 'neutral';
        }
        $reasonKeys = array_keys($reasonOptions);
        $reasonId = $reasonOptions[$reasonKeys[$idx % count($reasonKeys)]];
        $impactId = $impactOptions[$impactKey];
        $impactLabel = [
          'positive' => 'positiv (+)',
          'strong' => 'stark positiv (++)',
          'neutral' => 'unauffällig (~)',
          'negative' => 'negativ (-)',
          'weak' => 'kaum nachweisbar (-)',
        ][$impactKey];
        $impactKind = in_array($impactKey, ['positive','strong'], true) ? 'positive' : (in_array($impactKey, ['negative','weak'], true) ? 'negative' : 'neutral');
        $mode = in_array($impactKind, ['negative'], true) ? 'summative' : 'formative';
        $note = $impactKind === 'positive'
          ? 'Demo: fachlicher Beitrag gut nachvollziehbar.'
          : ($impactKind === 'negative' ? 'Demo: Fachbezug bleibt in dieser Situation noch unsicher.' : 'Demo: Beobachtung ohne klare Tendenz.');

        $pdo->prepare("INSERT INTO participation_events(student_id,teacher_id,class_id,subject_id,lesson_id,event_date,reason_option_id,reason_label,impact_option_id,rating,impact_kind,reason_text,note,pedagogical_mode,created_at)
                       VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$studentId, $teacherId, $classId, $subjectId, $lessonId, $date, $reasonId, 'Demo-Anlass', $impactId, $impactLabel, $impactKind, $topics[$code][$idx] ?? '', $note, $mode, now_iso()]);
        $eventId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO participation_event_options(event_id,option_id) VALUES(?,?)")->execute([$eventId, $performanceOptions[$idx % count($performanceOptions)]]);
        $pdo->prepare("INSERT INTO participation_event_options(event_id,option_id) VALUES(?,?)")->execute([$eventId, $groupOptions[$idx % count($groupOptions)]]);
        $criteriaIds = $criteriaIdsBySubject[$code] ?? [];
        if($criteriaIds){
          $pdo->prepare("INSERT INTO participation_event_criteria(event_id,criteria_id) VALUES(?,?)")->execute([$eventId, $criteriaIds[($idx + $pos) % count($criteriaIds)]]);
        }
        $lbvTags = $impactKind === 'positive' ? ['d','e'] : ($impactKind === 'negative' ? ['b','d'] : ['c']);
        $insLbvo = $pdo->prepare("INSERT IGNORE INTO participation_event_lbvo(event_id,tag,source,created_at) VALUES(?,?,?,?)");
        foreach($lbvTags as $tag){
          $insLbvo->execute([$eventId, $tag, 'auto', now_iso()]);
        }
      }
    }
  }
}

function demo_installation_seed_oral_assessments(PDO $pdo, int $teacherId, int $classId, array $subjectIds, array $studentIds, array $impactOptions, array $period): void {
  foreach($subjectIds as $code => $subjectId){
    foreach($studentIds as $pos => $studentId){
      if($pos % 3 === 1) continue;
      $impactKey = ($pos % 4 === 3) ? 'negative' : (($pos % 4 === 2) ? 'neutral' : 'positive');
      $date = demo_installation_school_date($period, $code === 'BW' ? '03-24' : '04-16');
      $pdo->prepare("INSERT INTO oral_assessments(class_id,subject_id,teacher_id,student_id,assessment_type,assessment_date,impact_option_id,impact_label,impact_kind,weight_multiplier,topic_area,questions,category,title,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
            $classId, $subjectId, $teacherId, $studentId, 'ORAL_EXAM', $date,
            $impactOptions[$impactKey],
            $impactKey === 'positive' ? 'positiv (+)' : ($impactKey === 'negative' ? 'negativ (-)' : 'unauffällig (~)'),
            $impactKey === 'positive' ? 'positive' : ($impactKey === 'negative' ? 'negative' : 'neutral'),
            $pos % 5 === 0 ? 1.5 : 1.0,
            $code === 'BW' ? 'Fallbeispiel und Fachbegriffe' : 'Projektplanung und Teamrolle',
            'Demo-Fragen zur fachlichen Begründung und Anwendung.',
            'Besondere mündliche Leistungsfeststellung',
            $code === 'BW' ? 'Mündliche Wiederholung BW' : 'Projektgespräch APM',
            now_iso(),
          ]);
    }
  }
}

function demo_installation_seed_written_assessments(PDO $pdo, int $teacherId, int $classId, array $subjectIds, array $studentIds, array $period): void {
  $examPlan = [
    'BW' => [
      ['SA', '11-18', '1. Schularbeit: betriebswirtschaftliche Grundlagen', 2.0],
      ['TEST', '03-10', 'Kurztest: Marketing und Finanzierung', 1.0],
      ['SA', '05-26', '2. Schularbeit: Unternehmensentscheidungen', 2.0],
    ],
    'APM' => [
      ['TASK', '10-14', 'Schriftlicher Projektauftrag', 1.0],
      ['TEST', '02-24', 'Kurztest: Projektplanung', 1.0],
      ['OTHER', '05-05', 'Dokumentationsabgabe', 1.5],
    ],
  ];
  foreach($examPlan as $code => $exams){
    $subjectId = (int)($subjectIds[$code] ?? 0);
    if($subjectId <= 0) continue;
    foreach($exams as $examIdx => $exam){
      $pdo->prepare("INSERT INTO exams(class_id,subject_id,teacher_id,exam_type,weight_multiplier,exam_date,title,created_at)
                     VALUES(?,?,?,?,?,?,?,?)")
          ->execute([$classId, $subjectId, $teacherId, $exam[0], $exam[3], demo_installation_school_date($period, $exam[1]), $exam[2], now_iso()]);
      $examId = (int)$pdo->lastInsertId();
      foreach($studentIds as $pos => $studentId){
        $base = ($pos % 4) + 1;
        $grade = min(5, max(1, $base + (($examIdx === 1 && $pos % 5 === 0) ? 1 : 0) - (($examIdx === 2 && $pos % 4 === 0) ? 1 : 0)));
        $pdo->prepare("INSERT INTO exam_grades(exam_id,student_id,grade,tendency,remark) VALUES(?,?,?,?,?)")
            ->execute([$examId, $studentId, $grade, $grade <= 2 ? 'sicher' : ($grade >= 4 ? 'prüfen' : 'solide'), 'Demo-Bewertung für Auswertungen.']);
      }
    }
  }
}

function demo_installation_grade_from_proposal(array $row): int {
  $value = $row['proposal']['value'] ?? null;
  if($value !== null){
    $grade = (int)$value;
    if($grade >= 1 && $grade <= 5) return $grade;
  }
  return 3;
}

function demo_installation_seed_final_drafts(PDO $pdo, int $teacherId, int $classId, array $subjectIds, array $period): void {
  $teacher = ['id' => $teacherId, 'role' => 'teacher', 'username' => 'demolehrer'];
  foreach($subjectIds as $subjectId){
    foreach(['semester1', 'year'] as $scope){
      $data = final_assessment_build_rows($pdo, $classId, (int)$subjectId, $period, $scope, $teacherId);
      foreach($data['rows'] as $row){
        $isSemester1 = $scope === 'semester1';
        $finalGrade = $isSemester1 ? demo_installation_grade_from_proposal($row) : null;
        $status = $isSemester1 ? 'final' : 'draft';
        $comment = $isSemester1
          ? 'Demo-Schulnachricht: final gespeichert, damit die Jahresbeurteilung mit einem abgeschlossenen 1. Semester demonstriert werden kann.'
          : 'Demo-Entwurf: vor der finalen Speicherung pädagogisch prüfen und bei Bedarf ergänzen.';
        $payload = final_assessment_build_payload(
          $teacher,
          $classId,
          (int)$subjectId,
          $row,
          $finalGrade,
          $status,
          $comment,
          $isSemester1 ? 'Demo-Erstanlage der abgeschlossenen Schulnachricht.' : ''
        );
        $existing = isset($row['existing']) && is_array($row['existing']) ? $row['existing'] : null;
        final_assessment_store($pdo, $existing, $payload, $teacher);
      }
    }
  }
}

function demo_installation_reset(PDO $pdo, ?string $schoolYearLabel = null): array {
  if(!demo_installation_is_active($pdo)){
    throw new RuntimeException('Diese Installation ist nicht als Demoinstallation markiert.');
  }
  $label = $schoolYearLabel ?: demo_installation_setting_get($pdo, 'demo_installation_school_year', demo_installation_default_year_label());
  return demo_installation_seed($pdo, $label);
}

function demo_installation_remove(PDO $pdo, array $newAdmin): int {
  if(!demo_installation_is_active($pdo)){
    throw new RuntimeException('Diese Installation ist nicht als Demoinstallation markiert.');
  }
  $username = trim((string)($newAdmin['username'] ?? ''));
  $firstName = trim((string)($newAdmin['first_name'] ?? ''));
  $lastName = trim((string)($newAdmin['last_name'] ?? ''));
  $password = (string)($newAdmin['password'] ?? '');
  if($username === '' || $firstName === '' || $lastName === ''){
    throw new RuntimeException('Bitte Benutzername, Vorname und Nachname für das neue Administrationskonto angeben.');
  }

  $pdo->beginTransaction();
  try{
    $newAdminId = demo_installation_create_user($pdo, $username, $firstName, $lastName, 'admin', $password, true);
    demo_installation_clear($pdo, [$newAdminId]);
    demo_installation_setting_set($pdo, 'installation_mode', 'production');
    demo_installation_setting_set($pdo, 'demo_installation_active', '0');
    demo_installation_setting_set($pdo, 'demo_installation_removed_at', now_iso());
    demo_installation_setting_set($pdo, 'demo_installation_seed_json', '');
    $pdo->commit();
    return $newAdminId;
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
