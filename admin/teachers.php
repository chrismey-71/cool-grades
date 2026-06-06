<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/_crud.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';
  if($a==='save'){
    $id=$_POST['id']?(int)$_POST['id']:null;
    $first=trim($_POST['first_name']??''); $last=trim($_POST['last_name']??'');
    $username=trim($_POST['username']??'');
    $active=(int)($_POST['is_active']??1);
    if($id===null){
      $pw=(string)($_POST['temp_password']??'');
      if(!password_policy_ok($pw)) die('Temp-Passwort muss mind. 8 Zeichen haben.');
      $hash=password_hash($pw,PASSWORD_DEFAULT);
      $nid=upsert('users',['username'=>$username,'first_name'=>$first,'last_name'=>$last,'role'=>'teacher','pass_hash'=>$hash,'is_active'=>$active,'must_change_password'=>1,'created_at'=>now_iso()],null);
      emit_event('admin_teacher_created',['target_id'=>$nid,'target_name'=>"$last, $first",'target_username'=>$username]);
    } else {
      upsert('users',['username'=>$username,'first_name'=>$first,'last_name'=>$last,'is_active'=>$active],$id);
      emit_event('admin_teacher_updated',['target_id'=>$id,'target_name'=>"$last, $first",'target_username'=>$username]);
    }
    header('Location: '.$bp.'/admin/teachers.php'); exit;
  }
  if($a==='reset_pw'){
    $id=(int)$_POST['id']; $pw=(string)($_POST['temp_password']??'');
    if(!password_policy_ok($pw)) die('Temp-Passwort muss mind. 8 Zeichen haben.');
    $hash=password_hash($pw,PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET pass_hash=?, must_change_password=1 WHERE id=?")->execute([$hash,$id]);
    $t=$pdo->prepare("SELECT username,first_name,last_name FROM users WHERE id=?");$t->execute([$id]);$t=$t->fetch();
    emit_event('admin_teacher_password_reset',['target_id'=>$id,'target_name'=>trim(($t['last_name']??'').', '.($t['first_name']??'')),'target_username'=>$t['username']??'']);
    header('Location: '.$bp.'/admin/teachers.php'); exit;
  }
  if($a==='delete'){
    $id=(int)$_POST['id']; emit_event('admin_teacher_deleted',['target_id'=>$id]); del('users',$id);
    header('Location: '.$bp.'/admin/teachers.php'); exit;
  }
}

$teachers=$pdo->query("SELECT id,username,first_name,last_name,is_active,must_change_password FROM users WHERE role='teacher' ORDER BY last_name,first_name")->fetchAll();
$edit=null; if(!empty($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM users WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }

render_header('Lehrer:innen',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Lehrer:innen</h1>
      <table class="table">
        <thead><tr><th>Name</th><th>Username</th><th>Aktiv</th><th>PW</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach($teachers as $t): ?>
          <tr>
            <td><?php echo h($t['last_name'].', '.$t['first_name']); ?></td>
            <td><span class="badge"><?php echo h($t['username']); ?></span></td>
            <td><?php echo ((int)$t['is_active']===1)?'<span class="badge ok">aktiv</span>':'<span class="badge off">inaktiv</span>'; ?></td>
            <td><?php echo ((int)$t['must_change_password']===1)?'<span class="badge warn">muss ändern</span>':'<span class="badge ok">ok</span>'; ?></td>
            <td>
              <a class="btn small secondary" href="<?php echo h($bp); ?>/admin/teachers.php?edit=<?php echo (int)$t['id']; ?>">Bearbeiten</a>
              <form method="post" style="display:inline">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="reset_pw"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <input class="input" name="temp_password" placeholder="Temp-PW (min 8)" style="width:160px;display:inline-block" required>
                <button class="btn small">PW reset</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen?');">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="btn small danger">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12 col-6">
    <div class="card">
      <h1><?php echo $edit?'Bearbeiten':'Neue Lehrkraft'; ?></h1>
      <form method="post" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo h($edit['id']??''); ?>">
        <div class="row">
          <div><label class="muted">Vorname</label><input class="input" name="first_name" required value="<?php echo h($edit['first_name']??''); ?>"></div>
          <div><label class="muted">Nachname</label><input class="input" name="last_name" required value="<?php echo h($edit['last_name']??''); ?>"></div>
        </div>
        <div style="height:10px"></div>
        <label class="muted">Username</label><input class="input" name="username" required value="<?php echo h($edit['username']??''); ?>">
        <div style="height:10px"></div>
        <label class="muted">Aktiv</label>
        <?php $a=(int)($edit['is_active']??1); ?>
        <select class="input" name="is_active"><option value="1" <?php echo $a===1?'selected':''; ?>>aktiv</option><option value="0" <?php echo $a===0?'selected':''; ?>>inaktiv</option></select>
        <?php if(!$edit): ?>
          <div style="height:10px"></div>
          <label class="muted">Temporäres Passwort (min 8) – muss nach Login geändert werden</label>
          <input class="input" name="temp_password" required>
        <?php endif; ?>
        <div style="height:12px"></div>
        <button class="btn">Speichern</button>
        <?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/teachers.php">Abbrechen</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php render_footer(); ?>
