<?php
require_once __DIR__.'/helpers.php';

function participation_normalize_text(string $text): string {
  $text = trim($text);
  if ($text === '') return '';
  if (function_exists('mb_strtolower')) $text = mb_strtolower($text, 'UTF-8');
  else $text = strtolower($text);
  $map = [
    'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
    'á' => 'a', 'à' => 'a', 'â' => 'a',
    'é' => 'e', 'è' => 'e', 'ê' => 'e',
    'í' => 'i', 'ì' => 'i', 'î' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u',
  ];
  $text = strtr($text, $map);
  return preg_replace('/\s+/', ' ', $text) ?? $text;
}

function participation_observation_group_semantic_key(string $label): string {
  $t = participation_normalize_text($label);
  if ($t === '') return 'other';

  if (preg_match('/versteh|erfass|begriff|nachvollzieh|analyse|analysier|zusammenhang/', $t)) return 'understanding';
  if (preg_match('/anwend|transfer|einord|modell|praxis|fall|loesung|aufgabe/', $t)) return 'application';
  if (preg_match('/argument|erklaer|erklaer|begruend|kommunik|praesent|fachbegrif|darstell/', $t)) return 'argumentation';
  if (preg_match('/gestalt|kreativ|erfind|entwickl|konzipier|entwerf|eigene loesung/', $t)) return 'creation';
  if (preg_match('/arbeits|genau|sorg|methode|strategie|operier|rechen|vollstaendig|termingerecht|dokument/', $t)) return 'work';
  if (preg_match('/kooper|gruppe|partner|team|selbststaendig|eigenstaendig|beitrag|respekt/', $t)) return 'cooperation';
  return 'other';
}

// Beobachtungsbereich: Achse 1 = kognitiver Fokus (Bloom-nah: Verstehen,
// Anwenden/Transfer, Argumentieren/Erklären, Gestalten/Eigene Lösung) -
// Pflichtfeld, genau einer.
// Achse 2 (Arbeits-/Sozialform: Arbeitsweise/Genauigkeit, Kooperation/
// Selbstständigkeit) wurde zum 2026-10-01 wieder entfernt (siehe
// migrations/2026-10-01_retire_observation_axis2.sql) - konzeptionell
// abgelöst durch die eigenständige "Kompetenz-Beobachtung"
// (lib/competence_observations.php). Die Achse-2-Funktionen/Konstanten
// bleiben hier bestehen, weil bereits erfasste Einträge mit einer
// Achse-2-Zuordnung weiterhin über den Legacy-Modus in
// teacher/participation_edit.php korrekt erkannt und unverändert
// angezeigt werden müssen - es werden nur keine neuen Achse-2-Optionen
// mehr angeboten (sie sind in der Datenbank archiviert).
// Die Achse wird primär aus der Datenbankspalte observation_axis gelesen
// (siehe migrations/2026-09-29_observation_group_axes.sql). Der Fallback über
// den semantischen Schlüssel greift nur, falls dieses Feld für eine Option
// ausnahmsweise nicht gesetzt ist.
const PARTICIPATION_OBSERVATION_GROUP_AXIS2_KEYS = ['work', 'cooperation'];

function participation_observation_group_axis(array $group): int {
  $axis = (int)($group['observation_axis'] ?? 0);
  if ($axis === 1 || $axis === 2) return $axis;
  $key = participation_observation_group_semantic_key((string)($group['label'] ?? ''));
  return in_array($key, PARTICIPATION_OBSERVATION_GROUP_AXIS2_KEYS, true) ? 2 : 1;
}

function participation_observation_group_split_by_axis(array $groups): array {
  $out = [1 => [], 2 => []];
  foreach ($groups as $group) {
    $axis = participation_observation_group_axis($group);
    $out[$axis][] = $group;
  }
  return $out;
}

function participation_observation_group_axis_counts(array $groups, array $selected_ids): array {
  $byId = [];
  foreach ($groups as $g) { $byId[(int)($g['id'] ?? 0)] = $g; }
  $counts = [1 => 0, 2 => 0];
  foreach ($selected_ids as $sid) {
    $sid = (int)$sid;
    if ($sid <= 0 || !isset($byId[$sid])) continue;
    $counts[participation_observation_group_axis($byId[$sid])]++;
  }
  return $counts;
}

// Entspricht die Auswahl dem aktuellen Modell: genau ein Eintrag aus Achse 1
// (kognitiver Fokus), kein zusätzlicher aus der entfernten Achse 2. Eine
// leere Auswahl gilt hier NICHT als konform (das Pflichtfeld Achse 1 fehlt
// dann) - die "mindestens einen wählen"-Prüfung bleibt ein eigener,
// vorgelagerter Schritt. Ein Eintrag mit einer historischen Achse-2-
// Zuordnung (count[2]>0) gilt bewusst als nicht konform, damit er über den
// bestehenden Legacy-Modus in teacher/participation_edit.php unverändert
// angezeigt wird, statt die Achse-2-Zuordnung beim nächsten Speichern
// stillschweigend zu verlieren.
function participation_observation_group_selection_conforms(array $groups, array $selected_ids): bool {
  $counts = participation_observation_group_axis_counts($groups, $selected_ids);
  return $counts[1] === 1 && $counts[2] === 0;
}

