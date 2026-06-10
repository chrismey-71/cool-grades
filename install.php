<?php
require_once __DIR__.'/lib/db.php';
require_once __DIR__.'/lib/helpers.php';

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

function install_lock_file(): string {
  return __DIR__.'/install.lock';
}

function install_mark_locked(): void {
  @file_put_contents(install_lock_file(), 'installed_at='.date('c')."\n");
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
if(!install_request_is_local()){
  http_response_code(403);
  echo "<p>Installation nur von localhost erlaubt.</p>";
  exit;
}
$pdo=db();
$schema=file_get_contents(__DIR__.'/schema.sql');
$pdo->exec($schema);
echo "<p>✅ Tabellen erstellt/aktualisiert.</p>";
$st=$pdo->query("SELECT COUNT(*) AS c FROM users")->fetch();
if((int)$st['c']===0){
  $initialPassword = bin2hex(random_bytes(8));
  $hash=password_hash($initialPassword, PASSWORD_DEFAULT);
  $pdo->prepare("INSERT INTO users (username,first_name,last_name,role,pass_hash,is_active,must_change_password,created_at) VALUES (?,?,?,?,?,?,?,?)")
      ->execute(['admin','Admin','User','admin',$hash,1,1,now_iso()]);
  echo "<p>✅ Admin angelegt: <code>admin</code> / <code>".h($initialPassword)."</code> (bitte Passwort ändern)</p>";
} else {
  echo "<p>Es existiert bereits mindestens ein Benutzer. Keine neuen Default-Zugangsdaten erzeugt.</p>";
}
install_mark_locked();
echo "<p>✅ Installation gesperrt: <code>install.lock</code> wurde angelegt.</p>";
echo "<p><strong>Wichtig:</strong> Entfernen Sie <code>install.php</code> nach erfolgreicher Installation vom Server.</p>";
echo "<p><a href='login.php'>Zum Login</a></p>";
