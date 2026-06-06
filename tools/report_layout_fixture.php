<?php
require_once __DIR__.'/../lib/simple_pdf.php';
require_once __DIR__.'/../lib/helpers.php';
require_once __DIR__.'/../lib/assessment_summaries.php';

$root = dirname(__DIR__, 2);
$outputDir = $root . '/output';
if(!is_dir($outputDir)){
  mkdir($outputDir, 0777, true);
}

$rows = [
  [
    'student' => 'Muster, Anna',
    'count' => '8',
    'days' => '7 Tage',
    'basis' => 'gute Datenbasis',
    'pnn' => '8 / 0 / 0',
    'quality' => 'deutlich positiv',
    'quality_avg' => 'Ø 1,25',
    'criteria' => 'Verstehen · Argumentieren · Arbeitsweise',
    'oral' => '2: positiv (+), positiv (+)',
    'written' => '3: SA 2, Test 2+, Auftrag 1',
    'comments' => 'arbeitet verlässlich mit | starke Sicherungsphase',
    'proposal' => 'Vorschlag 1',
    'semester' => 'stabile positive Mitarbeit · Schularbeitsleistungen gesondert berücksichtigen',
    'row_class' => '',
    'basis_tone' => 'positive',
    'quality_tone' => 'positive',
    'proposal_tone' => 'positive',
  ],
  [
    'student' => 'Beispiel, Elias',
    'count' => '3',
    'days' => '3 Tage',
    'basis' => 'Einschätzung möglich',
    'pnn' => '2 / 1 / 0',
    'quality' => 'überw. positiv',
    'quality_avg' => 'Ø 0,67',
    'criteria' => 'Transfer · Arbeitsauftrag · Kooperation',
    'oral' => '1: neutral',
    'written' => '1: Wdh. 3',
    'comments' => 'kurze Unsicherheit bei Transfer',
    'proposal' => 'Vorschlag 2',
    'semester' => 'Gesamtbild pädagogisch würdigen',
    'row_class' => '',
    'basis_tone' => 'positive',
    'quality_tone' => 'positive',
    'proposal_tone' => 'positive',
  ],
  [
    'student' => 'Probe, Mia',
    'count' => '2',
    'days' => '2 Tage',
    'basis' => 'Datenlage noch dünn',
    'pnn' => '1 / 0 / 1',
    'quality' => 'gemischt',
    'quality_avg' => 'Ø 0,00',
    'criteria' => 'Hausübungen · Mitarbeit',
    'oral' => '–',
    'written' => '–',
    'comments' => 'Datenlage noch ausbauen',
    'proposal' => 'Datenlage noch dünn',
    'semester' => 'Einschätzung vorsichtig verwenden',
    'row_class' => 'report-row-warning',
    'basis_tone' => 'neutral',
    'quality_tone' => 'neutral',
    'proposal_tone' => 'neutral',
  ],
  [
    'student' => 'Kritisch, Noah',
    'count' => '6',
    'days' => '6 Tage',
    'basis' => 'gute Datenbasis',
    'pnn' => '1 / 1 / 4',
    'quality' => 'überw. kritisch',
    'quality_avg' => 'Ø -0,83',
    'criteria' => 'Arbeitsweise · Wiederholung · Genauigkeit',
    'oral' => '1: negativ (-)',
    'written' => '2: Test 4, Sonst. 4-',
    'comments' => 'mehrere Lücken in Sicherungsphasen',
    'proposal' => 'Vorschlag 4',
    'semester' => 'kritische Mitarbeitslage · schriftliche Sonderleistung schwach',
    'row_class' => 'report-row-critical',
    'basis_tone' => 'positive',
    'quality_tone' => 'critical',
    'proposal_tone' => 'critical',
  ],
];

$styles = '';
foreach ([__DIR__ . '/../assets/styles.css', __DIR__ . '/../assets/app.css'] as $cssFile) {
  if (is_file($cssFile)) {
    $styles .= "\n" . file_get_contents($cssFile);
  }
}
$styles .= "\nbody{padding:20px;background:#f3f5f7}.wrap{max-width:1480px;margin:0 auto}.card{margin-bottom:16px}.report-summary-table td,.report-summary-table th{font-size:13px}.report-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;border:1px solid #cfd6df;font-weight:700}.report-chip.positive{background:#e9f7f0;color:#206a4e}.report-chip.neutral{background:#f5f7fa;color:#475467}.report-chip.critical{background:#fdecea;color:#9f2d2d}.report-row-warning{background:#fff8e1}.report-row-critical{background:#fdecec}.muted{color:#475467}.report-focus-block{border:1px solid #d7dde5;border-radius:14px;background:#fff;padding:14px;margin-top:12px}.report-kv{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.report-kv .item{border:1px solid #d7dde5;border-radius:12px;padding:10px;background:#fff}.report-kv .label{display:block;font-size:12px;color:#667085;margin-bottom:4px}";

