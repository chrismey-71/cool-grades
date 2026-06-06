<?php

function lbvo_contains_any(string $haystack, array $needles): bool {
  foreach($needles as $needle){
    if($needle !== '' && strpos($haystack, $needle) !== false) return true;
  }
  return false;
}

function lbvo_auto_tags($reason_label,$phase_label,$hw_label,$criteria_labels,$perf_labels,$note,$reason_text): array {
  $tags=[];

  $rl=mb_strtolower(trim((string)$reason_label));
  $pl=mb_strtolower(trim((string)$phase_label));
  $hl=mb_strtolower(trim((string)$hw_label));
  $txt=mb_strtolower(trim(
    (string)$note.' '.
    (string)$reason_text.' '.
    implode(' ',(array)$criteria_labels).' '.
    implode(' ',(array)$perf_labels)
  ));

  // § 4 lit. a: eingebundene muendliche/schriftliche/praktische/graphische Leistungen
  // In der App entspricht das der expliziten Auswahl unter "Leistungsart".
  if(!empty(array_filter((array)$perf_labels, fn($label)=>trim((string)$label)!==''))) $tags['a']=true;

  // § 4 lit. b: Sicherung des Unterrichtsertrages einschliesslich Hausuebungen.
  // Daher reicht entweder Hausuebungsbezug oder Sicherungsbezug.
  if($hl!=='' || lbvo_contains_any($rl,['haus','sicherung']) || lbvo_contains_any($pl,['sicherung'])) $tags['b']=true;

  // § 4 lit. c: Erarbeitung neuer Lehrstoffe.
  // "Einstieg" allein ist dafuer zu unscharf; wir bleiben bewusst eng.
  if(lbvo_contains_any($pl,['erarbeitung','neuer'])) $tags['c']=true;

  // § 4 lit. d: Erfassen und Verstehen von Sachverhalten.
  if(lbvo_contains_any($txt,['versteh','erfass','begriff','nachvollzieh'])) $tags['d']=true;

  // § 4 lit. e: Einordnen und Anwenden.
  if(lbvo_contains_any($pl,['übung']) || lbvo_contains_any($txt,['anwend','transfer','fall','einord'])) $tags['e']=true;

  return array_keys($tags);
}
