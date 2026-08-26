<?php
if(PHP_VERSION_ID < 80000){
  http_response_code(500);
  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>PHP-Version nicht unterstützt</title></head><body>';
  echo '<p>COOL-Grades benötigt PHP 8.0 oder neuer. Auf diesem Server läuft PHP '.htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'.</p>';
  echo '</body></html>';
  exit;
}

if(session_status() !== PHP_SESSION_ACTIVE){
  session_name('coolgrades_install_sid');
  session_start();
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

const INSTALL_MIN_PHP = 80000;

function install_h($value): string {
  return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function install_root(): string {
  return __DIR__;
}

function install_lock_file(): string {
  return install_root().'/install.lock';
}

function install_config_file(): string {
  return install_root().'/config.php';
}

function install_request_is_local(): bool {
  $addr = $_SERVER['REMOTE_ADDR'] ?? '';
  return in_array($addr, ['127.0.0.1', '::1'], true);
}

function install_random_token(): string {
  return bin2hex(random_bytes(32));
}

function install_state(): array {
  if(!isset($_SESSION['coolgrades_install']) || !is_array($_SESSION['coolgrades_install'])){
    $_SESSION['coolgrades_install'] = [];
  }
  return $_SESSION['coolgrades_install'];
}

function install_state_set(array $state): void {
  $_SESSION['coolgrades_install'] = $state;
}

function install_config_load_existing(): ?array {
  $file = install_config_file();
  if(!is_file($file)) return null;
  try{
    $cfg = require $file;
    return is_array($cfg) ? $cfg : null;
  }catch(Throwable $e){
    return null;
  }
}

function install_default_config_values(): array {
  $existing = install_config_load_existing() ?? [];
  $db = (array)($existing['db'] ?? []);
  return [
    'db_host' => (string)($db['host'] ?? 'localhost'),
    'db_name' => (string)($db['name'] ?? ''),
    'db_user' => (string)($db['user'] ?? ''),
    'db_pass' => '',
    'db_charset' => (string)($db['charset'] ?? 'utf8mb4'),
    'base_path' => (string)($existing['base_path'] ?? ''),
    'session_name' => (string)($existing['session_name'] ?? 'coolgrades_sid'),
    'timezone' => (string)($existing['timezone'] ?? 'Europe/Vienna'),
    'demo_reset_schedule' => (string)($existing['demo_reset_schedule'] ?? 'täglich ca. 03:00 Uhr'),
    'install_token' => (string)($existing['install_token'] ?? install_random_token()),
    'install_local_only' => !empty($existing['install_local_only']) ? '1' : '0',
    'log_dir' => (string)($existing['log_dir'] ?? '{APP}/../cool-grades-logs'),
  ];
}

function install_log_dir_resolve(string $input): string {
  $value = trim($input);
  if($value === '') return install_root().'/logs';
  $value = str_replace(['{APP}', '{ROOT}'], install_root(), $value);
  return $value;
}

function install_config_from_post(): array {
  return [
    'db_host' => trim((string)($_POST['db_host'] ?? 'localhost')),
    'db_name' => trim((string)($_POST['db_name'] ?? '')),
    'db_user' => trim((string)($_POST['db_user'] ?? '')),
    'db_pass' => (string)($_POST['db_pass'] ?? ''),
    'db_charset' => trim((string)($_POST['db_charset'] ?? 'utf8mb4')),
    'base_path' => trim((string)($_POST['base_path'] ?? '')),
    'session_name' => trim((string)($_POST['session_name'] ?? 'coolgrades_sid')),
    'timezone' => trim((string)($_POST['timezone'] ?? 'Europe/Vienna')),
    'demo_reset_schedule' => trim((string)($_POST['demo_reset_schedule'] ?? '')),
    'install_token' => trim((string)($_POST['install_token'] ?? install_random_token())),
    'install_local_only' => (string)($_POST['install_local_only'] ?? '0') === '1' ? '1' : '0',
    'log_dir' => trim((string)($_POST['log_dir'] ?? '{APP}/logs')),
  ];
}

function install_validate_config(array $cfg): array {
  $errors = [];
  if($cfg['db_host'] === '') $errors[] = 'Bitte den Datenbank-Host angeben.';
  if($cfg['db_name'] === '') $errors[] = 'Bitte den Datenbanknamen angeben.';
  if($cfg['db_user'] === '') $errors[] = 'Bitte den Datenbankbenutzer angeben.';
  if(!preg_match('/^[a-zA-Z0-9_\-.]+$/', (string)$cfg['db_charset'])) $errors[] = 'Bitte einen gültigen Datenbank-Zeichensatz angeben.';
  if(!preg_match('/^[a-zA-Z0-9_-]{6,64}$/', (string)$cfg['session_name'])) $errors[] = 'Der Session-Name darf nur Buchstaben, Zahlen, Unterstrich und Bindestrich enthalten und muss mindestens 6 Zeichen haben.';
  if($cfg['timezone'] !== ''){
    try{ new DateTimeZone((string)$cfg['timezone']); }catch(Throwable $e){ $errors[] = 'Die Zeitzone ist ungültig. Beispiel: Europe/Vienna.'; }
  }
  if(strlen((string)$cfg['install_token']) < 24) $errors[] = 'Das Installations-Token muss mindestens 24 Zeichen lang sein.';
  return $errors;
}

function install_pdo_from_config(array $cfg): PDO {
  $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset={$cfg['db_charset']}";
  return new PDO($dsn, (string)$cfg['db_user'], (string)$cfg['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}

function install_safe_db_error(Throwable $e): string {
  $message = $e->getMessage();
  $message = preg_replace('/password=[^;\s]+/i', 'password=***', $message) ?? $message;
  return get_class($e).': '.$message;
}

function install_test_db(array $cfg): array {
  try{
    $pdo = install_pdo_from_config($cfg);
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    return ['ok' => true, 'detail' => 'Verbindung erfolgreich. MySQL/MariaDB: '.$version];
  }catch(Throwable $e){
    return ['ok' => false, 'detail' => install_safe_db_error($e)];
  }
}

function install_check_log_dir(string $logDirInput): array {
  $logDir = install_log_dir_resolve($logDirInput);
  $exists = is_dir($logDir) || @mkdir($logDir, 0750, true);
  $testFile = rtrim($logDir, '/').'/install_check.tmp';
  $writable = $exists && @file_put_contents($testFile, 'ok '.date('c')) !== false;
  if(is_file($testFile)) @unlink($testFile);
  return [$writable, $logDir];
}

function install_diagnostics(?array $cfg = null): array {
  $configExists = is_file(install_config_file());
  [$logWritable, $logDir] = install_check_log_dir((string)($cfg['log_dir'] ?? '{APP}/../cool-grades-logs'));
  $lockWritable = is_file(install_lock_file()) ? is_writable(install_lock_file()) : is_writable(install_root());
  $configWritable = $configExists ? is_writable(install_config_file()) : is_writable(install_root());
  $rows = [
    ['PHP-Version', PHP_VERSION_ID >= INSTALL_MIN_PHP ? 'ok' : 'error', PHP_VERSION.' (erforderlich: PHP 8.0 oder neuer)'],
    ['PDO-Erweiterung', extension_loaded('pdo') ? 'ok' : 'error', extension_loaded('pdo') ? 'PDO ist geladen.' : 'PHP-Erweiterung PDO fehlt.'],
    ['PDO MySQL', extension_loaded('pdo_mysql') ? 'ok' : 'error', extension_loaded('pdo_mysql') ? 'pdo_mysql ist geladen.' : 'PHP-Erweiterung pdo_mysql fehlt.'],
    ['schema.sql lesbar', is_readable(install_root().'/schema.sql') ? 'ok' : 'error', install_root().'/schema.sql'],
    ['config.php schreiben', $configWritable ? 'ok' : 'warn', $configExists ? 'config.php existiert bereits.' : 'Installationsverzeichnis muss für config.php beschreibbar sein.'],
    ['install.lock möglich', $lockWritable ? 'ok' : 'error', is_file(install_lock_file()) ? 'install.lock existiert bereits.' : 'Installationsverzeichnis beschreibbar: '.install_root()],
    ['Logverzeichnis beschreibbar', $logWritable ? 'ok' : 'warn', $logDir],
  ];
  if($cfg !== null){
    $db = install_test_db($cfg);
    $rows[] = ['Datenbankverbindung', $db['ok'] ? 'ok' : 'error', $db['detail']];
  } else {
    $rows[] = ['Datenbankverbindung', 'warn', 'Wird nach Eingabe der Datenbankdaten geprüft.'];
  }
  return $rows;
}

function install_has_errors(array $diagnostics): bool {
  foreach($diagnostics as $row){
    if(($row[1] ?? '') === 'error') return true;
  }
  return false;
}

function install_log_dir_code(string $input): string {
  $input = trim($input);
  if($input === '' || $input === '{APP}/logs' || $input === '{ROOT}/logs') return "__DIR__.'/logs'";
  if(str_starts_with($input, '{APP}/')) return "__DIR__.".var_export('/'.substr($input, 6), true);
  if(str_starts_with($input, '{ROOT}/')) return "__DIR__.".var_export('/'.substr($input, 7), true);
  return var_export($input, true);
}

function install_config_content(array $cfg): string {
  return "<?php\nreturn [\n"
    ."  'db' => [\n"
    ."    'host' => ".var_export((string)$cfg['db_host'], true).",\n"
    ."    'name' => ".var_export((string)$cfg['db_name'], true).",\n"
    ."    'user' => ".var_export((string)$cfg['db_user'], true).",\n"
    ."    'pass' => ".var_export((string)$cfg['db_pass'], true).",\n"
    ."    'charset' => ".var_export((string)$cfg['db_charset'], true).",\n"
    ."  ],\n"
    ."  'base_path' => ".var_export((string)$cfg['base_path'], true).",\n"
    ."  'session_name' => ".var_export((string)$cfg['session_name'], true).",\n"
    ."  'timezone' => ".var_export((string)$cfg['timezone'], true).",\n"
    ."  'demo_reset_schedule' => ".var_export((string)$cfg['demo_reset_schedule'], true).",\n"
    ."  'install_token' => ".var_export((string)$cfg['install_token'], true).",\n"
    ."  'install_local_only' => ".(((string)$cfg['install_local_only'] === '1') ? 'true' : 'false').",\n"
    ."  'log_dir' => ".install_log_dir_code((string)$cfg['log_dir']).",\n"
    ."];\n";
}

function install_write_config(array $cfg): array {
  $content = install_config_content($cfg);
  $ok = @file_put_contents(install_config_file(), $content, LOCK_EX);
  if($ok === false && install_existing_config_matches($cfg)){
    return [true, $content];
  }
  return [$ok !== false, $content];
}

function install_existing_config_matches(array $cfg): bool {
  $existing = install_config_load_existing();
  if(!$existing) return false;
  $db = (array)($existing['db'] ?? []);
  $checks = [
    [(string)($db['host'] ?? ''), (string)$cfg['db_host']],
    [(string)($db['name'] ?? ''), (string)$cfg['db_name']],
    [(string)($db['user'] ?? ''), (string)$cfg['db_user']],
    [(string)($db['pass'] ?? ''), (string)$cfg['db_pass']],
    [(string)($db['charset'] ?? 'utf8mb4'), (string)$cfg['db_charset']],
    [(string)($existing['base_path'] ?? ''), (string)$cfg['base_path']],
    [(string)($existing['session_name'] ?? 'coolgrades_sid'), (string)$cfg['session_name']],
    [(string)($existing['timezone'] ?? ''), (string)$cfg['timezone']],
    [(string)($existing['demo_reset_schedule'] ?? ''), (string)$cfg['demo_reset_schedule']],
    [(string)($existing['install_token'] ?? ''), (string)$cfg['install_token']],
    [!empty($existing['install_local_only']) ? '1' : '0', (string)$cfg['install_local_only']],
    [install_log_dir_resolve((string)($existing['log_dir'] ?? '')), install_log_dir_resolve((string)$cfg['log_dir'])],
  ];
  foreach($checks as [$left, $right]){
    if($left !== $right) return false;
  }
  return true;
}

function install_mark_locked(): void {
  $ok = @file_put_contents(install_lock_file(), 'installed_at='.date('c')."\n");
  if($ok === false) throw new RuntimeException('install.lock konnte nicht geschrieben werden. Bitte Schreibrechte im Installationsverzeichnis prüfen.');
}

function install_password_policy_errors_default(string $pw): array {
  $errors = [];
  if(mb_strlen($pw) < 12) $errors[] = 'mindestens 12 Zeichen';
  if(!preg_match('/[A-ZÄÖÜ]/u', $pw)) $errors[] = 'mindestens einen Großbuchstaben';
  if(!preg_match('/[a-zäöüß]/u', $pw)) $errors[] = 'mindestens einen Kleinbuchstaben';
  if(!preg_match('/\d/u', $pw)) $errors[] = 'mindestens eine Zahl';
  if(!preg_match('/[^\p{L}\p{N}\s]/u', $pw)) $errors[] = 'mindestens ein Sonderzeichen';
  return $errors;
}

function install_admin_from_post(): array {
  return [
    'username' => trim((string)($_POST['admin_username'] ?? '')),
    'first_name' => trim((string)($_POST['admin_first_name'] ?? '')),
    'last_name' => trim((string)($_POST['admin_last_name'] ?? '')),
    'password' => (string)($_POST['admin_password'] ?? ''),
    'password_confirm' => (string)($_POST['admin_password_confirm'] ?? ''),
  ];
}

function install_validate_admin(array $admin): array {
  $errors = [];
  if(!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', (string)$admin['username'])) $errors[] = 'Bitte einen Benutzernamen mit 3 bis 64 Zeichen verwenden.';
  if($admin['first_name'] === '') $errors[] = 'Bitte den Vornamen des Admins angeben.';
  if($admin['last_name'] === '') $errors[] = 'Bitte den Nachnamen des Admins angeben.';
  if($admin['password'] !== $admin['password_confirm']) $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
  foreach(install_password_policy_errors_default((string)$admin['password']) as $error) $errors[] = 'Passwort: '.$error.'.';
  return $errors;
}

function install_run_schema(PDO $pdo): void {
  $schema = file_get_contents(install_root().'/schema.sql');
  if($schema === false) throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
  $pdo->exec($schema);
}

function install_create_admin(PDO $pdo, array $admin): void {
  $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if($count > 0) throw new RuntimeException('Es existiert bereits mindestens ein Benutzerkonto. Die Standardinstallation legt aus Sicherheitsgründen keinen weiteren Erstadmin an.');
  $hash = password_hash((string)$admin['password'], PASSWORD_DEFAULT);
  $pdo->prepare("INSERT INTO users(username,first_name,last_name,role,pass_hash,is_active,must_change_password,created_at)
                 VALUES(?,?,?,?,?,?,?,?)")
      ->execute([(string)$admin['username'], (string)$admin['first_name'], (string)$admin['last_name'], 'admin', $hash, 1, 0, now_iso()]);
}

function install_run_installation(array $state): array {
  $cfg = (array)($state['config'] ?? []);
  $mode = (string)($state['mode'] ?? 'production');
  [$configWritten, $configContent] = install_write_config($cfg);
  if(!$configWritten){
    return [
      'ok' => false,
      'manual_config' => true,
      'config_content' => $configContent,
      'message' => 'config.php konnte nicht automatisch geschrieben werden. Bitte den Inhalt manuell als config.php speichern und danach den Assistenten erneut öffnen.',
    ];
  }
  if((string)($cfg['timezone'] ?? '') !== ''){
    try{ date_default_timezone_set((string)$cfg['timezone']); }catch(Throwable $e){ /* ignore */ }
  }
  $pdo = install_pdo_from_config($cfg);
  install_run_schema($pdo);
  if($mode === 'demo'){
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if($count > 0) throw new RuntimeException('Eine Demoinstallation kann nur in einer leeren Installation ohne bestehende Benutzerkonten angelegt werden.');
    $demo = demo_installation_seed($pdo, (string)($state['demo_school_year'] ?? demo_installation_default_year_label()));
    install_mark_locked();
    return ['ok' => true, 'mode' => 'demo', 'demo' => $demo];
  }
  install_create_admin($pdo, (array)($state['admin'] ?? []));
  install_mark_locked();
  return ['ok' => true, 'mode' => 'production', 'admin_username' => (string)($state['admin']['username'] ?? '')];
}

function install_step_url(string $step): string {
  return 'install.php?step='.rawurlencode($step);
}

function install_render_header(string $step): void {
  $steps = ['welcome'=>'Willkommen','system'=>'Systemprüfung','database'=>'Datenbank/config.php','mode'=>'Installationsart','admin'=>'Admin/Demo','summary'=>'Zusammenfassung','run'=>'Installation'];
  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>COOL-Grades Installation</title>';
  echo '<style>body{font-family:Arial,sans-serif;background:#f5f7f2;color:#172018;margin:0;line-height:1.5}.wrap{max-width:1040px;margin:0 auto;padding:28px 18px}.card{background:#fff;border:1px solid #d9e2d4;border-radius:18px;padding:22px;box-shadow:0 10px 32px rgba(38,55,35,.08)}.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:8px;margin:0 0 18px}.step{padding:8px 10px;border-radius:999px;background:#e9efe5;color:#52605a;font-size:13px;text-align:center}.step.active{background:#2f6f3a;color:#fff;font-weight:700}h1{margin:0 0 8px;font-size:28px}h2{margin-top:0}.muted{color:#52605a}.btn{display:inline-block;border:0;border-radius:10px;background:#2f6f3a;color:#fff;padding:10px 15px;font-weight:700;text-decoration:none;cursor:pointer}.btn.secondary{background:#eef4ea;color:#25442b;border:1px solid #cddac8}.btn.danger{background:#9f1d1d}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.field{display:block}label{font-weight:700}.input{box-sizing:border-box;width:100%;margin-top:5px;padding:10px;border:1px solid #cbd5c4;border-radius:10px;font:inherit}.hint,.small{font-size:13px;color:#52605a;margin-top:5px}.alert{border-radius:12px;padding:12px;margin:12px 0}.alert.err{border:1px solid #b91c1c;background:#fff5f5;color:#7f1d1d}.alert.ok{border:1px solid #166534;background:#f0fdf4;color:#14532d}.alert.warn{border:1px solid #b7791f;background:#fffaf0;color:#7c4a03}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:9px;border-bottom:1px solid #e3eadf;text-align:left;vertical-align:top}.status-ok{background:#ecfdf3;color:#166534;font-weight:700}.status-warn{background:#fff7df;color:#7c4a03;font-weight:700}.status-error{background:#fff1f1;color:#991b1b;font-weight:700}code,textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}textarea{width:100%;min-height:280px;border:1px solid #cbd5c4;border-radius:10px;padding:10px}.choice{display:block;border:1px solid #d7dfd1;border-radius:14px;padding:14px;margin:10px 0;background:#fbfdf8}</style></head><body><div class="wrap"><div class="card">';
  echo '<h1>COOL-Grades installieren</h1><p class="muted">Geführter Assistent für Serverprüfung, config.php, Datenbankeinrichtung und erstes Konto.</p><div class="steps">';
  foreach($steps as $key=>$label) echo '<div class="step '.($key===$step?'active':'').'">'.install_h($label).'</div>';
  echo '</div>';
}

function install_render_footer(): void {
  echo '</div></div></body></html>';
}

function install_render_errors(array $errors): void {
  if(!$errors) return;
  echo '<div class="alert err"><strong>Bitte korrigieren:</strong><ul>';
  foreach($errors as $error) echo '<li>'.install_h($error).'</li>';
  echo '</ul></div>';
}

function install_render_diagnostics(array $diagnostics): void {
  echo '<table><thead><tr><th>Prüfung</th><th>Status</th><th>Details</th></tr></thead><tbody>';
  foreach($diagnostics as $row){
    $status = (string)$row[1];
    $label = $status === 'ok' ? 'OK' : ($status === 'warn' ? 'Prüfen' : 'Korrigieren');
    echo '<tr><th>'.install_h($row[0]).'</th><td class="status-'.$status.'">'.install_h($label).'</td><td>'.install_h($row[2]).'</td></tr>';
  }
  echo '</tbody></table>';
}

function install_input(string $label, string $name, string $value, string $hint, string $type = 'text'): void {
  echo '<div class="field"><label>'.install_h($label).'<br><input class="input" type="'.install_h($type).'" name="'.install_h($name).'" value="'.install_h($value).'" autocomplete="off"></label><div class="hint">'.install_h($hint).'</div></div>';
}

function install_render_welcome(): void {
  install_render_header('welcome');
  echo '<h2>Willkommen</h2><p>Dieser Assistent führt durch die vollständige Einrichtung. Die bisher separate Installationsprüfung ist hier integriert; <code>config.php</code> wird aus Ihren Angaben erzeugt.</p>';
  echo '<div class="alert warn"><strong>Wichtig:</strong> Führen Sie die Installation nur mit einer leeren Datenbank aus. Nach erfolgreicher Installation wird <code>install.lock</code> angelegt. Entfernen Sie <code>install.php</code> danach vom Server.</div>';
  echo '<p><strong>Sie benötigen:</strong> Datenbankname, Datenbankbenutzer, Datenbankpasswort und die Entscheidung zwischen Standard- und Demoinstallation.</p>';
  echo '<div class="actions"><a class="btn" href="'.install_step_url('system').'">Installation starten</a></div>';
  install_render_footer();
}

function install_render_system(): void {
  install_render_header('system');
  echo '<h2>Systemprüfung</h2><p>Grün bedeutet in Ordnung, gelb bedeutet prüfen, rot muss vor der Installation korrigiert werden.</p>';
  install_render_diagnostics(install_diagnostics());
  echo '<div class="actions"><a class="btn secondary" href="'.install_step_url('welcome').'">Zurück</a><a class="btn" href="'.install_step_url('database').'">Weiter zu Datenbankdaten</a></div>';
  install_render_footer();
}

function install_render_database(array $values, array $errors = [], ?array $diagnostics = null): void {
  install_render_header('database');
  echo '<h2>Datenbankdaten und config.php</h2><p>Diese Angaben finden Sie meist im Kundenmenü Ihres Hosters. Passwörter werden nicht auf Folgeseiten angezeigt, sondern serverseitig in der Installations-Session gehalten.</p>';
  install_render_errors($errors);
  if($diagnostics) install_render_diagnostics($diagnostics);
  echo '<form method="post" action="'.install_step_url('database').'"><div class="grid">';
  install_input('Datenbank-Host', 'db_host', $values['db_host'], 'Meist localhost. Manche Hoster verwenden einen eigenen Datenbankserver.');
  install_input('Datenbankname', 'db_name', $values['db_name'], 'Name der bereits angelegten Datenbank.');
  install_input('Datenbankbenutzer', 'db_user', $values['db_user'], 'Benutzerkonto mit Rechten für diese Datenbank.');
  install_input('Datenbankpasswort', 'db_pass', '', 'Wird nicht im Klartext auf Folgeseiten angezeigt.', 'password');
  install_input('Zeichensatz', 'db_charset', $values['db_charset'], 'Standard: utf8mb4.');
  install_input('Base-Path', 'base_path', $values['base_path'], 'Leer lassen, wenn COOL-Grades direkt unter der Domain liegt. Beispiel: /cool-grades');
  install_input('Session-Name', 'session_name', $values['session_name'], 'Eindeutiger Cookie-Name, z. B. coolgrades_sid oder coolgrades_demo_sid.');
  install_input('Zeitzone', 'timezone', $values['timezone'], 'Für Österreich: Europe/Vienna.');
  install_input('Demo-Reset-Hinweis', 'demo_reset_schedule', $values['demo_reset_schedule'], 'Öffentlich sichtbarer Text im Demomodus, z. B. täglich ca. 03:00 Uhr.');
  install_input('Installations-Token', 'install_token', $values['install_token'], 'Wird automatisch erzeugt und in config.php gespeichert.');
  install_input('Log-Verzeichnis', 'log_dir', $values['log_dir'], 'Empfohlen: {APP}/../cool-grades-logs oder ein absoluter Pfad außerhalb des Webroots.');
  echo '<div class="field"><label><input type="checkbox" name="install_local_only" value="1" '.($values['install_local_only']==='1'?'checked':'').'> Installation nur von localhost erlauben</label><div class="hint">Nur aktivieren, wenn Sie lokal installieren oder Ihr Hosting dies erlaubt.</div></div>';
  echo '</div><div class="actions"><a class="btn secondary" href="'.install_step_url('system').'">Zurück</a><button class="btn" type="submit">Daten prüfen und weiter</button></div></form>';
  install_render_footer();
}

function install_render_mode(array $state, array $errors = []): void {
  install_render_header('mode');
  $mode = (string)($state['mode'] ?? 'production');
  $year = (string)($state['demo_school_year'] ?? demo_installation_default_year_label());
  echo '<h2>Installationsart wählen</h2><p>Standardinstallation und Demoinstallation schließen einander aus. Eine Demoinstallation ist nur für Tests und Vorführungen geeignet.</p>';
  install_render_errors($errors);
  echo '<form method="post" action="'.install_step_url('mode').'">';
  echo '<label class="choice"><input type="radio" name="mode" value="production" '.($mode==='production'?'checked':'').'> <strong>Standardinstallation</strong><br><span class="muted">Für produktive Nutzung. Im nächsten Schritt legen Sie den ersten Admin an.</span></label>';
  echo '<label class="choice"><input type="radio" name="mode" value="demo" '.($mode==='demo'?'checked':'').'> <strong>Demoinstallation</strong><br><span class="muted">Legt Demoschule, Demoklasse, Demo-Fächer und umfangreiche Beispieldaten an. Nicht für echte Schüler:innendaten verwenden.</span></label>';
  echo '<label class="field">Demoschuljahr<br><input class="input" name="demo_school_year" value="'.install_h($year).'" placeholder="2025/26"></label><div class="hint">Nur für Demoinstallation. Semester werden automatisch passend erzeugt.</div>';
  echo '<div class="actions"><a class="btn secondary" href="'.install_step_url('database').'">Zurück</a><button class="btn" type="submit">Weiter</button></div></form>';
  install_render_footer();
}

function install_render_admin(array $state, array $errors = []): void {
  $mode = (string)($state['mode'] ?? 'production');
  install_render_header('admin');
  if($mode === 'demo'){
    echo '<h2>Demo-Daten konfigurieren</h2><p>Für die Demoinstallation werden die Konten <code>demoadmin</code> und <code>demolehrer</code> mit Musterdaten erzeugt.</p>';
    echo '<div class="alert warn">Die Demoinstallation ist nicht für echte Schüler:innendaten geeignet. Sie kann im Adminbereich später zurückgesetzt oder gelöscht werden.</div>';
    echo '<div class="actions"><a class="btn secondary" href="'.install_step_url('mode').'">Zurück</a><a class="btn" href="'.install_step_url('summary').'">Weiter zur Zusammenfassung</a></div>';
    install_render_footer();
    return;
  }
  $admin = (array)($state['admin'] ?? ['username'=>'admin','first_name'=>'','last_name'=>'']);
  echo '<h2>Erstes Administrationskonto</h2><p>Dieses Konto verwaltet Schulen, Klassen, Fächer, Schuljahre und Benutzer:innen. Das Passwort braucht mindestens 12 Zeichen, Groß-/Kleinbuchstaben, Zahl und Sonderzeichen.</p>';
  install_render_errors($errors);
  echo '<form method="post" action="'.install_step_url('admin').'"><div class="grid">';
  install_input('Benutzername', 'admin_username', $admin['username'] ?? 'admin', 'Zum Anmelden. Beispiel: admin.');
  install_input('Vorname', 'admin_first_name', $admin['first_name'] ?? '', 'Wird im Konto angezeigt.');
  install_input('Nachname', 'admin_last_name', $admin['last_name'] ?? '', 'Wird im Konto angezeigt.');
  install_input('Passwort', 'admin_password', '', 'Wird nicht angezeigt und nicht im Klartext gespeichert.', 'password');
  install_input('Passwort wiederholen', 'admin_password_confirm', '', 'Zur Sicherheit erneut eingeben.', 'password');
  echo '</div><div class="actions"><a class="btn secondary" href="'.install_step_url('mode').'">Zurück</a><button class="btn" type="submit">Weiter zur Zusammenfassung</button></div></form>';
  install_render_footer();
}

function install_render_summary(array $state, array $errors = []): void {
  install_render_header('summary');
  $cfg = (array)($state['config'] ?? []);
  $mode = (string)($state['mode'] ?? 'production');
  echo '<h2>Zusammenfassung und Sicherheitsprüfung</h2><p>Bitte prüfen Sie die Angaben. Passwörter werden hier nicht angezeigt.</p>';
  install_render_errors($errors);
  $diagnostics = $cfg ? install_diagnostics($cfg) : install_diagnostics();
  install_render_diagnostics($diagnostics);
  echo '<table><tbody>';
  echo '<tr><th>Installationsart</th><td>'.($mode==='demo'?'Demoinstallation':'Standardinstallation').'</td></tr>';
  echo '<tr><th>Datenbank</th><td>'.install_h(($cfg['db_user'] ?? '').'@'.($cfg['db_host'] ?? '').' / '.($cfg['db_name'] ?? '')).'</td></tr>';
  echo '<tr><th>Zeitzone</th><td>'.install_h($cfg['timezone'] ?? '').'</td></tr>';
  echo '<tr><th>Session-Name</th><td>'.install_h($cfg['session_name'] ?? '').'</td></tr>';
  echo '<tr><th>Log-Verzeichnis</th><td>'.install_h(install_log_dir_resolve((string)($cfg['log_dir'] ?? ''))).'</td></tr>';
  if($mode === 'production') echo '<tr><th>Erster Admin</th><td>'.install_h((string)($state['admin']['username'] ?? '')).'</td></tr>';
  if($mode === 'demo') echo '<tr><th>Demoschuljahr</th><td>'.install_h((string)($state['demo_school_year'] ?? '')).'</td></tr>';
  echo '</tbody></table>';
  if(install_has_errors($diagnostics)){
    echo '<div class="alert err">Bitte beheben Sie die roten Punkte, bevor Sie die Installation ausführen.</div>';
  } else {
    echo '<form method="post" action="'.install_step_url('run').'"><div class="alert warn"><label><input type="checkbox" name="confirm_install" value="1"> Ich bestätige, dass diese Datenbank für COOL-Grades verwendet werden soll und ich die Sicherheitshinweise gelesen habe.</label></div><div class="actions"><a class="btn secondary" href="'.install_step_url('admin').'">Zurück</a><button class="btn danger" type="submit">Installation jetzt ausführen</button></div></form>';
  }
  install_render_footer();
}

function install_render_done(array $result): void {
  install_render_header('run');
  echo '<h2>Installation abgeschlossen</h2>';
  if(($result['mode'] ?? '') === 'demo'){
    $demo = (array)($result['demo'] ?? []);
    echo '<div class="alert ok"><strong>Demoinstallation wurde angelegt.</strong></div>';
    echo '<p><strong>Demo-Schule:</strong> '.install_h($demo['school'] ?? '').' · <strong>Klasse:</strong> '.install_h($demo['class'] ?? '').' · <strong>Schuljahr:</strong> '.install_h($demo['school_year'] ?? '').'</p>';
    echo '<p><strong>Demo-Admin:</strong> <code>'.install_h($demo['admin_username'] ?? '').'</code> / <code>'.install_h($demo['password'] ?? '').'</code></p>';
    echo '<p><strong>Demo-Lehrer:in:</strong> <code>'.install_h($demo['teacher_username'] ?? '').'</code> / <code>'.install_h($demo['password'] ?? '').'</code></p>';
  } else {
    echo '<div class="alert ok"><strong>Standardinstallation wurde angelegt.</strong></div><p>Sie können sich mit dem Administrationskonto <code>'.install_h($result['admin_username'] ?? '').'</code> anmelden.</p>';
  }
  echo '<div class="alert warn"><strong>Sicherheit:</strong> <code>install.lock</code> wurde angelegt. Entfernen Sie <code>install.php</code> nach erfolgreicher Prüfung vom Server oder schützen Sie die Datei serverseitig.</div><div class="actions"><a class="btn" href="login.php">Zum Login</a></div>';
  unset($_SESSION['coolgrades_install']);
  install_render_footer();
}

function install_render_manual_config(array $result): void {
  install_render_header('run');
  echo '<h2>config.php manuell anlegen</h2><div class="alert err">'.install_h($result['message'] ?? 'config.php konnte nicht geschrieben werden.').'</div><p>Der folgende Inhalt enthält sensible Daten. Zeigen Sie diese Seite nicht öffentlich.</p>';
  echo '<textarea readonly>'.install_h($result['config_content'] ?? '').'</textarea><div class="actions"><a class="btn" href="'.install_step_url('summary').'">Nach dem Speichern erneut prüfen</a></div>';
  install_render_footer();
}

if(is_file(install_lock_file())){
  http_response_code(403);
  install_render_header('welcome');
  echo '<h2>Installation bereits abgeschlossen</h2><p>Die Datei <code>install.lock</code> existiert. Die Installation ist gesperrt.</p><div class="actions"><a class="btn" href="login.php">Zum Login</a></div>';
  install_render_footer();
  exit;
}

$step = (string)($_GET['step'] ?? 'welcome');
$state = install_state();
$existingCfg = install_config_load_existing();
if($existingCfg){
  $token = (string)($existingCfg['install_token'] ?? '');
  $provided = (string)($_GET['token'] ?? $_POST['token'] ?? '');
  if($token !== '' && strlen($token) >= 24 && $provided !== '' && hash_equals($token, $provided)){
    $state['token_ok'] = true;
    install_state_set($state);
  } elseif($step !== 'welcome' && empty($state['token_ok'])) {
    http_response_code(403);
    install_render_header('welcome');
    echo '<h2>Installation nur mit gültigem Token</h2><p>Für bestehende <code>config.php</code>-Dateien bleibt der Token-Schutz aktiv.</p><p>Aufruf: <code>install.php?token=...</code></p>';
    install_render_footer();
    exit;
  }
  if(!empty($existingCfg['install_local_only']) && !install_request_is_local()){
    http_response_code(403);
    install_render_header('welcome');
    echo '<h2>Installation nur lokal erlaubt</h2><p>In <code>config.php</code> ist <code>install_local_only</code> aktiv.</p>';
    install_render_footer();
    exit;
  }
}

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if($requestMethod === 'POST'){
  if($step === 'database'){
    $cfg = install_config_from_post();
    $errors = install_validate_config($cfg);
    $diagnostics = $errors ? null : install_diagnostics($cfg);
    if($errors || ($diagnostics && install_has_errors($diagnostics))){
      install_render_database($cfg, $errors, $diagnostics);
      exit;
    }
    $state['config'] = $cfg;
    install_state_set($state);
    header('Location: '.install_step_url('mode'));
    exit;
  }
  if($step === 'mode'){
    $mode = (string)($_POST['mode'] ?? 'production');
    $year = trim((string)($_POST['demo_school_year'] ?? demo_installation_default_year_label()));
    $errors = [];
    if(!in_array($mode, ['production','demo'], true)) $errors[] = 'Bitte eine Installationsart wählen.';
    if($mode === 'demo'){
      try{ demo_installation_period_from_label($year); }catch(Throwable $e){ $errors[] = $e->getMessage(); }
    }
    if($errors){
      $state['mode'] = $mode;
      $state['demo_school_year'] = $year;
      install_render_mode($state, $errors);
      exit;
    }
    $state['mode'] = $mode;
    $state['demo_school_year'] = $year;
    install_state_set($state);
    header('Location: '.install_step_url('admin'));
    exit;
  }
  if($step === 'admin'){
    if((string)($state['mode'] ?? 'production') === 'production'){
      $admin = install_admin_from_post();
      $errors = install_validate_admin($admin);
      $state['admin'] = $admin;
      if($errors){
        install_render_admin($state, $errors);
        exit;
      }
      install_state_set($state);
    }
    header('Location: '.install_step_url('summary'));
    exit;
  }
  if($step === 'run'){
    if((string)($_POST['confirm_install'] ?? '') !== '1'){
      install_render_summary($state, ['Bitte bestätigen Sie die Installation.']);
      exit;
    }
    try{
      $result = install_run_installation($state);
      if(empty($result['ok']) && !empty($result['manual_config'])){
        install_render_manual_config($result);
        exit;
      }
      install_render_done($result);
      exit;
    }catch(Throwable $e){
      install_render_summary($state, [$e->getMessage()]);
      exit;
    }
  }
}

if($step === 'system'){
  install_render_system();
} elseif($step === 'database'){
  install_render_database((array)($state['config'] ?? install_default_config_values()));
} elseif($step === 'mode'){
  if(empty($state['config'])){ header('Location: '.install_step_url('database')); exit; }
  install_render_mode($state);
} elseif($step === 'admin'){
  if(empty($state['config'])){ header('Location: '.install_step_url('database')); exit; }
  install_render_admin($state);
} elseif($step === 'summary'){
  if(empty($state['config'])){ header('Location: '.install_step_url('database')); exit; }
  install_render_summary($state);
} else {
  install_render_welcome();
}
