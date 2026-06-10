<?php
require_once __DIR__.'/settings.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/logger.php';

function security_is_https_request(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
  $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
  return $forwardedProto === 'https';
}

function security_send_headers(): void {
  if (headers_sent()) return;

  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

  if (security_is_https_request()) {
    header('Strict-Transport-Security: max-age=31536000');
  }

  $csp = implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self' data:",
    "connect-src 'self'",
    "frame-src 'none'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ]);
  header('Content-Security-Policy: '.$csp);
}

function security_int_setting(string $key, int $default, int $min, int $max): int {
  $value = (int)app_setting_get($key, $default);
  if ($value < $min) return $min;
  if ($value > $max) return $max;
  return $value;
}

function password_policy(): array {
  return [
    'min_length' => security_int_setting('password_min_length', 12, 8, 128),
    'require_upper' => ((string)app_setting_get('password_require_upper', '1') === '1'),
    'require_lower' => ((string)app_setting_get('password_require_lower', '1') === '1'),
    'require_digit' => ((string)app_setting_get('password_require_digit', '1') === '1'),
    'require_special' => ((string)app_setting_get('password_require_special', '1') === '1'),
  ];
}

function password_policy_errors(string $pw): array {
  $policy = password_policy();
  $errors = [];
  if (mb_strlen($pw) < (int)$policy['min_length']) {
    $errors[] = 'mindestens '.(int)$policy['min_length'].' Zeichen';
  }
  if ($policy['require_upper'] && !preg_match('/[A-ZÄÖÜ]/u', $pw)) {
    $errors[] = 'mindestens einen Großbuchstaben';
  }
  if ($policy['require_lower'] && !preg_match('/[a-zäöüß]/u', $pw)) {
    $errors[] = 'mindestens einen Kleinbuchstaben';
  }
  if ($policy['require_digit'] && !preg_match('/\d/u', $pw)) {
    $errors[] = 'mindestens eine Zahl';
  }
  if ($policy['require_special'] && !preg_match('/[^\p{L}\p{N}\s]/u', $pw)) {
    $errors[] = 'mindestens ein Sonderzeichen';
  }
  return $errors;
}

function password_policy_ok(string $pw): bool {
  return password_policy_errors($pw) === [];
}

function password_policy_summary(): string {
  $policy = password_policy();
  $parts = ['mindestens '.(int)$policy['min_length'].' Zeichen'];
  if ($policy['require_upper']) $parts[] = 'Großbuchstabe';
  if ($policy['require_lower']) $parts[] = 'Kleinbuchstabe';
  if ($policy['require_digit']) $parts[] = 'Zahl';
  if ($policy['require_special']) $parts[] = 'Sonderzeichen';
  return implode(', ', $parts);
}

function login_rate_limit_config(): array {
  return [
    'max_attempts' => security_int_setting('login_rate_limit_max_attempts', 5, 1, 50),
    'delay_seconds' => security_int_setting('login_rate_limit_delay_seconds', 2, 0, 30),
    'lockout_minutes' => security_int_setting('login_rate_limit_lockout_minutes', 15, 1, 1440),
  ];
}

function login_attempt_ip_hash(): string {
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
  $secret = (string)(cfg()['install_token'] ?? cfg()['session_name'] ?? 'coolgrades');
  return hash('sha256', $secret.'|'.$ip);
}

function login_rate_limit_cleanup(): void {
  try{
    db()->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
  }catch(Throwable $e){
    app_log('warn', 'login attempt cleanup failed', ['error' => $e->getMessage()]);
  }
}

function login_attempt_record(string $username, bool $success): void {
  try{
    static $cleaned = false;
    if(!$cleaned){
      $cleaned = true;
      login_rate_limit_cleanup();
    }

    $username = mb_substr(mb_strtolower(trim($username)), 0, 128);
    if($username === '') $username = '(empty)';
    $st = db()->prepare("INSERT INTO login_attempts(username,ip_hash,success,attempted_at) VALUES(?,?,?,NOW())");
    $st->execute([$username, login_attempt_ip_hash(), $success ? 1 : 0]);

    if($success){
      $clear = db()->prepare("DELETE FROM login_attempts WHERE username=? AND ip_hash=? AND success=0");
      $clear->execute([$username, login_attempt_ip_hash()]);
    }
  }catch(Throwable $e){
    app_log('warn', 'login attempt recording failed', ['error' => $e->getMessage()]);
  }
}

function login_rate_limit_blocked(string $username): array {
  $config = login_rate_limit_config();
  $username = mb_substr(mb_strtolower(trim($username)), 0, 128);
  if($username === '') $username = '(empty)';

  try{
    $st = db()->prepare("SELECT COUNT(*) AS failed_count, MAX(attempted_at) AS last_failed
                         FROM login_attempts
                         WHERE username=?
                           AND ip_hash=?
                           AND success=0
                           AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $st->execute([$username, login_attempt_ip_hash(), (int)$config['lockout_minutes']]);
    $row = $st->fetch() ?: [];
    $count = (int)($row['failed_count'] ?? 0);
    $lastFailed = (string)($row['last_failed'] ?? '');
    if($count < (int)$config['max_attempts'] || $lastFailed === '') return ['blocked' => false];

    $until = strtotime($lastFailed.' +'.(int)$config['lockout_minutes'].' minutes');
    if($until !== false && $until > time()){
      return [
        'blocked' => true,
        'retry_after_seconds' => max(1, $until - time()),
        'message' => 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte später erneut versuchen.',
      ];
    }
  }catch(Throwable $e){
    app_log('warn', 'login rate limit check failed', ['error' => $e->getMessage()]);
  }

  return ['blocked' => false];
}
