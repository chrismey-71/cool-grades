<?php
/**
 * Simple version helper.
 *
 * Version is stored in /version.json so it can be bumped without touching PHP files.
 * Requested scheme:
 *   - small changes: increment AFTER the dot (b)
 *   - big changes:   increment BEFORE the dot (a)
 *
 * CLI helper: /tools/bump_version.php
 */

function app_version(): string {
  $file = __DIR__ . '/../version.json';
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    $j = json_decode((string)$raw, true);
    if (is_array($j)) {
      $a = isset($j['a']) ? (int)$j['a'] : null; // before dot
      $b = isset($j['b']) ? (int)$j['b'] : null; // after dot
      if ($a !== null && $b !== null) return $a . '.' . str_pad((string)$b, 2, '0', STR_PAD_LEFT);
      if (isset($j['version']) && is_string($j['version'])) return $j['version'];
    }
  }
  return '1.55';
}
