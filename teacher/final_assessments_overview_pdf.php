<?php
require_once __DIR__.'/../lib/auth.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/simple_pdf.php';
require_once __DIR__.'/../lib/final_assessments.php';

$u = require_role('teacher');
$pdo = db();

$schoolPeriodSetId = (int)($_GET['school_period_set_id'] ?? 0);
$period = final_assessment_overview_period_normalize((string)($_GET['period'] ?? 'current'));
$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$statusFilter = final_assessment_overview_status_normalize((string)($_GET['status'] ?? 'all'));
$periodSet = $schoolPeriodSetId > 0 ? app_school_period_find($schoolPeriodSetId, true) : null;
if(!$periodSet){
  http_response_code(400);
  exit('Bitte ein gültiges Schuljahr auswählen.');
}

$overview = final_assessment_teacher_overview(
  $pdo,
  (int)$u['id'],
  $periodSet,
  $period,
  $classId,
  $subjectId,
  $statusFilter
);
$stats = $overview['stats'];
$completion = ((int)$stats['total'] > 0) ? (int)round(((int)$stats['final'] / (int)$stats['total']) * 100) : 0;
$teacherName = trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''));
$statusLabel = final_assessment_overview_status_options()[$statusFilter] ?? 'Alle Bearbeitungsstände';
$periodLabel = final_assessment_overview_period_options()[$period] ?? 'Aktueller Beurteilungszeitraum';

$pdf = new SimplePdfDocument('landscape');
$pdf->setFooterText(
  'Diese Übersicht dokumentiert gespeicherte Entwürfe und finale Abschlussbeurteilungen. Notenvorschläge dienen ausschließlich als pädagogische Entscheidungshilfe. Die endgültige Leistungsbeurteilung erfolgt durch die Lehrkraft gemäß LBV, Lehrplan und Unterrichtsverlauf.',
  8
);
$pdf->heading('COOL-Grades – Notenübersicht', 18);
$pdf->paragraph(
  'Gesamtübersicht der Abschlussbeurteilungen über alle ausgewählten Klassen und Fächer. Offene Zeilen kennzeichnen Schüler:innen, für die im gewählten Zeitraum noch keine Beurteilung gespeichert wurde.',
  10,
  'regular',
  [71,84,103],
  6
);
$pdf->kvGrid([
  'Schuljahr' => (string)($periodSet['label'] ?? ''),
  'Zeitraum' => $periodLabel,
  'Bearbeitungsstand' => $statusLabel,
  'Lehrkraft' => $teacherName !== '' ? $teacherName : (string)($u['username'] ?? ''),
  'Erstellt am' => date('d.m.Y H:i'),
  'Fortschritt' => $completion.' % final gespeichert',
]);
$pdf->boxedSection(
  'Bearbeitungsstand',
  [
    'Gesamt: '.(int)$stats['total'].' · Final: '.(int)$stats['final'].' · Entwürfe: '.(int)$stats['draft'].' · Offen: '.(int)$stats['open'],
    'Gespeicherte Noten: 1: '.(int)$stats['grade_counts'][1].' · 2: '.(int)$stats['grade_counts'][2].' · 3: '.(int)$stats['grade_counts'][3].' · 4: '.(int)$stats['grade_counts'][4].' · 5: '.(int)$stats['grade_counts'][5],
  ],
  [238,248,242],
  [174,211,188]
);

if((int)$overview['skipped_combinations'] > 0){
  $pdf->boxedSection(
    'Hinweis zur Zeitraumwahl',
    [(int)$overview['skipped_combinations'].' Klasse-Fach-Kombination(en) im Jahresmodell wurden nicht aufgenommen, weil dort keine eigenständige 2. Semesterbeurteilung geführt wird.'],
    [255,248,235],
    [214,192,141]
  );
}

if(empty($overview['groups'])){
  $pdf->boxedSection(
    'Keine passenden Einträge',
    ['Für die gewählten Filter wurden keine Schüler:innen bzw. Beurteilungsstände gefunden.'],
    [248,250,252],
    [207,214,223]
  );
} else {
  $pdf->advance(6);
  foreach($overview['groups'] as $group){
    $groupStats = $group['stats'];
    $pdf->heading((string)$group['class_name'].' · '.(string)$group['subject_code'].' – '.(string)$group['subject_name'], 13);
    $pdf->paragraph(
      (string)$group['scope_label'].' · '.(string)$group['assessment_system_label'].' · Final '.(int)$groupStats['final'].' / Entwurf '.(int)$groupStats['draft'].' / Offen '.(int)$groupStats['open'],
      9,
      'regular',
      [71,84,103],
      4
    );
    $tableRows = [];
    foreach($group['rows'] as $row){
      $assessment = $row['assessment'];
      $grade = ($assessment && $assessment['final_grade'] !== null) ? (int)$assessment['final_grade'] : null;
      $proposalData = (array)($row['proposal'] ?? []);
      $proposal = trim((string)($proposalData['label'] ?? '')) ?: '–';
      $comment = $assessment ? report_eval_clip((string)($assessment['teacher_comment'] ?? ''), 95) : '';
      $updated = $assessment ? trim((string)($assessment['updated_at'] ?? '')) : '';
      $tableRows[] = [
        (string)$row['student_name'],
        (string)$row['status_label'],
        $updated !== '' ? date('d.m.Y', strtotime($updated)) : '–',
        $grade !== null ? 'NOTE '.$grade.' · '.final_assessment_grade_label($grade) : 'noch nicht festgelegt',
        $proposal,
        $comment !== '' ? $comment : '–',
      ];
    }
    $pdf->table(
      ['Schüler:in','Status','Aktualisiert','GESPEICHERTE NOTE','Notenvorschlag (Hilfe)','Begründung / Kommentar'],
      $tableRows,
      [150,85,72,120,105,233],
      [
        'header_size' => 8,
        'body_size' => 8,
        'line_height' => 9,
        'padding' => 3.5,
        'header_height' => 20,
        'header_fill' => [229,241,235],
        'header_stroke' => [174,211,188],
        'repeat_header' => true,
      ]
    );
  }
}

$safeYear = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($periodSet['label'] ?? 'Schuljahr'));
$pdf->output('cool-grades-notenuebersicht-'.$safeYear.'.pdf');
