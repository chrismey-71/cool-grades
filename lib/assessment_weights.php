<?php

require_once __DIR__.'/report_evaluation.php';
require_once __DIR__.'/assessment_systems.php';

function assessment_weight_defaults(?string $subjectSchularbeitStatus = null): array {
  $status = in_array($subjectSchularbeitStatus, ['yes','no'], true) ? $subjectSchularbeitStatus : '';
  $areaDefaults = $status === 'yes'
    ? ['participation' => 40.0, 'oral' => 10.0, 'written' => 50.0]
    : ($status === 'no'
      ? ['participation' => 40.0, 'oral' => 30.0, 'written' => 30.0]
      : ['participation' => 60.0, 'oral' => 20.0, 'written' => 20.0]);
  return $areaDefaults + [
    'first_semester' => 40.0,
    'current_year' => 60.0,
  ];
}

function assessment_weight_preset_definitions(?string $subjectSchularbeitStatus = null): array {
  if($subjectSchularbeitStatus === 'yes'){
    return [
      'sa_balanced' => [
        'label' => 'Schularbeitsfach - ausgewogen',
        'description' => 'Beispielhafte Orientierungsgewichtung für ein Fach mit Schularbeiten. Die Werte sind frei anpassbar und keine gesetzliche Vorgabe.',
        'weights' => ['participation'=>40.0,'oral'=>10.0,'written'=>50.0],
      ],
      'sa_written' => [
        'label' => 'Schularbeitsfach - schriftlich stärker',
        'description' => 'Orientierung mit stärkerem schriftlichem Anteil; auch diese Werte sind frei anpassbar.',
        'weights' => ['participation'=>30.0,'oral'=>10.0,'written'=>60.0],
      ],
      'sa_continuous' => [
        'label' => 'Schularbeitsfach - kontinuierlicher',
        'description' => 'Orientierung mit stärkerer laufender Dokumentation und mündlichen Leistungsfeststellungen.',
        'weights' => ['participation'=>45.0,'oral'=>15.0,'written'=>40.0],
      ],
    ];
  }

  return [
    'balanced' => [
      'label' => 'Ausgewogen',
      'description' => 'Allgemeine Orientierungsgewichtung für ein Fach ohne verpflichtende Schularbeiten.',
      'weights' => ['participation'=>40.0,'oral'=>30.0,'written'=>30.0],
    ],
    'participation' => [
      'label' => 'Mitarbeitsorientiert',
      'description' => 'Orientierung mit stärkerem Schwerpunkt auf laufender Mitarbeit.',
      'weights' => ['participation'=>50.0,'oral'=>25.0,'written'=>25.0],
    ],
    'continuous' => [
      'label' => 'Stark kontinuierlich',
      'description' => 'Orientierung mit deutlichem Schwerpunkt auf laufender Unterrichtsarbeit.',
      'weights' => ['participation'=>60.0,'oral'=>20.0,'written'=>20.0],
    ],
  ];
}

