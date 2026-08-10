<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/_crud.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
$isSuperAdmin=admin_is_superadmin($pdo,$u);
$allowedSchoolIds=admin_assigned_school_ids($pdo,$u);
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
    $requestedRole=(string)($_POST['role'] ?? 'teacher');
    $formValues=$_POST;
    try{
      $pdo->beginTransaction();
      if($id===null){
        if(!in_array($requestedRole,['teacher','admin'],true)) throw new RuntimeException('Bitte eine gültige Benutzerrolle auswählen.');
        $pw=(string)($_POST['temp_password']??'');
        $pwErrors = password_policy_errors($pw);
        if($pwErrors) throw new RuntimeException('Temp-Passwort erfüllt die Regeln nicht: '.implode(', ', $pwErrors).'.');
        $hash=password_hash($pw,PASSWORD_DEFAULT);
        $nid=upsert('users',['username'=>$username,'first_name'=>$first,'last_name'=>$last,'role'=>$requestedRole,'pass_hash'=>$hash,'is_active'=>$active,'must_change_password'=>1,'created_at'=>now_iso()],null);
      } else {
        require_admin_user_manage_access($pdo,$u,$id);
        $roleLookup=$pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
        $roleLookup->execute([$id]);
        $existingRole=(string)$roleLookup->fetchColumn();
        if(!in_array($existingRole,['teacher','admin'],true)) throw new RuntimeException('Benutzerkonto wurde nicht gefunden.');
        if($existingRole==='admin' && $active!==1 && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn()<=1){
          throw new RuntimeException('Das letzte aktive Administrationskonto kann nicht deaktiviert werden.');
        }
        $nid=$id;
        upsert('users',['username'=>$username,'first_name'=>$first,'last_name'=>$last,'is_active'=>$active],$id);
      }
      $submittedSchoolIds=is_array($schoolIds)?array_map('intval',$schoolIds):[];
      if(!$isSuperAdmin){
        $submittedSchoolIds=array_values(array_intersect($submittedSchoolIds,$allowedSchoolIds));
        if(!$submittedSchoolIds) throw new RuntimeException('Bitte mindestens eine Schule aus Ihrer Schulzuordnung auswählen.');
        if($id!==null){
          $existingSchoolIds=user_school_ids($pdo,$nid);
          $externalSchoolIds=array_values(array_diff($existingSchoolIds,$allowedSchoolIds));
          $submittedSchoolIds=array_values(array_unique(array_merge($externalSchoolIds,$submittedSchoolIds)));
        }
      }
      $roleForSchoolRequirement=$id ? $existingRole : $requestedRole;
      user_schools_sync($pdo,$nid,$submittedSchoolIds,!($isSuperAdmin && $roleForSchoolRequirement==='admin' && !$submittedSchoolIds));
      $pdo->commit();
      emit_event($id?'admin_teacher_updated':'admin_teacher_created',['target_id'=>$nid,'target_name'=>"$last, $first",'target_username'=>$username,'role'=>$id ? $existingRole : $requestedRole,'school_ids'=>array_map('intval',(array)$schoolIds)]);
      header('Location: '.$bp.'/admin/teachers.php'); exit;
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $err=$e instanceof RuntimeException ? $e->getMessage() : 'Benutzerkonto konnte nicht gespeichert werden. Bitte prüfen Sie die Eingaben.';
    }
  }
  if($a==='reset_pw'){
    $id=(int)$_POST['id']; $pw=(string)($_POST['temp_password']??'');
    require_admin_user_manage_access($pdo,$u,$id);
    $pwErrors = password_policy_errors($pw);
    if($pwErrors) die('Temp-Passwort erfüllt die Regeln nicht: '.h(implode(', ', $pwErrors)).'.');
    $hash=password_hash($pw,PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET pass_hash=?, must_change_password=1 WHERE id=?")->execute([$hash,$id]);
    $t=$pdo->prepare("SELECT username,first_name,last_name FROM users WHERE id=?");$t->execute([$id]);$t=$t->fetch();
    emit_event('admin_teacher_password_reset',['target_id'=>$id,'target_name'=>trim(($t['last_name']??'').', '.($t['first_name']??'')),'target_username'=>$t['username']??'']);
    header('Location: '.$bp.'/admin/teachers.php'); exit;
  }
  if($a==='delete'){
    $id=(int)$_POST['id'];
    require_admin_user_manage_access($pdo,$u,$id);
    $target=$pdo->prepare("SELECT id,role,is_active FROM users WHERE id=? LIMIT 1");
    $target->execute([$id]); $target=$target->fetch();
    if(!$target){
      $err='Benutzerkonto wurde nicht gefunden.';
    } elseif((int)$target['id']===(int)$u['id']){
      $err='Das eigene Administrationskonto kann hier nicht gelöscht werden.';
    } elseif(admin_user_has_external_schools($pdo,$u,$id)){
      $err='Dieses Konto ist auch anderen Schulen zugeordnet und kann von diesem Administrationskonto nicht gelöscht werden.';
    } elseif((string)$target['role']==='admin' && (int)$target['is_active']===1 && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn()<=1){
      $err='Das letzte aktive Administrationskonto kann nicht gelöscht werden.';
    } else {
      emit_event('admin_teacher_deleted',['target_id'=>$id,'role'=>(string)$target['role']]); del('users',$id);
      header('Location: '.$bp.'/admin/teachers.php'); exit;
    }
  }
}

$schools=admin_schools_load($pdo,$u,true);
$teacherSql="SELECT u.id,u.username,u.first_name,u.last_name,u.role,u.is_active,u.must_change_password,
                              GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ' · ') AS school_names
                       FROM users u
                       LEFT JOIN teacher_schools ts ON ts.teacher_id=u.id
                       LEFT JOIN schools s ON s.id=ts.school_id
                       WHERE u.role IN ('teacher','admin')";
