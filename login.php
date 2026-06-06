<?php
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/events.php';
$error='';
$info='';
if(!empty($_GET['timeout'])){
  $info='Sie wurden aus Datenschutzgründen automatisch abgemeldet (Inaktivität). Bitte erneut anmelden.';
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $username=trim($_POST['username']??''); $pw=(string)($_POST['password']??'');
  if(login($username,$pw)){ emit_event('login',[]); redirect('/dashboard.php'); }
  $error='Login fehlgeschlagen.';
}
render_header('Login');
?>
<div class="grid"><div class="col-12 col-6"><div class="card">
<h1>Login</h1><p class="muted">Mit Username und Passwort anmelden.</p>
<?php if($info): ?><div class="flash info"><?php echo h($info); ?></div><?php endif; ?>
<?php if($error): ?><div class="card" style="border-color:#ffc6c0;background:#ffeceb;margin-bottom:10px"><?php echo h($error); ?></div><?php endif; ?>
<form method="post">
<?php echo csrf_input(); ?>
<label class="muted">Username</label><input class="input" name="username" required>
<div style="height:10px"></div>
<label class="muted">Passwort</label><input class="input" type="password" name="password" required>
<div style="height:12px"></div><button class="btn">Anmelden</button>
</form></div></div></div>
<?php render_footer(); ?>
