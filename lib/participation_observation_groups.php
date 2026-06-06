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
  if (preg_match('/arbeits|genau|sorg|methode|strategie|operier|rechen|vollstaendig|termingerecht|dokument/', $t)) return 'work';
  if (preg_match('/kooper|gruppe|partner|team|selbststaendig|eigenstaendig|beitrag|respekt/', $t)) return 'cooperation';
  return 'other';
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

  return array_slice(array_values(array_unique(array_map(static fn(array $r): int => (int)$r['id'], $ranked))), 0, max(1, $limit));
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