$teacherParams=[];
if(!$isSuperAdmin && $allowedSchoolIds){
  $teacherSql.=" AND EXISTS (
                   SELECT 1 FROM teacher_schools ts_scope
                   WHERE ts_scope.teacher_id=u.id
                     AND ts_scope.school_id IN (".implode(',',array_fill(0,count($allowedSchoolIds),'?')).")
                 )";
  $teacherParams=$allowedSchoolIds;
}
$teacherSql.=" GROUP BY u.id,u.username,u.first_name,u.last_name,u.role,u.is_active,u.must_change_password
                       ORDER BY CASE u.role WHEN 'admin' THEN 0 ELSE 1 END,u.last_name,u.first_name";
$st=$pdo->prepare($teacherSql);
$st->execute($teacherParams);
$teachers=$st->fetchAll();
$userExternalMap=[];
foreach($teachers as $teacherRow) $userExternalMap[(int)$teacherRow['id']]=admin_user_has_external_schools($pdo,$u,(int)$teacherRow['id']);
$edit=null; if(!empty($_GET['edit'])){ $editId=(int)$_GET['edit']; require_admin_user_manage_access($pdo,$u,$editId); $st=$pdo->prepare("SELECT * FROM users WHERE id=?");$st->execute([$editId]);$edit=$st->fetch(); }
$selectedSchoolIds=$edit ? user_school_ids($pdo,(int)$edit['id']) : [];
if($formValues){
  $selectedSchoolIds=array_map('intval',(array)($formValues['school_ids'] ?? []));
  if(!$edit) $edit=[];
  foreach(['first_name','last_name','username','is_active','role'] as $field) if(array_key_exists($field,$formValues)) $edit[$field]=$formValues[$field];
}

render_header('Lehrkräfte und Administrator:innen',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Lehrkräfte und Administrator:innen</h1>
      <p class="muted">Lehrkräfte und Administrator:innen sind getrennte Konten. Ein Administrationskonto erhält keine Lehrkraft-Zuweisungen oder Eingaberechte. Wenn dieselbe Person beide Aufgaben übernimmt, legen Sie zwei Konten mit unterschiedlichen Usernames an.</p>
      <table class="table">
        <thead><tr><th>Name</th><th>Rolle</th><th>Username</th><th>Schulen</th><th>Aktiv</th><th>PW</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach($teachers as $t): ?>
          <tr>
            <td><?php echo h($t['last_name'].', '.$t['first_name']); ?></td>
            <td><?php echo (string)$t['role']==='admin' ? '<span class="badge warn">Administrator:in</span>' : '<span class="badge ok">Lehrkraft</span>'; ?></td>
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
                <button class="btn small danger" <?php echo ($userExternalMap[(int)$t['id']] ?? false)?'disabled':''; ?>>Löschen</button>
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
      <h1><?php echo $edit?'Benutzerkonto bearbeiten':'Neues Benutzerkonto'; ?></h1>
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
        <?php $role=(string)($edit['role'] ?? 'teacher'); ?>
        <label class="muted">Benutzerrolle <span aria-hidden="true">*</span></label>
        <?php if($edit): ?>
          <input class="input" readonly value="<?php echo $role==='admin' ? 'Administrator:in' : 'Lehrkraft'; ?>">
          <input type="hidden" name="role" value="<?php echo h($role); ?>">
          <div class="muted" style="margin-top:6px;font-size:13px">Die Rolle eines bestehenden Kontos wird nicht geändert. Für die jeweils andere Rolle legen Sie ein separates Konto an.</div>
        <?php else: ?>
          <select class="input" name="role" required>
            <option value="teacher" <?php echo $role==='teacher'?'selected':''; ?>>Lehrkraft</option>
            <option value="admin" <?php echo $role==='admin'?'selected':''; ?>>Administrator:in</option>
          </select>
          <div class="muted" style="margin-top:6px;font-size:13px">Administrator:innen verwalten die Anwendung. Lehrkräfte erfassen und beurteilen Leistungen. Für beide Aufgaben derselben Person sind getrennte Konten erforderlich.</div>
        <?php endif; ?>
        <div style="height:10px"></div>
        <label class="muted">Aktiv</label>
        <?php $a=(int)($edit['is_active']??1); ?>
        <select class="input" name="is_active"><option value="1" <?php echo $a===1?'selected':''; ?>>aktiv</option><option value="0" <?php echo $a===0?'selected':''; ?>>inaktiv</option></select>
        <div style="height:10px"></div>
        <label class="muted">Zugeordnete Schulen</label>
        <div class="muted" style="margin:4px 0 7px">Die Zuordnung dokumentiert, für welche Schulen dieses Konto zuständig ist. Admin-Konten ohne Schulzuordnung sind Superadministrator:innen. Schulgebundene Admins können nur eigene Schulen auswählen; fremde bestehende Zuordnungen bleiben erhalten.</div>
        <div class="school-choice-list" style="max-height:260px;overflow:auto">
          <?php foreach($schools as $school): $schoolId=(int)$school['id']; ?>
            <label class="school-choice <?php echo h(school_tone_class($schoolId)); ?>">
              <input type="checkbox" name="school_ids[]" value="<?php echo $schoolId; ?>" <?php echo in_array($schoolId,$selectedSchoolIds,true)?'checked':''; ?>>
              <span class="school-choice-copy"><span class="school-choice-name"><?php echo h($school['name']); ?></span><span class="school-choice-detail">Zugeordnete Schule dieses Benutzerkontos<?php echo (int)$school['active']===1?'':' · inaktiv'; ?></span></span>
            </label>
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
