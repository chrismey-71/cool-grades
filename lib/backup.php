<?php
require_once __DIR__.'/helpers.php';

function backup_ident(string $name): string {
  return '`' . str_replace('`', '``', $name) . '`';
}

function backup_sql_value(PDO $pdo, $value): string {
  if ($value === null) return 'NULL';
  if (is_bool($value)) return $value ? '1' : '0';
  if (is_int($value) || is_float($value)) return (string)$value;
  return $pdo->quote((string)$value);
}

function backup_sql_dump(PDO $pdo): string {
  @set_time_limit(0);
  $out = "-- COOL-Grades SQL-Backup\n";
  $out .= "-- Erstellt am " . date('Y-m-d H:i:s') . "\n";
  $out .= "-- Zeichensatz utf8mb4\n\n";
  $out .= "SET NAMES utf8mb4;\n";
  $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

  $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
  foreach($tables as $row){
    $table = (string)($row[0] ?? '');
    if($table === '') continue;

    $quotedTable = backup_ident($table);
    $createRow = $pdo->query("SHOW CREATE TABLE " . $quotedTable)->fetch(PDO::FETCH_NUM);
    $createSql = (string)($createRow[1] ?? '');

    $out .= "--\n-- Tabellenstruktur für " . $table . "\n--\n\n";
    $out .= "DROP TABLE IF EXISTS " . $quotedTable . ";\n";
    $out .= $createSql . ";\n\n";

    $stmt = $pdo->query("SELECT * FROM " . $quotedTable);
    $rowsWritten = 0;
    while($data = $stmt->fetch(PDO::FETCH_ASSOC)){
      $columns = [];
      $values = [];
      foreach($data as $column => $value){
        $columns[] = backup_ident((string)$column);
        $values[] = backup_sql_value($pdo, $value);
      }
      $out .= "INSERT INTO " . $quotedTable . " (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
      $rowsWritten++;
    }
    if($rowsWritten > 0) $out .= "\n";
  }

  $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
  return $out;
}

function backup_rows(PDO $pdo, string $sql, array $params = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function backup_in_clause(array $values, array &$params): string {
  $ids = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $v): bool => $v > 0)));
  if(!$ids) return '(NULL)';
  foreach($ids as $id) $params[] = $id;
  return '(' . implode(',', array_fill(0, count($ids), '?')) . ')';
}

function backup_ids(array $rows, string $field): array {
  $out = [];
  foreach($rows as $row){
    $id = (int)($row[$field] ?? 0);
    if($id > 0) $out[$id] = $id;
  }
  return array_values($out);
}

