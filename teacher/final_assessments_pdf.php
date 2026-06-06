<?php
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/simple_pdf.php';
require_once __DIR__.'/../lib/final_assessments.php';

$u = require_role('teacher');
$pdo = db();

$class_id = (int)($_GET['class_id'] ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);
$school_period_set_id = (int)($_GET['school_period_set_id'] ?? 0);
$scope = (string)($_GET['scope'] ?? 'semester1');
if(!isset(final_assessment_scope_options()[$scope])) $scope = 'semester1';

if(!$class_id || !$subject_id || !$school_period_set_id){
  http_response_code(400);
  exit('Bitte Klasse, Fach und Schuljahr wählen.');
}
require_teacher_assignment($u, $class_id, $subject_id);

$periodSet = app_school_period_find($school_period_set_id, true);
if(!$periodSet){
  http_response_code(404);
  exit('Schuljahr nicht gefunden.');
}

$st = $pdo->prepare("SELECT name FROM classes WHERE id=? LIMIT 1");
$st->execute([$class_id]);
$className = (string)($st->fetchColumn() ?: ('#'.$class_id));

$st = $pdo->prepare("SELECT code,name FROM subjects WHERE id=? LIMIT 1");
$st->execute([$subject_id]);
$subjectRow = $st->fetch(PDO::FETCH_ASSOC) ?: ['code' => '#'.$subject_id, 'name' => ''];

$data = final_assessment_build_rows($pdo, $class_id, $subject_id, $periodSet, $scope);
$periodMeta = $data['period_meta'];
$subjectContext = $data['subject_context'];
$classContext = $data['class_context'];
$rows = $data['rows'];

$pdf = new SimplePdfDocument('landscape');
$pdf->setFooterText(
  'Diese Übersicht dokumentiert die von der Lehrkraft festgelegte Abschlussbeurteilung. Die angezeigten Notenvorschläge dienen ausschließlich als pädagogische Entscheidungshilfe. Die endgültige Leistungsbeurteilung erfolgt durch die Lehrkraft auf Grundlage der Leistungsbeurteilungsverordnung, des Lehrplans, des Unterrichtsverlaufs und der dokumentierten Leistungsfeststellungen. Je nach Beurteilungssystem der Klasse sind Semesterergebnisse und Jahresdaten entsprechend zu berücksichtigen.',
  8
);

$pdf->heading('COOL-Grades – Abschlussbeurteilung', 18);
$pdf->paragraph(
  'Die Übersicht zeigt die zusammengeführte Entscheidungsgrundlage pro Schüler:in und dokumentiert die von der Lehrkraft gewählte finale Semester- oder Jahresbeurteilung. Der Notenvorschlag bleibt unverbindliche pädagogische Entscheidungshilfe.',
  10,
  'regular',
  [71,84,103],
  6
);
$pdf->kvGrid([
  'Klasse' => $className,
  'Fach' => (string)$subjectRow['code'].' – '.(string)$subjectRow['name'],
  'Schuljahr' => (string)$periodMeta['school_year_label'],
  'Zeitraum' => (string)$periodMeta['assessment_label'],
  'Beurteilungssystem' => (string)$classContext['label'],
  'Schularbeitsfach' => (string)$subjectContext['status_label'],
  'Lehrkraft' => trim((string)$u['first_name'].' '.(string)$u['last_name']),
  'Erstellt am' => date('d.m.Y H:i'),
]);

$pdf->boxedSection(
  'Hinweis zum Beurteilungssystem',
  [(string)$classContext['note']],
  [248,250,252],
  [207,214,223]
);

$pdf->boxedSection(
  'Hinweis zum Fachstatus',
  [$subjectContext['note']],
  [248,250,252],
  [207,214,223]
);

$pdf->boxedSection(
  'Web-Hinweis in Berichtsfassung',
  ['Die App unterstützt Sie bei der Zusammenführung von Mitarbeit, besonderen mündlichen und besonderen schriftlichen Leistungsfeststellungen. Der Notenvorschlag ist nicht verbindlich. Die finale Semester- oder Jahresnote wird von Ihnen als Lehrkraft festgelegt.'],
  [248,250,252],
  [207,214,223]
);

