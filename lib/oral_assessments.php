<?php

require_once __DIR__.'/helpers.php';
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

function oral_assessment_type_short_label(string $type): string {
  $type = oral_assessment_normalize_type($type);
  $map = [
    'ORAL_EXAM' => 'mdl.Pr.',
    'ORAL_EXERCISE' => 'mdl.Üb.',
  ];
  return $map[$type] ?? $type;
}

/**
 * A besondere mündliche Leistungsfeststellung (§ 5/6 LBV) is, like a
 * Schularbeit or Test, a discrete graded assessment: it must be beurteilt
 * with a real Note (1-5), not the Eindruck/Relevanz impression scale used
 * for ongoing Mitarbeit. Entry masks stop collecting impact_option_id, but
 * older rows saved before this change only have an impact_label and no
 * grade - those stay visible for context but no longer contribute to any
 * Notendurchschnitt. This renders one row's grade/tendency symbol, or a
 * clearly marked legacy fallback for such a row.
 */
function oral_assessment_grade_symbol(int $grade, ?string $tendency = ''): string {
  $suffix = '';
  $tendency = normalize_exam_grade_tendency((string)$tendency);
  if($tendency === 'plus') $suffix = '+';
  elseif($tendency === 'minus') $suffix = '-';
  return (string)$grade.$suffix;
}

function oral_assessment_grade_display(array $row): string {
  $grade = (int)($row['grade'] ?? 0);
  if($grade >= 1 && $grade <= 5){
    return oral_assessment_grade_symbol($grade, (string)($row['tendency'] ?? ''));
  }
  $impactLabel = trim((string)($row['impact_label'] ?? ''));
  if($impactLabel !== ''){
    return '– (alt: Eindruck „'.$impactLabel.'", zählt nicht mehr in die Notenberechnung)';
  }
  return '–';
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
