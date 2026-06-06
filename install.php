<?php
require_once __DIR__.'/lib/db.php';
require_once __DIR__.'/lib/helpers.php';

function install_request_is_local(): bool {
  $addr = $_SERVER['REMOTE_ADDR'] ?? '';
  return in_array($addr, ['127.0.0.1', '::1'], true);
}

echo "<h2>COOL Noten & Mitarbeit – Installation</h2>";
if(!install_request_is_local()){
  http_response_code(403);
  echo "<p>Installation nur von localhost erlaubt.</p>";
  exit;
}
if(!file_exists(__DIR__.'/config.php')){
  echo "<p><b>Schritt 1:</b> <code>config.example.php</code> nach <code>config.php</code> kopieren und DB-Zugangsdaten eintragen.</p>";
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
echo "<p><a href='login.php'>Zum Login</a></p>";
