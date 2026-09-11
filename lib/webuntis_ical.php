<?php
// WebUntis iCal import (GitHub issue #2 – MVP).
//
// Scope of this first version: a teacher stores their private WebUntis
// iCal subscription link (protected like a credential – see
// security_encrypt_secret()/security_decrypt_secret()), fetches it on
// demand, and the app maps each lesson event onto an existing
// lesson_sessions row (reusing the table, not a parallel structure – per
// the issue's "Technische Zielstruktur"). Matching is done by exact clock
// time (DTSTART/DTEND), not by a WebUntis "UE" number, because the feed
// does not expose one. Only subjects/classes the importing teacher is
// actively assigned to are ever touched; anything that cannot be mapped
// with confidence is skipped and reported back, never guessed.
//
// lesson_unit itself IS still filled in when possible: if an admin has
// defined the school's UE grid (admin/lesson_unit_times.php), each
// newly-seen time slot is translated into a UE number (or, for a
// contiguous double period, "1,2") via lesson_unit_for_time() - see
// webuntis_upsert_lesson_session() below. A UE that is already set, on
// any lesson_sessions row, is never touched by this.
//
// A changed WebUntis timetable (moved/dropped slots) is also cleaned up:
// after each import, previously-imported rows that fell out of the
// schedule are deleted, within the date range this run's feed actually
// covers - unless the row has participation entries (never auto-deleted,
// same as the manual delete) or a manually-set topic (kept, removable only
// by hand). See webuntis_prune_stale_lesson_sessions() below (2026-09
// teacher feedback).

require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/logger.php';
require_once __DIR__.'/lesson_unit_times.php';
require_once __DIR__.'/schools.php';

/**
 * Normalizes a WebUntis subject/event code (SUMMARY field, subjects.code,
 * or a saved webuntis_subject_mappings.webuntis_code) for comparison.
 *
 * Why this exists: MySQL's default utf8mb4 collation compares/GROUPs
 * strings case- and (for some collations) accent-insensitively, and
 * effectively ignores a trailing/non-breaking space - so two events whose
 * SUMMARY differs only in casing (e.g. "SPRE" vs "Spre") or a stray
 * non-breaking space show up as ONE merged row on the review page
 * (teacher/webuntis_review.php groups by webuntis_code). But the actual
 * import matching in webuntis_import_for_teacher() compares codes as PHP
 * array keys, which is always byte-exact - so only the exact casing the
 * teacher mapped got recognized, and any differently-cased occurrence of
 * "the same" code silently stayed unmapped/un-ignored forever, even
 * though the review page made it look like a single, already-handled
 * code. Normalizing every code the same way, wherever it is stored or
 * compared, keeps both sides consistent.
 */
function webuntis_normalize_code(string $code): string {
  $code = str_replace("\xC2\xA0", ' ', $code); // geschütztes Leerzeichen (NBSP) -> normales Leerzeichen
  $code = preg_replace('/\s+/u', ' ', $code) ?? $code;
  $code = trim($code);
  return mb_strtoupper($code, 'UTF-8');
}

/**
 * Fetches the raw iCal text from a (private, token-bearing) URL.
 * Never logs the URL itself – only a masked form – so the token cannot
 * leak into logs on failure.
 */
