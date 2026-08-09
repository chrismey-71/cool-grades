<?php
require_once __DIR__.'/../lib/layout.php'; require_once __DIR__.'/../lib/events.php'; require_once __DIR__.'/../lib/schools.php'; require_once __DIR__.'/_crud.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
$err=''; $formValues=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';
  if($a==='save'){ $id=$_POST['id']?(int)$_POST['id']:null; $code=strtoupper(trim($_POST['code']??'')); $name=trim($_POST['name']??'');
    $schularbeitRaw = $_POST['is_schularbeit_subject'] ?? '';
    $schularbeitValue = ($schularbeitRaw === '') ? null : ((int)$schularbeitRaw === 1 ? 1 : 0);
    $formValues=$_POST;
    try{
      $pdo->beginTransaction();
      $nid=upsert('subjects',['code'=>$code,'name'=>$name,'is_schularbeit_subject'=>$schularbeitValue],$id);
      subject_school_forms_sync($pdo,$nid,is_array($_POST['school_form_ids'] ?? null)?$_POST['school_form_ids']:[]);
      $pdo->commit();
      emit_event($id?'admin_subject_updated':'admin_subject_created',['target_id'=>$nid,'target_name'=>$name,'subject_code'=>$code,'school_form_ids'=>array_map('intval',(array)($_POST['school_form_ids'] ?? []))]);
      header('Location: '.$bp.'/admin/subjects.php'); exit;
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $err=$e instanceof RuntimeException ? $e->getMessage() : 'Fach konnte nicht gespeichert werden. Bitte prüfen Sie die Eingaben.';
    }
  }
  if($a==='delete'){ $id=(int)$_POST['id']; emit_event('admin_subject_deleted',['target_id'=>$id]); del('subjects',$id); header('Location: '.$bp.'/admin/subjects.php'); exit; }
}
$schoolForms=school_forms_load($pdo,true);
$formsBySubject=[];
$formMap=$pdo->query("SELECT ssf.subject_id,sf.id AS school_form_id,sf.code,sf.name,s.name AS school_name
                      FROM subject_school_forms ssf
                      JOIN school_forms sf ON sf.id=ssf.school_form_id
                      JOIN schools s ON s.id=sf.school_id
                      ORDER BY s.name,sf.code")->fetchAll();
foreach($formMap as $mapping) $formsBySubject[(int)$mapping['subject_id']][]=$mapping;
$subjects=$pdo->query("SELECT * FROM subjects ORDER BY code")->fetchAll();
$edit=null; if(!empty($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }
$selectedSchoolFormIds=$edit ? subject_school_form_ids($pdo,(int)$edit['id']) : [];
if($formValues){
  $selectedSchoolFormIds=array_map('intval',(array)($formValues['school_form_ids'] ?? []));
  if(!$edit) $edit=[];
  foreach(['code','name','is_schularbeit_subject'] as $field) if(array_key_exists($field,$formValues)) $edit[$field]=$formValues[$field];
}
render_header('Fächer',$u);
?>
<div class="grid">
<div class="col-12"><div class="card"><h1>Fächer</h1>
<div style="overflow-x:auto">
<table class="table" style="min-width:860px"><thead><tr><th>Code</th><th>Name</th><th>Schulformen</th><th>Schularbeitsfach</th><th>Aktion</th></tr></thead><tbody>
<?php foreach($subjects as $s): ?><tr>
<td><span class="badge"><?php echo h($s['code']); ?></span></td><td><?php echo h($s['name']); ?></td>
<td><?php $formLabels=[]; foreach(($formsBySubject[(int)$s['id']] ?? []) as $form) $formLabels[]=$form['school_name'].' · '.$form['code']; echo h(implode(' | ',$formLabels) ?: 'Keine Zuordnung'); ?></td>
<td>
  <?php
    $status = $s['is_schularbeit_subject'];
    if($status === null){ echo '<span class="muted">Nicht festgelegt</span>'; }
    else echo ((int)$status === 1) ? 'Ja' : 'Nein';
  ?>
</td>
<td class="actions" style="white-space:nowrap">
<a class="btn small secondary" href="<?php echo h($bp); ?>/admin/subjects.php?edit=<?php echo (int)$s['id']; ?>">Bearbeiten</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen?');">
          <?php echo csrf_input(); ?>
<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
<button class="btn small danger">Löschen</button></form>
</td></tr><?php endforeach; ?>
</tbody></table>
</div></div></div>
<div class="col-12"><div class="card" style="max-width:680px"><h1><?php echo $edit?'Bearbeiten':'Neues Fach anlegen'; ?></h1>
<?php if($err!==''): ?><div class="notice error"><?php echo h($err); ?></div><?php endif; ?>
<form method="post" <?php echo dirty_form_attrs(); ?>><?php echo csrf_input(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo h($edit['id']??''); ?>">
<label class="muted">Kurzcode</label><input class="input" name="code" required maxlength="8" value="<?php echo h($edit['code']??''); ?>" placeholder="z.B. RWCO">
<div style="height:10px"></div><label class="muted">Langname</label><input class="input" name="name" required value="<?php echo h($edit['name']??''); ?>">
<div style="height:10px"></div><label class="muted">Schularbeitsfach</label>
<select class="input" name="is_schularbeit_subject">
  <option value="" <?php echo (!isset($edit['is_schularbeit_subject']) || $edit['is_schularbeit_subject']===null || $edit['is_schularbeit_subject']==='') ? 'selected' : ''; ?>>Nicht festgelegt</option>
  <option value="1" <?php echo isset($edit['is_schularbeit_subject']) && (string)$edit['is_schularbeit_subject']==='1' ? 'selected' : ''; ?>>Ja</option>
  <option value="0" <?php echo isset($edit['is_schularbeit_subject']) && (string)$edit['is_schularbeit_subject']==='0' ? 'selected' : ''; ?>>Nein</option>
</select>
<div class="muted" style="margin-top:6px">Die Kennzeichnung steuert nur die Interpretation in der Auswertung. Die Mitarbeit wird weiterhin unverändert gezählt.</div>
<div style="height:10px"></div><label class="muted">Gültige Schulformen <span aria-hidden="true">*</span></label>
<div class="muted" style="margin:4px 0 7px">Das Fach erscheint bei neuen Zuweisungen nur für Klassen dieser Schulformen. Bestehende Zuweisungen und historische Daten werden dadurch nicht verändert.</div>
<div class="settings-panel" style="max-height:210px;overflow:auto">
  <?php foreach($schoolForms as $form): $formId=(int)$form['id']; ?>
    <label style="display:block;margin:5px 0"><input type="checkbox" name="school_form_ids[]" value="<?php echo $formId; ?>" <?php echo in_array($formId,$selectedSchoolFormIds,true)?'checked':''; ?>> <?php echo h($form['school_name'].' · '.$form['code'].' – '.$form['name']); ?><?php echo (int)$form['active']===1?'':' · inaktiv'; ?></label>
  <?php endforeach; ?>
  <?php if(!$schoolForms): ?><span class="muted">Zuerst unter „Schulen und Schulformen“ eine Schulform anlegen.</span><?php endif; ?>
</div>
<div style="height:12px"></div><button class="btn">Speichern</button>
<?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/subjects.php">Abbrechen</a><?php endif; ?>
</form></div></div></div>
<?php render_footer(); ?>
