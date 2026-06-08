<?php
require_once __DIR__.'/settings.php';
require_once __DIR__.'/helpers.php';

function legal_page_keys(): array {
  return [
    'impressum' => [
      'title' => 'Impressum',
      'setting' => 'legal_imprint_html',
      'empty' => '<p>Das Impressum wurde noch nicht hinterlegt.</p>',
    ],
    'datenschutz' => [
      'title' => 'Datenschutz',
      'setting' => 'legal_privacy_html',
      'empty' => '<p>Die Datenschutzbestimmung wurde noch nicht hinterlegt.</p>',
    ],
  ];
}

function legal_page_config(string $slug): ?array {
  $pages = legal_page_keys();
  return $pages[$slug] ?? null;
}

function legal_page_sanitize_html(string $html): string {
  $html = str_replace("\0", '', $html);
  $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
  $html = preg_replace('/<(script|style|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
  $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><a><blockquote><hr>');

  $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';
  $html = preg_replace('/\s+style\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? '';

  $html = preg_replace_callback('/<a\b[^>]*>/i', static function(array $m): string {
    $tag = $m[0];
    $href = '';
    if(preg_match('/\s+href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $hm)){
      $href = trim((string)($hm[2] ?? $hm[3] ?? $hm[4] ?? ''));
    }
    if($href === '') return '<a>';
    $lower = strtolower($href);
    $allowed = str_starts_with($lower, 'https://')
      || str_starts_with($lower, 'http://')
      || str_starts_with($lower, 'mailto:')
      || str_starts_with($lower, 'tel:')
      || str_starts_with($href, '/')
      || str_starts_with($href, '#');
    if(!$allowed) return '<a>';
    return '<a href="'.h($href).'" target="_blank" rel="noopener noreferrer">';
  }, $html) ?? '';

  $html = preg_replace('/<\/a\b[^>]*>/i', '</a>', $html) ?? '';
  $html = preg_replace_callback('/<(\/?)(p|br|strong|b|em|i|u|ul|ol|li|h2|h3|h4|blockquote|hr)\b[^>]*>/i', static function(array $m): string {
    $slash = $m[1] === '/' ? '/' : '';
    $tag = strtolower($m[2]);
    if(in_array($tag, ['br','hr'], true)) return '<'.$tag.'>';
    return '<'.$slash.$tag.'>';
  }, $html) ?? '';

  return trim($html);
}

function legal_page_get_html(string $slug): string {
  $config = legal_page_config($slug);
  if(!$config) return '';
  $html = (string)app_setting_get((string)$config['setting'], '');
  $html = legal_page_sanitize_html($html);
  return $html !== '' ? $html : (string)$config['empty'];
}

function legal_page_save_html(string $slug, string $html): void {
  $config = legal_page_config($slug);
  if(!$config) throw new InvalidArgumentException('Unbekannte Rechtsseite.');
  app_setting_set((string)$config['setting'], legal_page_sanitize_html($html));
}
