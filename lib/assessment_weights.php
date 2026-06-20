<?php

require_once __DIR__.'/report_evaluation.php';
require_once __DIR__.'/assessment_systems.php';

function assessment_weight_defaults(): array {
  return [
    'participation' => 60.0,
    'oral' => 20.0,
    'written' => 20.0,
    'first_semester' => 40.0,
    'current_year' => 60.0,
  ];
}

function assessment_weight_area_labels(): array {
  return [
    'participation' => 'Mitarbeit',
    'oral' => 'Bes. mündl. Leistungsfeststellung',
    'written' => 'Bes. schriftl. Leistungsfeststellung',
  ];
}

function assessment_weight_format(float $value): string {
  $rounded = round($value, 1);
  return number_format($rounded, abs($rounded - round($rounded)) < 0.001 ? 0 : 1, ',', '.').' %';
}

function assessment_weight_grade_format(float $value): string {
  return number_format($value, 2, ',', '.');
}

function assessment_weight_normalize(array $weights, array $keys): array {
  $positive = [];
  foreach($keys as $key){
    $positive[$key] = max(0.0, (float)($weights[$key] ?? 0));
  }
  $sum = array_sum($positive);
  if($sum <= 0 && $keys){
    $equal = 100.0 / count($keys);
    return array_fill_keys($keys, $equal);
  }
  $normalized = [];
  foreach($positive as $key => $value){
    $normalized[$key] = $sum > 0 ? ($value / $sum) * 100.0 : 0.0;
  }
  return $normalized;
}

function assessment_weight_settings_resolve(?array $row, ?string $assessmentSystem): array {
  $defaults = assessment_weight_defaults();
  $mapping = [
    'participation' => 'participation_weight',
    'oral' => 'special_oral_weight',
    'written' => 'special_written_weight',
    'first_semester' => 'first_semester_to_annual_weight',
    'current_year' => 'current_year_to_annual_weight',
  ];
  $configured = [];
  $fallbackKeys = [];
  foreach($mapping as $key => $column){
    if($row && array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '' && is_numeric($row[$column])){
      $configured[$key] = max(0.0, (float)$row[$column]);
    } else {
      $configured[$key] = $defaults[$key];
      $fallbackKeys[] = $key;
    }
  }

  $areaKeys = ['participation','oral','written'];
  $yearKeys = ['first_semester','current_year'];
  $areaSum = array_sum(array_intersect_key($configured, array_flip($areaKeys)));
  $yearSum = array_sum(array_intersect_key($configured, array_flip($yearKeys)));
  $warnings = [];
  if($fallbackKeys){
    $warnings[] = 'Für fehlende Gewichtungen wurden Standardwerte verwendet.';
  }
  if(abs($areaSum - 100.0) > 0.01){
    $warnings[] = 'Die gespeicherten Bereichsgewichtungen ergaben nicht 100 %. Für die Berechnung wurden sie intern normalisiert.';
  }
  if($assessmentSystem === 'yearly' && abs($yearSum - 100.0) > 0.01){
    $warnings[] = 'Die gespeicherte Jahresgewichtung ergab nicht 100 %. Für die Berechnung wurde sie intern normalisiert.';
  }

  return [
    'record' => $row,
    'source' => $row ? 'saved' : 'default',
    'configured' => $configured,
    'area_normalized' => assessment_weight_normalize($configured, $areaKeys),
    'year_normalized' => assessment_weight_normalize($configured, $yearKeys),
    'fallback_keys' => $fallbackKeys,
    'warnings' => array_values(array_unique($warnings)),
    'assessment_system' => $assessmentSystem,
  ];
}

