<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/schools.php';

$u=require_role('admin');
$pdo=db();
$bp=cfg()['base_path'] ?? '';

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=trim((string)($_POST['action'] ?? ''));

  if($action==='delete_school'){
    $id=(int)($_POST['id'] ?? 0);
    if($id<=0){
      $err='Bitte eine Schule zum Löschen auswählen.';
    } else {
      $st=$pdo->prepare("SELECT COUNT(*) FROM school_forms WHERE school_id=?");
      $st->execute([$id]);
      $formCount=(int)$st->fetchColumn();
      if($formCount>0){
        $err='Diese Schule kann nicht gelöscht werden, weil noch Schulformen zugeordnet sind.';
      } else {
        $nameSt=$pdo->prepare("SELECT name FROM schools WHERE id=?");
        $nameSt->execute([$id]);
        $schoolName=(string)($nameSt->fetchColumn() ?: '');
        $del=$pdo->prepare("DELETE FROM schools WHERE id=?");
        $del->execute([$id]);
        emit_event('admin_school_deleted',['target_id'=>$id,'target_name'=>$schoolName]);
        $msg='Schule gelöscht.';
      }
    }
  } elseif($action==='save_school'){
    $id=(int)($_POST['id'] ?? 0);
    $name=trim((string)($_POST['name'] ?? ''));
    $address=trim((string)($_POST['address'] ?? ''));
    $active=isset($_POST['active']) && (int)$_POST['active'] === 1 ? 1 : 0;
    if($name===''){
      $err='Bitte einen Schulnamen eingeben.';
    } else {
      try{
        if($id>0){
          $st=$pdo->prepare("UPDATE schools SET name=?, address=?, active=?, updated_at=NOW() WHERE id=?");
          $st->execute([$name,$address,$active,$id]);
          emit_event('admin_school_updated',['target_id'=>$id,'target_name'=>$name]);
          $msg='Schule gespeichert.';
        } else {
          $st=$pdo->prepare("INSERT INTO schools(name,address,active,created_at,updated_at) VALUES(?,?,?,NOW(),NOW())");
          $st->execute([$name,$address,$active]);
          emit_event('admin_school_created',['target_id'=>(int)$pdo->lastInsertId(),'target_name'=>$name]);
          $msg='Schule angelegt.';
        }
      }catch(Throwable $e){
        $err='Schule konnte nicht gespeichert werden. Bitte prüfen, ob der Name bereits existiert.';
      }
    }
  } elseif($action==='save_form'){
    $id=(int)($_POST['id'] ?? 0);
    $schoolId=(int)($_POST['school_id'] ?? 0);
    $code=strtoupper(trim((string)($_POST['code'] ?? '')));
    $name=trim((string)($_POST['name'] ?? ''));
    $active=isset($_POST['active']) && (int)$_POST['active'] === 1 ? 1 : 0;
    if($schoolId<=0 || $code==='' || $name===''){
      $err='Bitte Schule, Kürzel und Bezeichnung der Schulform vollständig eingeben.';
    } elseif(!preg_match('/^[A-Z0-9_-]{1,32}$/', $code)){
      $err='Das Kürzel darf nur Großbuchstaben, Zahlen, Unterstrich oder Bindestrich enthalten.';
    } else {
      try{
        if($id>0){
          $st=$pdo->prepare("UPDATE school_forms SET school_id=?, code=?, name=?, active=?, updated_at=NOW() WHERE id=?");
          $st->execute([$schoolId,$code,$name,$active,$id]);
          emit_event('admin_school_form_updated',['target_id'=>$id,'target_name'=>$code]);
          $msg='Schulform gespeichert.';
        } else {
          $st=$pdo->prepare("INSERT INTO school_forms(school_id,code,name,active,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())");
          $st->execute([$schoolId,$code,$name,$active]);
          emit_event('admin_school_form_created',['target_id'=>(int)$pdo->lastInsertId(),'target_name'=>$code]);
          $msg='Schulform angelegt.';
        }
      }catch(Throwable $e){
        $err='Schulform konnte nicht gespeichert werden. Bitte prüfen, ob das Kürzel für diese Schule bereits existiert.';
      }
    }
  }
}

$schools=schools_load($pdo,true);
$forms=school_forms_load($pdo,true);
$formCountBySchool=[];
foreach($forms as $form){
  $schoolId=(int)($form['school_id'] ?? 0);
  $formCountBySchool[$schoolId]=($formCountBySchool[$schoolId] ?? 0) + 1;
}
$editSchool=null;
if(!empty($_GET['edit_school'])){
  $st=$pdo->prepare("SELECT * FROM schools WHERE id=?");
  $st->execute([(int)$_GET['edit_school']]);
  $editSchool=$st->fetch() ?: null;
}
$editForm=null;
if(!empty($_GET['edit_form'])){
  $editForm=school_form_find($pdo,(int)$_GET['edit_form']);
}