function assessment_weight_preset_resolve(string $presetKey, ?string $subjectSchularbeitStatus = null): ?array {
  $presets = assessment_weight_preset_definitions($subjectSchularbeitStatus);
  return $presets[$presetKey] ?? null;
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

function assessment_weight_settings_resolve(?array $row, ?string $assessmentSystem, array $subjectContext = []): array {
  $defaults = assessment_weight_defaults((string)($subjectContext['status'] ?? ''));
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

function assessment_weight_settings_load(PDO $pdo, int $teacherId, int $classId, int $subjectId, int $schoolPeriodSetId, ?string $assessmentSystem, array $subjectContext = []): array {
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
  return assessment_weight_settings_resolve($row, $assessmentSystem, $subjectContext);
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

function assessment_weight_multiplier_normalize($value): float {
  $normalized = (float)str_replace(',', '.', (string)$value);
  $allowed = [0.5, 1.0, 1.5, 2.0, 3.0];
  foreach($allowed as $candidate){
    if(abs($normalized - $candidate) < 0.001) return $candidate;
  }
  return 1.0;
}

function assessment_weight_multiplier_options(): array {
  return [
    '0.5' => '0,5 x',
    '1' => '1 x',
    '1.5' => '1,5 x',
    '2' => '2 x',
    '3' => '3 x',
  ];
}

function assessment_weight_settings_activity(PDO $pdo, int $teacherId, int $classId, int $subjectId, array $periodSet): array {
  $from = (string)($periodSet['semester1_from'] ?? '');
  $to = (string)($periodSet['semester2_to'] ?? '');
  $stats = [
    'participation_count' => 0,
    'oral_count' => 0,
    'written_count' => 0,
    'written_type_counts' => [],
    'from' => $from,
    'to' => $to,
  ];
  if($teacherId <= 0 || $classId <= 0 || $subjectId <= 0 || $from === '' || $to === ''){
    return $stats;
  }

  try{
    $st = $pdo->prepare("SELECT COUNT(*) FROM participation_events
                         WHERE teacher_id=? AND class_id=? AND subject_id=? AND event_date BETWEEN ? AND ?");
    $st->execute([$teacherId,$classId,$subjectId,$from,$to]);
    $stats['participation_count'] = (int)$st->fetchColumn();
  }catch(Throwable $e){}

  try{
    $st = $pdo->prepare("SELECT COUNT(*) FROM oral_assessments
                         WHERE teacher_id=? AND class_id=? AND subject_id=? AND assessment_date BETWEEN ? AND ?");
    $st->execute([$teacherId,$classId,$subjectId,$from,$to]);
    $stats['oral_count'] = (int)$st->fetchColumn();
  }catch(Throwable $e){}

  try{
    $st = $pdo->prepare("SELECT UPPER(IFNULL(exam_type,'SA')) AS exam_type, COUNT(*) AS cnt
                         FROM exams
                         WHERE teacher_id=? AND class_id=? AND subject_id=? AND exam_date BETWEEN ? AND ?
                         GROUP BY UPPER(IFNULL(exam_type,'SA'))");
    $st->execute([$teacherId,$classId,$subjectId,$from,$to]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
      $type = written_assessment_normalize_type((string)($row['exam_type'] ?? 'SA'));
      $count = (int)($row['cnt'] ?? 0);
      $stats['written_type_counts'][$type] = ($stats['written_type_counts'][$type] ?? 0) + $count;
      $stats['written_count'] += $count;
    }
  }catch(Throwable $e){}

  return $stats;
}

function assessment_weight_plausibility_warnings(array $settings, array $subjectContext = [], array $activity = [], array $areas = []): array {
  $configured = (array)($settings['configured'] ?? assessment_weight_defaults((string)($subjectContext['status'] ?? '')));
  $warnings = [];
  $writtenWeight = (float)($configured['written'] ?? 0);
  $schularbeitCount = (int)($activity['written_type_counts']['SA'] ?? 0);
  $subjectStatus = (string)($subjectContext['status'] ?? '');

  if($subjectStatus === 'yes' && $writtenWeight < 30.0){
    $warnings[] = 'Die besonderen schriftlichen Leistungsfeststellungen sind in diesem Schularbeitsfach vergleichsweise niedrig gewichtet. Bitte prüfe, ob die Gewichtung unter Berücksichtigung von Anzahl, Umfang und Schwierigkeit der Leistungsfeststellungen für dein Fach passend ist.';
  }
  if($subjectStatus === 'yes' && $schularbeitCount >= 2 && $writtenWeight < 30.0){
    $warnings[] = 'Für dieses Schularbeitsfach sind bereits mehrere Schularbeiten erfasst. Prüfe bitte, ob die gewählte Gewichtung der besonderen schriftlichen Leistungsfeststellungen angesichts von Anzahl, Umfang und Schwierigkeit passend ist.';
  }

  $availableDataKeys = [];
  if((int)($activity['participation_count'] ?? 0) > 0 || !empty($areas['participation']['available'])) $availableDataKeys[] = 'participation';
  if((int)($activity['oral_count'] ?? 0) > 0 || !empty($areas['oral']['available'])) $availableDataKeys[] = 'oral';
  if((int)($activity['written_count'] ?? 0) > 0 || !empty($areas['written']['available'])) $availableDataKeys[] = 'written';
  if(count(array_unique($availableDataKeys)) >= 2){
    foreach(['participation','oral','written'] as $key){
      if((float)($configured[$key] ?? 0) > 80.0){
        $warnings[] = 'Ein Leistungsbereich ist sehr stark gewichtet. Bitte prüfe, ob diese Gewichtung das gesamte Leistungsbild angemessen widerspiegelt.';
        break;
      }
    }
  }

  return array_values(array_unique($warnings));
}

function assessment_weight_value_from_qualitative_average(float $average): float {
  $clamped = max(-2.0, min(2.0, $average));
  $value = $clamped >= 0
    ? 3.0 - (1.5 * $clamped)
    : 3.0 - $clamped;
  return round(max(1.0, min(5.0, $value)), 2);
}

/**
 * Produces comparable 1-5 proposal values. Participation stays qualitative
 * (Eindruck/Relevanz - § 4 LBV never grades a single Mitarbeit contribution).
 * Besondere mündliche Leistungsfeststellungen (§ 5/6 LBV) are, like written
 * assessments, discrete graded assessments and use their stored Noten and
 * optional assessment weights directly, the same way written does. Oral rows
 * saved before Noten were required (Eindruck/Relevanz only, no grade) are not
 * included in this average - see report_eval_oral_summary().
 */
function assessment_weight_area_values(array $summary): array {
  $participationProposal = $summary['note_proposal'] ?? [];
  $participationAverage = ($summary['quality']['avg'] ?? null) !== null ? (float)$summary['quality']['avg'] : null;
  $participationProposalAvailable = isset($participationProposal['value']) && $participationProposal['value'] !== null;
  $participationValue = null;
  if($participationProposalAvailable && $participationAverage !== null){
    $participationValue = assessment_weight_value_from_qualitative_average($participationAverage);
  } elseif($participationProposalAvailable){
    $participationValue = (float)$participationProposal['value'];
  }

  $oralGradedCount = (int)($summary['oral_graded_count'] ?? 0);
  $oralAverage = ($summary['oral_avg'] ?? null) !== null ? (float)$summary['oral_avg'] : null;
  $oralValue = ($oralGradedCount > 0 && $oralAverage !== null) ? $oralAverage : null;

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
        ? 'qualitativer Bereichswert '.assessment_weight_grade_format($participationValue).($participationAverage !== null ? ' aus Eindrucksdurchschnitt '.number_format($participationAverage, 2, ',', '.') : '')
        : 'keine ausreichend belastbare Mitarbeitstendenz',
      'qualitative_average' => $participationAverage,
    ],
    'oral' => [
      'available' => $oralValue !== null,
      'value' => $oralValue,
      'count' => $oralGradedCount,
      'kind' => 'Noten',
      'basis' => $oralValue !== null
        ? $oralGradedCount.' Note(n), gewichteter Durchschnitt '.assessment_weight_grade_format($oralValue)
        : 'keine verwertbaren Noten',
    ],
    'written' => [
      'available' => $writtenValue !== null,
      'value' => $writtenValue,
      'count' => $writtenCount,
      'kind' => 'Noten',
      'basis' => $writtenValue !== null
        ? $writtenCount.' Note(n), gewichteter Durchschnitt '.assessment_weight_grade_format($writtenValue)
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
function assessment_weight_compute_area_proposal(array $summary, array $settings, array $subjectContext = []): array {
  $areas = assessment_weight_area_values($summary);
  $labels = assessment_weight_area_labels();
  $availableKeys = [];
  $excludedLabels = [];
  foreach($areas as $key => $area){
    if(!empty($area['available'])) $availableKeys[] = $key;
    else $excludedLabels[] = $labels[$key];
  }
  $configured = (array)($settings['configured'] ?? assessment_weight_defaults());
  $activity = [
    'participation_count' => (int)($summary['participation_count'] ?? 0),
    'oral_count' => count((array)($summary['oral_rows'] ?? [])),
    'written_count' => (int)($summary['written_count'] ?? 0),
    'written_type_counts' => (array)($summary['written_type_counts'] ?? []),
  ];
  $warnings = array_merge(
    (array)($settings['warnings'] ?? []),
    assessment_weight_plausibility_warnings($settings, $subjectContext, $activity, $areas)
  );
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
        'warnings' => array_values(array_unique($warnings)),
      ],
      'signals' => array_values(array_unique($warnings)),
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
