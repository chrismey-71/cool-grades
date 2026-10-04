<?php
require_once __DIR__.'/helpers.php';

/**
 * Sitzpläne (seating plans) für die Mitarbeitserfassung.
 *
 * Geltungsbereich: pro (Lehrkraft, Klasse, Fach) können mehrere benannte
 * Sitzpläne existieren (genau wie bei teacher_student_groups) - nicht nur
 * einer. Das deckt z.B. ab, dass dieselbe Klasse im selben Fach mal im
 * normalen Klassenraum, mal im EDV-Saal sitzt, oder dass bei einer
 * Gruppenstunde (nur ein Teil der Klasse anwesend) eine eigene Anordnung
 * gebraucht wird, ohne den "normalen" Sitzplan zu überschreiben. Die
 * Lehrkraft entscheidet in der Mitarbeitserfassung selbst, welchen der
 * vorhandenen Sitzpläne sie gerade sehen möchte, und kann jederzeit einen
 * bestehenden Sitzplan weiterverwenden oder einen neuen dafür anlegen.
 *
 * layout_type ist bewusst schon vorgesehen, damit später neben 'grid'
 * (klassische Tischreihen) auch andere Anordnungen (z.B. 'groups' für
 * Gruppentische/Inseln) ergänzt werden können, ohne die Tabelle erneut
 * migrieren zu müssen. Aktuell wird ausschließlich 'grid' unterstützt.
 */

const SEATING_PLAN_MIN_SIZE = 1;
const SEATING_PLAN_MAX_SIZE = 20;
const SEATING_PLAN_DEFAULT_NAME = 'Standard';

function seating_plan_clamp_size(int $value, int $default): int {
  if($value < SEATING_PLAN_MIN_SIZE) return $default;
  if($value > SEATING_PLAN_MAX_SIZE) return SEATING_PLAN_MAX_SIZE;
  return $value;
}

function seating_plan_name(string $name): string {
  $name = preg_replace('/\s+/u', ' ', trim($name));
  $name = is_string($name) ? $name : '';
  return $name !== '' ? $name : SEATING_PLAN_DEFAULT_NAME;
}

/**
 * Note: die DB-Spalte heißt "grid_rows", nicht "rows" - ROWS ist seit
 * MySQL 8.0.2 ein reserviertes Schlüsselwort. In den SELECTs unten auf
 * "rows" zurück-aliased, damit der restliche Code weiterhin damit arbeitet.
 * Der Alias selbst muss dabei in Backticks stehen (`rows`), sonst ist er
 * als unquotiertes reserviertes Wort ebenso ein SQL-Syntaxfehler wie eine
 * Spalte namens "rows" es wäre - genau das hat den Fehler in Produktion
 * verursacht, obwohl die Spalte selbst schon grid_rows hieß.
 */
const SEATING_PLAN_COLUMNS_SQL = "id,teacher_id,class_id,subject_id,name,layout_type,columns,grid_rows AS `rows`,created_at,updated_at";