function backup_teacher_export(PDO $pdo, array $teacher): array {
  $teacherId = (int)($teacher['id'] ?? 0);
  $teacherSafe = $teacher;
  unset($teacherSafe['password_hash'], $teacherSafe['password'], $teacherSafe['reset_token']);

  $assignments = backup_rows($pdo, "SELECT * FROM teacher_assignments WHERE teacher_id=? ORDER BY class_id,subject_id", [$teacherId]);
  $classIds = backup_ids($assignments, 'class_id');
  $subjectIds = backup_ids($assignments, 'subject_id');

  $params = [];
  $classIn = backup_in_clause($classIds, $params);
  $classes = $classIds ? backup_rows($pdo, "SELECT * FROM classes WHERE id IN $classIn ORDER BY school_period_set_id,name", $params) : [];
  $periodIds = backup_ids($classes, 'school_period_set_id');

  $params = [];
  $periodIn = backup_in_clause($periodIds, $params);
  $schoolYears = $periodIds ? backup_rows($pdo, "SELECT * FROM school_period_sets WHERE id IN $periodIn ORDER BY semester1_from", $params) : [];

  $params = [];
  $classIn = backup_in_clause($classIds, $params);
  $enrollments = $classIds ? backup_rows($pdo, "SELECT * FROM class_enrollments WHERE class_id IN $classIn ORDER BY class_id,student_id", $params) : [];
  $studentIds = backup_ids($enrollments, 'student_id');

  $params = [];
  $studentIn = backup_in_clause($studentIds, $params);
  $students = $studentIds ? backup_rows($pdo, "SELECT * FROM students WHERE id IN $studentIn ORDER BY last_name,first_name", $params) : [];

  $params = [];
  $subjectIn = backup_in_clause($subjectIds, $params);
  $subjects = $subjectIds ? backup_rows($pdo, "SELECT * FROM subjects WHERE id IN $subjectIn ORDER BY code,name", $params) : [];

  $params = [$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $lessons = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM lesson_sessions WHERE teacher_id=? AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY lesson_date,id", $params) : [];

  $params = [$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $participation = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM participation_events WHERE teacher_id=? AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY event_date,id", $params) : [];
  $eventIds = backup_ids($participation, 'id');

  $params = [];
  $eventIn = backup_in_clause($eventIds, $params);
  $participationOptions = $eventIds ? backup_rows($pdo, "SELECT * FROM participation_event_options WHERE event_id IN $eventIn ORDER BY event_id,option_id", $params) : [];

  $params = [];
  $eventIn = backup_in_clause($eventIds, $params);
  $participationCriteria = $eventIds ? backup_rows($pdo, "SELECT * FROM participation_event_criteria WHERE event_id IN $eventIn ORDER BY event_id,criteria_id", $params) : [];

  $usedOptionIds = [];
  foreach($participation as $row){
    foreach(['reason_option_id','impact_option_id','social_form_option_id','phase_option_id','homework_option_id'] as $field){
      $id = (int)($row[$field] ?? 0);
      if($id > 0) $usedOptionIds[$id] = $id;
    }
  }
  foreach($participationOptions as $row){
    $id = (int)($row['option_id'] ?? 0);
    if($id > 0) $usedOptionIds[$id] = $id;
  }

  $params = [$teacherId];
  $optionWhere = ["teacher_id=?"];
  if($subjectIds){
    $subjectIn = backup_in_clause($subjectIds, $params);
    $optionWhere[] = "(scope='subject' AND subject_id IN $subjectIn)";
  }
  if($usedOptionIds){
    $optIn = backup_in_clause(array_values($usedOptionIds), $params);
    $optionWhere[] = "id IN $optIn";
  }
  $picklistOptions = backup_rows($pdo, "SELECT * FROM participation_options WHERE ".implode(' OR ', $optionWhere)." ORDER BY opt_type,scope,subject_id,teacher_id,sort,label", $params);

  $usedCriteriaIds = backup_ids($participationCriteria, 'criteria_id');
  $params = [$teacherId];
  $criteriaSetWhere = ["teacher_id=?"];
  if($subjectIds){
    $subjectIn = backup_in_clause($subjectIds, $params);
    $criteriaSetWhere[] = "(scope='subject' AND subject_id IN $subjectIn)";
  }
  $criteriaSets = backup_rows($pdo, "SELECT * FROM criteria_sets WHERE ".implode(' OR ', $criteriaSetWhere)." ORDER BY scope,subject_id,teacher_id,name", $params);
  $criteriaSetIds = backup_ids($criteriaSets, 'id');
  $criteriaWhere = [];
  $criteriaParams = [];
  if($criteriaSetIds){
    $setIn = backup_in_clause($criteriaSetIds, $criteriaParams);
    $criteriaWhere[] = "criteria_set_id IN $setIn";
  }
  if($usedCriteriaIds){
    $critIn = backup_in_clause($usedCriteriaIds, $criteriaParams);
    $criteriaWhere[] = "id IN $critIn";
  }
  $criteria = $criteriaWhere ? backup_rows($pdo, "SELECT * FROM criteria WHERE ".implode(' OR ', $criteriaWhere)." ORDER BY criteria_set_id,category,label", $criteriaParams) : [];

  $params = [$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $exams = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM exams WHERE teacher_id=? AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY exam_date,id", $params) : [];
  $examIds = backup_ids($exams, 'id');
  $params = [];
  $examIn = backup_in_clause($examIds, $params);
  $examGrades = $examIds ? backup_rows($pdo, "SELECT * FROM exam_grades WHERE exam_id IN $examIn ORDER BY exam_id,student_id", $params) : [];

  $params = [$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $orals = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM oral_assessments WHERE teacher_id=? AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY assessment_date,id", $params) : [];

  $params = [$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $groups = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM teacher_student_groups WHERE teacher_id=? AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY class_id,subject_id,name", $params) : [];
  $groupIds = backup_ids($groups, 'id');
  $params = [];
  $groupIn = backup_in_clause($groupIds, $params);
  $groupMembers = $groupIds ? backup_rows($pdo, "SELECT * FROM teacher_student_group_members WHERE group_id IN $groupIn ORDER BY group_id,sort,student_id", $params) : [];

  $params = [$teacherId];
  $subjectIn = backup_in_clause($subjectIds, $params);
  $presets = $subjectIds ? backup_rows($pdo, "SELECT * FROM teacher_participation_presets WHERE teacher_id=? AND subject_id IN $subjectIn ORDER BY subject_id,name", $params) : [];

  $params = [$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $weights = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM assessment_weight_settings WHERE teacher_id=? AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY school_period_set_id,class_id,subject_id", $params) : [];

  $params = [$teacherId,$teacherId];
  $classIn = backup_in_clause($classIds, $params);
  $subjectIn = backup_in_clause($subjectIds, $params);
  $finals = ($classIds && $subjectIds) ? backup_rows($pdo, "SELECT * FROM final_assessments WHERE (created_by=? OR updated_by=?) AND class_id IN $classIn AND subject_id IN $subjectIn ORDER BY school_period_set_id,class_id,subject_id,student_id,assessment_scope", $params) : [];
  $finalIds = backup_ids($finals, 'id');
  $params = [];
  $finalIn = backup_in_clause($finalIds, $params);
  $finalHistory = $finalIds ? backup_rows($pdo, "SELECT * FROM final_assessment_history WHERE final_assessment_id IN $finalIn ORDER BY final_assessment_id,created_at,id", $params) : [];

  return [
    'metadata' => [
      'app' => 'COOL-Grades',
      'backup_type' => 'teacher_scoped_json',
      'created_at' => date('c'),
      'teacher_id' => $teacherId,
      'teacher_name' => trim((string)($teacher['first_name'] ?? '').' '.(string)($teacher['last_name'] ?? '')),
      'scope_note' => 'Export enthält nur Daten zu aktuell zugewiesenen Klassen/Fächern/Schuljahren dieser Lehrkraft sowie zugehörige Kontextdaten.',
    ],
    'teacher' => $teacherSafe,
    'tables' => [
      'teacher_assignments' => $assignments,
      'school_period_sets' => $schoolYears,
      'classes' => $classes,
      'subjects' => $subjects,
      'class_enrollments' => $enrollments,
      'students' => $students,
      'lesson_sessions' => $lessons,
      'participation_events' => $participation,
      'participation_event_options' => $participationOptions,
      'participation_event_criteria' => $participationCriteria,
      'participation_options' => $picklistOptions,
      'criteria_sets' => $criteriaSets,
      'criteria' => $criteria,
      'exams' => $exams,
      'exam_grades' => $examGrades,
      'oral_assessments' => $orals,
      'teacher_student_groups' => $groups,
      'teacher_student_group_members' => $groupMembers,
      'teacher_participation_presets' => $presets,
      'assessment_weight_settings' => $weights,
      'final_assessments' => $finals,
      'final_assessment_history' => $finalHistory,
    ],
  ];
}

function backup_zip_bytes(array $files, string $password = ''): string {
  if(!class_exists('ZipArchive')) throw new RuntimeException('ZIP-Erweiterung ist auf diesem Server nicht verfügbar.');
  $tmp = tempnam(sys_get_temp_dir(), 'cool-grades-backup-');
  if($tmp === false) throw new RuntimeException('Temporäre Datei für ZIP-Sicherung konnte nicht erstellt werden.');
  $zip = new ZipArchive();
  if($zip->open($tmp, ZipArchive::OVERWRITE) !== true){
    @unlink($tmp);
    throw new RuntimeException('ZIP-Sicherung konnte nicht erstellt werden.');
  }
  $usePassword = $password !== '';
  if($usePassword) $zip->setPassword($password);
  foreach($files as $name => $content){
    $safeName = str_replace('\\', '/', ltrim((string)$name, '/'));
    $zip->addFromString($safeName, (string)$content);
    if($usePassword){
      if(defined('ZipArchive::EM_AES_256')){
        $zip->setEncryptionName($safeName, ZipArchive::EM_AES_256);
      } else {
        $zip->setEncryptionName($safeName, ZipArchive::EM_TRAD_PKWARE);
      }
    }
  }
  $zip->close();
  $bytes = (string)file_get_contents($tmp);
  @unlink($tmp);
  return $bytes;
}

function backup_send_zip(string $filename, array $files, string $password = ''): void {
  $bytes = backup_zip_bytes($files, $password);
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
  header('Content-Length: ' . strlen($bytes));
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo $bytes;
}
