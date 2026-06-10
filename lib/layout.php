<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/version.php';
require_once __DIR__.'/security.php';

function _asset_v(string $path): string {
  $full = __DIR__ . '/../' . ltrim($path,'/');
  if (is_file($full)) return (string)filemtime($full);
  return '1';
}

function render_header(string $title, ?array $u=null): void {
  $bp = cfg()['base_path'] ?? '';
  $u  = $u ?? current_user();
  security_send_headers();
  include __DIR__.'/../partials/header.php';
}

function render_footer(): void {
  $bp = cfg()['base_path'] ?? '';
  include __DIR__.'/../partials/footer.php';
}

function dirty_form_attrs(bool $initial = false): string {
  $attrs = ['data-dirty-watch="1"'];
  if ($initial) $attrs[] = 'data-dirty-initial="1"';
  return implode(' ', $attrs);
}

function legal_hints_enabled(?array $u = null): bool {
  $u = $u ?? current_user();
  return ((string)($u['pref_legal_hints_enabled'] ?? '1') !== '0');
}

function compact_entry_forms_enabled(?array $u = null): bool {
  $u = $u ?? current_user();
  return ((string)($u['pref_compact_forms_enabled'] ?? '0') === '1');
}

function entry_contrast_mode(?array $u = null): string {
  $u = $u ?? current_user();
  return ((string)($u['pref_visual_contrast'] ?? 'normal') === 'high') ? 'high' : 'normal';
}

function simplified_participation_entry_enabled(?array $u = null): bool {
  $u = $u ?? current_user();
  if (($u['role'] ?? '') !== 'teacher') return false;
  return ((string)($u['pref_simple_participation_entry'] ?? '0') === '1');
}

function teacher_school_context_label(?array $u = null): string {
  $u = $u ?? current_user();
  if (($u['role'] ?? '') !== 'teacher') return '';
  $teacherId = (int)($u['id'] ?? 0);
  if($teacherId <= 0) return '';

  static $cache = [];
  if(isset($cache[$teacherId])) return $cache[$teacherId];

  try{
    $st = db()->prepare("SELECT DISTINCT s.name
                         FROM teacher_assignments ta
                         JOIN classes c ON c.id=ta.class_id
                         JOIN school_forms sf ON sf.id=c.school_form_id
                         JOIN schools s ON s.id=sf.school_id
                         WHERE ta.teacher_id=? AND IFNULL(s.active,1)=1
                         ORDER BY s.name");
    $st->execute([$teacherId]);
    $names = array_values(array_filter(array_map(static fn($row): string => trim((string)($row['name'] ?? '')), $st->fetchAll())));
    if(!$names){
      $fallback = db()->query("SELECT name FROM schools WHERE active=1 ORDER BY name LIMIT 1")->fetchColumn();
      if($fallback !== false) $names[] = trim((string)$fallback);
    }
    $cache[$teacherId] = $names ? implode(' · ', array_unique($names)) : '';
  }catch(Throwable $e){
    $cache[$teacherId] = '';
  }

  return $cache[$teacherId];
}

function teacher_assignment_pairs(int $teacherId): array {
  static $cache = [];
  if (isset($cache[$teacherId])) return $cache[$teacherId];
  $st = db()->prepare("SELECT class_id, subject_id FROM teacher_assignments WHERE teacher_id=?");
  $st->execute([$teacherId]);
  $cache[$teacherId] = $st->fetchAll();
  return $cache[$teacherId];
}

function teacher_assignment_guard_attrs(?array $u = null, string $classField = 'class_id', string $subjectField = 'subject_id', string $message = 'Keine Berechtigung für diese Klasse/dieses Fach.'): string {
  $u = $u ?? current_user();
  if (($u['role'] ?? '') !== 'teacher') return '';
  $pairs = teacher_assignment_pairs((int)$u['id']);
  $encoded = [];
  foreach($pairs as $pair){
    $classId = (int)($pair['class_id'] ?? 0);
    $subjectId = (int)($pair['subject_id'] ?? 0);
    if($classId > 0 && $subjectId > 0) $encoded[] = $classId . ':' . $subjectId;
  }
  $encoded = array_values(array_unique($encoded));
  if(!$encoded) return '';
  return implode(' ', [
    'data-assignment-guard="1"',
    'data-class-field="'.h($classField).'"',
    'data-subject-field="'.h($subjectField).'"',
    'data-allowed-combos="'.h(implode(',', $encoded)).'"',
    'data-assignment-message="'.h($message).'"',
  ]);
}

function accordion_section_start(bool $enabled, string $title, bool $open = false, string $style = 'margin-top:12px', string $meta = '', string $class = ''): void {
  if(!$enabled) return;
  $classes = trim('accordion ' . $class);
  echo '<details class="'.h($classes).'"'.($open?' open':'').' style="'.h($style).'">';
  echo '<summary><span class="acc-title">'.h($title).'</span>';
  if($meta!=='') echo '<span class="acc-meta">'.$meta.'</span>';
  echo '</summary><div class="acc-body">';
}

function accordion_section_end(bool $enabled): void {
  if($enabled) echo '</div></details>';
}
