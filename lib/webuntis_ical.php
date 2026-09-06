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

require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/logger.php';

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
 * dedupe/refresh identically. Returns 'imported'|'updated'|'unchanged'.
 */
function webuntis_upsert_lesson_session(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $lessonDate, string $startTime, string $endTime, ?string $room, ?string $uid, ?string $subgroup): string {
  $findStmt = $pdo->prepare("SELECT id, source FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date=? AND start_time <=> ? LIMIT 1");
  $findStmt->execute([$classId, $subjectId, $lessonDate, $startTime]);
  $existing = $findStmt->fetch();

  if ($existing) {
    if ((string)$existing['source'] !== 'webuntis') {
      $pdo->prepare("UPDATE lesson_sessions SET source='webuntis', external_uid=?, room=COALESCE(room,?), webuntis_subgroup=? WHERE id=?")
          ->execute([$uid, $room, $subgroup, (int)$existing['id']]);
      return 'updated';
    }
    $pdo->prepare("UPDATE lesson_sessions SET external_uid=?, room=COALESCE(room,?), webuntis_subgroup=? WHERE id=? AND source='webuntis'")
        ->execute([$uid, $room, $subgroup, (int)$existing['id']]);
    return 'unchanged';
  }

  try {
    $pdo->prepare("INSERT INTO lesson_sessions
                   (teacher_id,class_id,subject_id,lesson_date,start_time,end_time,room,source,external_uid,webuntis_subgroup,created_at)
                   VALUES (?,?,?,?,?,?,?,'webuntis',?,?,?)")
        ->execute([$teacherId, $classId, $subjectId, $lessonDate, $startTime, $endTime, $room, $uid, $subgroup, now_iso()]);
    return 'imported';
  } catch (PDOException $e) {
    // Concurrent import/manual entry created the same slot in the meantime; treat as already present.
    app_log('info', 'webuntis import: duplicate slot on insert', ['class_id' => $classId, 'subject_id' => $subjectId, 'lesson_date' => $lessonDate]);
    return 'unchanged';
  }
}

/**
 * Persists (or refreshes) a raw event that did NOT become a lesson_sessions
 * row because its subject code is unrecognized or explicitly ignored – the
 * data behind the review/correction page. Upserts by (teacher_id,
 * external_uid) so repeated imports don't pile up duplicate rows and so a
 * status flips cleanly once the teacher makes a mapping decision.
 */
function webuntis_store_unmapped_event(PDO $pdo, int $teacherId, array $event, string $status): void {
  if (!($event['dtstart'] instanceof DateTime)) return;
  $uid = $event['uid'] !== '' ? mb_substr($event['uid'], 0, 128) : null;
  $lessonDate = $event['dtstart']->format('Y-m-d');
  $startTime = $event['dtstart']->format('H:i:s');
  $endTime = ($event['dtend'] instanceof DateTime) ? $event['dtend']->format('H:i:s') : null;
  $room = $event['location'] !== '' ? mb_substr($event['location'], 0, 64) : null;
  $desc = $event['description'] !== '' ? mb_substr($event['description'], 0, 255) : null;
  $code = mb_substr(trim($event['summary']), 0, 32);
  $now = now_iso();

  if ($uid !== null) {
    $st = $pdo->prepare("SELECT id FROM webuntis_unmapped_events WHERE teacher_id=? AND external_uid=? LIMIT 1");
    $st->execute([$teacherId, $uid]);
    $existingId = $st->fetchColumn();
    if ($existingId) {
      $pdo->prepare("UPDATE webuntis_unmapped_events SET webuntis_code=?, webuntis_description=?, lesson_date=?, start_time=?, end_time=?, room=?, status=?, updated_at=? WHERE id=?")
          ->execute([$code, $desc, $lessonDate, $startTime, $endTime, $room, $status, $now, (int)$existingId]);
      return;
    }
  }

  $pdo->prepare("INSERT INTO webuntis_unmapped_events
                 (teacher_id,external_uid,webuntis_code,webuntis_description,lesson_date,start_time,end_time,room,status,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$teacherId, $uid, $code, $desc, $lessonDate, $startTime, $endTime, $room, $status, $now, $now]);
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
    $subjectIdByCode[(string)$row['subject_code']] = (int)$row['subject_id'];
    $allowedCombo[(int)$row['class_id'].':'.(int)$row['subject_id']] = true;
  }

  // Per-teacher decisions made on the review/correction page for a WebUntis
  // code that doesn't match any subjects.code exactly.
  $codeMappings = [];
  $mst = $pdo->prepare("SELECT webuntis_code, action, subject_id FROM webuntis_subject_mappings WHERE teacher_id=?");
  $mst->execute([$teacherId]);
  foreach ($mst->fetchAll() as $mrow) {
    $codeMappings[(string)$mrow['webuntis_code']] = [
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
  ];

  foreach ($events as $event) {
    if ($event['status'] !== '' && $event['status'] !== 'CONFIRMED') {
      $summary['skipped_cancelled']++;
      continue;
    }
    if (!($event['dtstart'] instanceof DateTime) || !($event['dtend'] instanceof DateTime)) {
      $summary['skipped_no_time']++;
      continue;
    }

    $summaryCode = trim($event['summary']);
    $uidForCleanup = $event['uid'] !== '' ? mb_substr($event['uid'], 0, 128) : null;
    $subjectId = $subjectIdByCode[$summaryCode] ?? null;
    $mapping = $codeMappings[$summaryCode] ?? null;
    if ($subjectId === null && $mapping !== null && $mapping['action'] === 'subject' && $mapping['subject_id']) {
      $subjectId = $mapping['subject_id'];
    }

    if ($subjectId === null) {
      if ($mapping !== null && $mapping['action'] === 'ignore') {
        $summary['skipped_ignored_code']++;
        webuntis_store_unmapped_event($pdo, $teacherId, $event, 'ignored');
      } else {
        $summary['skipped_unmapped_subject']++;
        if ($summaryCode !== '' && !in_array($summaryCode, $summary['unmapped_subjects'], true)) {
          $summary['unmapped_subjects'][] = $summaryCode;
        }
        webuntis_store_unmapped_event($pdo, $teacherId, $event, 'unmapped_subject');
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
      if ($result === 'imported') $summary['imported']++;
      elseif ($result === 'updated') $summary['updated']++;
    }
  }

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
  $code = trim($code);
  if ($code === '') throw new InvalidArgumentException('Kein Fachkürzel angegeben.');
  if (!in_array($action, ['subject', 'ignore'], true)) throw new InvalidArgumentException('Ungültige Aktion.');
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
  $pdo->prepare("DELETE FROM webuntis_subject_mappings WHERE teacher_id=? AND webuntis_code=?")->execute([$teacherId, $code]);
  $pdo->prepare("UPDATE webuntis_unmapped_events SET status='unmapped_subject' WHERE teacher_id=? AND webuntis_code=? AND status='ignored'")->execute([$teacherId, $code]);
}