function webuntis_fetch_ical(string $url): string {
  if ($url === '' || !preg_match('#^https?://#i', $url)) {
    throw new RuntimeException('Ungültiger iCal-Link.');
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Accept: text/calendar, text/plain, */*'],
    CURLOPT_USERAGENT => 'COOL-Grades/1.0 (+WebUntis iCal import)',
  ]);
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($errno !== 0) {
    app_log('warn', 'webuntis ical fetch failed (transport)', ['host' => (string)parse_url($url, PHP_URL_HOST), 'curl_errno' => $errno]);
    throw new RuntimeException('Der iCal-Feed konnte nicht abgerufen werden (Verbindungsfehler).');
  }
  if ($status < 200 || $status >= 300 || $body === false) {
    app_log('warn', 'webuntis ical fetch failed (http status)', ['host' => (string)parse_url($url, PHP_URL_HOST), 'status' => $status]);
    throw new RuntimeException('Der iCal-Feed konnte nicht abgerufen werden (HTTP '.$status.').');
  }
  if (stripos($body, 'BEGIN:VCALENDAR') === false) {
    throw new RuntimeException('Die Antwort sieht nicht wie ein gültiger iCal-Feed aus.');
  }
  return $body;
}

/**
 * Unfolds RFC5545 line continuations (a leading space/tab means "this
 * line continues the previous one") and splits into logical lines.
 */
function webuntis_ical_unfold(string $ics): array {
  $ics = str_replace(["\r\n", "\r"], "\n", $ics);
  $rawLines = explode("\n", $ics);
  $lines = [];
  foreach ($rawLines as $line) {
    if (($line !== '') && ($line[0] === ' ' || $line[0] === "\t") && $lines) {
      $lines[count($lines) - 1] .= substr($line, 1);
    } else {
      $lines[] = $line;
    }
  }
  return $lines;
}

function webuntis_ical_unescape(string $value): string {
  $value = str_replace(['\\,', '\\;', '\\N', '\\n'], [',', ';', "\n", "\n"], $value);
  $value = str_replace('\\\\', '\\', $value);
  return $value;
}

/**
 * Parses one content line ("NAME;PARAM=X:VALUE") into [name, params, value].
 */
function webuntis_ical_parse_line(string $line): ?array {
  $colonPos = strpos($line, ':');
  if ($colonPos === false) return null;
  $head = substr($line, 0, $colonPos);
  $value = substr($line, $colonPos + 1);
  $parts = explode(';', $head);
  $name = strtoupper(array_shift($parts));
  $params = [];
  foreach ($parts as $part) {
    $eq = strpos($part, '=');
    if ($eq === false) continue;
    $params[strtoupper(substr($part, 0, $eq))] = substr($part, $eq + 1);
  }
  return [$name, $params, $value];
}

/**
 * Parses a DTSTART/DTEND value (with optional TZID param) into a DateTime.
 * Returns null if the value is not a recognizable date-time.
 */
function webuntis_ical_parse_datetime(string $value, array $params): ?DateTime {
  $value = trim($value);
  try {
    if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $m)) {
      return new DateTime($m[1].'T'.$m[2].'Z', new DateTimeZone('UTC'));
    }
    if (preg_match('/^(\d{8})T(\d{6})$/', $value, $m)) {
      $tzid = (string)($params['TZID'] ?? date_default_timezone_get());
      $tz = new DateTimeZone($tzid);
      $dt = DateTime::createFromFormat('Ymd\THis', $m[1].'T'.$m[2], $tz);
      return $dt ?: null;
    }
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

/**
 * Parses the ICS text into a list of VEVENTs with normalized fields.
 * Each event: uid, status, summary, location, description,
 * dtstart (DateTime|null), dtend (DateTime|null).
 */
function webuntis_parse_ical(string $ics): array {
  $lines = webuntis_ical_unfold($ics);
  $events = [];
  $current = null;
  foreach ($lines as $line) {
    if ($line === '') continue;
    $parsed = webuntis_ical_parse_line($line);
    if ($parsed === null) continue;
    [$name, $params, $value] = $parsed;

    if ($name === 'BEGIN' && strtoupper($value) === 'VEVENT') {
      $current = ['uid' => '', 'status' => '', 'summary' => '', 'location' => '', 'description' => '', 'dtstart' => null, 'dtend' => null];
      continue;
    }
    if ($name === 'END' && strtoupper($value) === 'VEVENT') {
      if ($current !== null) $events[] = $current;
      $current = null;
      continue;
    }
    if ($current === null) continue;

    switch ($name) {
      case 'UID': $current['uid'] = webuntis_ical_unescape($value); break;
      case 'STATUS': $current['status'] = strtoupper(trim($value)); break;
      case 'SUMMARY': $current['summary'] = trim(webuntis_ical_unescape($value)); break;
      case 'LOCATION': $current['location'] = trim(webuntis_ical_unescape($value)); break;
      case 'DESCRIPTION': $current['description'] = trim(webuntis_ical_unescape($value)); break;
      case 'DTSTART': $current['dtstart'] = webuntis_ical_parse_datetime($value, $params); break;
      case 'DTEND': $current['dtend'] = webuntis_ical_parse_datetime($value, $params); break;
    }
  }
  return $events;
}

/**
 * Extracts the WebUntis class/group tokens from a DESCRIPTION value.
 * Observed shapes: "3HLSa MEYS", "3HLSa\; 3HLSb MEYS", bare "MEYS" (no
 * class at all – e.g. a meeting/duty entry). Class tokens are split on
 * ';', the trailing teacher shortname is stripped off the last one, and
 * anything that doesn't start with a digit (WebUntis class names always
 * start with the year, e.g. "3FSBa") is discarded rather than guessed –
 * this is what correctly drops a bare "MEYS" to "no class identified".
 */
function webuntis_class_tokens_from_description(string $description): array {
  $text = trim($description);
  if ($text === '') return [];
  $parts = array_map('trim', explode(';', $text));
  $lastIndex = count($parts) - 1;
  $parts[$lastIndex] = trim(preg_replace('/\s+[A-ZÄÖÜ]{2,8}$/u', '', $parts[$lastIndex]));
  $tokens = [];
  foreach ($parts as $part) {
    if ($part !== '' && preg_match('/^\d/', $part)) $tokens[] = $part;
  }
  return $tokens;
}

/**
 * Maps a WebUntis class token onto a COOL-Grades class name by stripping
 * the trailing lowercase subgroup letter (a/b), e.g. "3FSBa" -> "3FSB".
 * Confirmed mapping rule (2026-09, per user decision).
 */
function webuntis_map_class_token(string $token): string {
  $token = trim($token);
  return preg_replace('/[ab]$/', '', $token);
}

/**
 * Inserts/updates/refreshes the lesson_sessions row for one resolved
 * (class_id, subject_id, date, time) slot. Shared by the main import loop
 * and by re-import after a subject-code mapping decision, so both paths
 * dedupe/refresh identically. Returns ['status'=>'imported'|'updated'|'unchanged','id'=>int]
 * - the id lets the caller track which rows are still present in the
 * current schedule, so it can prune ones that are not (see
 * webuntis_prune_stale_lesson_sessions()).
 */
function webuntis_upsert_lesson_session(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $lessonDate, string $startTime, string $endTime, ?string $room, ?string $uid, ?string $subgroup): array {
  $findStmt = $pdo->prepare("SELECT id, source, lesson_unit FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date=? AND start_time <=> ? LIMIT 1");
  $findStmt->execute([$classId, $subjectId, $lessonDate, $startTime]);
  $existing = $findStmt->fetch();

  // Only ever used to FILL a still-empty lesson_unit (e.g. after an admin
  // configures/changes the UE grid later) - never to overwrite one a
  // teacher already has, whether it was set manually or by a past import.
  // The UE grid is per school, so it's resolved from the class itself
  // (class_school_id()) rather than passed in - a class's school can't
  // change mid-import, and this keeps the derivation self-contained.
  $derivedUnit = null;
  if (!$existing || $existing['lesson_unit'] === null || $existing['lesson_unit'] === '') {
    $schoolId = class_school_id($pdo, $classId);
    $derivedUnit = lesson_unit_for_time($pdo, $schoolId, $startTime, $endTime);
  }

  if ($existing) {
    $id = (int)$existing['id'];
    if ((string)$existing['source'] !== 'webuntis') {
      $pdo->prepare("UPDATE lesson_sessions SET source='webuntis', external_uid=?, room=COALESCE(room,?), webuntis_subgroup=?, lesson_unit=COALESCE(lesson_unit,?) WHERE id=?")
          ->execute([$uid, $room, $subgroup, $derivedUnit, $id]);
      return ['status' => 'updated', 'id' => $id];
    }
    $pdo->prepare("UPDATE lesson_sessions SET external_uid=?, room=COALESCE(room,?), webuntis_subgroup=?, lesson_unit=COALESCE(lesson_unit,?) WHERE id=? AND source='webuntis'")
        ->execute([$uid, $room, $subgroup, $derivedUnit, $id]);
    return ['status' => 'unchanged', 'id' => $id];
  }

  try {
    $pdo->prepare("INSERT INTO lesson_sessions
                   (teacher_id,class_id,subject_id,lesson_date,lesson_unit,start_time,end_time,room,source,external_uid,webuntis_subgroup,created_at)
                   VALUES (?,?,?,?,?,?,?,?,'webuntis',?,?,?)")
        ->execute([$teacherId, $classId, $subjectId, $lessonDate, $derivedUnit, $startTime, $endTime, $room, $uid, $subgroup, now_iso()]);
    return ['status' => 'imported', 'id' => (int)$pdo->lastInsertId()];
  } catch (PDOException $e) {
    // Concurrent import/manual entry created the same slot in the meantime; treat as already present.
    app_log('info', 'webuntis import: duplicate slot on insert', ['class_id' => $classId, 'subject_id' => $subjectId, 'lesson_date' => $lessonDate]);
    $findStmt->execute([$classId, $subjectId, $lessonDate, $startTime]);
    $again = $findStmt->fetch();
    return ['status' => 'unchanged', 'id' => $again ? (int)$again['id'] : 0];
  }
}

/**
 * Deletes stale WebUntis-imported lesson_sessions rows that no longer
 * appear in the freshly-imported schedule (e.g. the teacher's timetable
 * changed and a slot was moved or dropped) - otherwise every old, no-longer-
 * current slot would pile up forever (2026-09 teacher feedback).
 *
 * Scope/safety rules:
 *  - Only rows for (class_id, subject_id) combos the teacher is currently
 *    actively assigned to are ever considered - same boundary the import
 *    itself uses ($allowedCombo).
 *  - Only rows within the date range the current feed actually covers
 *    ($coverageMinDate..$coverageMaxDate, from every event with a parseable
 *    date, regardless of whether it mapped to a lesson) are candidates -
 *    older/further-out history the feed says nothing about this run is
 *    never touched, even if it's still marked source='webuntis'.
 *  - A row still matched by an event in this run ($touchedIds) is of
 *    course kept.
 *  - A row with linked participation_events is never auto-deleted, same as
 *    the manual delete in teacher/lesson_delete.php.
 *  - A row with a manually-set topic is never auto-deleted either - unlike
 *    the participation-entries case, this one CAN still be removed, but
 *    only manually via teacher/lesson_delete.php.
 * Returns ['deleted'=>int,'kept_entries'=>int,'kept_topic'=>int].
 */
function webuntis_prune_stale_lesson_sessions(PDO $pdo, array $allowedCombo, ?string $coverageMinDate, ?string $coverageMaxDate, array $touchedIds): array {
  $result = ['deleted' => 0, 'kept_entries' => 0, 'kept_topic' => 0];
  if ($coverageMinDate === null || $coverageMaxDate === null || !$allowedCombo) return $result;

  $touchedIds = array_flip(array_map('intval', $touchedIds));
  $rowStmt = $pdo->prepare("SELECT id, topic FROM lesson_sessions
                            WHERE class_id=? AND subject_id=? AND source='webuntis'
                              AND lesson_date >= ? AND lesson_date <= ?");
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM participation_events WHERE lesson_id=?");
  $delStmt = $pdo->prepare("DELETE FROM lesson_sessions WHERE id=?");

  foreach (array_keys($allowedCombo) as $comboKey) {
    [$classId, $subjectId] = array_map('intval', explode(':', (string)$comboKey, 2));
    if ($classId <= 0 || $subjectId <= 0) continue;

    $rowStmt->execute([$classId, $subjectId, $coverageMinDate, $coverageMaxDate]);
    foreach ($rowStmt->fetchAll() as $row) {
      $id = (int)$row['id'];
      if (isset($touchedIds[$id])) continue;

      if (!empty($row['topic'])) { $result['kept_topic']++; continue; }

      $countStmt->execute([$id]);
      if ((int)$countStmt->fetchColumn() > 0) { $result['kept_entries']++; continue; }

      $delStmt->execute([$id]);
      $result['deleted']++;
    }
  }
  return $result;
}

/**
 * Persists (or refreshes) a raw event that did NOT become a lesson_sessions
 * row because its subject code is unrecognized or explicitly ignored – the
 * data behind the review/correction page. Upserts by (teacher_id,
 * external_uid) so repeated imports don't pile up duplicate rows and so a
 * status flips cleanly once the teacher makes a mapping decision.
 *
 * Some WebUntis feeds export certain entries (often exactly the untitled
 * "blocked time" placeholders that have no SUMMARY either) without a UID
 * line at all. Without a stable UID to upsert on, every import would
 * otherwise INSERT a brand-new row for "the same" occurrence instead of
 * updating the existing one - so a teacher's mapping decision would only
 * ever resolve whichever single row the most recent import happened to
 * touch, while older rows for the same code/date/time combination stayed
 * "unmapped" forever. When no UID is present, this falls back to matching
 * on (teacher_id, webuntis_code, lesson_date, start_time) instead.
 *
 * Returns the affected row's id (0 if the event has no DTSTART and
 * nothing was stored), so callers can track which rows this import run
 * actually touched - see webuntis_prune_stale_unmapped_events().
 */
function webuntis_store_unmapped_event(PDO $pdo, int $teacherId, array $event, string $status): int {
  if (!($event['dtstart'] instanceof DateTime)) return 0;
  $uid = $event['uid'] !== '' ? mb_substr($event['uid'], 0, 128) : null;
  $lessonDate = $event['dtstart']->format('Y-m-d');
  $startTime = $event['dtstart']->format('H:i:s');
  $endTime = ($event['dtend'] instanceof DateTime) ? $event['dtend']->format('H:i:s') : null;
  $room = $event['location'] !== '' ? mb_substr($event['location'], 0, 64) : null;
  $desc = $event['description'] !== '' ? mb_substr($event['description'], 0, 255) : null;
  $code = mb_substr(webuntis_normalize_code($event['summary']), 0, 32);
  $now = now_iso();

  $existingId = null;
  if ($uid !== null) {
    $st = $pdo->prepare("SELECT id FROM webuntis_unmapped_events WHERE teacher_id=? AND external_uid=? LIMIT 1");
    $st->execute([$teacherId, $uid]);
    $existingId = $st->fetchColumn() ?: null;
  } else {
    $st = $pdo->prepare("SELECT id FROM webuntis_unmapped_events WHERE teacher_id=? AND external_uid IS NULL AND webuntis_code=? AND lesson_date=? AND start_time=? LIMIT 1");
    $st->execute([$teacherId, $code, $lessonDate, $startTime]);
    $existingId = $st->fetchColumn() ?: null;
  }

  if ($existingId) {
    $pdo->prepare("UPDATE webuntis_unmapped_events SET webuntis_code=?, webuntis_description=?, lesson_date=?, start_time=?, end_time=?, room=?, status=?, updated_at=? WHERE id=?")
        ->execute([$code, $desc, $lessonDate, $startTime, $endTime, $room, $status, $now, (int)$existingId]);
    return (int)$existingId;
  }

  $pdo->prepare("INSERT INTO webuntis_unmapped_events
                 (teacher_id,external_uid,webuntis_code,webuntis_description,lesson_date,start_time,end_time,room,status,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$teacherId, $uid, $code, $desc, $lessonDate, $startTime, $endTime, $room, $status, $now, $now]);
  return (int)$pdo->lastInsertId();
}

/**
 * Removes webuntis_unmapped_events rows that this import run did not
 * touch (neither re-stored as still-unmapped/ignored, nor cleared as
 * newly-resolved), but only within the date range this run's feed fetch
 * actually covers (coverageMinDate/coverageMaxDate) - mirrors
 * webuntis_prune_stale_lesson_sessions() for the same reason.
 *
 * Without this, a row can outlive whatever WebUntis occurrence it once
 * represented (the occurrence got cancelled, moved, or simply stopped
 * being exported) and sit forever on the review page looking like an
 * unresolved code, even after every occurrence the feed currently
 * reports for that code has actually been handled.
 */
function webuntis_prune_stale_unmapped_events(PDO $pdo, int $teacherId, ?string $coverageMinDate, ?string $coverageMaxDate, array $touchedIds): int {
  if ($coverageMinDate === null || $coverageMaxDate === null) return 0;
  $touchedIds = array_flip(array_filter(array_map('intval', $touchedIds)));
  $st = $pdo->prepare("SELECT id FROM webuntis_unmapped_events WHERE teacher_id=? AND lesson_date >= ? AND lesson_date <= ?");
  $st->execute([$teacherId, $coverageMinDate, $coverageMaxDate]);
  $delStmt = $pdo->prepare("DELETE FROM webuntis_unmapped_events WHERE id=?");
  $deleted = 0;
  foreach ($st->fetchAll() as $row) {
    $id = (int)$row['id'];
    if (isset($touchedIds[$id])) continue;
    $delStmt->execute([$id]);
    $deleted++;
  }
  return $deleted;
}

/**
 * Removes a resolved event from webuntis_unmapped_events (its subject code
 * is now recognized, either directly or via a mapping) so the review page
 * stops listing it. No-op if it was never stored there.
 */
function webuntis_clear_unmapped_event(PDO $pdo, int $teacherId, ?string $uid): void {
  if ($uid === null || $uid === '') return;
  $pdo->prepare("DELETE FROM webuntis_unmapped_events WHERE teacher_id=? AND external_uid=?")->execute([$teacherId, $uid]);
}

/**
 * Imports a teacher's WebUntis lessons into lesson_sessions.
 *
 * Only classes/subjects the teacher currently has an active assignment
 * for are considered (security requirement from the issue: never mix in
 * foreign classes/subjects). Subject mapping is exact-match against
 * subjects.code, or – once the teacher has made a decision on the review
 * page – via webuntis_subject_mappings (alias to an existing subject, or
 * "not a real lesson"). Anything still unresolved is skipped, reported,
 * and kept in webuntis_unmapped_events for that review page; nothing is
 * ever guessed.
 *
 * After the import, webuntis_prune_stale_lesson_sessions() removes
 * previously-imported rows that fell out of the schedule (see its own
 * docblock for the exact scope/protection rules).
 *
 * Returns a summary array: counts + short lists of unmapped subject/class
 * tokens, so the teacher can see what wasn't imported.
 */
function webuntis_import_for_teacher(PDO $pdo, array $teacher): array {
  $teacherId = (int)$teacher['id'];
  $encUrl = (string)($teacher['webuntis_ical_url_enc'] ?? '');
  $url = security_decrypt_secret($encUrl);
  if ($url === null || $url === '') {
    throw new RuntimeException('Es ist kein WebUntis-iCal-Link hinterlegt.');
  }

  $ics = webuntis_fetch_ical($url);
  $events = webuntis_parse_ical($ics);

  // The date span this particular feed actually reports on, from every
  // event with a parseable start date (regardless of status/mapping) -
  // used below to bound stale-row pruning to what this import can actually
  // speak to, so history outside the feed's window is never touched.
  $coverageMinDate = null;
  $coverageMaxDate = null;
  foreach ($events as $event) {
    if (!($event['dtstart'] instanceof DateTime)) continue;
    $d = $event['dtstart']->format('Y-m-d');
    if ($coverageMinDate === null || $d < $coverageMinDate) $coverageMinDate = $d;
    if ($coverageMaxDate === null || $d > $coverageMaxDate) $coverageMaxDate = $d;
  }

  // Teacher's own active class/subject combinations only.
  $st = $pdo->prepare("SELECT DISTINCT c.id AS class_id, c.name AS class_name, s.id AS subject_id, s.code AS subject_code
                       FROM teacher_assignments ta
                       JOIN classes c ON c.id=ta.class_id
                       JOIN subjects s ON s.id=ta.subject_id
                       WHERE ta.teacher_id=? AND ta.status='active' AND c.is_archived=0 AND c.is_departed=0");
  $st->execute([$teacherId]);
  $rows = $st->fetchAll();

  $classIdByName = [];
  $subjectIdByCode = [];
  $allowedCombo = [];
  foreach ($rows as $row) {
    $classIdByName[(string)$row['class_name']] = (int)$row['class_id'];
    $subjectIdByCode[webuntis_normalize_code((string)$row['subject_code'])] = (int)$row['subject_id'];
    $allowedCombo[(int)$row['class_id'].':'.(int)$row['subject_id']] = true;
  }

  // Per-teacher decisions made on the review/correction page for a WebUntis
  // code that doesn't match any subjects.code exactly.
  $codeMappings = [];
  $mst = $pdo->prepare("SELECT webuntis_code, action, subject_id FROM webuntis_subject_mappings WHERE teacher_id=?");
  $mst->execute([$teacherId]);
  foreach ($mst->fetchAll() as $mrow) {
    $codeMappings[webuntis_normalize_code((string)$mrow['webuntis_code'])] = [
      'action' => (string)$mrow['action'],
      'subject_id' => $mrow['subject_id'] !== null ? (int)$mrow['subject_id'] : null,
    ];
  }

  $summary = [
    'total_events' => count($events),
    'imported' => 0,
    'updated' => 0,
    'skipped_cancelled' => 0,
    'skipped_no_time' => 0,
    'skipped_unmapped_subject' => 0,
    'skipped_ignored_code' => 0,
    'skipped_unmapped_class' => 0,
    'skipped_not_assigned' => 0,
    'unmapped_subjects' => [],
    'unmapped_classes' => [],
    // class_id:subject_id pairs where at least one imported lesson was for
    // a single a/b subgroup only – used to offer a "Gruppen a/b anlegen"
    // shortcut for combos that don't have matching teacher_student_groups yet.
    'subgroup_combos' => [],
    'pruned_stale' => 0,
    'pruned_kept_entries' => 0,
    'pruned_kept_topic' => 0,
    'pruned_stale_unmapped' => 0,
  ];

  // ids of lesson_sessions rows still confirmed present by this import run
  // (imported, updated, or unchanged) - anything source='webuntis' within
  // the feed's date range that is NOT in here has fallen out of the
  // schedule and is a pruning candidate below.
  $touchedIds = [];
  // ids of webuntis_unmapped_events rows this run re-confirmed (still
  // unmapped or ignored) - see webuntis_prune_stale_unmapped_events().
  $touchedUnmappedIds = [];

  foreach ($events as $event) {
    if ($event['status'] !== '' && $event['status'] !== 'CONFIRMED') {
      $summary['skipped_cancelled']++;
      continue;
    }
    if (!($event['dtstart'] instanceof DateTime) || !($event['dtend'] instanceof DateTime)) {
      $summary['skipped_no_time']++;
      continue;
    }

    $summaryCode = webuntis_normalize_code($event['summary']);
    $uidForCleanup = $event['uid'] !== '' ? mb_substr($event['uid'], 0, 128) : null;
    $subjectId = $subjectIdByCode[$summaryCode] ?? null;
    $mapping = $codeMappings[$summaryCode] ?? null;
    if ($subjectId === null && $mapping !== null && $mapping['action'] === 'subject' && $mapping['subject_id']) {
      $subjectId = $mapping['subject_id'];
    }

    if ($subjectId === null) {
      if ($mapping !== null && $mapping['action'] === 'ignore') {
        $summary['skipped_ignored_code']++;
        $touchedUnmappedIds[] = webuntis_store_unmapped_event($pdo, $teacherId, $event, 'ignored');
      } else {
        $summary['skipped_unmapped_subject']++;
        if ($summaryCode !== '' && !in_array($summaryCode, $summary['unmapped_subjects'], true)) {
          $summary['unmapped_subjects'][] = $summaryCode;
        }
        $touchedUnmappedIds[] = webuntis_store_unmapped_event($pdo, $teacherId, $event, 'unmapped_subject');
      }
      continue;
    }
    // Subject is now recognized (directly or via mapping) – this event is
    // no longer "unmapped" even if a class-level issue below still skips it.
    webuntis_clear_unmapped_event($pdo, $teacherId, $uidForCleanup);

    $classTokens = webuntis_class_tokens_from_description($event['description']);
    // Group raw tokens by the COOL-Grades class they map to: two tokens
    // ("3FSBa","3FSBb") landing on the same class mean a joint lesson for
    // the whole class; exactly one token whose a/b suffix got stripped
    // means the lesson is for that single subgroup only.
    $tokensByClassId = [];
    $anyClassToken = false;
    foreach ($classTokens as $token) {
      $anyClassToken = true;
      $mapped = webuntis_map_class_token($token);
      if (isset($classIdByName[$mapped])) {
        $tokensByClassId[$classIdByName[$mapped]][] = $token;
      } else {
        if (!in_array($token, $summary['unmapped_classes'], true)) {
          $summary['unmapped_classes'][] = $token;
        }
      }
    }
    if (!$anyClassToken || !$tokensByClassId) {
      $summary['skipped_unmapped_class']++;
      continue;
    }

    $lessonDate = $event['dtstart']->format('Y-m-d');
    $startTime = $event['dtstart']->format('H:i:s');
    $endTime = $event['dtend']->format('H:i:s');
    $room = $event['location'] !== '' ? mb_substr($event['location'], 0, 64) : null;
    $uid = $event['uid'] !== '' ? mb_substr($event['uid'], 0, 128) : null;

    foreach ($tokensByClassId as $classId => $tokens) {
      if (!isset($allowedCombo[$classId.':'.$subjectId])) {
        $summary['skipped_not_assigned']++;
        continue;
      }

      $subgroup = null;
      if (count($tokens) === 1) {
        $onlyToken = $tokens[0];
        $lastChar = strtolower(substr($onlyToken, -1));
        if (($lastChar === 'a' || $lastChar === 'b') && webuntis_map_class_token($onlyToken) !== $onlyToken) {
          $subgroup = $lastChar;
          $comboKey = $classId.':'.$subjectId;
          if (!in_array($comboKey, $summary['subgroup_combos'], true)) {
            $summary['subgroup_combos'][] = $comboKey;
          }
        }
      }

      $result = webuntis_upsert_lesson_session($pdo, $teacherId, $classId, $subjectId, $lessonDate, $startTime, $endTime, $room, $uid, $subgroup);
      if ($result['id'] > 0) $touchedIds[] = $result['id'];
      if ($result['status'] === 'imported') $summary['imported']++;
      elseif ($result['status'] === 'updated') $summary['updated']++;
    }
  }

  $pruneResult = webuntis_prune_stale_lesson_sessions($pdo, $allowedCombo, $coverageMinDate, $coverageMaxDate, $touchedIds);
  $summary['pruned_stale'] = $pruneResult['deleted'];
  $summary['pruned_kept_entries'] = $pruneResult['kept_entries'];
  $summary['pruned_kept_topic'] = $pruneResult['kept_topic'];
  $summary['pruned_stale_unmapped'] = webuntis_prune_stale_unmapped_events($pdo, $teacherId, $coverageMinDate, $coverageMaxDate, $touchedUnmappedIds);

  $st = $pdo->prepare("UPDATE users SET webuntis_ical_last_import_at=?, webuntis_ical_last_import_summary=? WHERE id=?");
  $st->execute([now_iso(), json_encode($summary, JSON_UNESCAPED_UNICODE), $teacherId]);

  app_log('info', 'webuntis import completed', ['teacher_id' => $teacherId] + array_diff_key($summary, ['unmapped_subjects' => 1, 'unmapped_classes' => 1, 'subgroup_combos' => 1]));

  return $summary;
}

/**
 * Given an import summary's 'subgroup_combos' (class_id:subject_id pairs
 * where a single-subgroup lesson was seen), returns the ones for which
 * this teacher does not yet have a "a"/"b" named teacher_student_groups
 * group – i.e. where the "Gruppen a/b anlegen" shortcut would still help.
 * Each entry: ['class_id'=>int,'subject_id'=>int,'class_name'=>string,'subject_code'=>string].
 */
function webuntis_missing_subgroup_combos(PDO $pdo, int $teacherId, array $summary): array {
  $combos = $summary['subgroup_combos'] ?? [];
  if (!$combos) return [];

  $missing = [];
  $checkStmt = $pdo->prepare("SELECT LOWER(name) AS name FROM teacher_student_groups WHERE teacher_id=? AND class_id=? AND subject_id=?");
  $infoStmt = $pdo->prepare("SELECT c.name AS class_name, s.code AS subject_code FROM classes c, subjects s WHERE c.id=? AND s.id=?");
  foreach ($combos as $comboKey) {
    [$classId, $subjectId] = array_map('intval', explode(':', $comboKey, 2));
    if ($classId <= 0 || $subjectId <= 0) continue;
    $checkStmt->execute([$teacherId, $classId, $subjectId]);
    $existingNames = array_map('strval', array_column($checkStmt->fetchAll(), 'name'));
    if (in_array('a', $existingNames, true) || in_array('b', $existingNames, true)) continue;

    $infoStmt->execute([$classId, $subjectId]);
    $info = $infoStmt->fetch();
    if (!$info) continue;
    $missing[] = [
      'class_id' => $classId,
      'subject_id' => $subjectId,
      'class_name' => (string)$info['class_name'],
      'subject_code' => (string)$info['subject_code'],
    ];
  }
  return $missing;
}

/**
 * Creates empty "a"/"b" teacher_student_groups shells for a class/subject
 * combo (idempotent). Membership itself is never guessed here – WebUntis
 * has no student-level data – the teacher still fills that in by hand via
 * the existing group management page.
 */
function webuntis_create_ab_groups(PDO $pdo, int $teacherId, int $classId, int $subjectId): void {
  $st = $pdo->prepare("INSERT IGNORE INTO teacher_student_groups (teacher_id,class_id,subject_id,name,created_at,updated_at) VALUES (?,?,?,?,?,?)");
  $now = now_iso();
  foreach (['a', 'b'] as $name) {
    $st->execute([$teacherId, $classId, $subjectId, $name, $now, $now]);
  }
}

/**
 * Distinct unmapped-subject WebUntis codes for the review/correction page,
 * with occurrence count and date range, newest-first by frequency.
 */
function webuntis_unmapped_subject_codes(PDO $pdo, int $teacherId): array {
  $st = $pdo->prepare("SELECT webuntis_code, COUNT(*) AS cnt, MIN(lesson_date) AS date_min, MAX(lesson_date) AS date_max
                       FROM webuntis_unmapped_events
                       WHERE teacher_id=? AND status='unmapped_subject'
                       GROUP BY webuntis_code
                       ORDER BY cnt DESC, webuntis_code ASC");
  $st->execute([$teacherId]);
  return $st->fetchAll();
}

/** A few example occurrences of one unmapped code, for the review page. */
function webuntis_unmapped_event_examples(PDO $pdo, int $teacherId, string $code, int $limit = 4): array {
  $st = $pdo->prepare("SELECT lesson_date, start_time, end_time, webuntis_description
                       FROM webuntis_unmapped_events
                       WHERE teacher_id=? AND status='unmapped_subject' AND webuntis_code=?
                       ORDER BY lesson_date ASC
                       LIMIT ".max(1, (int)$limit));
  $st->execute([$teacherId, $code]);
  return $st->fetchAll();
}

/**
 * Codes the teacher has already decided on (mapped to a subject, or marked
 * as not a real lesson), for the "already handled" list with an undo option.
 */
function webuntis_subject_mappings_for_teacher(PDO $pdo, int $teacherId): array {
  $st = $pdo->prepare("SELECT m.id, m.webuntis_code, m.action, m.subject_id, m.note, s.code AS subject_code, s.name AS subject_name,
                       (SELECT COUNT(*) FROM webuntis_unmapped_events e WHERE e.teacher_id=m.teacher_id AND e.webuntis_code=m.webuntis_code AND e.status='ignored') AS ignored_count
                       FROM webuntis_subject_mappings m
                       LEFT JOIN subjects s ON s.id=m.subject_id
                       WHERE m.teacher_id=?
                       ORDER BY m.webuntis_code ASC");
  $st->execute([$teacherId]);
  return $st->fetchAll();
}

/** Saves (or replaces) the teacher's mapping decision for one WebUntis code. */
function webuntis_save_subject_mapping(PDO $pdo, int $teacherId, string $code, string $action, ?int $subjectId, ?string $note): void {
  $code = webuntis_normalize_code($code);
  if (!in_array($action, ['subject', 'ignore'], true)) throw new InvalidArgumentException('Ungültige Aktion.');
  // Ein leeres Kürzel (z. B. ein Termin ohne Titel im WebUntis-Feed) darf
  // als "keine Unterrichtsstunde" markiert werden - dafür ist diese Funktion
  // ja da. Einem leeren Kürzel dagegen ein Fach zuzuordnen ergibt keinen
  // Sinn (es gäbe kein Unterscheidungsmerkmal zu jedem anderen titellosen
  // Termin), das bleibt weiterhin gesperrt.
  if ($code === '' && $action === 'subject') throw new InvalidArgumentException('Ein Termin ohne Titel/Kürzel im Feed kann nicht einem Fach zugeordnet werden - bitte stattdessen als „keine Unterrichtsstunde" markieren.');
  if ($action === 'subject' && !$subjectId) throw new InvalidArgumentException('Bitte ein Fach auswählen.');
  $note = $note !== null ? trim($note) : null;
  if ($note === '') $note = null;

  $now = now_iso();
  $st = $pdo->prepare("INSERT INTO webuntis_subject_mappings (teacher_id,webuntis_code,action,subject_id,note,created_at,updated_at)
                       VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE action=VALUES(action), subject_id=VALUES(subject_id), note=VALUES(note), updated_at=VALUES(updated_at)");
  $st->execute([$teacherId, $code, $action, $action === 'subject' ? $subjectId : null, $note, $now, $now]);
}

/** Removes a mapping decision and reverts any already-ignored events for it back to "unmapped". */
function webuntis_delete_subject_mapping(PDO $pdo, int $teacherId, string $code): void {
  $code = webuntis_normalize_code($code);
  $pdo->prepare("DELETE FROM webuntis_subject_mappings WHERE teacher_id=? AND webuntis_code=?")->execute([$teacherId, $code]);
  $pdo->prepare("UPDATE webuntis_unmapped_events SET status='unmapped_subject' WHERE teacher_id=? AND webuntis_code=? AND status='ignored'")->execute([$teacherId, $code]);
}