render_header('Schulen und Schulformen',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Schulen und Schulformen</h1>
      <p class="muted">Hier werden Schulen und die dort geführten Schulformen gepflegt. Diese Schulformen stehen anschließend beim Anlegen und Bearbeiten von Klassen zur Auswahl.</p>

      <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <div class="grid">
        <div class="col-12 col-md-6">
          <details class="accordion" open>
            <summary><span class="acc-title"><?php echo $editSchool?'Schule bearbeiten':'Schule anlegen'; ?></span></summary>
            <div class="acc-body">
              <form method="post" <?php echo dirty_form_attrs(); ?>>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="save_school">
                <input type="hidden" name="id" value="<?php echo h((string)($editSchool['id'] ?? '0')); ?>">
                <label class="muted">Name der Schule</label>
                <input class="input" name="name" required value="<?php echo h((string)($editSchool['name'] ?? '')); ?>" placeholder="z. B. HLW Musterstadt">
                <div style="height:10px"></div>
                <label class="muted">Adresse</label>
                <textarea class="input" name="address" rows="4" placeholder="Straße, PLZ Ort"><?php echo h((string)($editSchool['address'] ?? '')); ?></textarea>
                <div style="height:10px"></div>
                <?php $schoolActive=(int)($editSchool['active'] ?? 1); ?>
                <label><input type="checkbox" name="active" value="1" <?php echo $schoolActive===1?'checked':''; ?>> Schule aktiv</label>
                <div class="muted" style="margin-top:6px;font-size:13px">Inaktive Schulen bleiben historisch erhalten, werden aber in neuen Auswahlen nicht mehr angeboten.</div>
                <div style="height:12px"></div>
                <button class="btn"><?php echo $editSchool?'Schule speichern':'Schule anlegen'; ?></button>
                <?php if($editSchool): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/schools.php">Abbrechen</a><?php endif; ?>
              </form>
            </div>
          </details>
        </div>

        <div class="col-12 col-md-6">
          <details class="accordion" open>
            <summary><span class="acc-title"><?php echo $editForm?'Schulform bearbeiten':'Schulform anlegen'; ?></span></summary>
            <div class="acc-body">
              <form method="post" <?php echo dirty_form_attrs(); ?>>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="save_form">
                <input type="hidden" name="id" value="<?php echo h((string)($editForm['id'] ?? '0')); ?>">
                <label class="muted">Schule</label>
                <select class="input" name="school_id" required>
                  <option value="0">Bitte wählen...</option>
                  <?php $formSchoolId=(int)($editForm['school_id'] ?? ($schools[0]['id'] ?? 0)); ?>
                  <?php foreach($schools as $school): ?>
                    <option value="<?php echo (int)$school['id']; ?>" <?php echo $formSchoolId===(int)$school['id']?'selected':''; ?>><?php echo h($school['name'].(((int)$school['active']===1)?'':' · inaktiv')); ?></option>
                  <?php endforeach; ?>
                </select>
                <div style="height:10px"></div>
                <label class="muted">Kürzel</label>
                <input class="input" name="code" required value="<?php echo h((string)($editForm['code'] ?? '')); ?>" placeholder="z. B. HLS oder FSB">
                <div class="muted" style="margin-top:6px;font-size:13px">Das Kürzel wird weiterhin für bestehende Kriterienlogik und Auswertungen verwendet.</div>
                <div style="height:10px"></div>
                <label class="muted">Bezeichnung</label>
                <input class="input" name="name" required value="<?php echo h((string)($editForm['name'] ?? '')); ?>" placeholder="z. B. Höhere Lehranstalt">
                <div style="height:10px"></div>
                <?php $formActive=(int)($editForm['active'] ?? 1); ?>
                <label><input type="checkbox" name="active" value="1" <?php echo $formActive===1?'checked':''; ?>> Schulform aktiv</label>
                <div style="height:12px"></div>
                <button class="btn"><?php echo $editForm?'Schulform speichern':'Schulform anlegen'; ?></button>
                <?php if($editForm): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/schools.php">Abbrechen</a><?php endif; ?>
              </form>
            </div>
          </details>
        </div>
      </div>

      <div style="height:16px"></div>
      <h2>Vorhandene Schulen</h2>
      <table class="table">
        <thead><tr><th>Schule</th><th>Adresse</th><th>Schulformen</th><th>Status</th><th>Aktion</th></tr></thead>
        <tbody>
          <?php foreach($schools as $school): ?>
            <?php $assignedForms=(int)($formCountBySchool[(int)$school['id']] ?? 0); ?>
            <tr>
              <td><?php echo h($school['name']); ?></td>
              <td><?php echo nl2br(h((string)$school['address'])); ?></td>
              <td><?php echo $assignedForms; ?></td>
              <td><?php echo ((int)$school['active']===1)?'<span class="badge ok">aktiv</span>':'<span class="badge off">inaktiv</span>'; ?></td>
              <td style="white-space:nowrap">
                <a class="btn small secondary" href="<?php echo h($bp); ?>/admin/schools.php?edit_school=<?php echo (int)$school['id']; ?>">Bearbeiten</a>
                <?php if($assignedForms===0): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Schule wirklich löschen?');" data-dirty-ignore="1">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="delete_school">
                    <input type="hidden" name="id" value="<?php echo (int)$school['id']; ?>">
                    <button class="btn small danger">Löschen</button>
                  </form>
                <?php else: ?>
                  <span class="muted" style="font-size:13px">Löschen erst ohne Schulformen möglich</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="height:16px"></div>
      <h2>Vorhandene Schulformen</h2>
      <table class="table">
        <thead><tr><th>Schule</th><th>Kürzel</th><th>Bezeichnung</th><th>Status</th><th>Aktion</th></tr></thead>
        <tbody>
          <?php foreach($forms as $form): ?>
            <tr>
              <td><?php echo h($form['school_name']); ?></td>
              <td><span class="badge"><?php echo h($form['code']); ?></span></td>
              <td><?php echo h($form['name']); ?></td>
              <td><?php echo ((int)$form['active']===1)?'<span class="badge ok">aktiv</span>':'<span class="badge off">inaktiv</span>'; ?></td>
              <td><a class="btn small secondary" href="<?php echo h($bp); ?>/admin/schools.php?edit_form=<?php echo (int)$form['id']; ?>">Bearbeiten</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück zu Einstellungen</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
