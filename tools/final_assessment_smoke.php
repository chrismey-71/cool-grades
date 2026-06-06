<?php
require_once __DIR__.'/../lib/final_assessments.php';

function fa_smoke_assert_true(bool $condition, string $message): void {
  if(!$condition){
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function fa_smoke_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual:   ".var_export($actual, true)."\n");
    exit(1);
  }
}

function sample_summary(array $overrides = []): array {
  return array_replace_recursive([
    'student_id' => 1,
    'student_name' => 'Muster, Anna',
    'participation_count' => 8,
    'documented_day_count' => 7,
    'positive_count' => 8,
    'neutral_count' => 0,
    'negative_count' => 0,
    'unrated_count' => 0,
    'positive_neutral_negative' => '8 / 0 / 0',
    'top_criteria' => 'aktive Beteiligung · Transfer',
    'comments_text' => '–',
    'quality' => ['label' => 'deutlich positiv', 'avg' => 1.25],
    'data_basis' => report_eval_data_basis(8, 7),
    'note_proposal' => ['value' => 2, 'label' => 'Vorschlag 2', 'explanation' => '8 Einträge an 7 Tagen'],
    'written_count' => 0,
    'written_avg' => null,
    'written_text' => '–',
    'written_type_counts' => [],
    'oral_count' => 0,
    'oral_text' => '–',
    'oral_positive_count' => 0,
    'oral_neutral_count' => 0,
    'oral_negative_count' => 0,
    'semester_hint' => 'stabile positive Mitarbeit',
  ], $overrides);
}

fa_smoke_assert_same('SOST - semestrierte Oberstufe', class_assessment_system_label('sost'), 'SOST muss als Beurteilungssystem korrekt benannt werden.');
fa_smoke_assert_same('NOST - neue Oberstufe / semestrierte Beurteilung', class_assessment_system_label('nost'), 'NOST muss als Beurteilungssystem korrekt benannt werden.');
fa_smoke_assert_same('Jahresbeurteilung - ganzjährige Beurteilung / Jahrgangsmodell', class_assessment_system_label('yearly'), 'Jahresbeurteilung muss als Beurteilungssystem korrekt benannt werden.');
fa_smoke_assert_true(stripos(class_assessment_system_note(null), 'noch kein Beurteilungssystem') !== false, 'Bei fehlender Klassenkonfiguration muss ein Prüfhinweis erscheinen.');

$scopeOptions = final_assessment_scope_options();
fa_smoke_assert_same('1. Semesterbeurteilung festlegen', $scopeOptions['semester1'] ?? null, 'Die Beschriftung für Semester 1 muss klar sein.');
fa_smoke_assert_same('2. Semesterbeurteilung festlegen', $scopeOptions['semester2'] ?? null, 'Die Beschriftung für Semester 2 muss klar sein.');
fa_smoke_assert_same('Jahresbeurteilung festlegen', $scopeOptions['year'] ?? null, 'Die Beschriftung für die Jahresbeurteilung muss klar sein.');
fa_smoke_assert_true(stripos(final_assessment_scope_help('semester2'), '1. Semesters') !== false, 'Die Hilfe für Semester 2 muss auf die Orientierung am 1. Semester hinweisen.');
fa_smoke_assert_same('semester2', final_assessment_default_scope(null, '2026-05-14'), 'Im Mai soll ohne explizite Auswahl das 2. Semester vorausgewählt werden.');
fa_smoke_assert_same('semester1', final_assessment_default_scope(null, '2026-11-14'), 'Im November soll ohne explizite Auswahl das 1. Semester vorausgewählt werden.');
fa_smoke_assert_same('semester1', final_assessment_default_scope([
  'semester1_from' => '2025-09-01',
  'semester1_to' => '2026-01-31',
  'semester2_from' => '2026-02-01',
  'semester2_to' => '2026-08-31',
], '2026-01-10'), 'Wenn konkrete Semesterdaten vorhanden sind, soll die Schuljahreslogik verwendet werden.');
fa_smoke_assert_same(2, final_assessment_default_period_set_id([
  ['id' => 3, 'semester1_from' => '2026-09-01', 'semester2_to' => '2027-08-31'],
  ['id' => 2, 'semester1_from' => '2025-09-01', 'semester2_to' => '2026-08-31'],
], '2026-05-14'), 'Das aktuelle Schuljahr soll anhand der gespeicherten Zeitraumgrenzen vorausgewählt werden.');
fa_smoke_assert_same(3, final_assessment_default_period_set_id([
  ['id' => 3, 'semester1_from' => '2026-09-01', 'semester2_to' => '2027-08-31'],
  ['id' => 2, 'semester1_from' => '2025-09-01', 'semester2_to' => '2026-08-31'],
], '2026-09-10'), 'Bei mehreren Schuljahren soll das zum Datum passende Schuljahr gewählt werden.');
fa_smoke_assert_same(5, final_assessment_default_period_set_id([
  ['id' => 5, 'semester1_from' => '2024-09-01', 'semester2_to' => '2025-08-31'],
], '2030-01-01'), 'Wenn kein Zeitraum zum Datum passt, soll das neueste aktive Schuljahr als Fallback dienen.');
fa_smoke_assert_same(0, final_assessment_default_period_set_id([], '2026-05-14'), 'Ohne Schuljahre soll keine Vorauswahl erzwungen werden.');
fa_smoke_assert_same(12, final_assessment_next_student_id([
  ['student_id' => 10],
  ['student_id' => 12],
  ['student_id' => 14],
], 10), 'Nach dem Speichern soll der nächste Schüler der aktuellen Sortierung geöffnet werden.');
fa_smoke_assert_same(null, final_assessment_next_student_id([
  ['student_id' => 10],
  ['student_id' => 12],
], 12), 'Am Ende der Liste soll kein nächster Schüler erzwungen werden.');