$pdf->boxedSection(
  'LBV-Hinweis (§ 11 und § 14)',
  ['Die abschließende Leistungsbeurteilung ist aus den vorgesehenen Formen der Leistungsfeststellung, unter Bedachtnahme auf Lehrplan und Unterrichtsstand, sachlich und gerecht zu gewinnen. Die Beurteilungsstufe wird von der Lehrkraft festgelegt.'],
  [248,250,252],
  [207,214,223]
);

if(!$rows){
  $pdf->boxedSection(
    'Keine Daten',
    ['Für die gewählte Klasse, das Fach und das Schuljahr liegen keine auswertbaren Schüler:innendaten vor.'],
    [253,248,235],
    [214,192,141]
  );
  $pdf->output('cool-grades-abschlussbeurteilung.pdf');
  exit;
}

$headers = ['Schueler:in'];
$widths = [86];
if($scope === 'semester2' || $scope === 'year'){
  $headers[] = '1. Sem.';
  $widths[] = 54;
}
if($scope === 'year'){
  $headers[] = '2. Sem.';
  $widths[] = 54;
}
$headers = array_merge($headers, ['Datenbasis','Mitarbeit','Tage','Bes. muendl.','Bes. schriftl.','Schularb.-Hinweis','Notenvorschlag','Finale Note','Kommentar','Status']);
$widths = array_merge($widths, [54,50,32,62,62,72,64,48,116,44]);
$tableRows = [];
foreach($rows as $row){
  $summary = $row['summary'];
  $existing = $row['existing'];
  $proposal = $row['proposal'];
  $rowValues = [$row['student_name']];
  $semesterContext = $row['semester_context'] ?? [];
  if($scope === 'semester2' || $scope === 'year'){
    $sem1 = $semesterContext['semester1_saved'] ?? null;
    $rowValues[] = $sem1 ? final_assessment_grade_label($sem1['final_grade'] !== null ? (int)$sem1['final_grade'] : null) : 'keine 1.-Sem.';
  }
  if($scope === 'year'){
    $sem2 = $semesterContext['semester2_saved'] ?? null;
    $rowValues[] = $sem2 ? final_assessment_grade_label($sem2['final_grade'] !== null ? (int)$sem2['final_grade'] : null) : 'keine 2.-Sem.';
  }
  $rowValues = array_merge($rowValues, [
    report_eval_data_basis_level_label($summary['data_basis']),
    $summary['positive_neutral_negative'],
    (string)$summary['documented_day_count'],
    $summary['oral_text'],
    $summary['written_text'],
    $subjectContext['short_note'],
    $proposal['label'],
    $existing ? final_assessment_grade_label($existing['final_grade'] !== null ? (int)$existing['final_grade'] : null) : 'noch nicht festgelegt',
    $existing ? report_eval_clip((string)($existing['teacher_comment'] ?? ''), 120) : '-',
    $existing ? final_assessment_status_label((string)$existing['status']) : 'offen',
  ]);
  $tableRows[] = $rowValues;
}

$pdf->heading('Tabelle pro Schüler:in', 14);
$pdf->table($headers, $tableRows, $widths, [
  'header_size' => 8,
  'body_size' => 7,
  'line_height' => 9,
  'padding' => 3.5,
  'header_height' => 20,
  'repeat_header' => true,
]);

$detailLines = [];
foreach($rows as $row){
  $summary = $row['summary'];
  $existing = $row['existing'];
  $proposal = $row['proposal'];
  $line = $row['student_name'].': '.$proposal['label'].' · '.$summary['semester_hint'];
  if($existing && $existing['final_grade'] !== null){
    $line .= ' · finale Note '.final_assessment_grade_label((int)$existing['final_grade']);
  }
  if($scope === 'year' && !empty($row['year_trend']['label'])){
    $line .= ' · '.(string)$row['year_trend']['label'];
  }
  $detailLines[] = $line;
  if(count($detailLines) >= 8) break;
}
$pdf->boxedSection(
  'Kurze pädagogische Hinweise',
  $detailLines,
  [248,250,252],
  [207,214,223]
);

$pdf->output('cool-grades-abschlussbeurteilung-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$subjectRow['code']).'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $className).'.pdf');
