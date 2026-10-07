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
 * layout_type:
 *  - 'grid': klassisches Raster aus Spalten x Reihen (seat_col/seat_row =
 *    Spalte/Reihe).
 *  - 'free': Sitzplan-Editor (seit 1.81.6). Die Tische stehen mit Typ,
 *    Position und Drehung in layout_json; seat_col ist die Tischnummer
 *    (1-basiert, Reihenfolge in layout_json), seat_row der Platz am Tisch.
 *    columns/grid_rows enthalten dann nur Kennzahlen (Anzahl Tische /
 *    größte Platzzahl an einem Tisch), damit bestehende Prüfungen gültig bleiben.
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
const SEATING_PLAN_COLUMNS_SQL = "id,teacher_id,class_id,subject_id,name,layout_type,columns,grid_rows AS `rows`,layout_json,created_at,updated_at";

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
    $plan['layout'] = seating_plan_decode_layout($plan);
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
  $plan['layout'] = seating_plan_decode_layout($plan);
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
    $check = $pdo->prepare("SELECT id, layout_type FROM teacher_seating_plans WHERE id=? AND teacher_id=? AND class_id=? AND subject_id=?");
    $check->execute([$planId, $teacherId, $classId, $subjectId]);
    $existing = $check->fetch();
    if(!$existing) throw new RuntimeException('Sitzplan nicht gefunden.');
    // Freie Sitzpläne (Sitzplan-Editor) haben kein Spalten-/Reihen-Raster;
    // ein Raster-Speichern würde ihre Platzbelegung zerstören.
    if(($existing['layout_type'] ?? 'grid') === 'free') throw new RuntimeException('Dieser Sitzplan wird im Sitzplan-Editor bearbeitet.');

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
  $st = $pdo->prepare("SELECT sp.id, sp.teacher_id, sp.class_id, sp.subject_id, sp.name, sp.layout_type, sp.columns, sp.grid_rows AS `rows`, sp.layout_json, sp.created_at, sp.updated_at,
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
    $plan['layout'] = seating_plan_decode_layout($plan);
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

/* ------------------------------------------------------------------------
 * Sitzplan-Editor (layout_type 'free', seit 1.81.6)
 * --------------------------------------------------------------------- */

const SEATING_LAYOUT_MAX_TABLES = 80;

/**
 * Vorlagen des Sitzplan-Editors. Die Geometrie selbst erzeugt
 * assets/seating_layout.js; hier stehen nur Schlüssel, Bezeichnung,
 * Gruppe und Kurzbeschreibung für die Kontoeinstellung und den Editor.
 */
function seating_plan_templates(): array {
  return [
    'reihen'    => ['label'=>'Reihen',          'group'=>'Frontal',       'desc'=>'Klassische Reihen, auch Einzeltische (Prüfung) oder Paare'],
    'fisch'     => ['label'=>'Fischgräte',      'group'=>'Frontal',       'desc'=>'Reihen schräg zur Mitte gedreht'],
    'edv'       => ['label'=>'EDV-Raum',        'group'=>'Frontal',       'desc'=>'Einzelplätze an den Wänden, Blick zur Wand'],
    'u'         => ['label'=>'U-Form',          'group'=>'Diskussion',    'desc'=>'Hufeisen mit Öffnung zur Tafel'],
    'doppelu'   => ['label'=>'Doppel-U',        'group'=>'Diskussion',    'desc'=>'Zwei U-Formen ineinander für große Klassen'],
    'konferenz' => ['label'=>'Konferenz',       'group'=>'Diskussion',    'desc'=>'Ein oder mehrere große Tische, Plätze rundum'],
    'bankett'   => ['label'=>'Bankett',         'group'=>'Diskussion',    'desc'=>'Lange Reihen, die einander gegenübersitzen'],
    'kreis'     => ['label'=>'Sitzkreis',       'group'=>'Diskussion',    'desc'=>'Stühle im Kreis ohne Tische'],
    'fishbowl'  => ['label'=>'Fishbowl',        'group'=>'Diskussion',    'desc'=>'Innenkreis diskutiert, Außenkreis beobachtet'],
    'inseln'    => ['label'=>'Lerninseln',      'group'=>'Gruppenarbeit', 'desc'=>'Gruppentische mit 3 bis 8 Plätzen'],
    'hufeisen'  => ['label'=>'Kleine Hufeisen', 'group'=>'Gruppenarbeit', 'desc'=>'Mehrere kleine U-Formen aus je drei Tischen'],
  ];
}

/** 'classic' (Raster wie bisher) oder 'editor' (Sitzplan-Editor). */
function user_seating_design(?array $u): string {
  return (($u['pref_seating_design'] ?? 'classic') === 'editor') ? 'editor' : 'classic';
}

/** Vom Benutzer freigegebene Vorlagen (NULL in der DB = alle). */
function user_seating_templates(?array $u): array {
  $all = array_keys(seating_plan_templates());
  $raw = $u['pref_seating_templates'] ?? null;
  if($raw === null) return $all;
  $chosen = array_filter(array_map('trim', explode(',', (string)$raw)), 'strlen');
  return array_values(array_intersect($all, $chosen));
}

/** Anzahl der Plätze eines Tisch-Typs, null bei unbekanntem Typ. */
function seating_layout_type_seats(string $type): ?int {
  static $fixed = ['t1'=>1,'t2'=>2,'t3'=>3,'chair'=>1,'lt'=>0,'board'=>0];
  if(isset($fixed[$type])) return $fixed[$type];
  if(preg_match('/^i([3-8])$/', $type, $m)) return (int)$m[1];
  if(preg_match('/^k(\d{1,2})$/', $type, $m)){
    $k = (int)$m[1];
    if($k >= 4 && $k <= 30 && $k % 2 === 0) return $k;
  }
  return null;
}

/**
 * Prüft und normalisiert ein Layout aus dem Editor. Wirft eine
 * RuntimeException bei ungültigen Daten. Rückgabe:
 * ['version'=>1,'tables'=>[['type'=>..,'x'=>..,'y'=>..,'rot'=>..], ...]]
 */
function seating_layout_normalize($layout): array {
  if(is_string($layout)) $layout = json_decode($layout, true);
  if(!is_array($layout) || !isset($layout['tables']) || !is_array($layout['tables'])){
    throw new RuntimeException('Der Sitzplan konnte nicht gelesen werden.');
  }
  if(count($layout['tables']) > SEATING_LAYOUT_MAX_TABLES){
    throw new RuntimeException('Ein Sitzplan kann höchstens '.SEATING_LAYOUT_MAX_TABLES.' Tische enthalten.');
  }
  $tables = [];
  $boards = 0;
  foreach(array_values($layout['tables']) as $t){
    if(!is_array($t)) throw new RuntimeException('Ungültiger Tisch im Sitzplan.');
    $type = (string)($t['type'] ?? '');
    if(seating_layout_type_seats($type) === null) throw new RuntimeException('Unbekannter Tischtyp im Sitzplan.');
    if($type === 'board') $boards++;
    $x = (int)round((float)($t['x'] ?? 0));
    $y = (int)round((float)($t['y'] ?? 0));
    $rot = (int)round((float)($t['rot'] ?? 0));
    $rot = (($rot % 360) + 360) % 360;
    $tables[] = [
      'type'=>$type,
      'x'=>max(-3000, min(5000, $x)),
      'y'=>max(-3000, min(5000, $y)),
      'rot'=>$rot,
    ];
  }
  if($boards > 1) throw new RuntimeException('Ein Sitzplan kann nur eine Tafel enthalten.');
  return ['version'=>1, 'tables'=>$tables];
}

/** Dekodiertes Layout eines Sitzplans ('free'), sonst null. */
function seating_plan_decode_layout(array $plan): ?array {
  if(($plan['layout_type'] ?? 'grid') !== 'free') return null;
  try{
    return seating_layout_normalize((string)($plan['layout_json'] ?? ''));
  }catch(Throwable $e){
    return ['version'=>1, 'tables'=>[]];
  }
}

/** Gesamtzahl der Plätze eines Layouts. */
function seating_layout_seat_count(array $layout): int {
  $n = 0;
  foreach($layout['tables'] as $t) $n += (int)seating_layout_type_seats((string)$t['type']);
  return $n;
}

/** Kurzbeschreibung für Buttons/Auswahllisten, z.B. "4×5" oder "frei, 26 Plätze". */
function seating_plan_size_label(array $plan): string {
  if(($plan['layout_type'] ?? 'grid') === 'free'){
    $layout = $plan['layout'] ?? seating_plan_decode_layout($plan);
    return 'frei, '.seating_layout_seat_count($layout ?: ['tables'=>[]]).' Plätze';
  }
  return (int)$plan['columns'].'×'.(int)$plan['rows'];
}

/**
 * Speichert einen Sitzplan aus dem Sitzplan-Editor (neu oder bestehend)
 * inklusive kompletter Platzbelegung in einer Transaktion.
 * $seats: Liste von [student_id, seat_col (Tisch-Nr.), seat_row (Platz)].
 * Personen, die nicht (mehr) zur Klasse gehören, und Plätze, die es im
 * Layout nicht gibt, werden übersprungen. Rückgabe: ['plan_id'=>..,'seat_count'=>..]
 */
function save_free_seating_plan(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $name, $layout, array $seats, int $planId = 0): array {
  $name = seating_plan_name($name);
  $layout = seating_layout_normalize($layout);

  $dup = $pdo->prepare("SELECT id FROM teacher_seating_plans
                        WHERE teacher_id=? AND class_id=? AND subject_id=? AND name=? AND id<>?
                        LIMIT 1");
  $dup->execute([$teacherId, $classId, $subjectId, $name, $planId]);
  if($dup->fetch()) throw new RuntimeException('Ein Sitzplan mit diesem Namen existiert für diese Klasse/dieses Fach bereits.');

  $tableCount = count($layout['tables']);
  $maxSeats = 1;
  foreach($layout['tables'] as $t) $maxSeats = max($maxSeats, (int)seating_layout_type_seats($t['type']));

  $st = $pdo->prepare("SELECT id FROM students WHERE class_id=?");
  $st->execute([$classId]);
  $validStudents = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

  $clean = [];
  $usedStudents = [];
  $usedSeats = [];
  foreach($seats as $seat){
    if(!is_array($seat) || count($seat) < 3) continue;
    [$sid, $col, $row] = array_map('intval', array_values($seat));
    if(!isset($validStudents[$sid]) || isset($usedStudents[$sid])) continue;
    if($col < 1 || $col > $tableCount) continue;
    $capacity = (int)seating_layout_type_seats($layout['tables'][$col-1]['type']);
    if($row < 1 || $row > $capacity) continue;
    if(isset($usedSeats[$col.'_'.$row])) continue;
    $usedStudents[$sid] = true;
    $usedSeats[$col.'_'.$row] = true;
    $clean[] = [$sid, $col, $row];
  }

  $json = json_encode($layout, JSON_UNESCAPED_UNICODE);
  $ownTx = !$pdo->inTransaction();
  if($ownTx) $pdo->beginTransaction();
  try{
    if($planId > 0){
      $check = $pdo->prepare("SELECT id FROM teacher_seating_plans WHERE id=? AND teacher_id=? AND class_id=? AND subject_id=?");
      $check->execute([$planId, $teacherId, $classId, $subjectId]);
      if(!$check->fetch()) throw new RuntimeException('Sitzplan nicht gefunden.');
      $pdo->prepare("UPDATE teacher_seating_plans SET name=?, layout_type='free', columns=?, grid_rows=?, layout_json=?, updated_at=? WHERE id=?")
          ->execute([$name, $tableCount, $maxSeats, $json, now_iso(), $planId]);
      $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=?")->execute([$planId]);
    } else {
      $pdo->prepare("INSERT INTO teacher_seating_plans (teacher_id,class_id,subject_id,name,layout_type,columns,grid_rows,layout_json,created_at,updated_at) VALUES (?,?,?,?,'free',?,?,?,?,?)")
          ->execute([$teacherId, $classId, $subjectId, $name, $tableCount, $maxSeats, $json, now_iso(), now_iso()]);
      $planId = (int)$pdo->lastInsertId();
    }
    $ins = $pdo->prepare("INSERT INTO teacher_seating_plan_seats (plan_id,student_id,seat_col,seat_row) VALUES (?,?,?,?)");
    foreach($clean as [$sid, $col, $row]) $ins->execute([$planId, $sid, $col, $row]);
    if($ownTx) $pdo->commit();
  }catch(Throwable $e){
    if($ownTx && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return ['plan_id'=>$planId, 'seat_count'=>count($clean)];
}

/**
 * Daten eines Sitzplans für assets/seating_layout.js (Anzeige oder Editor):
 * Layout plus Belegung als [student_id, Tisch-Nr., Platz].
 */
function seating_plan_client_data(array $plan): array {
  $seats = [];
  foreach(($plan['seats_by_position'] ?? []) as $seat){
    $seats[] = [(int)$seat['student_id'], (int)$seat['col'], (int)$seat['row']];
  }
  return ['layout'=>$plan['layout'] ?? seating_plan_decode_layout($plan) ?? ['version'=>1,'tables'=>[]], 'seats'=>$seats];
}

/** Schülerliste für assets/seating_layout.js. */
function seating_plan_client_students(array $students): array {
  $out = [];
  foreach($students as $s){
    $out[] = ['id'=>(int)$s['id'], 'first'=>(string)$s['first_name'], 'last'=>(string)$s['last_name']];
  }
  return $out;
}