$subjectSa = [
  'status' => 'yes',
  'status_label' => 'Ja',
  'tone' => 'neutral',
  'short_note' => 'Schularbeitsleistungen sind gesondert zu berücksichtigen.',
  'note' => 'Hinweis Schularbeitsfach',
];

$subjectNoSa = [
  'status' => 'no',
  'status_label' => 'Nein',
  'tone' => 'positive',
  'short_note' => 'Die Auswertung stützt sich auf dokumentierte Mitarbeit und erfasste besondere Leistungsfeststellungen.',
  'note' => 'Hinweis Nicht-Schularbeitsfach',
];

$proposalGood = final_assessment_compute_proposal(sample_summary(), $subjectNoSa, 'semester2');
fa_smoke_assert_same(2, $proposalGood['value'], '8 positive Einträge aus 7 Tagen sollen einen positiven Vorschlag behalten.');
fa_smoke_assert_true(stripos($proposalGood['label'], 'Notenvorschlag') !== false, 'Der Vorschlag soll als Notenvorschlag bezeichnet werden.');

$proposalEnough = final_assessment_compute_proposal(sample_summary([
  'participation_count' => 3,
  'documented_day_count' => 3,
  'positive_count' => 3,
  'positive_neutral_negative' => '3 / 0 / 0',
  'data_basis' => report_eval_data_basis(3, 3),
  'note_proposal' => ['value' => 2, 'label' => 'Vorschlag 2', 'explanation' => '3 Einträge an 3 Tagen'],
]), $subjectNoSa, 'semester2');
fa_smoke_assert_true($proposalEnough['value'] !== null, 'Ab 3 Einträgen oder Tagen soll eine erste Einschätzung möglich sein.');

$proposalThin = final_assessment_compute_proposal(sample_summary([
  'participation_count' => 1,
  'documented_day_count' => 1,
  'positive_count' => 1,
  'positive_neutral_negative' => '1 / 0 / 0',
  'data_basis' => report_eval_data_basis(1, 1),
  'note_proposal' => ['value' => null, 'label' => 'Datenlage noch dünn', 'explanation' => '1 Eintrag an 1 Tag'],
]), $subjectNoSa, 'semester2');
fa_smoke_assert_same(null, $proposalThin['value'], 'Bei dünner Datenlage darf kein harter Vorschlag erzwungen werden.');
fa_smoke_assert_true(stripos($proposalThin['label'], 'Datenlage') !== false || stripos($proposalThin['label'], 'prüfen') !== false, 'Bei dünner Datenlage soll ein Prüfhinweis erscheinen.');

$proposalNoTest = final_assessment_compute_proposal(sample_summary([
  'written_count' => 0,
  'written_avg' => null,
  'written_text' => '–',
]), $subjectNoSa, 'semester2');
fa_smoke_assert_true(stripos($proposalNoTest['explanation'], 'nicht negativ') !== false, 'Fehlende Tests in Nicht-Schularbeitsfächern dürfen nicht automatisch negativ wirken.');

$proposalSa = final_assessment_compute_proposal(sample_summary(), $subjectSa, 'semester2');
fa_smoke_assert_true(stripos($proposalSa['explanation'], 'Schularbeitsleistungen') !== false, 'Schularbeitsfächer müssen einen gesonderten Hinweis im Vorschlag tragen.');

$trendImprove = final_assessment_year_trend(
  ['note_proposal' => ['value' => 4]],
  ['note_proposal' => ['value' => 2]],
  null,
  null
);
fa_smoke_assert_same('deutliche Verbesserung im 2. Semester', $trendImprove['label'], 'Eine starke Verbesserung zwischen den Semestern soll sichtbar werden.');

$trendMixed = final_assessment_year_trend(
  ['note_proposal' => ['value' => 2]],
  ['note_proposal' => ['value' => 2]],
  null,
  null
);
fa_smoke_assert_true(in_array($trendMixed['label'], ['Jahrestendenz stabil positiv', 'Semesterleistungen uneinheitlich'], true), 'Eine Jahresbeurteilung darf nicht blind gemittelt werden, sondern soll eine Tendenz zeigen.');

echo "OK: final_assessment smoke tests passed.\n";
