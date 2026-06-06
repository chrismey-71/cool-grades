<?php
function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): void { $bp=cfg()['base_path'] ?? ''; header('Location: '.$bp.$path); exit; }
function now_iso(): string { return date('Y-m-d H:i:s'); }

function exam_grade_tendency_choices(): array {
  return [
    'plus' => 'plus',
    'minus' => 'minus',
  ];
}

function normalize_exam_grade_tendency(?string $value): string {
  $value = trim((string)$value);
  if($value==='') return '';
  $lower = mb_strtolower($value);
  if(in_array($lower, ['+','plus','positiv'], true)) return 'plus';
  if(in_array($lower, ['-','minus','negativ'], true)) return 'minus';
  return $value;
}
