<?php
/**
 * Temporary installation diagnostics.
 *
 * Use only during setup:
 *   install_check.php?token=YOUR_INSTALL_TOKEN
 *
 * Delete this file after installation.
 */

function ic_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ic_row(string $label, bool $ok, string $detail): void {
  $color = $ok ? '#166534' : '#991b1b';
  $bg = $ok ? '#f0fdf4' : '#fef2f2';
  echo '<tr>';
  echo '<th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb">'.ic_h($label).'</th>';
  echo '<td style="padding:8px;border-bottom:1px solid #e5e7eb;background:'.$bg.';color:'.$color.';font-weight:700">'.($ok ? 'OK' : 'Prüfen').'</td>';
  echo '<td style="padding:8px;border-bottom:1px solid #e5e7eb">'.ic_h($detail).'</td>';
  echo '</tr>';
}

function ic_config_load(string $configFile): array {
  if(!is_file($configFile)){
    return [false, null, 'config.php wurde nicht gefunden.'];
  }
  try{
    $cfg = require $configFile;
    if(!is_array($cfg)){
      return [false, null, 'config.php muss ein PHP-Array zurückgeben.'];
    }
    return [true, $cfg, 'config.php wurde geladen.'];
  }catch(Throwable $e){
    return [false, null, get_class($e).': '.$e->getMessage()];
  }
}

if(!headers_sent()){
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}

echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>COOL-Grades Installationsprüfung</title></head><body style="font-family:Arial,sans-serif;max-width:980px;margin:28px auto;padding:0 18px;line-height:1.45">';
echo '<h1>COOL-Grades Installationsprüfung</h1>';
echo '<p>Diese Seite dient nur der Einrichtung. Bitte nach erfolgreicher Installation löschen.</p>';

$root = __DIR__;
$configFile = $root.'/config.php';
[$configOk, $cfg, $configMessage] = ic_config_load($configFile);
$providedToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$configuredToken = $configOk ? (string)($cfg['install_token'] ?? '') : '';
$tokenOk = $configuredToken !== '' && strlen($configuredToken) >= 24 && $providedToken !== '' && hash_equals($configuredToken, $providedToken);

if(!$tokenOk){
  http_response_code(403);
  echo '<div style="padding:12px;border:1px solid #991b1b;background:#fef2f2;color:#991b1b;border-radius:8px">';
  echo '<strong>Diagnose nur mit gültigem Install-Token möglich.</strong><br>';
  echo 'Aufruf: <code>install_check.php?token=...</code>';
  if(!$configOk) echo '<br>Zusätzlich: '.ic_h($configMessage);
  echo '</div></body></html>';
  exit;
}

echo '<table style="border-collapse:collapse;width:100%;margin-top:18px">';
echo '<thead><tr><th style="text-align:left;padding:8px">Prüfung</th><th style="text-align:left;padding:8px">Status</th><th style="text-align:left;padding:8px">Details</th></tr></thead><tbody>';

ic_row('PHP-Version', PHP_VERSION_ID >= 80000, PHP_VERSION.' (empfohlen/erforderlich: PHP 8.0 oder neuer)');
ic_row('config.php', $configOk, $configMessage);
ic_row('PDO-Erweiterung', extension_loaded('pdo'), extension_loaded('pdo') ? 'PDO ist geladen.' : 'PHP-Erweiterung PDO fehlt.');
ic_row('PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'pdo_mysql ist geladen.' : 'PHP-Erweiterung pdo_mysql fehlt.');
ic_row('schema.sql lesbar', is_readable($root.'/schema.sql'), $root.'/schema.sql');

$logDir = trim((string)($cfg['log_dir'] ?? ($root.'/logs')));
if($logDir === '') $logDir = $root.'/logs';
$logDirExists = is_dir($logDir) || @mkdir($logDir, 0750, true);
$logTestFile = rtrim($logDir, '/').'/install_check.tmp';
$logWritable = $logDirExists && @file_put_contents($logTestFile, 'ok '.date('c')) !== false;
if(is_file($logTestFile)) @unlink($logTestFile);
ic_row('Logverzeichnis beschreibbar', $logWritable, $logDir);

$lockFile = $root.'/install.lock';
$lockWritable = is_file($lockFile) ? is_writable($lockFile) : is_writable($root);
ic_row('install.lock möglich', $lockWritable, is_file($lockFile) ? 'install.lock existiert bereits: '.$lockFile : 'Installationsverzeichnis beschreibbar: '.$root);

$dbOk = false;
$dbDetail = 'Keine Datenbankdaten gefunden.';
try{
  $db = (array)($cfg['db'] ?? []);
  $dsn = (string)($db['dsn'] ?? '');
  if($dsn === ''){
    $host = (string)($db['host'] ?? '');
    $name = (string)($db['name'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
  }
  $pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
  $dbOk = true;
  $dbDetail = 'Verbindung erfolgreich. MySQL/MariaDB: '.$version;
}catch(Throwable $e){
  $dbDetail = get_class($e).': '.$e->getMessage();
}
ic_row('Datenbankverbindung', $dbOk, $dbDetail);

echo '</tbody></table>';
echo '<p style="margin-top:18px"><strong>Nächster Schritt:</strong> Wenn alles OK ist, <code>install.php?token=...</code> öffnen. Wenn etwas rot ist, bitte genau diese Zeile korrigieren.</p>';
echo '</body></html>';
