<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/_crud.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
$err='';
$formValues=[];

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';
  if($a==='save'){
    $id=$_POST['id']?(int)$_POST['id']:null;
    $first=trim($_POST['first_name']??''); $last=trim($_POST['last_name']??'');
    $username=trim($_POST['username']??'');
    $active=(int)($_POST['is_active']??1);
    $schoolIds=$_POST['school_ids'] ?? [];
    $formValues=$_POST;
    try{
      $pdo->beginTransaction();
      if($id===null){
        $pw=(string)($_POST['temp_password']??'');
        $pwErrors = password_policy_errors($pw);
        if($pwErrors) throw new RuntimeException('Temp-Passwort erfüllt die Regeln nicht: '.implode(', ', $pwErrors).'.');
        $hash=password_hash($pw,PASSWORD_DEFAULT);
        $nid=upsert('users',['username'=>$username,'first_name'=>$first,'last_name'=>$last,'role'=>'teacher','pass_hash'=>$hash,'is_active'=>$active,'must_change_password'=>1,'created_at'=>now_iso()],null);
      } else {
        $nid=$id;
        upsert('users',['username'=>$username,'first_name'=>$first,'last_name'=>$last,'is_active'=>$active],$id);
      }
      teacher_schools_sync($pdo,$nid,is_array($schoolIds)?$schoolIds:[]);
      $pdo->commit();
      emit_event($id?'admin_teacher_updated':'admin_teacher_created',['target_id'=>$nid,'target_name'=>"$last, $first",'target_username'=>$username,'school_ids'=>array_map('intval',(array)$schoolIds)]);
      header('Location: '.$bp.'/admin/teachers.php'); exit;
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $err=$e instanceof RuntimeException ? $e->getMessage() : 'Lehrkraft konnte nicht gespeichert werden. Bitte prüfen Sie die Eingaben.';
    }
  }
  if($a==='reset_pw'){
    $id=(int)$_POST['id']; $pw=(string)($_POST['temp_password']??'');
    $pwErrors = password_policy_errors($pw);
    if($pwErrors) die('Temp-Passwort erfüllt die Regeln nicht: '.h(implode(', ', $pwErrors)).'.');
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

$schools=schools_load($pdo,true);
$teachers=$pdo->query("SELECT u.id,u.username,u.first_name,u.last_name,u.is_active,u.must_change_password,
                              GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ' · ') AS school_names
                       FROM users u
                       LEFT JOIN teacher_schools ts ON ts.teacher_id=u.id
                       LEFT JOIN schools s ON s.id=ts.school_id
                       WHERE u.role='teacher'
                       GROUP BY u.id,u.username,u.first_name,u.last_name,u.is_active,u.must_change_password
                       ORDER BY u.last_name,u.first_name")->fetchAll();
$edit=null; if(!empty($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM users WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }
$selectedSchoolIds=$edit ? teacher_school_ids($pdo,(int)$edit['id']) : [];
if($formValues){
  $selectedSchoolIds=array_map('intval',(array)($formValues['school_ids'] ?? []));
  if(!$edit) $edit=[];
  foreach(['first_name','last_name','username','is_active'] as $field) if(array_key_exists($field,$formValues)) $edit[$field]=$formValues[$field];
}

render_header('Lehrer:innen',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Lehrer:innen</h1>
      <table class="table">
        <thead><tr><th>Name</th><th>Username</th><th>Schulen</th><th>Aktiv</th><th>PW</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach($teachers as $t): ?>
          <tr>
            <td><?php echo h($t['last_name'].', '.$t['first_name']); ?></td>
            <td><span class="badge"><?php echo h($t['username']); ?></span></td>
            <td><?php echo h((string)($t['school_names'] ?: 'Keine Zuordnung')); ?></td>
            <td><?php echo ((int)$t['is_active']===1)?'<span class="badge ok">aktiv</span>':'<span class="badge off">inaktiv</span>'; ?></td>
            <td><?php echo ((int)$t['must_change_password']===1)?'<span class="badge warn">muss ändern</span>':'<span class="badge ok">ok</span>'; ?></td>
            <td>
              <a class="btn small secondary" href="<?php echo h($bp); ?>/admin/teachers.php?edit=<?php echo (int)$t['id']; ?>">Bearbeiten</a>
              <form method="post" style="display:inline">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="reset_pw"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <input class="input" name="temp_password" placeholder="Temp-PW" style="width:160px;display:inline-block" required>
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
      <?php if($err!==''): ?><div class="notice error"><?php echo h($err); ?></div><?php endif; ?>
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
        <div style="height:10px"></div>
        <label class="muted">Zugeordnete Schulen <span aria-hidden="true">*</span></label>
        <div class="muted" style="margin:4px 0 7px">Die Zuordnung bestimmt, für welche Schulen diese Lehrkraft künftig Klassen-Fach-Zuweisungen erhalten kann. Bereits beendete Zuweisungen und historische Daten bleiben erhalten.</div>
        <div class="settings-panel" style="max-height:180px;overflow:auto">
          <?php foreach($schools as $school): $schoolId=(int)$school['id']; ?>
            <label style="display:block;margin:5px 0"><input type="checkbox" name="school_ids[]" value="<?php echo $schoolId; ?>" <?php echo in_array($schoolId,$selectedSchoolIds,true)?'checked':''; ?>> <?php echo h($school['name']); ?><?php echo (int)$school['active']===1?'':' · inaktiv'; ?></label>
          <?php endforeach; ?>
          <?php if(!$schools): ?><span class="muted">Zuerst unter „Schulen und Schulformen“ eine Schule anlegen.</span><?php endif; ?>
        </div>
        <?php if(!$edit): ?>
          <div style="height:10px"></div>
          <label class="muted">Temporäres Passwort – muss nach Login geändert werden</label>
          <input class="input" name="temp_password" required>
          <div class="small muted settings-panel-note">Erforderlich: <?php echo h(password_policy_summary()); ?>.</div>
        <?php endif; ?>
        <div style="height:12px"></div>
        <button class="btn">Speichern</button>
        <?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/teachers.php">Abbrechen</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<?php render_footer(); ?>
