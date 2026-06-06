<?php
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/helpers.php';
require_once __DIR__.'/lib/settings.php';
require_once __DIR__.'/lib/simple_pdf.php';
require_once __DIR__.'/lib/report_evaluation.php';
require_once __DIR__.'/lib/final_assessments.php';

$u = require_role('teacher');
$pdo = db();

$class_id = (int)($_GET['class_id'] ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);
$student_id = (int)($_GET['student_id'] ?? 0);
$period = (string)($_GET['period'] ?? 'current');
$customFrom = (string)($_GET['from'] ?? '');
$customTo = (string)($_GET['to'] ?? '');
$resolvedPeriod = app_school_period_resolve($period, $customFrom, $customTo);
$date_from = (string)$resolvedPeriod['from'];
$date_to = (string)$resolvedPeriod['to'];
$assessmentContext = report_eval_assessment_context_from_period($resolvedPeriod, $period);

if(!$class_id || !$subject_id){
  http_response_code(400);
  exit('Bitte Klasse und Fach wählen.');
}
require_teacher_assignment($u, $class_id, $subject_id);

$st = $pdo->prepare("SELECT name FROM classes WHERE id=?");
$st->execute([$class_id]);
$className = (string)($st->fetchColumn() ?: ('#'.$class_id));

$st = $pdo->prepare("SELECT code,name,is_schularbeit_subject FROM subjects WHERE id=?");
$st->execute([$subject_id]);
$subjectRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['code'=>'#'.$subject_id,'name'=>'','is_schularbeit_subject'=>null];
$subjectCode = (string)$subjectRow['code'];
$subjectName = (string)$subjectRow['name'];
$subjectContext = report_eval_subject_context_from_row(array_merge($subjectRow, ['id' => $subject_id]));

$summaries = report_build_student_summaries($pdo, $class_id, $subject_id, $date_from, $date_to);
$finalAssessments = [];
if((int)($assessmentContext['school_period_set_id'] ?? 0) > 0){
  $finalAssessments = final_assessment_existing_map(
    $pdo,
    $class_id,
    $subject_id,
    (int)$assessmentContext['school_period_set_id'],
    (string)($assessmentContext['scope'] ?? 'semester1')
  );
}
foreach($summaries as &$summaryRow){
  $summaryRow['final_assessment'] = $finalAssessments[(int)$summaryRow['student_id']] ?? null;
}
unset($summaryRow);
if($student_id){
  $summaries = array_values(array_filter($summaries, static fn(array $row): bool => (int)$row['student_id'] === $student_id));
}

$hasSpecial = false;
foreach($summaries as $summary){
  if(((int)$summary['written_count']) > 0 || ((int)$summary['oral_count']) > 0){
    $hasSpecial = true;
    break;
  }
}

$pdf = new SimplePdfDocument('landscape');
$pdf->setFooterText(report_eval_legal_note($hasSpecial), 8);
$pdf->heading('COOL-Grades – Auswertung pro Schüler:in', 18);
$pdf->paragraph(
  'Die Tabelle dient als transparente Entscheidungshilfe für die Semesterbeurteilung. Sie bündelt dokumentierte Mitarbeit, besondere mündliche Leistungsfeststellungen und besondere schriftliche Leistungsfeststellungen, ohne die pädagogische Endentscheidung vorwegzunehmen.',
  10,
  'regular',
  [71,84,103],
  6
);
$pdf->kvGrid([
  'Klasse' => $className,
  'Fach' => $subjectCode.' – '.$subjectName,
  'Zeitraum' => ($resolvedPeriod['label'] ?? 'Zeitraum').' · '.($date_from ?: '–').' bis '.($date_to ?: '–'),
  'Schularbeitsfach' => $subjectContext['status_label'],
  'Erstellt am' => date('d.m.Y H:i'),
]);

$pdf->boxedSection(
  'Fachstatus',
  [$subjectContext['note']],
  [248,250,252],
  [207,214,223]
);

