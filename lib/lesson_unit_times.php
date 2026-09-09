<?php
// Admin-configurable clock time per UE (Unterrichtseinheit, 1..12), per school.
//
// Purpose: WebUntis only ever gives us a clock time (start_time/end_time on
// lesson_sessions), never a UE number - a school's own timetable grid
// ("UE 1 = 07:40-08:30" etc.) lives only in the admins' heads until they
// enter it here. Once entered, lib/webuntis_ical.php uses
// lesson_unit_for_time() to auto-fill lesson_unit on newly imported rows,
// so teachers see "UE 3" instead of a bare time even for WebUntis-sourced
// lessons - without ever overwriting a value that is already set (see
// webuntis_upsert_lesson_session() in lib/webuntis_ical.php). The imported
// start_time/end_time itself is untouched either way - it is always the
// authoritative value; lesson_unit is only ever a derived convenience label
// looked up from it.
//
// Scoped per school (school_id): different schools using the same install
// can have different period-time grids, so every lookup/save here needs the
// relevant school_id - there is no "global" fallback. A school_id of 0 (not
// resolvable, e.g. a class without a school_form assigned yet) means "skip
// derivation, never guess", exactly like an unconfigured grid does.
//
// A double (or triple, ...) period that WebUntis exports as one contiguous
// event is detected by finding every configured UE of that school whose own
// start/end falls entirely inside the event's span, and joining their
// numbers with a comma (e.g. "1,2") - the same free-text format
// teacher/lesson.php's manual "UE/Stunde" field already accepts.

require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';

const LESSON_UNIT_TIME_MIN = 1;
const LESSON_UNIT_TIME_MAX = 12;

/**
 * All 12 UE slots for one school, always present in the returned array
 * (unit => ['start'=>?,'end'=>?]). start/end are 'H:i' strings (seconds
 * stripped for display/inputs) or null when an admin hasn't configured that
 * UE yet for this school.
 */
function lesson_unit_time_rows(PDO $pdo, int $schoolId): array {
  $rows = [];
  for ($u = LESSON_UNIT_TIME_MIN; $u <= LESSON_UNIT_TIME_MAX; $u++) {
    $rows[$u] = ['start' => null, 'end' => null];
  }
  if ($schoolId <= 0) return $rows;
  try {
    $st = $pdo->prepare("SELECT unit, start_time, end_time FROM lesson_unit_times WHERE school_id=? ORDER BY unit ASC");
    $st->execute([$schoolId]);
    foreach ($st->fetchAll() as $r) {
      $u = (int)$r['unit'];
      if (!isset($rows[$u])) continue;
      $rows[$u] = [
        'start' => substr((string)$r['start_time'], 0, 5),
        'end'   => substr((string)$r['end_time'], 0, 5),
      ];
    }
  } catch (Exception $e) { /* table not migrated yet on this connection – treat as unconfigured */ }
  return $rows;
}

/** Only the UEs an admin has actually filled in for this school, keyed by unit (int) => ['start'=>'H:i:s','end'=>'H:i:s']. */
function lesson_unit_time_map(PDO $pdo, int $schoolId): array {
  $map = [];
  if ($schoolId <= 0) return $map;
  try {
    $st = $pdo->prepare("SELECT unit, start_time, end_time FROM lesson_unit_times WHERE school_id=? ORDER BY unit ASC");
    $st->execute([$schoolId]);
    foreach ($st->fetchAll() as $r) {
      $map[(int)$r['unit']] = [
        'start' => (string)$r['start_time'],
        'end'   => (string)$r['end_time'],
      ];
    }
  } catch (Exception $e) { /* unconfigured */ }
  return $map;
}

/**
 * Derives a lesson_unit value ("3" or, for a contiguous double/triple
 * period, "3,4") from a WebUntis start/end time, using the given school's
 * admin-configured UE grid. Returns null when the school isn't resolvable,
 * nothing is configured for it, or nothing matches - callers must leave
 * lesson_unit untouched in that case, never guess.
 */
function lesson_unit_for_time(PDO $pdo, int $schoolId, ?string $startTime, ?string $endTime): ?string {
  if (!$startTime || $schoolId <= 0) return null;
  $map = lesson_unit_time_map($pdo, $schoolId);
  if (!$map) return null;

  $start = substr($startTime, 0, 8);
  $end = $endTime ? substr($endTime, 0, 8) : null;

  // Exact single-UE match first.
  foreach ($map as $unit => $t) {
    if ($t['start'] === $start && ($end === null || $t['end'] === $end)) {
      return (string)$unit;
    }
  }

  // Contiguous run of UEs fully inside the event's span (double/triple period).
  if ($end !== null && $end > $start) {
    $contained = [];
    foreach ($map as $unit => $t) {
      if ($t['start'] >= $start && $t['end'] <= $end) {
        $contained[] = $unit;
      }
    }
    if ($contained) {
      sort($contained);
      return implode(',', $contained);
    }
  }

  // Fall back to a UE whose start matches, even if its own end differs
  // (shorter/longer than the admin's grid, e.g. an early dismissal).
  foreach ($map as $unit => $t) {
    if ($t['start'] === $start) return (string)$unit;
  }

  return null;
}

/** Short "UE 1: 07:40-08:30 · UE 2: ..." hint string for entry-mask UIs. Empty string if nothing configured or no school resolvable. */
function lesson_unit_legend_label(PDO $pdo, int $schoolId): string {
  $map = lesson_unit_time_map($pdo, $schoolId);
  if (!$map) return '';
  $parts = [];
  foreach ($map as $unit => $t) {
    $parts[] = 'UE '.$unit.': '.substr($t['start'], 0, 5).'–'.substr($t['end'], 0, 5);
  }
  return implode(' · ', $parts);
}

/**
 * Saves the admin form for one school: $rows = [unit => ['start'=>'H:i'|'', 'end'=>'H:i'|'']].
 * A row with both fields empty deletes that UE's entry for this school
 * (goes back to unconfigured); an incomplete row (only one of start/end
 * given) is an error. Returns null on success, or an error message.
 */
function lesson_unit_times_save(PDO $pdo, int $schoolId, array $rows): ?string {
  if ($schoolId <= 0) return 'Bitte zuerst eine Schule wählen.';

  $clean = [];
  foreach ($rows as $unit => $r) {
    $unit = (int)$unit;
    if ($unit < LESSON_UNIT_TIME_MIN || $unit > LESSON_UNIT_TIME_MAX) continue;
    $start = trim((string)($r['start'] ?? ''));
    $end = trim((string)($r['end'] ?? ''));
    if ($start === '' && $end === '') {
      $clean[$unit] = null; // delete
      continue;
    }
    if (!preg_match('/^\d{1,2}:\d{2}$/', $start) || !preg_match('/^\d{1,2}:\d{2}$/', $end)) {
      return 'UE '.$unit.': Bitte Uhrzeiten im Format hh:mm angeben.';
    }
    if ($start >= $end) {
      return 'UE '.$unit.': Ende muss nach dem Beginn liegen.';
    }
    $clean[$unit] = ['start' => $start.':00', 'end' => $end.':00'];
  }

  $now = now_iso();
  foreach ($clean as $unit => $v) {
    if ($v === null) {
      $pdo->prepare("DELETE FROM lesson_unit_times WHERE school_id=? AND unit=?")->execute([$schoolId, $unit]);
    } else {
      $pdo->prepare("INSERT INTO lesson_unit_times (school_id,unit,start_time,end_time,updated_at) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), updated_at=VALUES(updated_at)")
          ->execute([$schoolId, $unit, $v['start'], $v['end'], $now]);
    }
  }
  return null;
}
