<?php
if(PHP_VERSION_ID < 80000){
  http_response_code(500);
  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>PHP-Version nicht unterstützt</title></head><body>';
  echo '<p>COOL-Grades benötigt PHP 8.0 oder neuer. Auf diesem Server läuft PHP '.htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'.</p>';
  echo '</body></html>';
  exit;
}
require_once __DIR__.'/lib/db.php';
require_once __DIR__.'/lib/helpers.php';
require_once __DIR__.'/lib/demo_installation.php';

if(!headers_sent()){
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
  header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function install_request_is_local(): bool {
  $addr = $_SERVER['REMOTE_ADDR'] ?? '';
  return in_array($addr, ['127.0.0.1', '::1'], true);
}

function install_local_only_enabled(array $cfg): bool {
  return filter_var($cfg['install_local_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function install_lock_file(): string {
  return __DIR__.'/install.lock';
}

function install_mark_locked(): void {
  $ok = @file_put_contents(install_lock_file(), 'installed_at='.date('c')."\n");
  if($ok === false){
    throw new RuntimeException('install.lock konnte nicht geschrieben werden. Bitte Schreibrechte im Installationsverzeichnis prüfen.');
  }
}

function install_render_form(string $token, string $defaultYear, string $error = ''): void {
  if($error !== ''){
    echo "<div style='border:1px solid #b91c1c;background:#fff5f5;color:#7f1d1d;padding:12px;border-radius:10px;margin:12px 0'>".h($error)."</div>";
  }
  echo "<form method='post' style='max-width:760px;border:1px solid #d9e2d4;border-radius:14px;padding:18px;background:#fbfdf8'>";
  echo "<input type='hidden' name='token' value='".h($token)."'>";
  echo "<h3>Installationsart wählen</h3>";
  echo "<p>Bitte entscheiden Sie, ob diese Installation produktiv genutzt oder ausschließlich als Demo mit Musterdaten angelegt werden soll.</p>";
  echo "<label style='display:block;margin:12px 0;padding:12px;border:1px solid #d7dfd1;border-radius:10px;background:white'>";
  echo "<input type='radio' name='install_mode' value='production' checked> <strong>Produktivinstallation</strong><br>";
  echo "<span style='color:#52605a'>Es wird ein erstes Administrationskonto mit zufälligem Startpasswort angelegt.</span>";
  echo "</label>";
  echo "<label style='display:block;margin:12px 0;padding:12px;border:1px solid #d7dfd1;border-radius:10px;background:white'>";
  echo "<input type='radio' name='install_mode' value='demo'> <strong>Demoinstallation</strong><br>";
  echo "<span style='color:#52605a'>Es werden Demo-Schule, Demo-Klasse, Demo-Fächer, Demo-Benutzer und umfangreiche Beispieldaten erstellt. Diese Installation ist nicht für echte Schüler:innendaten gedacht.</span>";
  echo "</label>";
  echo "<label style='display:block;margin:12px 0'><strong>Demoschuljahr</strong><br>";
  echo "<input name='demo_school_year' value='".h($defaultYear)."' placeholder='2025/26' style='padding:9px;border:1px solid #cbd5c4;border-radius:8px;min-width:180px'> ";
  echo "<span style='color:#52605a'>Nur für Demoinstallation, Format z. B. 2025/26.</span></label>";
  echo "<button type='submit' name='run_install' value='1' style='padding:10px 16px;border:0;border-radius:9px;background:#2F6F3A;color:white;font-weight:700'>Installation starten</button>";
  echo "</form>";
}

function install_render_internal_error(Throwable $e): void {
  app_log('error', 'installation failed', [
    'type' => get_class($e),
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ]);
  if(!headers_sent()) http_response_code(500);
  echo "<div style='border:1px solid #b91c1c;background:#fff5f5;color:#7f1d1d;padding:12px;border-radius:10px;margin:12px 0'>";
  echo "<strong>Installation konnte nicht abgeschlossen werden.</strong><br>";
  echo "Bitte prüfen Sie Datenbankzugang, Datenbankrechte und den Logordner. Details wurden in die Fehlerlogs geschrieben, sofern der Webserver dort schreiben darf.";
  echo "<br><span style='font-size:13px'>Logverzeichnis laut Konfiguration: <code>".h(app_log_dir())."</code></span>";
  echo "</div>";
}

echo "<h2>COOL Noten & Mitarbeit – Installation</h2>";
if(is_file(install_lock_file())){
  http_response_code(403);
  echo "<p>Die Installation wurde bereits abgeschlossen. Entfernen Sie <code>install.php</code> vom Server.</p>";
  exit;
}
if(!file_exists(__DIR__.'/config.php')){
  echo "<p><b>Schritt 1:</b> <code>config.example.php</code> nach <code>config.php</code> kopieren und DB-Zugangsdaten eintragen.</p>";
  echo "<p>Zusätzlich muss in <code>config.php</code> ein langes <code>install_token</code> gesetzt werden.</p>";
  exit;
}

$cfg = cfg();
$configuredToken = (string)($cfg['install_token'] ?? '');
$providedToken = (string)($_POST['token'] ?? $_GET['token'] ?? '');
if($configuredToken === '' || str_contains($configuredToken, 'CHANGE_ME') || strlen($configuredToken) < 24){
  http_response_code(403);
  echo "<p>Bitte in <code>config.php</code> zuerst ein langes, zufälliges <code>install_token</code> setzen.</p>";
  exit;
}
if($providedToken === '' || !hash_equals($configuredToken, $providedToken)){
  http_response_code(403);
  echo "<p>Installation nur mit gültigem Einmal-Token möglich.</p>";
  echo "<p>Aufruf: <code>install.php?token=...</code></p>";
  exit;
}
if(install_local_only_enabled($cfg) && !install_request_is_local()){
  http_response_code(403);
  app_log('warn', 'installation blocked by install_local_only', [
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
  ]);
  echo "<p>Installation nur von localhost erlaubt, weil <code>install_local_only</code> aktiv ist.</p>";
  exit;
}
try{
  $pdo=db();
  $schema=file_get_contents(__DIR__.'/schema.sql');
  if($schema === false) throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
  $pdo->exec($schema);
  echo "<p>✅ Tabellen erstellt/aktualisiert.</p>";
}catch(Throwable $e){
  install_render_internal_error($e);
  exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST' || (string)($_POST['run_install'] ?? '') !== '1'){
  install_render_form($providedToken, demo_installation_default_year_label());
  exit;
}

$installMode = (string)($_POST['install_mode'] ?? 'production');
if(!in_array($installMode, ['production','demo'], true)){
  install_render_form($providedToken, demo_installation_default_year_label(), 'Bitte eine gültige Installationsart wählen.');
  exit;
}

$st=$pdo->query("SELECT COUNT(*) AS c FROM users")->fetch();
if($installMode === 'demo'){
  if((int)$st['c'] !== 0){
    install_render_form($providedToken, demo_installation_default_year_label(), 'Eine Demoinstallation kann nur in einer leeren Installation ohne bestehende Benutzerkonten angelegt werden.');
    exit;
  }
  try{
    $demo = demo_installation_seed($pdo, (string)($_POST['demo_school_year'] ?? demo_installation_default_year_label()));
  }catch(Throwable $e){
    install_render_form($providedToken, demo_installation_default_year_label(), $e->getMessage());
    exit;
  }
  echo "<p>✅ Demoinstallation angelegt.</p>";
  echo "<p><strong>Demo-Schule:</strong> ".h($demo['school'])." · <strong>Klasse:</strong> ".h($demo['class'])." · <strong>Schuljahr:</strong> ".h($demo['school_year'])."</p>";
  echo "<p><strong>Demo-Admin:</strong> <code>".h($demo['admin_username'])."</code> / <code>".h($demo['password'])."</code></p>";
  echo "<p><strong>Demo-Lehrer:in:</strong> <code>".h($demo['teacher_username'])."</code> / <code>".h($demo['password'])."</code></p>";
  echo "<p><strong>Hinweis:</strong> Diese Installation ist als Demo markiert. Sie können sie im Adminbereich zurücksetzen oder löschen.</p>";
} elseif((int)$st['c']===0){
  try{
    $initialPassword = bin2hex(random_bytes(8));
    $hash=password_hash($initialPassword, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username,first_name,last_name,role,pass_hash,is_active,must_change_password,created_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute(['admin','Admin','User','admin',$hash,1,1,now_iso()]);
    echo "<p>✅ Admin angelegt: <code>admin</code> / <code>".h($initialPassword)."</code> (bitte Passwort ändern)</p>";
  }catch(Throwable $e){
    install_render_internal_error($e);
    exit;
  }
} else {
  echo "<p>Es existiert bereits mindestens ein Benutzer. Keine neuen Default-Zugangsdaten erzeugt.</p>";
}
try{
  install_mark_locked();
  echo "<p>✅ Installation gesperrt: <code>install.lock</code> wurde angelegt.</p>";
}catch(Throwable $e){
  install_render_internal_error($e);
  exit;
}
echo "<p><strong>Wichtig:</strong> Entfernen Sie <code>install.php</code> nach erfolgreicher Installation vom Server.</p>";
echo "<p><a href='login.php'>Zum Login</a></p>";