function seating_plan_load_seats(PDO $pdo, int $planId): array {
  $st = $pdo->prepare("SELECT sp.seat_col, sp.seat_row, sp.student_id, s.first_name, s.last_name
                       FROM teacher_seating_plan_seats sp
                       JOIN students s ON s.id=sp.student_id
                       WHERE sp.plan_id=?");
  $st->execute([$planId]);

  $seatsByPosition = [];
  $seatsByStudent = [];
  foreach($st->fetchAll() as $row){
    $col = (int)$row['seat_col'];
    $rowNum = (int)$row['seat_row'];
    $sid = (int)$row['student_id'];
    $entry = [
      'student_id' => $sid,
      'first_name' => (string)$row['first_name'],
      'last_name' => (string)$row['last_name'],
      'col' => $col,
      'row' => $rowNum,
    ];
    $seatsByPosition[$col.'_'.$rowNum] = $entry;
    $seatsByStudent[$sid] = $entry;
  }
  return [$seatsByPosition, $seatsByStudent];
}

/**
 * Lädt alle Sitzpläne einer Lehrkraft für Klasse+Fach inkl. aller belegten
 * Plätze, zuletzt geänderte zuerst (das ist normalerweise der zuletzt
 * verwendete Sitzplan und damit eine sinnvolle Vorauswahl). Leeres Array,
 * wenn noch kein Sitzplan angelegt wurde.
 */
function load_teacher_seating_plans(PDO $pdo, int $teacherId, int $classId, int $subjectId): array {
  $st = $pdo->prepare("SELECT ".SEATING_PLAN_COLUMNS_SQL."
                       FROM teacher_seating_plans
                       WHERE teacher_id=? AND class_id=? AND subject_id=?
                       ORDER BY updated_at DESC, name, id");
  $st->execute([$teacherId, $classId, $subjectId]);
  $plans = $st->fetchAll();
  foreach($plans as &$plan){
    $plan['id'] = (int)$plan['id'];
    $plan['columns'] = (int)$plan['columns'];
    $plan['rows'] = (int)$plan['rows'];
    [$seatsByPosition, $seatsByStudent] = seating_plan_load_seats($pdo, $plan['id']);
    $plan['seats_by_position'] = $seatsByPosition;
    $plan['seats_by_student'] = $seatsByStudent;
  }
  unset($plan);
  return $plans;
}

/**
 * Lädt einen einzelnen Sitzplan einer Lehrkraft anhand seiner ID (inkl.
 * Besitz-Prüfung). Gibt null zurück, wenn er nicht existiert oder nicht
 * dieser Lehrkraft gehört.
 */
function load_teacher_seating_plan_by_id(PDO $pdo, int $teacherId, int $planId): ?array {
  $st = $pdo->prepare("SELECT ".SEATING_PLAN_COLUMNS_SQL."
                       FROM teacher_seating_plans WHERE id=? AND teacher_id=? LIMIT 1");
  $st->execute([$planId, $teacherId]);
  $plan = $st->fetch();
  if(!$plan) return null;

  $plan['id'] = (int)$plan['id'];
  $plan['columns'] = (int)$plan['columns'];
  $plan['rows'] = (int)$plan['rows'];
  [$seatsByPosition, $seatsByStudent] = seating_plan_load_seats($pdo, $plan['id']);
  $plan['seats_by_position'] = $seatsByPosition;
  $plan['seats_by_student'] = $seatsByStudent;
  return $plan;
}

/**
 * Legt einen neuen benannten Sitzplan an oder aktualisiert Name/Spalten-
 * /Reihenanzahl eines bestehenden (planId>0). Plätze, die durch eine
 * Verkleinerung außerhalb des neuen Rasters liegen, werden dabei
 * automatisch frei (Zuweisung entfernt, die Schüler:innen selbst bleiben
 * natürlich unverändert). Gibt [plan_id, removed_seat_count] zurück.
 */
function save_seating_plan(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $name, int $columns, int $rows, int $planId = 0): array {
  $name = seating_plan_name($name);
  $columns = seating_plan_clamp_size($columns, 4);
  $rows = seating_plan_clamp_size($rows, 4);

  $dup = $pdo->prepare("SELECT id FROM teacher_seating_plans
                        WHERE teacher_id=? AND class_id=? AND subject_id=? AND name=? AND id<>?
                        LIMIT 1");
  $dup->execute([$teacherId, $classId, $subjectId, $name, $planId]);
  if($dup->fetch()) throw new RuntimeException('Ein Sitzplan mit diesem Namen existiert für diese Klasse/dieses Fach bereits.');

  $removed = 0;
  if($planId > 0){
    $check = $pdo->prepare("SELECT id FROM teacher_seating_plans WHERE id=? AND teacher_id=? AND class_id=? AND subject_id=?");
    $check->execute([$planId, $teacherId, $classId, $subjectId]);
    if(!$check->fetch()) throw new RuntimeException('Sitzplan nicht gefunden.');

    $st = $pdo->prepare("SELECT COUNT(*) FROM teacher_seating_plan_seats WHERE plan_id=? AND (seat_col>? OR seat_row>?)");
    $st->execute([$planId, $columns, $rows]);
    $removed = (int)$st->fetchColumn();

    $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=? AND (seat_col>? OR seat_row>?)")
        ->execute([$planId, $columns, $rows]);
    $pdo->prepare("UPDATE teacher_seating_plans SET name=?, columns=?, grid_rows=?, updated_at=? WHERE id=?")
        ->execute([$name, $columns, $rows, now_iso(), $planId]);
  } else {
    $pdo->prepare("INSERT INTO teacher_seating_plans (teacher_id,class_id,subject_id,name,layout_type,columns,grid_rows,created_at,updated_at) VALUES (?,?,?,?, 'grid', ?,?,?,?)")
        ->execute([$teacherId, $classId, $subjectId, $name, $columns, $rows, now_iso(), now_iso()]);
    $planId = (int)$pdo->lastInsertId();
  }

  return ['plan_id'=>$planId, 'removed_seat_count'=>$removed];
}

function delete_seating_plan(PDO $pdo, int $teacherId, int $planId): ?array {
  $st = $pdo->prepare("SELECT ".SEATING_PLAN_COLUMNS_SQL." FROM teacher_seating_plans WHERE id=? AND teacher_id=? LIMIT 1");
  $st->execute([$planId, $teacherId]);
  $plan = $st->fetch();
  if(!$plan) return null;

  $pdo->prepare("DELETE FROM teacher_seating_plans WHERE id=? AND teacher_id=?")->execute([$planId, $teacherId]);
  return $plan;
}

/**
 * Weist eine Schülerin/einen Schüler einem Platz zu. Ein bereits an diesem
 * Platz sitzender anderer Schüler wird dabei automatisch frei (Platztausch
 * ist damit implizit möglich: neuen Platz wählen, alten Platz bleibt frei).
 * Saß die Person schon woanders im selben Sitzplan, wird der alte Platz
 * automatisch geräumt (eine Person sitzt immer nur an einem Platz).
 */
function assign_seating_plan_seat(PDO $pdo, int $planId, int $studentId, int $col, int $row): void {
  $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=? AND student_id=?")->execute([$planId, $studentId]);
  $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=? AND seat_col=? AND seat_row=?")->execute([$planId, $col, $row]);
  $pdo->prepare("INSERT INTO teacher_seating_plan_seats (plan_id,student_id,seat_col,seat_row) VALUES (?,?,?,?)")
      ->execute([$planId, $studentId, $col, $row]);
  $pdo->prepare("UPDATE teacher_seating_plans SET updated_at=? WHERE id=?")->execute([now_iso(), $planId]);
}

function unassign_seating_plan_seat(PDO $pdo, int $planId, int $col, int $row): void {
  $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=? AND seat_col=? AND seat_row=?")->execute([$planId, $col, $row]);
  $pdo->prepare("UPDATE teacher_seating_plans SET updated_at=? WHERE id=?")->execute([now_iso(), $planId]);
}

/**
 * Sitzplaene derselben Klasse in ANDEREN Faechern dieser Lehrkraft, inkl.
 * aller Platzzuweisungen - Grundlage fuer den "Sitzplan aus Fach X
 * uebernehmen"-Vorschlag beim Anlegen eines neuen Sitzplans: eine Klasse
 * sitzt in den meisten Faellen unabhaengig vom Fach im selben Raum, daher
 * muss die Anordnung nicht jedes Mal neu eingetippt werden (Feedback vom
 * 2026-10-04).
 */
function load_teacher_seating_plans_for_class_other_subjects(PDO $pdo, int $teacherId, int $classId, int $excludeSubjectId): array {
  $st = $pdo->prepare("SELECT sp.id, sp.teacher_id, sp.class_id, sp.subject_id, sp.name, sp.layout_type, sp.columns, sp.grid_rows AS `rows`, sp.created_at, sp.updated_at,
                              sub.code AS subject_code, sub.name AS subject_name
                       FROM teacher_seating_plans sp
                       JOIN subjects sub ON sub.id=sp.subject_id
                       WHERE sp.teacher_id=? AND sp.class_id=? AND sp.subject_id<>?
                       ORDER BY sp.updated_at DESC, sp.name, sp.id");
  $st->execute([$teacherId, $classId, $excludeSubjectId]);
  $plans = $st->fetchAll();
  foreach($plans as &$plan){
    $plan['id'] = (int)$plan['id'];
    $plan['subject_id'] = (int)$plan['subject_id'];
    $plan['columns'] = (int)$plan['columns'];
    $plan['rows'] = (int)$plan['rows'];
    [$seatsByPosition, $seatsByStudent] = seating_plan_load_seats($pdo, $plan['id']);
    $plan['seats_by_position'] = $seatsByPosition;
    $plan['seats_by_student'] = $seatsByStudent;
  }
  unset($plan);
  return $plans;
}

/**
 * Liefert einen in diesem Fach noch unbenutzten Sitzplan-Namen - haengt bei
 * einer Namenskollision "(2)", "(3)", ... an. Wird beim Uebernehmen eines
 * Sitzplans aus einem anderen Fach gebraucht, falls dort zufaellig schon
 * ein gleichnamiger Sitzplan existiert (z.B. beide heissen "Standard").
 */
function seating_plan_unique_name(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $baseName): string {
  $name = seating_plan_name($baseName);
  $candidate = $name;
  $suffix = 2;
  while(true){
    $st = $pdo->prepare("SELECT id FROM teacher_seating_plans WHERE teacher_id=? AND class_id=? AND subject_id=? AND name=? LIMIT 1");
    $st->execute([$teacherId, $classId, $subjectId, $candidate]);
    if(!$st->fetch()) return $candidate;
    $candidate = $name.' ('.$suffix.')';
    $suffix++;
  }
}
