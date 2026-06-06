<?php
// Simple key/value settings stored in DB (global app settings)
require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';

/**
 * Get a setting value.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function app_setting_get(string $key, $default=null) {
  try{
    $st=db()->prepare("SELECT value FROM app_settings WHERE `key`=? LIMIT 1");
    $st->execute([$key]);
    $v=$st->fetchColumn();
    if($v===false || $v===null) return $default;
    return $v;
  }catch(Exception $e){
    return $default;
  }
}

/**
 * Set a setting value.
 */
function app_setting_set(string $key, string $value): void {
  $st=db()->prepare("INSERT INTO app_settings(`key`,`value`,updated_at,created_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)");
  $now=now_iso();
  $st->execute([$key,$value,$now,$now]);
}

function sanitize_hex_color(?string $color, string $default = '#2F6F3A'): string {
  $color = trim((string)$color);
  if ($color === '') return strtoupper($default);
  if ($color[0] !== '#') $color = '#' . $color;
  if (preg_match('/^#([0-9a-f]{3})$/i', $color, $m)) {
    $hex = strtoupper($m[1]);
    return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
  }
  if (preg_match('/^#([0-9a-f]{6})$/i', $color)) return strtoupper($color);
  return strtoupper($default);
}

function _hex_to_rgb(string $hex): array {
  $hex = ltrim(sanitize_hex_color($hex), '#');
  return [
    hexdec(substr($hex, 0, 2)),
    hexdec(substr($hex, 2, 2)),
    hexdec(substr($hex, 4, 2)),
  ];
}

function _rgb_to_hex(array $rgb): string {
  $rgb = array_map(static function($n): int {
    $n = (int)round((float)$n);
    if ($n < 0) $n = 0;
    if ($n > 255) $n = 255;
    return $n;
  }, $rgb);
  return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
}

function _mix_colors(string $from, string $to, float $ratioTo): string {
  $ratioTo = max(0.0, min(1.0, $ratioTo));
  $a = _hex_to_rgb($from);
  $b = _hex_to_rgb($to);
  return _rgb_to_hex([
    $a[0] + (($b[0] - $a[0]) * $ratioTo),
    $a[1] + (($b[1] - $a[1]) * $ratioTo),
    $a[2] + (($b[2] - $a[2]) * $ratioTo),
  ]);
}

function app_brand_palette(): array {
  $primary = sanitize_hex_color((string)app_setting_get('brand_primary_color', '#2F6F3A'), '#2F6F3A');
  $dark = _mix_colors($primary, '#000000', 0.16);
  $light = _mix_colors($primary, '#FFFFFF', 0.18);
  $soft = _mix_colors($primary, '#FFFFFF', 0.88);
  $softAlt = _mix_colors($primary, '#FFFFFF', 0.78);
  $ring = _mix_colors($primary, '#FFFFFF', 0.68);
  return [
    'primary' => $primary,
    'dark' => $dark,
    'light' => $light,
    'soft' => $soft,
    'soft_alt' => $softAlt,
    'ring' => $ring,
  ];
}

function app_brand_color(): string {
  $palette = app_brand_palette();
  return $palette['primary'];
}

function app_brand_css_vars(): string {
  $palette = app_brand_palette();
  return implode('', [
    '--brand-primary:', $palette['primary'], ';',
    '--brand-green:', $palette['light'], ';',
    '--brand-green-dark:', $palette['dark'], ';',
    '--blue:', $palette['dark'], ';',
    '--ok:', $palette['light'], ';',
    '--brand-primary-soft:', $palette['soft'], ';',
    '--brand-primary-soft-alt:', $palette['soft_alt'], ';',
    '--brand-primary-ring:', $palette['ring'], ';',
  ]);
}

function school_period_default_ranges(?DateTimeImmutable $now = null): array {
  $now = $now ?? new DateTimeImmutable('now');
  $year = (int)$now->format('Y');
  $month = (int)$now->format('n');
  $startYear = ($month >= 9) ? $year : ($year - 1);

  return [
    'semester1' => [
      'from' => sprintf('%04d-09-01', $startYear),
      'to'   => sprintf('%04d-01-31', $startYear + 1),
    ],
    'semester2' => [
      'from' => sprintf('%04d-02-01', $startYear + 1),
      'to'   => sprintf('%04d-08-31', $startYear + 1),
    ],
  ];
}

