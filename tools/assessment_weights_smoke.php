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

$saDefaults = assessment_weight_settings_resolve(null, 'yearly', ['status'=>'yes']);
aw_assert_same(40.0, $saDefaults['configured']['participation'], 'Neues Schularbeitsfach soll 40 % Mitarbeit als Orientierung erhalten.');
aw_assert_same(10.0, $saDefaults['configured']['oral'], 'Neues Schularbeitsfach soll 10 % mündlich als Orientierung erhalten.');
aw_assert_same(50.0, $saDefaults['configured']['written'], 'Neues Schularbeitsfach soll 50 % schriftlich als Orientierung erhalten.');

$nonSaDefaults = assessment_weight_settings_resolve(null, 'yearly', ['status'=>'no']);
aw_assert_same(40.0, $nonSaDefaults['configured']['participation'], 'Neues Nicht-Schularbeitsfach soll 40 % Mitarbeit als Orientierung erhalten.');
aw_assert_same(30.0, $nonSaDefaults['configured']['oral'], 'Neues Nicht-Schularbeitsfach soll 30 % mündlich als Orientierung erhalten.');
aw_assert_same(30.0, $nonSaDefaults['configured']['written'], 'Neues Nicht-Schularbeitsfach soll 30 % schriftlich als Orientierung erhalten.');

$saved = assessment_weight_settings_resolve([
  'participation_weight' => 55,
  'special_oral_weight' => 15,
  'special_written_weight' => 30,
  'first_semester_to_annual_weight' => 40,
  'current_year_to_annual_weight' => 60,
], 'yearly', ['status'=>'yes']);
aw_assert_same(55.0, $saved['configured']['participation'], 'Gespeicherte individuelle Mitarbeit-Gewichtung darf nicht durch Fachtyp-Presets überschrieben werden.');
aw_assert_same(30.0, $saved['configured']['written'], 'Gespeicherte individuelle schriftliche Gewichtung darf nicht überschrieben werden.');

$saPreset = assessment_weight_preset_resolve('sa_written', 'yes');
aw_assert_same(60.0, $saPreset['weights']['written'], 'Bewusst gewähltes Schularbeitsfach-Preset schriftlich stärker muss 60 % schriftlich setzen.');
$nonSaPreset = assessment_weight_preset_resolve('participation', 'no');
aw_assert_same(50.0, $nonSaPreset['weights']['participation'], 'Bewusst gewähltes Nicht-Schularbeitsfach-Preset mitarbeiterorientiert muss 50 % Mitarbeit setzen.');
aw_assert_same('Diktat', written_assessment_type_label('DICTATION'), 'Diktat muss als eigener schriftlicher Typ erhalten sein.');

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
aw_assert_same(2.25, $participationOral['areas']['oral']['value'], 'Positiv plus unauffällig muss qualitativ zu einem positiven mündlichen Bereichswert verdichtet werden.');

$oralNeutral = assessment_weight_area_values(aw_summary([
  'participation_count'=>0,
  'note_proposal'=>['value'=>null],
  'oral_rows'=>[['impact_label'=>'unauffällig (~)']],
]));
aw_assert_same(3.0, $oralNeutral['oral']['value'], 'Unauffällig (~) muss als neutraler qualitativer Bereichswert 3 verdichtet werden.');

$oralWeighted = assessment_weight_area_values(aw_summary([
  'participation_count'=>0,
  'note_proposal'=>['value'=>null],
  'oral_rows'=>[
    ['impact_label'=>'positiv (+)','weight_multiplier'=>3],
    ['impact_label'=>'negativ (-)','weight_multiplier'=>0.5],
  ],
]));
aw_assert_same(1.93, $oralWeighted['oral']['value'], 'Besondere mündliche Leistungsfeststellungen müssen optionale Einzelgewichte berücksichtigen.');

$writtenSummary = report_eval_written_summary([
  ['grade'=>2,'exam_type'=>'TEST','tendency'=>'','weight_multiplier'=>0.5,'exam_date'=>'2026-03-01'],
  ['grade'=>3,'exam_type'=>'SA','tendency'=>'','weight_multiplier'=>2,'exam_date'=>'2026-04-01'],
  ['grade'=>2,'exam_type'=>'SA','tendency'=>'','weight_multiplier'=>2,'exam_date'=>'2026-05-01'],
]);
aw_assert_same(2.4444444444444446, $writtenSummary['avg'], 'Schriftliche Leistungsfeststellungen müssen als gewichteter Mittelwert berechnet werden.');
aw_assert_same(2, $writtenSummary['type_counts']['SA'], 'Nur Schularbeiten dürfen als Schularbeiten gezählt werden.');
aw_assert_same(1, $writtenSummary['type_counts']['TEST'], 'Tests müssen getrennt von Schularbeiten gezählt werden.');

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
