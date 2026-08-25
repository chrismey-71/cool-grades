<?php
require_once __DIR__.'/../lib/participation_pedagogical_mode.php';
require_once __DIR__.'/../lib/participation_options.php';
require_once __DIR__.'/../lib/report_evaluation.php';

function impact_kind_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual:   ".var_export($actual, true)."\n");
    exit(1);
  }
}

function impact_kind_assert_true(bool $condition, string $message): void {
  if(!$condition){
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

// The saved field wins over the visible label. This is the key regression case.
$negativeOption = ['label' => 'kaum nachweisbar (0)', 'impact_kind' => 'negative'];
impact_kind_assert_same('negative', participation_impact_kind_from_option($negativeOption), 'Explizit negativ muss unabhängig vom Label negativ bleiben.');
impact_kind_assert_same('negative', report_eval_rating_classification('kaum nachweisbar (0)', 'negative'), 'Die Auswertung muss die explizite negative Richtung verwenden.');
impact_kind_assert_same(-1, report_eval_rating_score('kaum nachweisbar (0)', 'negative'), 'Negative Richtung muss einen negativen qualitativen Wert ergeben.');

// Legacy data remains safely classifiable after the idempotent migration.
impact_kind_assert_same('negative', participation_impact_kind_from_label('kaum nachweisbar (-)'), 'Das alte Minus-Suffix muss als negativ erkannt werden.');
impact_kind_assert_same('negative', participation_impact_kind_from_label('kaum nachweisbar (0)'), 'Bestehende kaum-nachweisbar-Einträge werden fachlich vorsichtig als negativ behandelt.');
impact_kind_assert_same('positive', report_eval_rating_classification('positiv (+)', 'positive'), 'Positiv muss auch mit Snapshot positiv bleiben.');
impact_kind_assert_same('neutral', report_eval_rating_classification('beliebiger Text', 'neutral'), 'Neutraler Snapshot darf nicht vom Label überschrieben werden.');
impact_kind_assert_same(null, report_eval_rating_classification('nur beobachtet', 'unrated'), 'Ohne Wertung darf nicht als neutral gezählt werden.');

impact_kind_assert_true(!participation_impact_kind_is_formative_compatible('negative'), 'Negative Eindruckswerte dürfen nicht als formativ zulässig erscheinen.');
impact_kind_assert_true(participation_impact_kind_is_formative_compatible('neutral'), 'Neutrale Eindruckswerte sollen formativ zulässig bleiben.');
$hint = participation_pedagogical_hint('formative', 'negative');
impact_kind_assert_same('error', $hint['level'], 'Ein formativer Kontext mit negativem Eindruck muss einen Konflikthinweis zeigen.');
impact_kind_assert_true(stripos($hint['text'], 'nicht negativ') !== false, 'Der Konflikthinweis muss die pädagogische Einschränkung erklären.');

impact_kind_assert_same(
  participation_option_label_key('mündlich'),
  participation_option_label_key('mÃ¼ndlich'),
  'Mojibake-Varianten mit Umlauten müssen in Picklisten als gleiche Option erkannt werden.'
);
impact_kind_assert_same(
  participation_option_label_key('Argumentieren / Erklären'),
  participation_option_label_key('Argumentieren / ErklÃ¤ren'),
  'Mojibake-Varianten in Beobachtungsbereichen dürfen keine sichtbaren Dubletten erzeugen.'
);

echo "OK: impact_kind smoke tests passed.\n";
