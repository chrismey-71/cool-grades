<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/oral_assessments.php';
require_once __DIR__.'/school_years.php';
require_once __DIR__.'/assessment_summaries.php';

function report_eval_normalize_rating_label(?string $label): array {
  $label = trim((string)$label);
  if($label === ''){
    return ['label' => '', 'norm' => '', 'ascii' => ''];
  }

  $norm = mb_strtolower($label, 'UTF-8');
  $norm = preg_replace('/\s+/u', ' ', $norm);
  $ascii = strtr($norm, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);

  return [
    'label' => $label,
    'norm' => $norm,
    'ascii' => $ascii,
  ];
}

function report_eval_rating_classification(?string $label): ?string {
  $parts = report_eval_normalize_rating_label($label);
  $norm = $parts['norm'];
  $ascii = $parts['ascii'];

  if($norm === ''){
    return null;
  }

  $hasPlus = (bool)preg_match('/(^|[^[:alnum:]])\+{1,3}($|[^[:alnum:]])/u', $norm);
  $hasMinus = (bool)preg_match('/(^|[^[:alnum:]])-{1,3}($|[^[:alnum:]])/u', $norm);

  if(
    strpos($norm, '+/-') !== false ||
    strpos($norm, '-/+') !== false ||
    str_contains($norm, 'gemischt') ||
    str_contains($norm, 'unauffällig') ||
    str_contains($norm, 'nur beobachtet') ||
    str_contains($norm, 'neutral') ||
    preg_match('/\bok\b/u', $norm)
  ){
    return 'neutral';
  }

  if(
    strpos($norm, 'auffällig positiv') !== false ||
    strpos($norm, 'sehr gut') !== false ||
    strpos($norm, 'sehr sicher') !== false ||
    str_contains($ascii, 'positiv') ||
    str_contains($ascii, 'positive') ||
    str_contains($ascii, 'gut') ||
    str_contains($ascii, 'sicher') ||
    str_contains($ascii, 'gelungen') ||
    str_contains($ascii, 'stark') ||
    str_contains($ascii, 'selbststaendig') ||
    $hasPlus
  ){
    if(!$hasMinus || $hasPlus){
      return 'positive';
    }
  }

  if(
    strpos($norm, 'auffällig negativ') !== false ||
    strpos($norm, 'nicht genügend') !== false ||
    strpos($ascii, 'ungenuegend') !== false ||
    strpos($norm, 'sehr kritisch') !== false ||
    strpos($norm, 'sehr unsicher') !== false ||
    str_contains($ascii, 'negativ') ||
    str_contains($ascii, 'kritisch') ||
    str_contains($ascii, 'unsicher') ||
    str_contains($ascii, 'schwach') ||
    str_contains($ascii, 'lueckenhaft') ||
    str_contains($ascii, 'fehlerhaft') ||
    str_contains($ascii, 'unzureichend') ||
    str_contains($ascii, 'problematisch') ||
    $hasMinus
  ){
    if(!$hasPlus || $hasMinus){
      return 'negative';
    }
  }

  return null;
}

function report_eval_rating_score(?string $label): ?int {
  $classification = report_eval_rating_classification($label);
  if($classification === null) return null;
  if($classification === 'neutral') return 0;

  $parts = report_eval_normalize_rating_label($label);
  $norm = $parts['norm'];
  $ascii = $parts['ascii'];

  if($classification === 'positive'){
    if(
      strpos($norm, 'auffällig positiv') !== false ||
      strpos($norm, 'sehr gut') !== false ||
      strpos($norm, 'sehr sicher') !== false ||
      preg_match('/\+{2,3}/', $norm)
    ){
      return 2;
    }
    return 1;
  }

  if(
    strpos($norm, 'auffällig negativ') !== false ||
    strpos($norm, 'nicht genügend') !== false ||
    strpos($ascii, 'ungenuegend') !== false ||
    strpos($norm, 'sehr kritisch') !== false ||
    strpos($norm, 'sehr unsicher') !== false ||
    preg_match('/-{2,3}/', $norm)
  ){
    return -2;
  }

  return -1;
}