function assessment_weight_settings_load(PDO $pdo, int $teacherId, int $classId, int $subjectId, int $schoolPeriodSetId, ?string $assessmentSystem): array {
  $row = null;
  try{
    $st = $pdo->prepare("SELECT * FROM assessment_weight_settings
                         WHERE teacher_id=? AND class_id=? AND subject_id=? AND school_period_set_id=?
                         LIMIT 1");
    $st->execute([$teacherId,$classId,$subjectId,$schoolPeriodSetId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }catch(Throwable $e){
    $row = null;
  }
  return assessment_weight_settings_resolve($row, $assessmentSystem);
}

function assessment_weight_settings_validate(array $input, bool $isYearly): array {
  $fields = [
    'participation_weight' => 'Gewichtung Mitarbeit',
    'special_oral_weight' => 'Gewichtung Bes. mündl. Leistungsfeststellung',
    'special_written_weight' => 'Gewichtung Bes. schriftl. Leistungsfeststellung',
  ];
  if($isYearly){
    $fields['first_semester_to_annual_weight'] = 'Gewichtung Schulnachricht / 1. Semester';
    $fields['current_year_to_annual_weight'] = 'Gewichtung restliches Schuljahr / aktueller Leistungsstand';
  }
  $values = [];
  $errors = [];
  foreach($fields as $field => $label){
    $raw = trim((string)($input[$field] ?? ''));
    if($raw === '' || !is_numeric(str_replace(',', '.', $raw))){
      $errors[$field] = $label.' muss als Prozentwert angegeben werden.';
      continue;
    }
    $value = (float)str_replace(',', '.', $raw);
    if($value < 0 || $value > 100){
      $errors[$field] = $label.' muss zwischen 0 und 100 liegen.';
      continue;
    }
    $values[$field] = $value;
  }
  if(!$errors){
    $areaSum = (float)$values['participation_weight'] + (float)$values['special_oral_weight'] + (float)$values['special_written_weight'];
    if(abs($areaSum - 100.0) > 0.01){
      $errors['area_sum'] = 'Die Gewichtungen der drei Leistungsbereiche müssen zusammen 100 % ergeben.';
    }
    if($isYearly){
      $yearSum = (float)$values['first_semester_to_annual_weight'] + (float)$values['current_year_to_annual_weight'];
      if(abs($yearSum - 100.0) > 0.01){
        $errors['year_sum'] = 'Die beiden Gewichtungen für den Jahresvorschlag müssen zusammen 100 % ergeben.';
      }
    }
  }
  return ['values'=>$values,'errors'=>$errors];
}

function assessment_weight_settings_store(
  PDO $pdo,
  int $teacherId,
  int $classId,
  int $subjectId,
  int $schoolPeriodSetId,
  string $assessmentModel,
  array $values
): void {
  $defaults = assessment_weight_defaults();
  $firstSemester = $assessmentModel === 'yearly'
    ? (float)($values['first_semester_to_annual_weight'] ?? $defaults['first_semester'])
    : null;
  $currentYear = $assessmentModel === 'yearly'
    ? (float)($values['current_year_to_annual_weight'] ?? $defaults['current_year'])
    : null;
  $st = $pdo->prepare("INSERT INTO assessment_weight_settings(
                        teacher_id,class_id,subject_id,school_period_set_id,assessment_model,
                        participation_weight,special_oral_weight,special_written_weight,
                        first_semester_to_annual_weight,current_year_to_annual_weight,created_at,updated_at
                      ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                        assessment_model=VALUES(assessment_model),
                        participation_weight=VALUES(participation_weight),
                        special_oral_weight=VALUES(special_oral_weight),
                        special_written_weight=VALUES(special_written_weight),
                        first_semester_to_annual_weight=VALUES(first_semester_to_annual_weight),
                        current_year_to_annual_weight=VALUES(current_year_to_annual_weight),
                        updated_at=VALUES(updated_at)");
  $now = now_iso();
  $st->execute([
    $teacherId,$classId,$subjectId,$schoolPeriodSetId,$assessmentModel,
    (float)$values['participation_weight'],(float)$values['special_oral_weight'],(float)$values['special_written_weight'],
    $firstSemester,$currentYear,$now,$now,
  ]);
}

function assessment_weight_value_from_qualitative_average(float $average): int {
  if($average >= 1.1) return 1;
  if($average >= 0.45) return 2;
  if($average > -0.15) return 3;
  if($average > -0.85) return 4;
  return 5;
}

/**
 * Produces comparable 1-5 proposal values without turning qualitative entries
 * into stored individual grades. Participation and oral use impression/relevance;
 * written assessments use their stored grades (equally weighted for now).
 */
function assessment_weight_area_values(array $summary): array {
  $participationProposal = $summary['note_proposal'] ?? [];
  $participationValue = isset($participationProposal['value']) && $participationProposal['value'] !== null
    ? (float)$participationProposal['value']
    : null;

  $oralScores = [];
  foreach((array)($summary['oral_rows'] ?? []) as $oralRow){
    $score = report_eval_rating_score((string)($oralRow['impact_label'] ?? ''));
    if($score !== null) $oralScores[] = $score;
  }
  $oralAverage = $oralScores ? array_sum($oralScores) / count($oralScores) : null;
  $oralValue = $oralAverage !== null ? (float)assessment_weight_value_from_qualitative_average($oralAverage) : null;

  $writtenCount = (int)($summary['written_count'] ?? 0);
  $writtenAverage = ($summary['written_avg'] ?? null) !== null ? (float)$summary['written_avg'] : null;
  $writtenValue = ($writtenCount > 0 && $writtenAverage !== null) ? $writtenAverage : null;

  return [
    'participation' => [
      'available' => $participationValue !== null,
      'value' => $participationValue,
      'count' => (int)($summary['participation_count'] ?? 0),
      'kind' => 'Eindruck/Relevanz',
      'basis' => $participationValue !== null
        ? 'qualitativ verdichteter Mitarbeitsnotenvorschlag '.assessment_weight_grade_format($participationValue)
        : 'keine ausreichend belastbare Mitarbeitstendenz',
    ],
    'oral' => [
      'available' => $oralValue !== null,
      'value' => $oralValue,
      'count' => count($oralScores),
      'kind' => 'Eindruck/Relevanz',
      'basis' => $oralValue !== null
        ? count($oralScores).' verwertbare Eindruckswerte, qualitativer Bereichswert '.assessment_weight_grade_format($oralValue)
        : 'keine verwertbaren Eindruckswerte',
      'qualitative_average' => $oralAverage,
    ],
    'written' => [
      'available' => $writtenValue !== null,
      'value' => $writtenValue,
      'count' => $writtenCount,
      'kind' => 'Noten',
      'basis' => $writtenValue !== null
        ? $writtenCount.' gleich gewichtete Note(n), Durchschnitt '.assessment_weight_grade_format($writtenValue)
        : 'keine verwertbaren Noten',
    ],
  ];
}

function assessment_weight_list_label(array $weights, array $keys, array $labels): string {
  $parts = [];
  foreach($keys as $key){
    $parts[] = ($labels[$key] ?? $key).' '.assessment_weight_format((float)($weights[$key] ?? 0));
  }
  return implode(' · ', $parts);
}

/** Missing areas are excluded and the configured weights are normalized to 100 %. */
function assessment_weight_compute_area_proposal(array $summary, array $settings): array {
  $areas = assessment_weight_area_values($summary);
  $labels = assessment_weight_area_labels();
  $availableKeys = [];
  $excludedLabels = [];
  foreach($areas as $key => $area){
    if(!empty($area['available'])) $availableKeys[] = $key;
    else $excludedLabels[] = $labels[$key];
  }
  $configured = (array)($settings['configured'] ?? assessment_weight_defaults());
  $warnings = (array)($settings['warnings'] ?? []);
  $configuredLabel = assessment_weight_list_label($configured, ['participation','oral','written'], $labels);

  if(!$availableKeys){
    $observedCount = (int)($summary['participation_count'] ?? 0)
      + count((array)($summary['oral_rows'] ?? []))
      + (int)($summary['written_count'] ?? 0);
    $hasObservedData = $observedCount > 0;
    return [
      'value' => null,
      'label' => $hasObservedData ? 'Datenlage prüfen' : 'Keine ausreichenden Daten vorhanden',
      'short' => $hasObservedData ? 'Daten prüfen' : 'keine Daten',
      'tone' => 'neutral',
      'explanation' => $hasObservedData
        ? 'Es sind Beobachtungen vorhanden, aber noch kein Leistungsbereich enthält einen ausreichend abgesicherten Bereichswert.'
        : 'Keiner der drei Leistungsbereiche enthält einen verwertbaren Bereichswert.',
      'calculated_value' => null,
      'areas' => $areas,
      'weighting' => [
        'configured_label' => $configuredLabel,
        'effective_label' => 'Keine Gewichtung angewendet',
        'effective' => [],
        'excluded' => $excludedLabels,
        'warnings' => $warnings,
      ],
      'signals' => $warnings,
    ];
  }

  $effective = assessment_weight_normalize($configured, $availableKeys);
  if(array_sum(array_map(static fn(string $key): float => max(0.0, (float)($configured[$key] ?? 0)), $availableKeys)) <= 0){
    $warnings[] = 'Die vorhandenen Bereiche hatten zusammen 0 % Gewicht. Für die Berechnung wurden sie gleich gewichtet.';
  }
  foreach($excludedLabels as $label){
    $warnings[] = $label.' wurde nicht berücksichtigt, da keine verwertbaren Einträge vorhanden sind. Die Gewichtung wurde automatisch angepasst.';
  }

  $weighted = 0.0;
  $areaParts = [];
  foreach($availableKeys as $key){
    $weighted += (float)$areas[$key]['value'] * ((float)$effective[$key] / 100.0);
    $areaParts[] = $labels[$key].' '.assessment_weight_grade_format((float)$areas[$key]['value']);
  }
  $effectiveLabel = assessment_weight_list_label($effective, $availableKeys, $labels);
  $rounded = max(1, min(5, (int)round($weighted, 0, PHP_ROUND_HALF_UP)));
  $writtenOnly = $availableKeys === ['written'];
  $tone = $rounded <= 2 ? 'positive' : ($rounded >= 4 ? 'critical' : 'neutral');
  if($writtenOnly){
    $warning = 'Es liegen derzeit nur besondere schriftliche Leistungsfeststellungen vor. Diese dürfen nicht alleinige Grundlage einer Semester- oder Jahresbeurteilung sein. Bitte berücksichtigen bzw. erfassen Sie auch Mitarbeit oder andere Leistungsfeststellungen.';
    $warnings[] = $warning;
    return [
      'value' => null,
      'intermediate_grade' => $rounded,
      'label' => 'Nicht ausreichend abgesicherter Zwischenwert '.assessment_weight_grade_format($weighted),
      'short' => 'Zwischenwert '.assessment_weight_grade_format($weighted),
      'tone' => 'critical',
      'explanation' => 'Rechnerischer Zwischenwert '.assessment_weight_grade_format($weighted).' aus ausschließlich schriftlichen Leistungsfeststellungen. '.$warning,
      'calculated_value' => $weighted,
      'areas' => $areas,
      'weighting' => [
        'configured_label' => $configuredLabel,
        'effective_label' => $effectiveLabel,
        'effective' => $effective,
        'excluded' => $excludedLabels,
        'warnings' => array_values(array_unique($warnings)),
      ],
      'signals' => array_values(array_unique($warnings)),
      'written_only' => true,
    ];
  }

  $explanation = 'Gewichteter Rechenwert '.assessment_weight_grade_format($weighted).' · Bereichswerte: '.implode(' · ', $areaParts).' · Berechnet aus vorhandenen Bereichen: '.$effectiveLabel.'.';
  return [
    'value' => $rounded,
    'label' => 'Notenvorschlag '.(string)$rounded,
    'short' => (string)$rounded,
    'tone' => $tone,
    'explanation' => $explanation,
    'calculated_value' => $weighted,
    'areas' => $areas,
    'weighting' => [
      'configured_label' => $configuredLabel,
      'effective_label' => $effectiveLabel,
      'effective' => $effective,
      'excluded' => $excludedLabels,
      'warnings' => array_values(array_unique($warnings)),
    ],
    'signals' => array_values(array_unique($warnings)),
    'written_only' => false,
  ];
}

function assessment_weight_compute_yearly_proposal(
  array $firstSemesterProposal,
  array $currentYearProposal,
  array $settings,
  ?array $savedFirstSemester = null
): array {
  $firstValue = null;
  $firstSource = '';
  if($savedFirstSemester && (string)($savedFirstSemester['status'] ?? '') === 'final' && $savedFirstSemester['final_grade'] !== null){
    $firstValue = (float)$savedFirstSemester['final_grade'];
    $firstSource = 'final gespeicherte Schulnachricht / 1. Semester';
  } elseif(($firstSemesterProposal['value'] ?? null) !== null){
    $firstValue = (float)$firstSemesterProposal['value'];
    $firstSource = 'berechneter Stand des 1. Semesters';
  }
  $currentValue = ($currentYearProposal['value'] ?? null) !== null ? (float)$currentYearProposal['value'] : null;
  $configured = (array)($settings['configured'] ?? assessment_weight_defaults());
  $available = [];
  if($firstValue !== null) $available[] = 'first_semester';
  if($currentValue !== null) $available[] = 'current_year';
  $warnings = array_merge(
    (array)($settings['warnings'] ?? []),
    (array)($firstSemesterProposal['weighting']['warnings'] ?? []),
    (array)($currentYearProposal['weighting']['warnings'] ?? [])
  );
  if(!$available){
    return [
      'value' => null,
      'label' => 'Datenlage für Jahresvorschlag prüfen',
      'short' => 'Jahresdaten prüfen',
      'tone' => 'neutral',
      'explanation' => 'Weder für das 1. Semester noch für den aktuellen Leistungsstand liegt ein ausreichend abgesicherter Teilwert vor.',
      'calculated_value' => null,
      'weighting' => $currentYearProposal['weighting'] ?? [],
      'year_weighting' => ['effective'=>[],'effective_label'=>'Keine Jahresgewichtung angewendet','warnings'=>array_values(array_unique($warnings))],
      'first_semester_proposal' => $firstSemesterProposal,
      'current_year_proposal' => $currentYearProposal,
      'signals' => array_values(array_unique($warnings)),
    ];
  }
  $effective = assessment_weight_normalize($configured, $available);
  if($firstValue === null) $warnings[] = 'Schulnachricht / 1. Semester konnte nicht berücksichtigt werden; die Jahresgewichtung wurde angepasst.';
  if($currentValue === null) $warnings[] = 'Der aktuelle Leistungsstand konnte nicht berücksichtigt werden; die Jahresgewichtung wurde angepasst.';
  $weighted = 0.0;
  if($firstValue !== null) $weighted += $firstValue * ((float)$effective['first_semester'] / 100.0);
  if($currentValue !== null) $weighted += $currentValue * ((float)$effective['current_year'] / 100.0);
  $rounded = max(1, min(5, (int)round($weighted, 0, PHP_ROUND_HALF_UP)));
  $tone = $rounded <= 2 ? 'positive' : ($rounded >= 4 ? 'critical' : 'neutral');
  $yearLabels = ['first_semester'=>'Schulnachricht / 1. Semester','current_year'=>'restliches Schuljahr / aktueller Leistungsstand'];
  $effectiveLabel = assessment_weight_list_label($effective, $available, $yearLabels);
  $parts = [];
  if($firstValue !== null) $parts[] = $yearLabels['first_semester'].' '.assessment_weight_grade_format($firstValue).' ('.$firstSource.')';
  if($currentValue !== null) $parts[] = $yearLabels['current_year'].' '.assessment_weight_grade_format($currentValue);
  return [
    'value' => $rounded,
    'label' => 'Notenvorschlag '.(string)$rounded,
    'short' => (string)$rounded,
    'tone' => $tone,
    'explanation' => 'Jahres-Rechenwert '.assessment_weight_grade_format($weighted).' · '.implode(' · ', $parts).' · Jahresgewichtung: '.$effectiveLabel.'.',
    'calculated_value' => $weighted,
    'weighting' => $currentYearProposal['weighting'] ?? [],
    'year_weighting' => [
      'effective' => $effective,
      'effective_label' => $effectiveLabel,
      'configured_label' => 'Schulnachricht / 1. Semester '.assessment_weight_format((float)$configured['first_semester']).' · restliches Schuljahr / aktueller Leistungsstand '.assessment_weight_format((float)$configured['current_year']),
      'warnings' => array_values(array_unique($warnings)),
    ],
    'first_semester_proposal' => $firstSemesterProposal,
    'current_year_proposal' => $currentYearProposal,
    'signals' => array_values(array_unique($warnings)),
    'written_only' => false,
  ];
}

function assessment_weight_semester_model_year_notice(?string $assessmentSystem): array {
  $model = $assessmentSystem === 'sost' ? 'SOST' : 'NOST';
  return [
    'value' => null,
    'label' => 'Kein regulärer Jahresvorschlag',
    'short' => 'semesterbezogen',
    'tone' => 'neutral',
    'explanation' => $model.' wird semesterbezogen beurteilt. Die App verbindet das Winter- und Sommersemester nicht automatisch zu einer Jahresnote.',
    'calculated_value' => null,
    'weighting' => [],
    'year_weighting' => [],
    'signals' => [$model.' bleibt semesterbezogen.'],
  ];
}
