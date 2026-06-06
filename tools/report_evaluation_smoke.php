<?php
require_once __DIR__.'/../lib/report_evaluation.php';

function smoke_assert_true(bool $condition, string $message): void {
  if(!$condition){
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function smoke_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual:   ".var_export($actual, true)."\n");
    exit(1);
  }
}

$positiveVariants = ['positiv (+)', '+', 'positive', 'POSITIVE', 'Eindruck: positiv (+)'];
foreach($positiveVariants as $variant){
  smoke_assert_same('positive', report_eval_rating_classification($variant), "Positive Variante wird nicht korrekt erkannt: {$variant}");
  smoke_assert_true((report_eval_rating_score($variant) ?? 0) > 0, "Positive Variante liefert keinen positiven Score: {$variant}");
}

smoke_assert_same('Schriftliche Wiederholung', written_assessment_type_label('REVIEW'), 'REVIEW muss als schriftliche Wiederholung angezeigt werden.');
smoke_assert_same('Arbeitsauftrag', written_assessment_type_label('TASK'), 'TASK muss als Arbeitsauftrag angezeigt werden.');
smoke_assert_same('Sonstige schriftliche Leistung', written_assessment_type_label('OTHER'), 'OTHER muss als sonstige schriftliche Leistung angezeigt werden.');
smoke_assert_same('OTHER', written_assessment_normalize_type('foobar'), 'Unbekannte schriftliche Leistungsarten sollen sauber auf OTHER fallen.');

$basisGood = report_eval_data_basis(8, 7);
smoke_assert_same('good', $basisGood['level'], '8 Einträge an 7 Tagen müssen eine gute Datenbasis ergeben.');
smoke_assert_true($basisGood['can_estimate'] === true, '8 Einträge an 7 Tagen müssen auswertbar sein.');
smoke_assert_same('gut', report_eval_data_basis_level_label($basisGood), 'Die Anzeige der Datenbasis soll das fachliche Label knapp benennen.');
smoke_assert_same('Datenbasis: gut', report_eval_data_basis_display($basisGood), 'Die UI-Anzeige soll klar als Datenbasis erkennbar sein.');

$basisEnough = report_eval_data_basis(3, 3);
smoke_assert_same('enough', $basisEnough['level'], '3 Einträge an 3 Tagen müssen eine Einschätzung ermöglichen.');
smoke_assert_true($basisEnough['can_estimate'] === true, '3 Einträge an 3 Tagen müssen auswertbar sein.');

$basisThin = report_eval_data_basis(2, 2);
smoke_assert_same('thin', $basisThin['level'], '2 Einträge müssen als dünne Datenlage gelten.');
smoke_assert_true($basisThin['can_estimate'] === false, '2 Einträge dürfen keinen sicheren Vorschlag erzeugen.');
smoke_assert_same('Datenbasis: dünn', report_eval_data_basis_display($basisThin), 'Dünne Datenlage soll in der UI als Datenbasis und nicht als Schüler:innenurteil erscheinen.');

$basisNone = report_eval_data_basis(0, 0);
smoke_assert_same('none', $basisNone['level'], '0 Einträge müssen als keine Daten gelten.');

$proposalStrong = report_eval_note_proposal(8, 7, array_fill(0, 8, 1), 8, 0, 0, 0);
smoke_assert_true($proposalStrong['value'] !== null, '8 positive Einträge an 7 Tagen dürfen nicht als "zu wenig Daten" enden.');

$proposalEnough = report_eval_note_proposal(3, 3, [1, 1, 1], 3, 0, 0, 0);
smoke_assert_true($proposalEnough['value'] !== null, '3 positive Einträge an 3 Tagen müssen einen Vorschlag erlauben.');

$proposalThin = report_eval_note_proposal(2, 2, [1, 1], 2, 0, 0, 0);
smoke_assert_same(null, $proposalThin['value'], '2 Einträge sollen keinen sicheren Vorschlag ergeben.');
smoke_assert_same('Datenlage noch dünn', $proposalThin['label'], '2 Einträge sollen als dünne Datenlage gekennzeichnet werden.');

$subjectYes = report_eval_subject_context_from_row(['id' => 1, 'code' => 'D', 'name' => 'Deutsch', 'is_schularbeit_subject' => 1]);
smoke_assert_same('yes', $subjectYes['status'], 'Schularbeitsfach=1 muss als yes erkannt werden.');

$subjectNo = report_eval_subject_context_from_row(['id' => 2, 'code' => 'GSK', 'name' => 'Geschichte', 'is_schularbeit_subject' => 0]);
smoke_assert_same('no', $subjectNo['status'], 'Schularbeitsfach=0 muss als no erkannt werden.');

$subjectUnset = report_eval_subject_context_from_row(['id' => 3, 'code' => 'AM', 'name' => 'Angewandte Mathematik', 'is_schularbeit_subject' => null]);
smoke_assert_same('unset', $subjectUnset['status'], 'Leerer Fachstatus muss als unset erkannt werden.');

$semesterHint = report_eval_semester_hint([
  'note_proposal' => ['value' => 2],
  'data_basis' => ['level' => 'enough'],
  'negative_count' => 0,
  'participation_count' => 3,
  'oral_positive_count' => 0,
  'oral_negative_count' => 0,
  'written_avg' => null,
], $subjectNo);
smoke_assert_true(stripos($semesterHint, 'schriftliche Sonderleistung') === false, 'Fehlende schriftliche Leistungen dürfen in Nicht-Schularbeitsfächern nicht negativ kommentiert werden.');

$semesterHintSa = report_eval_semester_hint([
  'note_proposal' => ['value' => 2],
  'data_basis' => ['level' => 'good'],
  'negative_count' => 0,
  'participation_count' => 8,
  'oral_positive_count' => 0,
  'oral_negative_count' => 0,
  'written_avg' => null,
], $subjectYes);
smoke_assert_true(stripos($semesterHintSa, 'Schularbeitsleistungen gesondert berücksichtigen') !== false, 'Schularbeitsfächer müssen einen gesonderten Hinweis tragen.');

echo "OK: report_evaluation smoke tests passed.\n";