function participation_observation_group_reason_scores(string $reason_label): array {
  $t = participation_normalize_text($reason_label);
  $scores = [];
  if ($t === '') return $scores;

  if (preg_match('/haus|sicherung/', $t)) {
    $scores['understanding'] = ($scores['understanding'] ?? 0) + 3;
    $scores['application'] = ($scores['application'] ?? 0) + 2;
    $scores['work'] = ($scores['work'] ?? 0) + 2;
  }
  if (preg_match('/gruppe|projekt/', $t)) {
    $scores['cooperation'] = ($scores['cooperation'] ?? 0) + 3;
    $scores['application'] = ($scores['application'] ?? 0) + 2;
  }
  if (preg_match('/praesent|referat/', $t)) {
    $scores['argumentation'] = ($scores['argumentation'] ?? 0) + 3;
    $scores['understanding'] = ($scores['understanding'] ?? 0) + 2;
  }
  if (preg_match('/muendlich/', $t)) {
    $scores['understanding'] = ($scores['understanding'] ?? 0) + 2;
    $scores['argumentation'] = ($scores['argumentation'] ?? 0) + 2;
  }
  if (preg_match('/arbeitsauftrag/', $t)) {
    $scores['application'] = ($scores['application'] ?? 0) + 2;
    $scores['work'] = ($scores['work'] ?? 0) + 2;
  }
  if (!$scores) {
    $scores['understanding'] = 1;
    $scores['application'] = 1;
  }
  return $scores;
}

function participation_observation_group_text_scores(string $text): array {
  $key = participation_observation_group_semantic_key($text);
  if ($key === 'other') return [];
  return [$key => 1];
}

function participation_observation_group_ids_from_scores(array $groups, array $scores, int $limit = 2): array {
  if (!$groups) return [];
  if (!$scores) return [];

  $ranked = [];
  foreach ($groups as $group) {
    $id = (int)($group['id'] ?? 0);
    if ($id <= 0) continue;
    $semantic = participation_observation_group_semantic_key((string)($group['label'] ?? ''));
    $score = (int)($scores[$semantic] ?? 0);
    if ($score <= 0) continue;
    $ranked[] = [
      'id' => $id,
      'axis' => participation_observation_group_axis($group),
      'score' => $score,
      'sort' => (int)($group['sort'] ?? 0),
      'label' => (string)($group['label'] ?? ''),
    ];
  }

  usort($ranked, static function(array $a, array $b): int {
    if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
    if ($a['sort'] !== $b['sort']) return $a['sort'] <=> $b['sort'];
    return strnatcasecmp($a['label'], $b['label']);
  });

  // Höchstens ein Vorschlag pro Achse (Achse 1 = kognitiver Fokus zuerst,
  // Achse 2 = Arbeits-/Sozialform optional), damit der Vorschlag immer zum
  // Zwei-Achsen-Modell passt statt blind die zwei besten Treffer insgesamt zu nehmen.
  $bestByAxis = [1 => null, 2 => null];
  foreach ($ranked as $r) {
    if ($bestByAxis[$r['axis']] === null) $bestByAxis[$r['axis']] = $r['id'];
  }

  $result = [];
  if ($bestByAxis[1] !== null) $result[] = $bestByAxis[1];
  if ($limit > 1 && $bestByAxis[2] !== null) $result[] = $bestByAxis[2];
  return array_slice($result, 0, max(1, $limit));
}

function participation_observation_group_ids_from_reason_and_criteria(array $groups, string $reason_label, array $criteria_rows, array $selected_criteria_ids, int $limit = 2): array {
  $scores = participation_observation_group_reason_scores($reason_label);

  if ($selected_criteria_ids) {
    $criteria_by_id = [];
    foreach ($criteria_rows as $row) {
      $criteria_by_id[(int)($row['id'] ?? 0)] = $row;
    }
    foreach ($selected_criteria_ids as $cid) {
      $cid = (int)$cid;
      if ($cid <= 0 || !isset($criteria_by_id[$cid])) continue;
      $row = $criteria_by_id[$cid];
      $parts = [];
      if (trim((string)($row['category'] ?? '')) !== '') $parts[] = (string)$row['category'];
      if (trim((string)($row['label'] ?? '')) !== '') $parts[] = (string)$row['label'];
      $text = implode(' ', $parts);
      foreach (participation_observation_group_text_scores($text) as $key => $value) {
        $scores[$key] = ($scores[$key] ?? 0) + $value + 2;
      }
    }
  }

  return participation_observation_group_ids_from_scores($groups, $scores, $limit);
}

function participation_observation_group_ids_from_payload(array $groups, array $criteria_rows, array $payload, string $reason_label = ''): array {
  $group_ids = array_values(array_filter(array_map('intval', (array)($payload['group_option_ids'] ?? [])), static fn(int $v): bool => $v > 0));
  if ($group_ids) return array_slice(array_values(array_unique($group_ids)), 0, 2);
  $criteria_ids = array_values(array_filter(array_map('intval', (array)($payload['criteria_ids'] ?? [])), static fn(int $v): bool => $v > 0));
  return participation_observation_group_ids_from_reason_and_criteria($groups, $reason_label, $criteria_rows, $criteria_ids, 2);
}

function participation_event_option_ids_by_type(PDO $pdo, int $event_id, string $type): array {
  $st = $pdo->prepare("SELECT peo.option_id
                       FROM participation_event_options peo
                       JOIN participation_options po ON po.id=peo.option_id
                       WHERE peo.event_id=? AND po.opt_type=?
                       ORDER BY po.sort, po.label");
  $st->execute([$event_id, $type]);
  return array_values(array_map(static fn(array $r): int => (int)$r['option_id'], $st->fetchAll()));
}

function participation_option_labels_by_ids(array $options, array $selected_ids): array {
  if (!$selected_ids || !$options) return [];
  $selected_lookup = [];
  foreach ($selected_ids as $id) $selected_lookup[(int)$id] = true;
  $labels = [];
  foreach ($options as $option) {
    $id = (int)($option['id'] ?? 0);
    if ($id > 0 && isset($selected_lookup[$id])) $labels[] = (string)($option['label'] ?? '');
  }
  return $labels;
}