$pdf->boxedSection(
  'LBV-Hinweis',
  [
    'Die Auswertung orientiert sich insbesondere an §§ 3, 4 und 11 LBV; besondere mündliche und schriftliche Leistungsfeststellungen werden – sofern vorhanden – gesondert ausgewiesen.',
    'Ein Notenvorschlag wird nur als nachvollziehbare Entscheidungshilfe dargestellt. Die abschließende Leistungsbeurteilung bleibt pädagogische Aufgabe der Lehrkraft.',
  ],
  [248,250,252],
  [207,214,223]
);
$pdf->boxedSection(
  'Methodik des Mitarbeitsvorschlags',
  [
    'Grundlage sind ausschließlich die dokumentierten Mitarbeitseinträge im gewählten Zeitraum.',
    'Ab 3 dokumentierten Tagen oder 3 verwertbaren Einträgen ist eine erste Einschätzung möglich; ab 6 dokumentierten Tagen gilt die Datenbasis in der Regel als gut.',
    'Bei positiver Tendenz ergibt sich eher ein Vorschlag 1 oder 2, bei gemischter Datenlage eher 3, bei kritischer Tendenz eher 4 oder 5.',
  ],
  [248,250,252],
  [207,214,223]
);

if(!$summaries){
  $pdf->boxedSection('Keine Daten', ['Für die gewählte Klasse, das Fach und den Zeitraum liegen keine auswertbaren Schüler:innendaten vor.'], [253,248,235], [214,192,141]);
  $safeSubject = preg_replace('/[^A-Za-z0-9_-]+/', '-', $subjectCode);
  $safeClass = preg_replace('/[^A-Za-z0-9_-]+/', '-', $className);
  $pdf->output('cool-grades-auswertung-'.$safeSubject.'-'.$safeClass.'.pdf');
  exit;
}

$headers = [
  'Schüler:in',
  'Mitarbeit',
  'Bes. mündl.',
  'Bes. schriftl.',
  'Vorschläge',
  'Endnote',
  'Hinweis / Kommentare',
];
$widths = [95,112,104,112,92,88,150];
$rows = [];
foreach($summaries as $summary){
  $qualityCell = $summary['quality']['label'];
  if($summary['quality']['avg'] !== null){
    $qualityCell .= ' (Ø '.number_format((float)$summary['quality']['avg'], 2, ',', '.').')';
  }
  $final = $summary['final_assessment'] ?? null;
  $finalGrade = $final ? final_assessment_grade_label($final['final_grade'] !== null ? (int)$final['final_grade'] : null) : 'noch nicht gespeichert';
  $finalStatus = $final ? final_assessment_status_label((string)$final['status']) : 'offen';
  $finalSuggestion = $final ? trim((string)($final['suggestion_label'] ?? '')) : '';
  if($finalSuggestion === '') $finalSuggestion = '–';
  $rows[] = [
    $summary['student_name'],
    (int)$summary['participation_count'].' Eintr. · '.(int)($summary['documented_day_count'] ?? count($summary['distinct_dates'])).' Tg. · pos '.$summary['positive_count'].' / neutral '.$summary['neutral_count'].' / neg '.$summary['negative_count'].' · '.$qualityCell.' · '.report_eval_data_basis_level_label($summary['data_basis']),
    $summary['oral_text'],
    $summary['written_text'],
    'Mitarbeit: '.$summary['note_proposal']['label'].' · Abschluss: '.$finalSuggestion,
    $finalGrade.' · '.$finalStatus,
    $summary['semester_hint'].' · Kriterien: '.$summary['top_criteria'].' · Kommentare: '.$summary['comments_text'],
  ];
}

$pdf->heading('Haupttabelle', 14);
$pdf->table($headers, $rows, $widths, [
  'header_size' => 8,
  'body_size' => 7,
  'line_height' => 9,
  'padding' => 3.5,
  'header_height' => 20,
  'repeat_header' => true,
]);

