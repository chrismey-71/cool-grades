<?php
require_once __DIR__.'/../lib/assessment_weights.php';

function aw_assert_true(bool $condition, string $message): void {
  if(!$condition){
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

function aw_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual:   ".var_export($actual, true)."\n");
    exit(1);
  }
}

function aw_summary(array $overrides = []): array {
  return array_replace_recursive([
    'participation_count' => 8,
    'note_proposal' => ['value'=>2],
    'oral_rows' => [],
    'written_count' => 0,
    'written_avg' => null,
  ], $overrides);
}

$defaults = assessment_weight_settings_resolve(null, 'yearly');
aw_assert_same('default', $defaults['source'], 'Ohne Datensatz müssen Standardwerte verwendet werden.');
aw_assert_same(60.0, $defaults['configured']['participation'], 'Standardgewicht Mitarbeit muss 60 % sein.');
aw_assert_same(40.0, $defaults['configured']['first_semester'], 'Standardgewicht Schulnachricht muss 40 % sein.');

$partial = assessment_weight_settings_resolve([
  'participation_weight' => 50,
  'special_oral_weight' => null,
  'special_written_weight' => 25,
  'first_semester_to_annual_weight' => null,
  'current_year_to_annual_weight' => null,
], 'yearly');
aw_assert_same(20.0, $partial['configured']['oral'], 'Ein fehlender Einzelwert muss auf den Standard zurückfallen.');
aw_assert_true(!empty($partial['warnings']), 'Fallback und Normalisierung müssen transparent als Hinweis erscheinen.');

$invalid = assessment_weight_settings_validate([
  'participation_weight'=>60,
  'special_oral_weight'=>30,
  'special_written_weight'=>20,
], false);
aw_assert_true(isset($invalid['errors']['area_sum']), 'Das Formular muss Summen ungleich 100 % ablehnen.');

$participationOnly = assessment_weight_compute_area_proposal(aw_summary(), $defaults);
aw_assert_same(2, $participationOnly['value'], 'Ein vorhandener Mitarbeit-Bereichswert muss als Vorschlag nutzbar sein.');
aw_assert_same(100.0, round((float)$participationOnly['weighting']['effective']['participation'], 4), 'Ein allein vorhandener nicht-schriftlicher Bereich muss auf 100 % normalisiert werden.');
aw_assert_true(stripos(implode(' ', $participationOnly['weighting']['warnings']), 'nicht berücksichtigt') !== false, 'Ausgeschlossene Bereiche müssen erläutert werden.');

$participationOral = assessment_weight_compute_area_proposal(aw_summary([
  'oral_rows' => [
    ['impact_label'=>'positiv (+)'],
    ['impact_label'=>'unauffällig (~)'],
  ],
]), $defaults);
aw_assert_same(75.0, round((float)$participationOral['weighting']['effective']['participation'], 4), '60/20 muss bei fehlendem Schriftbereich zu 75/25 werden.');
aw_assert_same(25.0, round((float)$participationOral['weighting']['effective']['oral'], 4), '60/20 muss bei fehlendem Schriftbereich zu 75/25 werden.');
aw_assert_same(2.0, $participationOral['areas']['oral']['value'], 'Positiv plus unauffällig muss qualitativ zu einem positiven mündlichen Bereichswert verdichtet werden.');

$oralNeutral = assessment_weight_area_values(aw_summary([
  'participation_count'=>0,
  'note_proposal'=>['value'=>null],
  'oral_rows'=>[['impact_label'=>'unauffällig (~)']],
]));
aw_assert_same(3.0, $oralNeutral['oral']['value'], 'Unauffällig (~) muss als neutraler qualitativer Bereichswert 3 verdichtet werden.');

$writtenOnly = assessment_weight_compute_area_proposal(aw_summary([
  'participation_count'=>0,
  'note_proposal'=>['value'=>null],
  'written_count'=>2,
  'written_avg'=>2.5,
]), $defaults);
aw_assert_same(null, $writtenOnly['value'], 'Ausschließlich schriftliche Leistungen dürfen keinen normal wirkenden Notenvorschlag erzeugen.');
aw_assert_same(3, $writtenOnly['intermediate_grade'], 'Der rechnerische schriftliche Zwischenwert darf intern transparent gerundet werden.');
aw_assert_true(stripos($writtenOnly['label'], 'Zwischenwert') !== false, 'Der schriftliche Einzelbereich muss als Zwischenwert bezeichnet werden.');

$firstSemester = assessment_weight_compute_area_proposal(aw_summary(['note_proposal'=>['value'=>2]]), $defaults);
$currentYear = assessment_weight_compute_area_proposal(aw_summary(['note_proposal'=>['value'=>3]]), $defaults);
$annual = assessment_weight_compute_yearly_proposal($firstSemester, $currentYear, $defaults, null);
aw_assert_same(3, $annual['value'], '40 % Note 2 und 60 % Note 3 müssen den transparent gerundeten Vorschlag 3 ergeben.');
aw_assert_true(stripos($annual['year_weighting']['effective_label'], '40') !== false, 'Die wirksame Jahresgewichtung muss ausgegeben werden.');

$annualSaved = assessment_weight_compute_yearly_proposal($firstSemester, $currentYear, $defaults, [
  'status'=>'final',
  'final_grade'=>1,
]);
aw_assert_true(stripos($annualSaved['explanation'], 'final gespeicherte Schulnachricht') !== false, 'Eine final gespeicherte Schulnachricht muss als Quelle kenntlich sein.');

foreach(['sost','nost'] as $model){
  $notice = assessment_weight_semester_model_year_notice($model);
  aw_assert_same(null, $notice['value'], strtoupper($model).' darf keinen regulären Jahresvorschlag erzeugen.');
  aw_assert_true(stripos($notice['explanation'], 'nicht automatisch') !== false, strtoupper($model).' muss die fehlende Semesterverrechnung erklären.');
}

echo "OK: assessment_weights smoke tests passed.\n";