function report_eval_data_basis(int $count, int $distinctDates): array {
  if($count <= 0){
    return [
      'level' => 'none',
      'label' => 'Keine Daten vorhanden',
      'short' => 'keine Daten',
      'tone' => 'neutral',
      'can_estimate' => false,
      'explanation' => 'Es liegen im gewählten Zeitraum keine verwertbaren Mitarbeitseinträge vor.',
    ];
  }

  if($distinctDates >= 6 || $count >= 6){
    return [
      'level' => 'good',
      'label' => 'gute Datenbasis',
      'short' => 'gute Datenbasis',
      'tone' => 'positive',
      'can_estimate' => true,
      'explanation' => sprintf('%d Einträge an %d dokumentierten Tagen bieten eine gute Grundlage für die Einschätzung.', $count, $distinctDates),
    ];
  }

  if($distinctDates >= 3 || $count >= 3){
    return [
      'level' => 'enough',
      'label' => 'Einschätzung möglich',
      'short' => 'ausreichend',
      'tone' => 'positive',
      'can_estimate' => true,
      'explanation' => sprintf('%d Einträge an %d dokumentierten Tagen erlauben eine erste belastbare Einschätzung.', $count, $distinctDates),
    ];
  }

  return [
    'level' => 'thin',
    'label' => 'Datenlage noch dünn',
    'short' => 'noch dünn',
    'tone' => 'neutral',
    'can_estimate' => false,
    'explanation' => sprintf('%d Einträge an %d dokumentierten Tagen sind vorhanden; die Einschätzung sollte noch vorsichtig verwendet werden.', $count, $distinctDates),
  ];
}

function report_eval_data_basis_level_label(array $dataBasis): string {
  $level = (string)($dataBasis['level'] ?? '');
  return match($level){
    'none' => 'keine Daten',
    'thin' => 'dünn',
    'enough' => 'ausreichend',
    'good' => 'gut',
    default => trim((string)($dataBasis['short'] ?? $dataBasis['label'] ?? 'prüfen')) ?: 'prüfen',
  };
}

function report_eval_data_basis_display(array $dataBasis): string {
  return 'Datenbasis: '.report_eval_data_basis_level_label($dataBasis);
}

function report_eval_subject_context_from_row(array $subjectRow): array {
  $raw = $subjectRow['is_schularbeit_subject'] ?? null;
  $status = 'unset';
  $statusLabel = 'Nicht festgelegt';
  $tone = 'neutral';
  $note = 'Für dieses Fach ist nicht festgelegt, ob es ein Schularbeitsfach ist. Bitte prüfen Sie die Facheinstellung, damit die Auswertung korrekt interpretiert werden kann.';
  $shortNote = 'Bitte prüfen: Für dieses Fach ist noch nicht festgelegt, ob es ein Schularbeitsfach ist.';

  if($raw !== null && $raw !== ''){
    if((int)$raw === 1){
      $status = 'yes';
      $statusLabel = 'Ja';
      $note = 'Hinweis: Dieses Fach ist als Schularbeitsfach gekennzeichnet. Schularbeitsleistungen sind bei der abschließenden Beurteilung gesondert zu berücksichtigen. Die vorliegende Auswertung bezieht sich auf die dokumentierte Mitarbeit und die in dieser App erfassten besonderen Leistungsfeststellungen.';
      $shortNote = 'Schularbeitsleistungen sind gesondert zu berücksichtigen.';
    } else {
      $status = 'no';
      $statusLabel = 'Nein';
      $tone = 'positive';
      $note = 'Die Auswertung basiert auf dokumentierter Mitarbeit sowie erfassten besonderen mündlichen und schriftlichen Leistungsfeststellungen.';
      $shortNote = 'Die Auswertung stützt sich auf dokumentierte Mitarbeit und erfasste besondere Leistungsfeststellungen.';
    }
  }

  return [
    'subject_id' => (int)($subjectRow['id'] ?? 0),
    'code' => (string)($subjectRow['code'] ?? ''),
    'name' => (string)($subjectRow['name'] ?? ''),
    'status' => $status,
    'status_label' => $statusLabel,
    'tone' => $tone,
    'note' => $note,
    'short_note' => $shortNote,
  ];
}

