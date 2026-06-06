<?php

require_once __DIR__.'/assessment_summaries.php';

function oral_assessment_types(): array {
  return [
    'ORAL_EXAM' => 'mündliche Prüfung',
    'ORAL_EXERCISE' => 'mündliche Übung',
  ];
}

function oral_assessment_normalize_type(string $type): string {
  $type = strtoupper(trim($type));
  return array_key_exists($type, oral_assessment_types()) ? $type : 'ORAL_EXAM';
}

function oral_assessment_type_label(string $type): string {
  $type = oral_assessment_normalize_type($type);
  $types = oral_assessment_types();
  return $types[$type];
}

function oral_assessment_summary(string $type): string {
  $type = strtoupper(trim($type));
  if ($type === 'ORAL_EXAM') return lbv_hint_card_html('oral_exam');
  if ($type === 'ORAL_EXERCISE') return lbv_hint_card_html('oral_exercise');
  return lbv_hint_group_html(['oral_exam', 'oral_exercise']);
}

function oral_assessment_detail(array $row): string {
  $type = oral_assessment_normalize_type((string)($row['assessment_type'] ?? ''));
  if ($type === 'ORAL_EXAM') {
    return trim((string)($row['topic_area'] ?? '')) ?: '—';
  }
  $category = trim((string)($row['category'] ?? ''));
  $title = trim((string)($row['title'] ?? ''));
  if ($category !== '' && $title !== '') return $category.' · '.$title;
  if ($category !== '') return $category;
  if ($title !== '') return $title;
  return '—';
}

function oral_assessment_summary_ref(string $type): string {
  $type = strtoupper(trim($type));
  if ($type === 'ALL') return '§ 5 und § 6 LBV';
  $type = oral_assessment_normalize_type($type);
  return $type === 'ORAL_EXAM' ? lbv_hint_ref('oral_exam') : lbv_hint_ref('oral_exercise');
}

function oral_assessment_summary_tooltip(string $type): string {
  $type = strtoupper(trim($type));
  if ($type === 'ALL') {
    return lbv_hint_title('oral_exam') . ': ' . lbv_hint_short_hint('oral_exam') . ' | '
      . lbv_hint_title('oral_exercise') . ': ' . lbv_hint_short_hint('oral_exercise');
  }
  $type = oral_assessment_normalize_type($type);
  return $type === 'ORAL_EXAM' ? lbv_hint_short_hint('oral_exam') : lbv_hint_short_hint('oral_exercise');
}