function school_period_year_label(string $semester1From, string $semester2To): string {
  $startYear = (int)substr($semester1From, 0, 4);
  $endYear = (int)substr($semester2To, 0, 4);
  if($startYear > 0 && $endYear === ($startYear + 1)){
    return sprintf('%04d/%02d', $startYear, $endYear % 100);
  }
  if($startYear > 0 && $endYear > 0 && $startYear !== $endYear){
    return sprintf('%04d/%04d', $startYear, $endYear);
  }
  if($startYear > 0){
    return (string)$startYear;
  }
  return trim($semester1From.' '.$semester2To);
}

function app_school_period_sets(bool $includeArchived = false): array {
  try{
    $sql = "SELECT id,label,semester1_from,semester1_to,semester2_from,semester2_to,archived,is_current,created_at,updated_at
            FROM school_period_sets";
    if(!$includeArchived){
      $sql .= " WHERE archived=0";
    }
    $sql .= " ORDER BY semester1_from DESC, id DESC";
    $rows = db()->query($sql)->fetchAll();
    if(!$rows) return [];
    return array_map(static function(array $row): array {
      $row['id'] = (int)$row['id'];
      $row['archived'] = (int)$row['archived'];
      $row['is_current'] = (int)($row['is_current'] ?? 0);
      return $row;
    }, $rows);
  }catch(Exception $e){
    return [];
  }
}

function app_school_period_find(int $id, bool $includeArchived = true): ?array {
  if($id <= 0) return null;
  foreach(app_school_period_sets($includeArchived) as $set){
    if((int)($set['id'] ?? 0) === $id) return $set;
  }
  return null;
}

