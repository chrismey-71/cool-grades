<?php
require_once __DIR__.'/helpers.php';

/**
 * Sitzplan (seating plan) für die Mitarbeitserfassung.
 *
 * Geltungsbereich: wie bei teacher_student_groups pro (Lehrkraft, Klasse,
 * Fach) - genau ein Sitzplan je Kombination. Jede Lehrkraft kann also für
 * dieselbe Klasse in unterschiedlichen Fächern unterschiedliche Sitzpläne
 * anlegen (z.B. weil der Unterricht in verschiedenen Räumen stattfindet).
 *
 * layout_type ist bewusst schon vorgesehen, damit später neben 'grid'
 * (klassische Tischreihen) auch andere Anordnungen (z.B. 'groups' für
 * Gruppentische/Inseln) ergänzt werden können, ohne die Tabelle erneut
 * migrieren zu müssen. Aktuell wird ausschließlich 'grid' unterstützt.
 */

const SEATING_PLAN_MIN_SIZE = 1;
const SEATING_PLAN_MAX_SIZE = 20;

function seating_plan_clamp_size(int $value, int $default): int {
  if($value < SEATING_PLAN_MIN_SIZE) return $default;
  if($value > SEATING_PLAN_MAX_SIZE) return SEATING_PLAN_MAX_SIZE;
  return $value;
}

/**
 * Lädt den Sitzplan einer Lehrkraft für Klasse+Fach inkl. aller belegten
 * Plätze (student_id, first_name, last_name je Spalte/Reihe). Gibt null
 * zurück, wenn noch kein Sitzplan angelegt wurde.
 */
function load_teacher_seating_plan(PDO $pdo, int $teacherId, int $classId, int $subjectId): ?array {
  // Note: the DB column is "grid_rows", not "rows" - ROWS became a reserved
  // word in MySQL 8.0.2, so an unquoted "rows" column name fails at CREATE
  // TABLE time on any current MySQL/MariaDB server. Aliased back to "rows"
  // here so the rest of the codebase can keep using that key.
  $st = $pdo->prepare("SELECT id,teacher_id,class_id,subject_id,layout_type,columns,grid_rows AS rows,created_at,updated_at
                       FROM teacher_seating_plans WHERE teacher_id=? AND class_id=? AND subject_id=? LIMIT 1");
  $st->execute([$teacherId, $classId, $subjectId]);
  $plan = $st->fetch();
  if(!$plan) return null;

  $planId = (int)$plan['id'];
  $st = $pdo->prepare("SELECT sp.seat_col, sp.seat_row, sp.student_id, s.first_name, s.last_name
                       FROM teacher_seating_plan_seats sp
                       JOIN students s ON s.id=sp.student_id
                       WHERE sp.plan_id=?");
  $st->execute([$planId]);

  $seatsByPosition = [];
  $seatsByStudent = [];
  foreach($st->fetchAll() as $row){
    $col = (int)$row['seat_col'];
    $row_ = (int)$row['seat_row'];
    $sid = (int)$row['student_id'];
    $entry = [
      'student_id' => $sid,
      'first_name' => (string)$row['first_name'],
      'last_name' => (string)$row['last_name'],
      'col' => $col,
      'row' => $row_,
    ];
    $seatsByPosition[$col.'_'.$row_] = $entry;
    $seatsByStudent[$sid] = $entry;
  }

  $plan['id'] = $planId;
  $plan['columns'] = (int)$plan['columns'];
  $plan['rows'] = (int)$plan['rows'];
  $plan['seats_by_position'] = $seatsByPosition;
  $plan['seats_by_student'] = $seatsByStudent;
  return $plan;
}

/**
 * Legt den Sitzplan an oder aktualisiert Spalten-/Reihenanzahl. Plätze, die
 * durch eine Verkleinerung außerhalb des neuen Rasters liegen, werden dabei
 * automatisch frei (Zuweisung entfernt, die Schüler:innen selbst bleiben
 * natürlich unverändert). Gibt [plan_id, removed_seat_count] zurück.
 */
function save_seating_plan_dimensions(PDO $pdo, int $teacherId, int $classId, int $subjectId, int $columns, int $rows): array {
  $columns = seating_plan_clamp_size($columns, 4);
  $rows = seating_plan_clamp_size($rows, 4);

  $st = $pdo->prepare("SELECT id FROM teacher_seating_plans WHERE teacher_id=? AND class_id=? AND subject_id=? LIMIT 1");
  $st->execute([$teacherId, $classId, $subjectId]);
  $planId = (int)($st->fetchColumn() ?: 0);

  $removed = 0;
  if($planId>0){
    $st = $pdo->prepare("SELECT COUNT(*) FROM teacher_seating_plan_seats WHERE plan_id=? AND (seat_col>? OR seat_row>?)");
    $st->execute([$planId, $columns, $rows]);
    $removed = (int)$st->fetchColumn();

    $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=? AND (seat_col>? OR seat_row>?)")
        ->execute([$planId, $columns, $rows]);
    $pdo->prepare("UPDATE teacher_seating_plans SET columns=?, grid_rows=?, updated_at=? WHERE id=?")
        ->execute([$columns, $rows, now_iso(), $planId]);
  } else {
    $pdo->prepare("INSERT INTO teacher_seating_plans (teacher_id,class_id,subject_id,layout_type,columns,grid_rows,created_at,updated_at) VALUES (?,?,?, 'grid', ?,?,?,?)")
        ->execute([$teacherId, $classId, $subjectId, $columns, $rows, now_iso(), now_iso()]);
    $planId = (int)$pdo->lastInsertId();
  }

  return ['plan_id'=>$planId, 'removed_seat_count'=>$removed];
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
}

function unassign_seating_plan_seat(PDO $pdo, int $planId, int $col, int $row): void {
  $pdo->prepare("DELETE FROM teacher_seating_plan_seats WHERE plan_id=? AND seat_col=? AND seat_row=?")->execute([$planId, $col, $row]);
}
