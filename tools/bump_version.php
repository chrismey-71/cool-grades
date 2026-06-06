<?php
/**
 * Bump COOL-Grades version stored in /version.json
 *
 * Usage:
 *   php tools/bump_version.php small   # increments AFTER the dot (b)
 *   php tools/bump_version.php big     # increments BEFORE the dot (a)
 *   php tools/bump_version.php show
 */

$root = realpath(__DIR__ . '/..');
$file = $root . '/version.json';

function read_v(string $file): array {
  if (!is_file($file)) return ['a'=>0,'b'=>0];
  $j = json_decode((string)file_get_contents($file), true);
  if (!is_array($j)) return ['a'=>0,'b'=>0];
  return ['a'=>(int)($j['a'] ?? 0), 'b'=>(int)($j['b'] ?? 0)];
}

function write_v(string $file, array $v): void {
  file_put_contents($file, json_encode(['a'=>(int)$v['a'],'b'=>(int)$v['b']], JSON_UNESCAPED_SLASHES));
}

function fmt(array $v): string {
  return ((int)$v['a']) . '.' . str_pad((string)((int)$v['b']), 2, '0', STR_PAD_LEFT);
}

$cmd = $argv[1] ?? 'show';
$v = read_v($file);

if ($cmd === 'show') { echo fmt($v) . PHP_EOL; exit(0); }
if ($cmd === 'small') { $v['b']=(int)$v['b']+1; write_v($file,$v); echo fmt($v) . PHP_EOL; exit(0); }
if ($cmd === 'big') { $v['a']=(int)$v['a']+1; $v['b']=0; write_v($file,$v); echo fmt($v) . PHP_EOL; exit(0); }

fwrite(STDERR, "Unknown command: $cmd\n");
exit(2);