function app_school_period_create(string $label, string $semester1From, string $semester1To, string $semester2From, string $semester2To): void {
  $label = trim($label);
  if($label === ''){
    $label = school_period_year_label($semester1From, $semester2To);
  }
  $st = db()->prepare("INSERT INTO school_period_sets(label,semester1_from,semester1_to,semester2_from,semester2_to,archived,created_at,updated_at)
                       VALUES(?,?,?,?,?,0,?,?)");
  $now = now_iso();
  $st->execute([$label,$semester1From,$semester1To,$semester2From,$semester2To,$now,$now]);
}

function app_school_period_archive(int $id): void {
  $pdo = db();
  $st = $pdo->prepare("UPDATE school_period_sets SET archived=1, is_current=0, updated_at=? WHERE id=?");
  $st->execute([now_iso(), $id]);
  try{
    $currentCount = (int)$pdo->query("SELECT COUNT(*) FROM school_period_sets WHERE is_current=1")->fetchColumn();
    if($currentCount === 0){
      $pdo->exec("UPDATE school_period_sets SET is_current=1 WHERE id=(SELECT id FROM (SELECT id FROM school_period_sets WHERE archived=0 ORDER BY semester1_from DESC, id DESC LIMIT 1) x)");
    }
  }catch(Exception $e){ /* ignore */ }
}

function app_school_period_restore(int $id): void {
  $st = db()->prepare("UPDATE school_period_sets SET archived=0, updated_at=? WHERE id=?");
  $st->execute([now_iso(), $id]);
}

function app_school_period_default_options(?DateTimeImmutable $now = null): array {
  $defaults = school_period_default_ranges($now);
  $yearLabel = school_period_year_label($defaults['semester1']['from'], $defaults['semester2']['to']);
  return [
    'default_semester1' => [
      'from' => $defaults['semester1']['from'],
      'to' => $defaults['semester1']['to'],
      'label' => '1. Semester '.$yearLabel,
      'kind' => 'semester1',
      'school_year_label' => $yearLabel,
    ],
    'default_semester2' => [
      'from' => $defaults['semester2']['from'],
      'to' => $defaults['semester2']['to'],
      'label' => '2. Semester '.$yearLabel,
      'kind' => 'semester2',
      'school_year_label' => $yearLabel,
    ],
    'default_schoolyear' => [
      'from' => $defaults['semester1']['from'],
      'to' => $defaults['semester2']['to'],
      'label' => 'Schuljahr '.$yearLabel,
      'kind' => 'schoolyear',
      'school_year_label' => $yearLabel,
    ],
  ];
}

function app_school_period_options(): array {
  $options = [];
  foreach(app_school_period_sets(true) as $set){
    $yearLabel = trim((string)$set['label']);
    if($yearLabel === ''){
      $yearLabel = school_period_year_label((string)$set['semester1_from'], (string)$set['semester2_to']);
    }
    $id = (int)$set['id'];
    $suffix = ((int)($set['archived'] ?? 0) === 1) ? ' · Archiv' : '';
    $options['period_'.$id.'_semester1'] = [
      'id' => $id,
      'kind' => 'semester1',
      'from' => (string)$set['semester1_from'],
      'to' => (string)$set['semester1_to'],
      'label' => '1. Semester '.$yearLabel.$suffix,
      'school_year_label' => $yearLabel,
    ];
    $options['period_'.$id.'_semester2'] = [
      'id' => $id,
      'kind' => 'semester2',
      'from' => (string)$set['semester2_from'],
      'to' => (string)$set['semester2_to'],
      'label' => '2. Semester '.$yearLabel.$suffix,
      'school_year_label' => $yearLabel,
    ];
    $options['period_'.$id.'_schoolyear'] = [
      'id' => $id,
      'kind' => 'schoolyear',
      'from' => (string)$set['semester1_from'],
      'to' => (string)$set['semester2_to'],
      'label' => 'Schuljahr '.$yearLabel.$suffix,
      'school_year_label' => $yearLabel,
    ];
  }
  return $options;
}

function app_school_period_current_key(?array $options = null, ?DateTimeImmutable $now = null): string {
  $options = $options ?? app_school_period_options();
  $now = $now ?? new DateTimeImmutable('now');
  $today = $now->format('Y-m-d');

  foreach($options as $key => $option){
    if(($option['kind'] ?? '') === 'semester1' || ($option['kind'] ?? '') === 'semester2'){
      $from = (string)($option['from'] ?? '');
      $to = (string)($option['to'] ?? '');
      if($from !== '' && $to !== '' && $today >= $from && $today <= $to){
        return $key;
      }
    }
  }

  foreach($options as $key => $option){
    if(($option['kind'] ?? '') === 'schoolyear'){
      $from = (string)($option['from'] ?? '');
      $to = (string)($option['to'] ?? '');
      if($from !== '' && $to !== '' && $today >= $from && $today <= $to){
        return $key;
      }
    }
  }

  if($options){
    foreach($options as $key => $option){
      if(($option['kind'] ?? '') === 'schoolyear'){
        return $key;
      }
    }
    $firstKey = array_key_first($options);
    return $firstKey !== null ? (string)$firstKey : 'default_schoolyear';
  }

  $defaults = app_school_period_default_options($now);
  foreach(['default_semester1','default_semester2'] as $key){
    $from = (string)$defaults[$key]['from'];
    $to = (string)$defaults[$key]['to'];
    if($today >= $from && $today <= $to){
      return $key;
    }
  }
  return 'default_schoolyear';
}

function app_school_period_resolve(?string $period, ?string $customFrom = '', ?string $customTo = ''): array {
  $savedOptions = app_school_period_options();
  $defaultOptions = app_school_period_default_options();
  $period = trim((string)$period);
  if($period === '') $period = 'current';

  if($period === 'custom'){
    return [
      'period' => 'custom',
      'label' => 'Benutzerdefinierter Zeitraum',
      'from' => trim((string)$customFrom),
      'to' => trim((string)$customTo),
      'ranges' => $savedOptions,
    ];
  }

  if($period === 'current'){
    $currentKey = app_school_period_current_key($savedOptions);
    $source = isset($savedOptions[$currentKey]) ? $savedOptions : $defaultOptions;
    $resolved = $source[$currentKey] ?? $defaultOptions['default_schoolyear'];
    return [
      'period' => 'current',
      'resolved_key' => $currentKey,
      'label' => (string)$resolved['label'],
      'from' => (string)$resolved['from'],
      'to' => (string)$resolved['to'],
      'ranges' => $savedOptions,
    ];
  }

  if(isset($savedOptions[$period])){
    return [
      'period' => $period,
      'label' => (string)$savedOptions[$period]['label'],
      'from' => (string)$savedOptions[$period]['from'],
      'to' => (string)$savedOptions[$period]['to'],
      'ranges' => $savedOptions,
    ];
  }

  if(isset($defaultOptions[$period])){
    return [
      'period' => $period,
      'label' => (string)$defaultOptions[$period]['label'],
      'from' => (string)$defaultOptions[$period]['from'],
      'to' => (string)$defaultOptions[$period]['to'],
      'ranges' => $savedOptions,
    ];
  }

  $fallbackKey = app_school_period_current_key($savedOptions);
  $fallbackSource = isset($savedOptions[$fallbackKey]) ? $savedOptions : $defaultOptions;
  $resolved = $fallbackSource[$fallbackKey] ?? $defaultOptions['default_schoolyear'];
  return [
    'period' => isset($savedOptions[$fallbackKey]) ? $fallbackKey : 'current',
    'resolved_key' => $fallbackKey,
    'label' => (string)$resolved['label'],
    'from' => (string)$resolved['from'],
    'to' => (string)$resolved['to'],
    'ranges' => $savedOptions,
  ];
}