$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>COOL-Grades Report Preview</title><style>'.$styles.'</style></head><body><div class="wrap">';
$html .= '<div class="card"><h1>Auswertungen</h1><div class="muted"><b>Filter:</b> 2A · RWCO · Zeitraum: 2. Semester 2025/26 (2026-02-01 bis 2026-06-30)</div></div>';
$html .= '<div class="report-focus-block"><strong>Fachstatus</strong><div class="muted" style="margin-top:6px">Fach: <b>RWCO – Rechnungswesen und Controlling</b> · Schularbeitsfach: <span class="report-chip neutral">Ja</span></div><div class="muted" style="margin-top:8px">Hinweis: Dieses Fach ist als Schularbeitsfach gekennzeichnet. Schularbeitsleistungen sind bei der abschließenden Beurteilung gesondert zu berücksichtigen.</div></div>';
$html .= '<div class="report-focus-block"><strong>LBV-orientierte Entscheidungshilfe</strong><div class="muted" style="margin-top:6px">Die Auswertung unterstützt die Beurteilung auf Basis der erfassten Mitarbeit und besonderer Leistungsfeststellungen. Die endgültige Note wird nicht automatisch festgelegt, sondern bleibt eine pädagogische Entscheidung der Lehrkraft gemäß LBV.</div></div>';
$html .= '<div style="height:14px"></div><h2>Zusammenfassende Auswertung pro Schüler:in</h2><div class="muted">Die Haupttabelle bündelt Mitarbeit, besondere mündliche und besondere schriftliche Leistungsfeststellungen zu einer kompakten Entscheidungsgrundlage.</div>';
$html .= '<table class="table report-summary-table" style="margin-top:12px"><thead><tr><th>Schüler:in</th><th>Anzahl Mitarbeit</th><th>positiv / neutral / negativ</th><th>Qualität der Mitarbeit</th><th>Wichtige Kriterien</th><th>Bes. mündlich</th><th>Bes. schriftlich</th><th>Kommentare / Auffälligkeiten</th><th>Notenvorschlag Mitarbeit</th><th>Hinweis Semesterbeurteilung</th></tr></thead><tbody>';
foreach($rows as $row){
  $html .= '<tr class="'.h($row['row_class']).'">';
  $html .= '<td><strong>'.h($row['student']).'</strong></td>';
  $html .= '<td><strong>'.h($row['count']).'</strong><div class="muted" style="font-size:12px">'.h($row['days']).'</div><div style="margin-top:4px"><span class="report-chip '.$row['basis_tone'].'">'.h($row['basis']).'</span></div></td>';
  $html .= '<td>'.h($row['pnn']).'</td>';
  $html .= '<td><span class="report-chip '.$row['quality_tone'].'">'.h($row['quality']).'</span><div class="muted" style="font-size:12px;margin-top:4px">'.h($row['quality_avg']).'</div></td>';
  $html .= '<td>'.h($row['criteria']).'</td>';
  $html .= '<td>'.h($row['oral']).'</td>';
  $html .= '<td>'.h($row['written']).'</td>';
  $html .= '<td>'.h($row['comments']).'</td>';
  $html .= '<td><span class="report-chip '.$row['proposal_tone'].'">'.h($row['proposal']).'</span></td>';
  $html .= '<td>'.h($row['semester']).'</td>';
  $html .= '</tr>';
}
$html .= '</tbody></table>';
$html .= '<div class="report-focus-block report-recommendation"><h2 style="margin-top:0">Kurz-Auswertung &amp; Empfehlung</h2><div class="muted">Datenlage: <b>gute Datenbasis</b>. 8 Einträge an 7 Tagen · positiv 8 / neutral 0 / negativ 0 · Durchschnitt 1,25. Notenvorschlag Mitarbeit: <b>Vorschlag 1</b>. Hinweis für die Semesterbeurteilung: <b>stabile positive Mitarbeit · Schularbeitsleistungen gesondert berücksichtigen</b>.</div></div>';
$html .= '</div></body></html>';

$htmlPath = $outputDir . '/cool-grades-report-preview-v1.54.html';
file_put_contents($htmlPath, $html);

$pdf = new SimplePdfDocument('landscape');
$pdf->setFooterText('Rechtlicher Hinweis: Diese Auswertung dient als pädagogische Entscheidungshilfe. Die endgültige Leistungsbeurteilung erfolgt durch die Lehrkraft.', 8);
$pdf->heading('COOL-Grades – Report Layout Preview', 18);
$pdf->kvGrid([
  'Klasse' => '2A',
  'Fach' => 'RWCO – Rechnungswesen und Controlling',
  'Zeitraum' => '2. Semester 2025/26 · 2026-02-01 bis 2026-06-30',
  'Schularbeitsfach' => 'Ja',
  'Erstellt am' => date('d.m.Y H:i'),
]);
$pdf->boxedSection('Fachstatus', ['Hinweis: Dieses Fach ist als Schularbeitsfach gekennzeichnet. Schularbeitsleistungen sind bei der abschließenden Beurteilung gesondert zu berücksichtigen.'], [248,250,252], [207,214,223]);
$pdf->heading('Haupttabelle', 14);
$headers = ['Schüler:in','Mitarb.','+ / = / -','Qualität','Kriterien','Bes. mündl.','Bes. schriftl.','Kommentare / Auffälligkeiten','Vorschlag','Semesterhilfe'];
$widths = [95,42,50,70,90,72,72,110,60,112];
$pdfRows = [];
foreach($rows as $row){
  $pdfRows[] = [
    $row['student'],
    $row['count'].' / '.$row['days'].' / '.$row['basis'],
    $row['pnn'],
    $row['quality'].' ('.$row['quality_avg'].')',
    $row['criteria'],
    $row['oral'],
    $row['written'],
    $row['comments'],
    $row['proposal'],
    $row['semester'],
  ];
}
$pdf->table($headers, $pdfRows, $widths, [
  'header_size' => 8,
  'body_size' => 7,
  'line_height' => 9,
  'padding' => 3.5,
  'header_height' => 20,
  'repeat_header' => true,
]);

$pdfPath = $outputDir . '/cool-grades-report-preview-v1.54.pdf';
$tmpOutput = fopen('php://temp', 'r+');
ob_start();
$pdf->output('cool-grades-report-preview-v1.54.pdf');
$pdfBinary = ob_get_clean();
file_put_contents($pdfPath, $pdfBinary);

echo "HTML: {$htmlPath}\nPDF: {$pdfPath}\n";
