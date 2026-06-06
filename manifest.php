<?php
require_once __DIR__.'/lib/db.php';
require_once __DIR__.'/lib/settings.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$bp = rtrim((string)(cfg()['base_path'] ?? ''), '/');
$scope = ($bp === '' ? '' : $bp) . '/';
$start = ($bp === '' ? '' : $bp) . '/dashboard.php';

$manifest = [
  'name' => 'COOL-Grades',
  'short_name' => 'COOL-Grades',
  'description' => 'Mitarbeit und Noten nach LBV erfassen',
  'lang' => 'de-AT',
  'start_url' => $start,
  'scope' => $scope,
  'display' => 'standalone',
  'display_override' => ['standalone', 'browser'],
  'background_color' => '#f6f7fb',
  'theme_color' => app_brand_color(),
  'icons' => [
    [
      'src' => $scope . 'assets/icons/icon-192.png',
      'sizes' => '192x192',
      'type' => 'image/png',
      'purpose' => 'any'
    ],
    [
      'src' => $scope . 'assets/icons/icon-512.png',
      'sizes' => '512x512',
      'type' => 'image/png',
      'purpose' => 'any'
    ],
    [
      'src' => $scope . 'assets/icons/icon-maskable-512.png',
      'sizes' => '512x512',
      'type' => 'image/png',
      'purpose' => 'maskable'
    ]
  ]
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
