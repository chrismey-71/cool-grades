<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/logger.php';

$u = require_role('admin');
$bp = cfg()['base_path'] ?? '';

/**
 * admin/logs.php - schnelle Sicht auf app.log, error.log und
 * webuntis_cron.log, damit Administrator:innen Fehler analysieren können,
 * ohne händisch per FTP/SSH in Logdateien zu suchen. Rein lesend, keine
 * Änderungen an den Logdateien.
 */

/**
 * Liest die letzten $maxLines Zeilen einer Datei, ohne die ganze Datei in
 * den Speicher zu laden (wichtig bei potenziell großen Logdateien). Liest
 * dazu vom Dateiende rückwärts in Blöcken, bis genug Zeilen gefunden
 * wurden oder $maxBytes erreicht ist. Rückgabe: neueste Zeile zuerst.
 */
function _logs_tail_lines(string $path, int $maxLines, int $maxBytes = 4194304): array {
  if(!is_file($path) || !is_readable($path)) return [];
  $size = @filesize($path);
  if($size === false || $size === 0) return [];
  $fh = @fopen($path, 'rb');
  if(!$fh) return [];
  $chunkSize = 8192;
  $pos = $size;
  $buffer = '';
  $lines = [];
  while($pos > 0 && (count($lines) <= $maxLines) && (($size - $pos) < $maxBytes)){
    $read = (int)min($chunkSize, $pos);
    $pos -= $read;
    if(fseek($fh, $pos) !== 0) break;
    $chunk = fread($fh, $read);
    if($chunk === false) break;
    $buffer = $chunk.$buffer;
    $lines = preg_split('/\R/', $buffer);
  }
  fclose($fh);
  if($pos > 0 && count($lines) > 0) array_shift($lines); // möglicherweise abgeschnittene erste Zeile verwerfen
  $lines = array_values(array_filter($lines, static fn(string $l): bool => $l !== ''));
  $lines = array_reverse($lines); // neueste zuerst
  if(count($lines) > $maxLines) $lines = array_slice($lines, 0, $maxLines);
  return $lines;
}

function _logs_format_ts(string $iso): string {
  try{
    $dt = new DateTimeImmutable($iso);
    return $dt->format('d.m.Y H:i:s');
  }catch(Throwable $e){
    return $iso;
  }
}

function _logs_level_badge(string $level): string {
  $level = strtolower($level);
  $label = h($level !== '' ? $level : '–');
  if(in_array($level, ['error','critical'], true)){
    return '<span class="badge off">'.$label.'</span>';
  }
  if($level === 'warn' || $level === 'warning'){
    return '<span class="badge" style="background:rgba(216,182,90,.18);border-color:rgba(216,182,90,.35);color:#8a6d16">'.$label.'</span>';
  }
  return '<span class="badge">'.$label.'</span>';
}

$logDefs = [
  'error' => [
    'label' => 'error.log',
    'desc' => 'Laufzeitfehler und unbehandelte Ausnahmen (PHP-Fehler, Fatal Errors) – der schnellste Einstieg bei einem gemeldeten Problem.',
    'format' => 'jsonl',
    'paths' => [app_log_file('error')],
  ],
  'app' => [
    'label' => 'app.log',
    'desc' => 'Alle protokollierten Anwendungsereignisse (info/warn/error), inklusive der Laufzeitfehler aus error.log.',
    'format' => 'jsonl',
    'paths' => [app_log_file('app')],
  ],
  'webuntis_cron' => [
    'label' => 'webuntis_cron.log',
    'desc' => 'Ausgabe des Cron-Skripts tools/webuntis_cron_import.php, sofern die Crontab die Ausgabe wie im Skript empfohlen in logs/webuntis_cron.log umleitet.',
    'format' => 'text',
    'paths' => [
      __DIR__.'/../logs/webuntis_cron.log',
      app_log_dir().'/webuntis_cron.log',
    ],
  ],
];

$logKey = (string)($_GET['log'] ?? 'error');
if(!isset($logDefs[$logKey])) $logKey = 'error';
$def = $logDefs[$logKey];

