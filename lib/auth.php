<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/logger.php';
function start_session(): void {
  if (session_status()===PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_name(cfg()['session_name'] ?? 'coolgrades_sid');
  ini_set('session.use_strict_mode', '1');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}
function current_user(): ?array {
  start_session();

  // Inactivity timeout (auto logout)
  $timeoutMin = (int)app_setting_get('session_timeout_minutes', 30);
  if ($timeoutMin > 0) {
    $last = $_SESSION['last_activity'] ?? null;
    if ($last && (time() - (int)$last) > ($timeoutMin * 60)) {
      // Session expired due to inactivity
      app_log('info','session timeout enforced',[
        'uid'=>(int)($_SESSION['uid'] ?? 0),
        'timeout_minutes'=>$timeoutMin,
      ]);
      logout();
      redirect('/login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
  }

  if (empty($_SESSION['uid'])) return null;
  // Use SELECT * to stay resilient when new optional preference columns are added.
  $st=db()->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
  $st->execute([$_SESSION['uid']]); $u=$st->fetch();
  if (!$u || (int)$u['is_active']!==1) return null;
  return $u;
}
function require_login(): array { $u=current_user(); if(!$u) redirect('/login.php'); return $u; }
function require_role(string $role): array { $u=require_login(); if($u['role']!==$role){http_response_code(403);exit('Forbidden');} return $u; }

function teacher_can_access(int $teacher_id,int $class_id,int $subject_id): bool {
  $st=db()->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND class_id=? AND subject_id=? LIMIT 1");
  $st->execute([$teacher_id,$class_id,$subject_id]);
  return (bool)$st->fetchColumn();
}

function deny_with_popup(string $message, string $fallback = '/dashboard.php'): void {
  app_log('warn','access denied',[
    'message'=>$message,
    'fallback'=>$fallback,
    'request'=>app_log_request_context(),
  ]);
  $bp = cfg()['base_path'] ?? '';
  $target = $bp . $fallback;
  $jsMessage = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $jsTarget = json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  http_response_code(403);
  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Keine Berechtigung</title></head><body>';
  echo '<script>';
  echo 'var message='.$jsMessage.';';
  echo 'var target='.$jsTarget.';';
  echo 'alert(message);';
  echo 'if(window.history.length>1){ window.history.back(); } else { window.location.href=target; }';
  echo '</script>';
  echo '<noscript><p>'.h($message).'</p><p><a href="'.h($target).'">Zurück</a></p></noscript>';
  echo '</body></html>';
  exit;
}

function require_teacher_assignment(array $u,int $class_id,int $subject_id): void {
  if (($u['role'] ?? '')!=='teacher') return;
  if (!teacher_can_access((int)$u['id'],$class_id,$subject_id)) {
    deny_with_popup('Keine Berechtigung für diese Klasse/dieses Fach.', '/teacher/index.php');
  }
}

function login(string $username,string $password): bool {
  start_session();
  $st=db()->prepare("SELECT id,pass_hash,is_active FROM users WHERE username=? LIMIT 1");
  $st->execute([$username]); $u=$st->fetch();
  if(!$u || (int)$u['is_active']!==1) return false;
  if(!password_verify($password,$u['pass_hash'])) return false;
  session_regenerate_id(true);
  $_SESSION['uid']=(int)$u['id'];
  $_SESSION['last_activity'] = time();
  return true;
}
function logout(): void {
  start_session();
  $_SESSION=[];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $path = (string)($params['path'] ?? '/');
    $domain = (string)($params['domain'] ?? '');
    $secure = (bool)($params['secure'] ?? false);
    $httponly = (bool)($params['httponly'] ?? true);
    $sameSite = (string)($params['samesite'] ?? 'Lax');
    if (PHP_VERSION_ID >= 70300) {
      setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $path,
        'domain' => $domain,
        'secure' => $secure,
        'httponly' => $httponly,
        'samesite' => $sameSite,
      ]);
    } else {
      setcookie(
        session_name(),
        '',
        time() - 42000,
        $path . '; samesite=' . $sameSite,
        $domain,
        $secure,
        $httponly
      );
    }
  }
  session_destroy();
}
function password_policy_ok(string $pw): bool { return mb_strlen($pw) >= 8; }
function change_password(int $uid,string $newPw): void {
  if(!password_policy_ok($newPw)) throw new Exception("Passwort muss mindestens 8 Zeichen haben.");
  $hash=password_hash($newPw,PASSWORD_DEFAULT);
  $st=db()->prepare("UPDATE users SET pass_hash=?, must_change_password=0 WHERE id=?");
  $st->execute([$hash,$uid]);
}

function csrf_token(): string {
  start_session();
  if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_input(): string {
  return '<input type="hidden" name="_csrf" value="'.h(csrf_token()).'">';
}

function verify_csrf(): void {
  start_session();
  $token = (string)($_POST['_csrf'] ?? '');
  if ($token === '') {
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header)) $token = $header;
  }
  $known = $_SESSION['csrf_token'] ?? '';
  if (!is_string($known) || $known === '' || !hash_equals($known, $token)) {
    app_log('warn','invalid csrf token',[
      'request'=>app_log_request_context(),
    ]);
    http_response_code(403);
    exit('Ungueltiger CSRF-Token.');
  }
}