function report_eval_subject_context(PDO $pdo, int $subjectId): array {
  $st = $pdo->prepare("SELECT id, code, name, is_schularbeit_subject FROM subjects WHERE id=? LIMIT 1");
  $st->execute([$subjectId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $subjectId, 'code' => '#'.$subjectId, 'name' => '', 'is_schularbeit_subject' => null];
  return report_eval_subject_context_from_row($row);
}

function report_eval_tendency_summary(array $scores): array {
  if(!$scores){
    return [
      'tone' => 'neutral',
      'label' => 'noch keine klare Tendenz',
      'short' => 'keine Tendenz',
      'avg' => null,
    ];
  }

  $avg = array_sum($scores) / count($scores);
  if($avg >= 1.2) return ['tone'=>'positive','label'=>'deutlich positiv','short'=>'deutlich positiv','avg'=>$avg];
  if($avg >= 0.35) return ['tone'=>'positive','label'=>'überwiegend positiv','short'=>'überw. positiv','avg'=>$avg];
  if($avg <= -1.2) return ['tone'=>'critical','label'=>'deutlich kritisch','short'=>'deutlich kritisch','avg'=>$avg];
  if($avg <= -0.35) return ['tone'=>'critical','label'=>'überwiegend kritisch','short'=>'überw. kritisch','avg'=>$avg];
  return ['tone'=>'neutral','label'=>'gemischt / ausgewogen','short'=>'gemischt','avg'=>$avg];
}

function report_eval_clip(string $text, int $length = 90): string {
  $text = trim(preg_replace('/\s+/u', ' ', $text));
  if($text === '') return '';
  if(mb_strlen($text) <= $length) return $text;
  return rtrim(mb_substr($text, 0, max(0, $length - 1))).'…';
}

function report_eval_grade_symbol(int $grade, ?string $tendency = ''): string {
  $suffix = '';
  $tendency = normalize_exam_grade_tendency((string)$tendency);
  if($tendency === 'plus') $suffix = '+';
  elseif($tendency === 'minus') $suffix = '-';
  return (string)$grade.$suffix;
}

function report_eval_note_proposal(int $count, int $distinctDates, array $scores, int $positiveCount, int $neutralCount, int $negativeCount, int $unratedCount = 0): array {
  $dataBasis = report_eval_data_basis($count, $distinctDates);
  if(!$dataBasis['can_estimate']){
    return [
      'value' => null,
      'label' => (string)$dataBasis['label'],
      'short' => (string)$dataBasis['short'],
      'tone' => 'neutral',
      'explanation' => (string)$dataBasis['explanation'],
    ];
  }

  if(count($scores) === 0){
    return [
      'value' => null,
      'label' => 'Eindruckswerte prüfen',
      'short' => 'Werte prüfen',
      'tone' => 'neutral',
      'explanation' => 'Es liegen Mitarbeitseinträge vor, aber keine auswertbaren Eindruckswerte für einen transparenten Vorschlag.',
    ];
  }

  $avg = array_sum($scores) / count($scores);
  $value = 3;
  if($avg >= 1.1 && $positiveCount >= 4 && $negativeCount === 0){
    $value = 1;
  } elseif($avg >= 0.45 && $positiveCount > ($negativeCount * 2)){
    $value = 2;
  } elseif($avg > -0.15){
    $value = 3;
  } elseif($avg > -0.85){
    $value = 4;
  } else {
    $value = 5;
  }

  $tone = $value <= 2 ? 'positive' : ($value >= 4 ? 'critical' : 'neutral');
  $label = 'Vorschlag '.(string)$value;
  if($value === 1) $label = 'Vorschlag 1';
  elseif($value === 2) $label = 'Vorschlag 2';
  elseif($value === 3) $label = 'Vorschlag 3';
  elseif($value === 4) $label = 'Vorschlag 4';
  elseif($value === 5) $label = 'Vorschlag 5';

  $explanation = sprintf(
    '%d Einträge an %d Tagen · positiv %d / neutral %d / negativ %d · Durchschnitt %s',
    $count,
    $distinctDates,
    $positiveCount,
    $neutralCount,
    $negativeCount,
    number_format($avg, 2, ',', '.')
  );
  if($unratedCount > 0){
    $explanation .= ' · ohne Wertung '.$unratedCount;
  }

  return [
    'value' => $value,
    'label' => $label,
    'short' => (string)$value,
    'tone' => $tone,
    'explanation' => $explanation,
  ];
}

function report_eval_join_top_counts(array $counts, int $limit = 3, int $clipLength = 26): string {
  if(!$counts) return '–';
  arsort($counts);
  $parts = [];
  foreach(array_slice($counts, 0, $limit, true) as $label => $count){
    $parts[] = report_eval_clip((string)$label, $clipLength);
  }
  return $parts ? implode(' · ', $parts) : '–';
}

function report_eval_written_summary(array $writtenRows): array {
  if(!$writtenRows){
    return [
      'count' => 0,
      'text' => '–',
      'avg' => null,
      'grades' => [],
      'type_counts' => [],
    ];
  }
  usort($writtenRows, static function(array $a, array $b): int {
    return strcmp((string)$a['exam_date'], (string)$b['exam_date']) * -1;
  });
  $symbols = [];
  $grades = [];
  $typeCounts = [];
  foreach($writtenRows as $row){
    $grade = (int)$row['grade'];
    if($grade <= 0) continue;
    $grades[] = $grade;
    $type = written_assessment_normalize_type((string)($row['exam_type'] ?? 'SA'));
    $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
    $symbols[] = written_assessment_type_short_label($type).' '.report_eval_grade_symbol($grade, (string)($row['tendency'] ?? ''));
  }
  $count = count($symbols);
  $avg = $grades ? array_sum($grades) / count($grades) : null;
  return [
    'count' => $count,
    'text' => $count ? ($count.': '.implode(', ', array_slice($symbols, 0, 4)).($count > 4 ? ', …' : '')) : '–',
    'avg' => $avg,
    'grades' => $grades,
    'type_counts' => $typeCounts,
  ];
}

function report_eval_oral_summary(array $oralRows): array {
  if(!$oralRows){
    return [
      'count' => 0,
      'text' => '–',
      'positive' => 0,
      'neutral' => 0,
      'negative' => 0,
    ];
  }
  usort($oralRows, static function(array $a, array $b): int {
    return strcmp((string)$a['assessment_date'], (string)$b['assessment_date']) * -1;
  });
  $parts = [];
  $positive = 0;
  $neutral = 0;
  $negative = 0;
  foreach($oralRows as $row){
    $label = trim((string)($row['impact_label'] ?? ''));
    $classification = report_eval_rating_classification($label);
    if($classification === 'positive') $positive++;
    elseif($classification === 'negative') $negative++;
    elseif($classification === 'neutral') $neutral++;
    if($label !== '') $parts[] = $label;
  }
  $count = count($oralRows);
  return [
    'count' => $count,
    'text' => $count ? ($count.': '.implode(', ', array_slice($parts, 0, 3)).($count > 3 ? ', …' : '')) : '–',
    'positive' => $positive,
    'neutral' => $neutral,
    'negative' => $negative,
  ];
}

function report_eval_semester_hint(array $summary, array $subjectContext = []): string {
  $hints = [];
  $proposal = $summary['note_proposal'];
  $dataBasis = $summary['data_basis'] ?? ['level' => 'none'];

  if(($dataBasis['level'] ?? 'none') === 'none'){
    $hints[] = 'keine ausreichenden Daten vorhanden';
  } elseif(($dataBasis['level'] ?? 'none') === 'thin'){
    $hints[] = 'Einschätzung vorsichtig verwenden';
  } elseif($proposal['value'] === null){
    $hints[] = 'Eindruckswerte prüfen';
  } elseif(($proposal['value'] ?? 0) <= 2 && ($summary['negative_count'] ?? 0) === 0 && ($summary['participation_count'] ?? 0) >= 6){
    $hints[] = 'stabile positive Mitarbeit';
  } elseif(($summary['negative_count'] ?? 0) >= 3){
    $hints[] = 'mehrere negative Einträge';
  } elseif(($proposal['value'] ?? 0) >= 4){
    $hints[] = 'kritische Mitarbeitslage';
  } elseif(($proposal['value'] ?? 0) === 3){
    $hints[] = 'uneinheitliche Mitarbeit';
  }

  if(($summary['oral_positive_count'] ?? 0) > 0 && ($summary['oral_negative_count'] ?? 0) === 0){
    $hints[] = 'mündliche Leistung verbessert Gesamtbild';
  } elseif(($summary['oral_negative_count'] ?? 0) > 0){
    $hints[] = 'mündliche Leistung kritisch prüfen';
  }

  if(($summary['written_avg'] ?? null) !== null){
    if($summary['written_avg'] <= 2.4){
      $hints[] = 'schriftliche Sonderleistung stützt Gesamtbild';
    } elseif($summary['written_avg'] >= 4.0){
      $hints[] = 'schriftliche Sonderleistung schwach';
    }
  }

  if(($summary['participation_count'] ?? 0) < 6){
    $hints[] = 'regelmäßige Dokumentation ausbauen';
  }

  if(($subjectContext['status'] ?? 'unset') === 'yes'){
    $hints[] = 'Schularbeitsleistungen gesondert berücksichtigen';
  } elseif(($subjectContext['status'] ?? 'unset') === 'unset'){
    $hints[] = 'Fachstatus prüfen';
  }

  $hints = array_values(array_unique($hints));
  return $hints ? implode(' · ', array_slice($hints, 0, 2)) : 'Gesamtbild pädagogisch würdigen';
}

function report_eval_legal_note(bool $hasSpecial): string {
  $text = 'Rechtlicher Hinweis: Diese Auswertung dient als pädagogische Entscheidungshilfe im Sinne der Leistungsbeurteilungsverordnung (LBV), insbesondere §§ 3, 4 und 11 LBV. Sie ersetzt nicht die abschließende Leistungsbeurteilung durch die Lehrkraft.';
  if($hasSpecial){
    $text .= ' Besondere mündliche und schriftliche Leistungsfeststellungen werden gemäß den jeweils einschlägigen Bestimmungen der LBV gesondert ausgewiesen.';
  }
  return $text;
}

function report_eval_assessment_context_from_period(array $resolvedPeriod, string $requestedPeriod = ''): array {
  $keys = [];
  foreach(['resolved_key','period'] as $field){
    $value = trim((string)($resolvedPeriod[$field] ?? ''));
    if($value !== '') $keys[] = $value;
  }
  $requestedPeriod = trim($requestedPeriod);
  if($requestedPeriod !== '') $keys[] = $requestedPeriod;

  foreach($keys as $key){
    if(preg_match('/^period_(\d+)_(semester1|semester2|schoolyear)$/', $key, $m)){
      $kind = (string)$m[2];
      return [
        'school_period_set_id' => (int)$m[1],
        'scope' => $kind === 'schoolyear' ? 'year' : $kind,
      ];
    }
  }

  $from = (string)($resolvedPeriod['from'] ?? '');
  $to = (string)($resolvedPeriod['to'] ?? '');
  foreach((array)($resolvedPeriod['ranges'] ?? []) as $key => $range){
    if($from !== '' && $to !== '' && (string)($range['from'] ?? '') === $from && (string)($range['to'] ?? '') === $to){
      if(preg_match('/^period_(\d+)_(semester1|semester2|schoolyear)$/', (string)$key, $m)){
        $kind = (string)$m[2];
        return [
          'school_period_set_id' => (int)$m[1],
          'scope' => $kind === 'schoolyear' ? 'year' : $kind,
        ];
      }
    }
  }

  return [
    'school_period_set_id' => 0,
    'scope' => 'semester1',
  ];
}

function report_build_student_summaries(PDO $pdo, int $classId, int $subjectId, string $dateFrom = '', string $dateTo = ''): array {
  $subjectContext = report_eval_subject_context($pdo, $subjectId);
  $students = load_class_students($pdo, $classId, false);

  $summaries = [];
  foreach($students as $student){
    $sid = (int)$student['id'];
    $summaries[$sid] = [
      'student_id' => $sid,
      'student_name' => $student['last_name'].', '.$student['first_name'],
      'participation_count' => 0,
      'distinct_dates' => [],
      'positive_count' => 0,
      'neutral_count' => 0,
      'negative_count' => 0,
      'unrated_count' => 0,
      'participation_scores' => [],
      'focus_counts' => [],
      'criteria_counts' => [],
      'lbv_counts' => ['a'=>0,'b'=>0,'c'=>0,'d'=>0,'e'=>0],
      'comment_candidates' => [],
      'participation_details' => [],
      'written_rows' => [],
      'oral_rows' => [],
      'written_avg' => null,
      'written_count' => 0,
      'written_type_counts' => [],
      'oral_count' => 0,
      'oral_positive_count' => 0,
      'oral_neutral_count' => 0,
      'oral_negative_count' => 0,
      'top_criteria' => '–',
      'written_text' => '–',
      'oral_text' => '–',
      'comments_text' => '–',
      'quality' => ['tone'=>'neutral','label'=>'noch nicht belastbar','short'=>'noch offen','avg'=>null],
      'data_basis' => ['level'=>'none','label'=>'Keine Daten vorhanden','short'=>'keine Daten','tone'=>'neutral','can_estimate'=>false,'explanation'=>''],
      'note_proposal' => ['value'=>null,'label'=>'Keine Daten vorhanden','short'=>'keine Daten','tone'=>'neutral','explanation'=>''],
      'semester_hint' => 'Datenlage prüfen',
      'subject_context' => $subjectContext,
    ];
  }

  if(!$summaries){
    return [];
  }

  $where = "pe.class_id=? AND pe.subject_id=?";
  $params = [$classId, $subjectId];
  if($dateFrom !== ''){ $where .= " AND pe.event_date >= ?"; $params[] = $dateFrom; }
  if($dateTo !== ''){ $where .= " AND pe.event_date <= ?"; $params[] = $dateTo; }

  $st = $pdo->prepare("SELECT pe.id, pe.student_id, pe.event_date, pe.reason_label, pe.rating, pe.reason_text, pe.note,
                              so.label AS social_label, ph.label AS phase_label, hw.label AS homework_label
                       FROM participation_events pe
                       LEFT JOIN participation_options so ON so.id=pe.social_form_option_id
                       LEFT JOIN participation_options ph ON ph.id=pe.phase_option_id
                       LEFT JOIN participation_options hw ON hw.id=pe.homework_option_id
                       WHERE $where
                       ORDER BY pe.event_date DESC, pe.id DESC");
  $st->execute($params);
  $events = $st->fetchAll();

  $eventIds = [];
  $eventToStudent = [];
  foreach($events as $event){
    $eid = (int)$event['id'];
    $sid = (int)$event['student_id'];
    if(!isset($summaries[$sid])) continue;
    $eventIds[] = $eid;
    $eventToStudent[$eid] = $sid;
    $summaries[$sid]['participation_count']++;
    $summaries[$sid]['distinct_dates'][(string)$event['event_date']] = true;
    $summaries[$sid]['focus_counts'][(string)$event['reason_label']] = ($summaries[$sid]['focus_counts'][(string)$event['reason_label']] ?? 0) + 1;
    if(!empty($event['social_label']) && (string)$event['social_label'] !== 'Alleinarbeit'){
      $summaries[$sid]['focus_counts'][(string)$event['social_label']] = ($summaries[$sid]['focus_counts'][(string)$event['social_label']] ?? 0) + 1;
    }
    if(!empty($event['homework_label'])){
      $summaries[$sid]['focus_counts'][(string)$event['homework_label']] = ($summaries[$sid]['focus_counts'][(string)$event['homework_label']] ?? 0) + 1;
    }

    $classification = report_eval_rating_classification((string)$event['rating']);
    $score = report_eval_rating_score((string)$event['rating']);
    if($classification === 'positive') $summaries[$sid]['positive_count']++;
    elseif($classification === 'negative') $summaries[$sid]['negative_count']++;
    elseif($classification === 'neutral') $summaries[$sid]['neutral_count']++;
    else $summaries[$sid]['unrated_count']++;
    if($score !== null) $summaries[$sid]['participation_scores'][] = $score;

    $comment = trim((string)($event['reason_text'] ?: $event['note'] ?: ''));
    if($comment !== ''){
      $priority = ($score !== null && $score < 0) ? 30 : 20;
      $summaries[$sid]['comment_candidates'][] = [
        'priority' => $priority,
        'date' => (string)$event['event_date'],
        'text' => $comment,
      ];
    }

    if(count($summaries[$sid]['participation_details']) < 5){
      $detail = (string)$event['event_date'].' · '.(string)$event['reason_label'].' · '.(string)$event['rating'];
      if($comment !== '') $detail .= ' — '.report_eval_clip($comment, 90);
      $summaries[$sid]['participation_details'][] = $detail;
    }
  }

  if($eventIds){
    $chunks = array_chunk($eventIds, 500);
    foreach($chunks as $chunk){
      $in = '('.implode(',', array_fill(0, count($chunk), '?')).')';

      $st = $pdo->prepare("SELECT peo.event_id, po.label
                           FROM participation_event_options peo
                           JOIN participation_options po ON po.id=peo.option_id
                           WHERE peo.event_id IN $in AND po.opt_type='observation_group'
                           ORDER BY po.sort, po.label");
      $st->execute($chunk);
      foreach($st->fetchAll() as $row){
        $eid = (int)$row['event_id'];
        $sid = $eventToStudent[$eid] ?? 0;
        if(!$sid || !isset($summaries[$sid])) continue;
        $label = (string)$row['label'];
        $summaries[$sid]['focus_counts'][$label] = ($summaries[$sid]['focus_counts'][$label] ?? 0) + 2;
      }

      $st = $pdo->prepare("SELECT pec.event_id, c.label
                           FROM participation_event_criteria pec
                           JOIN criteria c ON c.id=pec.criteria_id
                           WHERE pec.event_id IN $in
                           ORDER BY c.label");
      $st->execute($chunk);
      foreach($st->fetchAll() as $row){
        $eid = (int)$row['event_id'];
        $sid = $eventToStudent[$eid] ?? 0;
        if(!$sid || !isset($summaries[$sid])) continue;
        $label = (string)$row['label'];
        $summaries[$sid]['criteria_counts'][$label] = ($summaries[$sid]['criteria_counts'][$label] ?? 0) + 1;
      }

      $st = $pdo->prepare("SELECT event_id, tag FROM participation_event_lbvo WHERE event_id IN $in");
      $st->execute($chunk);
      foreach($st->fetchAll() as $row){
        $eid = (int)$row['event_id'];
        $sid = $eventToStudent[$eid] ?? 0;
        $tag = (string)$row['tag'];
        if(!$sid || !isset($summaries[$sid]) || !isset($summaries[$sid]['lbv_counts'][$tag])) continue;
        $summaries[$sid]['lbv_counts'][$tag]++;
      }
    }
  }

  $whereWritten = "e.class_id=? AND e.subject_id=?";
  $paramsWritten = [$classId, $subjectId];
  if($dateFrom !== ''){ $whereWritten .= " AND e.exam_date >= ?"; $paramsWritten[] = $dateFrom; }
  if($dateTo !== ''){ $whereWritten .= " AND e.exam_date <= ?"; $paramsWritten[] = $dateTo; }
  $st = $pdo->prepare("SELECT eg.student_id, e.exam_date, e.exam_type, e.title, eg.grade, eg.tendency, eg.remark
                       FROM exams e
                       JOIN exam_grades eg ON eg.exam_id=e.id
                       WHERE $whereWritten
                       ORDER BY e.exam_date DESC, e.id DESC");
  $st->execute($paramsWritten);
  foreach($st->fetchAll() as $row){
    $sid = (int)$row['student_id'];
    if(!isset($summaries[$sid])) continue;
    $summaries[$sid]['written_rows'][] = $row;
    $remark = trim((string)($row['remark'] ?? ''));
    if($remark !== ''){
      $summaries[$sid]['comment_candidates'][] = [
        'priority' => 35,
        'date' => (string)$row['exam_date'],
        'text' => 'Schriftlich: '.$remark,
      ];
    }
  }

  $whereOral = "oa.class_id=? AND oa.subject_id=?";
  $paramsOral = [$classId, $subjectId];
  if($dateFrom !== ''){ $whereOral .= " AND oa.assessment_date >= ?"; $paramsOral[] = $dateFrom; }
  if($dateTo !== ''){ $whereOral .= " AND oa.assessment_date <= ?"; $paramsOral[] = $dateTo; }
  $st = $pdo->prepare("SELECT oa.student_id, oa.assessment_date, oa.assessment_type, oa.impact_label, oa.topic_area, oa.questions, oa.category, oa.title
                       FROM oral_assessments oa
                       WHERE $whereOral
                       ORDER BY oa.assessment_date DESC, oa.id DESC");
  $st->execute($paramsOral);
  foreach($st->fetchAll() as $row){
    $sid = (int)$row['student_id'];
    if(!isset($summaries[$sid])) continue;
    $summaries[$sid]['oral_rows'][] = $row;
    $classification = report_eval_rating_classification((string)($row['impact_label'] ?? ''));
    if($classification === 'positive') $summaries[$sid]['oral_positive_count']++;
    elseif($classification === 'negative') $summaries[$sid]['oral_negative_count']++;
    elseif($classification === 'neutral') $summaries[$sid]['oral_neutral_count']++;
  }

  foreach($summaries as $sid => $summary){
    $documentedDayCount = count($summary['distinct_dates']);
    $dataBasis = report_eval_data_basis((int)$summary['participation_count'], $documentedDayCount);
    $quality = report_eval_tendency_summary($summary['participation_scores']);
    $topCriteria = report_eval_join_top_counts($summary['focus_counts'], 3, 24);
    if($topCriteria === '–'){
      $topCriteria = report_eval_join_top_counts($summary['criteria_counts'], 3, 24);
    }

    $written = report_eval_written_summary($summary['written_rows']);
    $oral = report_eval_oral_summary($summary['oral_rows']);
    $proposal = report_eval_note_proposal(
      (int)$summary['participation_count'],
      $documentedDayCount,
      $summary['participation_scores'],
      (int)$summary['positive_count'],
      (int)$summary['neutral_count'],
      (int)$summary['negative_count'],
      (int)$summary['unrated_count']
    );

    usort($summary['comment_candidates'], static function(array $a, array $b): int {
      if(($a['priority'] ?? 0) === ($b['priority'] ?? 0)){
        return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
      }
      return (($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
    });
    $commentTexts = [];
    foreach($summary['comment_candidates'] as $candidate){
      $text = report_eval_clip((string)$candidate['text'], 90);
      if($text === '' || in_array($text, $commentTexts, true)) continue;
      $commentTexts[] = $text;
      if(count($commentTexts) >= 2) break;
    }
    $commentsText = $commentTexts ? implode(' | ', $commentTexts) : '–';

    $summary['quality'] = $quality;
    $summary['documented_day_count'] = $documentedDayCount;
    $summary['data_basis'] = $dataBasis;
    $summary['top_criteria'] = $topCriteria;
    $summary['written_count'] = (int)$written['count'];
    $summary['written_text'] = (string)$written['text'];
    $summary['written_avg'] = $written['avg'];
    $summary['written_type_counts'] = $written['type_counts'];
    $summary['oral_count'] = (int)$oral['count'];
    $summary['oral_text'] = (string)$oral['text'];
    $summary['comments_text'] = $commentsText;
    $summary['note_proposal'] = $proposal;
    $summary['semester_hint'] = report_eval_semester_hint(array_merge($summary, [
      'written_avg' => $written['avg'],
      'data_basis' => $dataBasis,
    ]), $subjectContext);
    $summary['positive_neutral_negative'] = $summary['positive_count'].' / '.$summary['neutral_count'].' / '.$summary['negative_count'];

    $summaries[$sid] = $summary;
  }

  uasort($summaries, static function(array $a, array $b): int {
    return strcasecmp((string)$a['student_name'], (string)$b['student_name']);
  });

  return array_values($summaries);
}