$path = null;
foreach($def['paths'] as $candidate){
  if(is_file($candidate)){ $path = $candidate; break; }
}

$q = trim((string)($_GET['q'] ?? ''));
$levelFilter = (string)($_GET['level'] ?? '');
$maxLines = (int)($_GET['lines'] ?? 300);
if(!in_array($maxLines, [100,300,1000,3000], true)) $maxLines = 300;

if((string)($_GET['download'] ?? '') === '1' && $path !== null){
  emit_event('admin_log_downloaded', ['log' => $logKey, 'file' => basename($path)]);
  header('Content-Type: text/plain; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.basename($path).'"');
  header('Content-Length: '.filesize($path));
  readfile($path);
  exit;
}

$rawLines = $path !== null ? _logs_tail_lines($path, $maxLines) : [];
$rows = [];
$parseErrors = 0;
foreach($rawLines as $line){
  if($q !== '' && stripos($line, $q) === false) continue;
  if($def['format'] === 'jsonl'){
    $parsed = json_decode($line, true);
    if(!is_array($parsed)){
      $parseErrors++;
      $rows[] = ['ts' => '', 'level' => '', 'msg' => $line, 'ctx' => null, 'raw' => true];
      continue;
    }
    $level = (string)($parsed['level'] ?? '');
    if($levelFilter !== '' && strtolower($level) !== strtolower($levelFilter)) continue;
    $rows[] = [
      'ts' => (string)($parsed['ts'] ?? ''),
      'level' => $level,
      'msg' => (string)($parsed['msg'] ?? ''),
      'ctx' => $parsed['ctx'] ?? null,
      'raw' => false,
    ];
  } else {
    $rows[] = ['ts' => '', 'level' => '', 'msg' => $line, 'ctx' => null, 'raw' => true];
  }
}

$fileInfo = null;
if($path !== null){
  $fileInfo = [
    'size' => filesize($path),
    'mtime' => filemtime($path),
  ];
}

function _logs_qs(array $overrides = []): string {
  $params = array_merge($_GET, $overrides);
  foreach($params as $k => $v){ if($v === '' || $v === null) unset($params[$k]); }
  return '?'.http_build_query($params);
}

function _logs_format_bytes(int $bytes): string {
  if($bytes < 1024) return $bytes.' B';
  if($bytes < 1024*1024) return round($bytes/1024,1).' KB';
  return round($bytes/1024/1024,1).' MB';
}

render_header('Logs & Fehleranalyse', $u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Logs &amp; Fehleranalyse</h1>
<p class="muted">Nur lesende Sicht auf die zuletzt geschriebenen Zeilen der Server-Logdateien. Zum Nachvollziehen von Nutzeraktionen die <a href="<?php echo h($bp); ?>/admin/events.php">Eventauswertungen</a> verwenden – diese Seite ist für technische Fehleranalyse gedacht.</p>

<div class="row" style="gap:8px;margin-top:10px">
  <?php foreach($logDefs as $key => $d): ?>
    <a class="btn <?php echo $key===$logKey?'':'secondary'; ?>" href="<?php echo h('logs.php?log='.$key); ?>"><?php echo h($d['label']); ?></a>
  <?php endforeach; ?>
</div>
<div class="muted" style="font-size:13px;margin-top:8px"><?php echo h($def['desc']); ?></div>

<?php if($path === null): ?>
  <div class="notice info" style="margin-top:14px">
    Keine Logdatei gefunden.
    <?php if($logKey === 'webuntis_cron'): ?>
      Das Cron-Skript schreibt keine eigene Logdatei – dafür muss die Ausgabe in der Server-Crontab umgeleitet werden, z. B.:
      <br><code>0 3 * * * php /pfad/zu/cool-grades/tools/webuntis_cron_import.php &gt;&gt; /pfad/zu/cool-grades/logs/webuntis_cron.log 2&gt;&amp;1</code>
      <br>Erwarteter Speicherort: <code>logs/webuntis_cron.log</code> im App-Verzeichnis (oder im konfigurierten <code>log_dir</code>).
    <?php else: ?>
      Es wurde noch nichts in diese Datei geschrieben, oder der konfigurierte <code>log_dir</code> in <code>config.php</code> zeigt auf ein anderes Verzeichnis.
    <?php endif; ?>
  </div>
<?php else: ?>

  <form method="get" class="row" style="align-items:end;margin-top:14px">
    <input type="hidden" name="log" value="<?php echo h($logKey); ?>">
    <div style="flex:1 1 220px">
      <label class="muted">Suche (Text/Fehlermeldung)</label>
      <input class="input" type="text" name="q" value="<?php echo h($q); ?>" placeholder="z. B. Exception, Dateiname, Nutzername">
    </div>
    <?php if($def['format'] === 'jsonl'): ?>
    <div>
      <label class="muted">Stufe</label>
      <select class="input" name="level">
        <option value="">– alle –</option>
        <?php foreach(['error','warn','info'] as $lvl): ?>
          <option value="<?php echo h($lvl); ?>" <?php echo $levelFilter===$lvl?'selected':''; ?>><?php echo h($lvl); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label class="muted">Zeilen</label>
      <select class="input" name="lines">
        <?php foreach([100,300,1000,3000] as $n): ?>
          <option value="<?php echo $n; ?>" <?php echo $maxLines===$n?'selected':''; ?>><?php echo $n; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Filtern</button></div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><a class="btn" href="<?php echo h(_logs_qs(['download'=>'1'])); ?>">Ganze Datei herunterladen</a></div>
  </form>

  <div class="muted" style="font-size:12px;margin-top:8px">
    Datei: <code><?php echo h($path); ?></code> ·
    Größe: <?php echo h(_logs_format_bytes((int)$fileInfo['size'])); ?> ·
    zuletzt geändert: <?php echo h(date('d.m.Y H:i:s', (int)$fileInfo['mtime'])); ?> ·
    zeigt die letzten <?php echo (int)$maxLines; ?> Zeilen<?php if($q !== '' || $levelFilter !== ''): ?>, gefiltert<?php endif; ?>
  </div>

  <?php if(!$rows): ?>
    <div class="notice info" style="margin-top:14px">Keine Einträge gefunden (bei aktivem Filter ggf. Suchbegriff/Stufe prüfen oder mehr Zeilen laden).</div>
  <?php elseif($def['format'] === 'jsonl'): ?>
    <div style="overflow-x:auto;margin-top:12px">
    <table class="table">
      <thead><tr><th style="white-space:nowrap">Zeit</th><th style="white-space:nowrap">Stufe</th><th>Nachricht</th><th>Kontext</th></tr></thead>
      <tbody>
        <?php foreach($rows as $row): ?>
          <tr>
            <td style="white-space:nowrap"><?php echo $row['raw'] ? '<span class="muted">–</span>' : h(_logs_format_ts($row['ts'])); ?></td>
            <td><?php echo $row['raw'] ? '<span class="muted">–</span>' : _logs_level_badge($row['level']); ?></td>
            <td style="max-width:520px;word-break:break-word"><?php echo h($row['msg']); ?></td>
            <td>
              <?php if(!empty($row['ctx'])): ?>
                <details>
                  <summary style="cursor:pointer">Details</summary>
                  <pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;margin:6px 0 0 0"><?php echo h(json_encode($row['ctx'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?></pre>
                </details>
              <?php else: ?>
                <span class="muted">–</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if($parseErrors > 0): ?>
      <div class="muted" style="font-size:12px;margin-top:6px"><?php echo (int)$parseErrors; ?> Zeile(n) konnten nicht als Log-Eintrag gelesen werden und werden unverändert angezeigt.</div>
    <?php endif; ?>
  <?php else: ?>
    <pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;margin-top:12px;max-height:70vh;overflow:auto;background:rgba(0,0,0,.03);padding:12px;border-radius:8px"><?php
      foreach($rows as $row){ echo h($row['msg'])."\n"; }
    ?></pre>
  <?php endif; ?>

<?php endif; ?>

<div style="height:12px"></div>
<a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück zu den Einstellungen</a>
</div></div></div>
<?php render_footer(); ?>