$selectedSummary = $summaries[0] ?? null;
if($student_id && $selectedSummary){
  $proposal = $selectedSummary['note_proposal'];
  $recommendationLines = [
    'Datenbasis: '.report_eval_data_basis_level_label($selectedSummary['data_basis']),
    'Notenvorschlag Mitarbeit: '.$proposal['label'],
    $proposal['explanation'],
    'Hinweis für die Semesterbeurteilung: '.$selectedSummary['semester_hint'],
    'Wichtige Kriterien / Anlässe: '.$selectedSummary['top_criteria'],
  ];
  $pdf->boxedSection(
    'Kurz-Auswertung & Empfehlung',
    $recommendationLines,
    [238,248,236],
    [185,214,177]
  );

  $participationLines = $selectedSummary['participation_details'] ?: ['Keine kurzen Detailhinweise zur Mitarbeit im gewählten Zeitraum.'];
  $pdf->boxedSection('Mitarbeit – ausgewählte Detailhinweise', $participationLines, [248,250,252], [207,214,223]);

  $writtenLines = [];
  foreach($selectedSummary['written_rows'] as $row){
    $line = (string)$row['exam_date'].' · '.written_assessment_type_label((string)($row['exam_type'] ?? 'SA')).' · '.(string)$row['title'];
    $line .= ' · '.report_eval_grade_symbol((int)$row['grade'], (string)($row['tendency'] ?? ''));
    $remark = trim((string)($row['remark'] ?? ''));
    if($remark !== '') $line .= ' — '.report_eval_clip($remark, 100);
    $writtenLines[] = $line;
  }
  $pdf->boxedSection(
    'Besondere schriftliche Leistungsfeststellungen',
    $writtenLines ?: ['Keine besonderen schriftlichen Leistungsfeststellungen im gewählten Zeitraum.'],
    [255,248,235],
    [214,192,141]
  );

  $oralLines = [];
  foreach($selectedSummary['oral_rows'] as $row){
    $type = oral_assessment_normalize_type((string)($row['assessment_type'] ?? ''));
    $line = (string)$row['assessment_date'].' · '.oral_assessment_type_label($type).' · '.trim((string)($row['impact_label'] ?? '–'));
    $primary = $type === 'ORAL_EXAM' ? trim((string)($row['topic_area'] ?? '')) : trim((string)($row['category'] ?? ''));
    $secondary = $type === 'ORAL_EXAM' ? trim((string)($row['questions'] ?? '')) : trim((string)($row['title'] ?? ''));
    if($primary !== '') $line .= ' · '.$primary;
    if($secondary !== '') $line .= ' — '.report_eval_clip($secondary, 100);
    $oralLines[] = $line;
  }
  $pdf->boxedSection(
    'Besondere mündliche Leistungsfeststellungen',
    $oralLines ?: ['Keine besonderen mündlichen Leistungsfeststellungen im gewählten Zeitraum.'],
    [238,249,251],
    [169,213,213]
  );
} else {
  $notable = [];
  foreach($summaries as $summary){
    if(($summary['note_proposal']['value'] ?? null) === null || ($summary['note_proposal']['tone'] ?? 'neutral') === 'critical' || $summary['comments_text'] !== '–'){
      $notable[] = $summary['student_name'].': '.$summary['semester_hint'];
    }
    if(count($notable) >= 8) break;
  }
  if($notable){
    $pdf->boxedSection(
      'Auffällige Hinweise',
      $notable,
      [248,250,252],
      [207,214,223]
    );
  }
}

$safeTarget = $student_id && $selectedSummary
  ? preg_replace('/[^A-Za-z0-9_-]+/', '-', $selectedSummary['student_name'])
  : preg_replace('/[^A-Za-z0-9_-]+/', '-', $className);
$safeSubject = preg_replace('/[^A-Za-z0-9_-]+/', '-', $subjectCode);
$pdf->output('cool-grades-auswertung-'.$safeSubject.'-'.$safeTarget.'.pdf');
