<?php

function participation_reason_mode_choices(): array {
  return [
    'auto' => 'automatisch',
    'formative' => 'eher formativ',
    'summative' => 'eher summativ',
  ];
}

function participation_reason_mode_normalize(?string $value): string {
  $value = trim((string)$value);
  if($value === '') return 'auto';
  $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  if(in_array($lower, ['auto','automatisch'], true)) return 'auto';
  if(in_array($lower, ['formative','formativ'], true)) return 'formative';
  if(in_array($lower, ['summative','summativ'], true)) return 'summative';
  return 'auto';
}

function participation_pedagogical_mode_choices(): array {
  return [
    'formative' => 'lernbegleitend (formativ)',
    'summative' => 'bilanzierend (summativ)',
  ];
}

function participation_pedagogical_mode_normalize(?string $value): string {
  $value = trim((string)$value);
  if($value === '') return '';
  $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  $lower = preg_replace('/\s+/', ' ', $lower);
  $ascii = strtr($lower, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);

  if(in_array($ascii, ['formative','formativ','lernbegleitend','lernbegleitend (formativ)'], true)) return 'formative';
  if(in_array($ascii, ['summative','summativ','bilanzierend','bilanzierend (summativ)'], true)) return 'summative';
  return '';
}

function participation_pedagogical_mode_label(?string $value): string {
  $value = participation_pedagogical_mode_normalize($value);
  $choices = participation_pedagogical_mode_choices();
  return $choices[$value] ?? '';
}

function _participation_pedagogical_mode_text_key(string $value): string {
  $value = trim($value);
  if($value === '') return '';
  $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  $lower = preg_replace('/\s+/', ' ', $lower);
  return strtr($lower, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
}

function participation_pedagogical_mode_suggestion_from_labels(?string $reason_label = '', ?string $phase_label = ''): string {
  $reason = _participation_pedagogical_mode_text_key((string)$reason_label);
  $phase = _participation_pedagogical_mode_text_key((string)$phase_label);

  if($phase !== ''){
    foreach(['sicherung','praesentation','abschluss','reflexion','wiederholung'] as $needle){
      if(str_contains($phase, $needle)) return 'summative';
    }
    foreach(['erarbeitung','einstieg','uebung'] as $needle){
      if(str_contains($phase, $needle)) return 'formative';
    }
  }

  if($reason !== ''){
    foreach(['hausuebung','sicherung','praesentation','referat','abschluss','lernziel','abfrage','wiederholung','zusammenfassung'] as $needle){
      if(str_contains($reason, $needle)) return 'summative';
    }
    foreach(['muendliche mitarbeit','mündliche mitarbeit','arbeitsauftrag','gruppenarbeit','projekt','sonstiges'] as $needle){
      if(str_contains($reason, $needle)) return 'formative';
    }
  }

  return 'formative';
}

function participation_pedagogical_mode_suggestion(array $reasons, array $phases, int $reason_id, int $phase_id): string {
  $reason_label = '';
  $reason_mode = 'auto';
  $phase_label = '';

  foreach($reasons as $option){
    if((int)($option['id'] ?? 0) === $reason_id){
      $reason_label = (string)($option['label'] ?? '');
      $reason_mode = participation_reason_mode_normalize((string)($option['pedagogical_hint_mode'] ?? 'auto'));
      break;
    }
  }
  foreach($phases as $option){
    if((int)($option['id'] ?? 0) === $phase_id){
      $phase_label = (string)($option['label'] ?? '');
      break;
    }
  }

  if($reason_mode === 'formative') return 'formative';
  if($reason_mode === 'summative') return 'summative';

  return participation_pedagogical_mode_suggestion_from_labels($reason_label, $phase_label);
}

function participation_impact_kind_from_label(?string $label): string {
  $label = trim((string)$label);
  if($label === '') return '';

  $lower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
  $lower = preg_replace('/\s+/', ' ', $lower);
  $ascii = strtr($lower, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);

  if(
    str_contains($lower, 'auffällig negativ') ||
    str_contains($lower, 'kritisch') ||
    str_contains($lower, 'unsicher') ||
    str_contains($lower, 'negativ') ||
    str_contains($lower, 'nicht genügend') ||
    str_contains($ascii, 'nicht genuegend') ||
    preg_match('/(^|[[:space:]])-(-)?($|[[:space:]])/u', $lower)
  ){
    return 'negative';
  }

  if(
    str_contains($lower, 'auffällig positiv') ||
    str_contains($lower, 'positiv') ||
    str_contains($lower, 'gut') ||
    str_contains($lower, 'sehr gut') ||
    str_contains($lower, 'sicher') ||
    preg_match('/\+\+|\+/', $lower)
  ){
    return 'positive';
  }

  return 'neutral';
}

function participation_pedagogical_hint(string $suggestedMode, string $impactKind=''): array {
  $suggestedMode = participation_pedagogical_mode_normalize($suggestedMode);
  $impactKind = trim($impactKind);

  if($suggestedMode === 'summative'){
    return [
      'level' => 'info',
      'text' => 'Hinweis: Die aktuelle Auswahl deutet eher auf eine bilanzierende/summative Situation hin. Eine negative Auswahl bei Eindruck/Relevanz ist hier grundsätzlich möglich.',
    ];
  }

  if($impactKind === 'negative'){
    return [
      'level' => 'error',
      'text' => 'Achtung: Die aktuelle Auswahl deutet eher auf eine lernbegleitende/formative Bewertung hin. Bei formativem Einsatz sollte Eindruck/Relevanz nicht negativ gewählt werden.',
    ];
  }

  return [
    'level' => 'info',
    'text' => 'Hinweis: Die aktuelle Auswahl deutet eher auf eine lernbegleitende/formative Bewertung hin. Eindruck/Relevanz sollte hier vor allem rückmeldend und nicht negativ genutzt werden.',
  ];
}
