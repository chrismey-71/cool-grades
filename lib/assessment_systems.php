<?php

function class_assessment_system_options(): array {
  return [
    'sost' => 'SOST - semestrierte Oberstufe',
    'nost' => 'NOST - neue Oberstufe / semestrierte Beurteilung',
    'yearly' => 'Jahresbeurteilung - ganzjährige Beurteilung / Jahrgangsmodell',
  ];
}

function class_assessment_system_is_valid(?string $value): bool {
  if($value === null) return false;
  return isset(class_assessment_system_options()[$value]);
}

function class_assessment_system_label(?string $value): string {
  if($value !== null && isset(class_assessment_system_options()[$value])) return class_assessment_system_options()[$value];
  return 'Nicht festgelegt';
}

function class_assessment_system_note(?string $value): string {
  if($value === 'sost'){
    return 'Diese Klasse ist als SOST-Klasse gekennzeichnet. Semesterbeurteilungen sind getrennt darzustellen. Ergebnisse des 1. Semesters werden bei späteren Beurteilungen zur Orientierung angezeigt.';
  }
  if($value === 'nost'){
    return 'Diese Klasse ist als NOST-Klasse gekennzeichnet. Semesterergebnisse sind für die weitere Beurteilung relevant und werden angezeigt.';
  }
  if($value === 'yearly'){
    return 'Diese Klasse ist als Klasse mit ganzjähriger Jahresbeurteilung gekennzeichnet. Die Jahresbeurteilung bezieht die Leistungsentwicklung des gesamten Unterrichtsjahres ein. Das Ergebnis des 1. Semesters dient als wichtige Orientierung.';
  }
  return 'Für diese Klasse wurde noch kein Beurteilungssystem hinterlegt. Bitte prüfen Sie die Klasseneinstellungen im Adminbereich.';
}

function class_assessment_system_tone(?string $value): string {
  if($value === 'sost' || $value === 'nost' || $value === 'yearly') return 'neutral';
  return 'critical';
}

function final_assessment_scope_meta(): array {
  return [
    'semester1' => [
      'label' => '1. Semesterbeurteilung festlegen',
      'help' => 'Für die Beurteilung des Wintersemesters. Die Note wird als Abschlussbeurteilung für das 1. Semester gespeichert.',
    ],
    'semester2' => [
      'label' => '2. Semesterbeurteilung festlegen',
      'help' => 'Für die Beurteilung des Sommersemesters. Die bereits gespeicherte Beurteilung des 1. Semesters wird zur Orientierung angezeigt.',
    ],
    'year' => [
      'label' => 'Jahresbeurteilung festlegen',
      'help' => 'Für die abschließende Beurteilung am Jahresende. Je nach Beurteilungssystem der Klasse werden Semesterergebnisse und Jahresdaten unterschiedlich dargestellt.',
    ],
  ];
}
