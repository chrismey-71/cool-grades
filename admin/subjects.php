<?php
require_once __DIR__.'/../lib/layout.php'; require_once __DIR__.'/../lib/events.php'; require_once __DIR__.'/../lib/schools.php'; require_once __DIR__.'/_crud.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
$isSuperAdmin=admin_is_superadmin($pdo,$u);
$allowedSchoolIds=admin_assigned_school_ids($pdo,$u);
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
      if($id!==null){
        require_admin_subject_access($pdo,$u,$id);
        if(admin_subject_has_external_school_forms($pdo,$u,$id)){
          throw new RuntimeException('Dieses Fach ist auch Schulformen außerhalb Ihrer Schulzuordnung zugeordnet und kann hier nicht zentral geändert werden.');
        }
      }
      $nid=upsert('subjects',['code'=>$code,'name'=>$name,'is_schularbeit_subject'=>$schularbeitValue],$id);
      subject_school_forms_sync_for_admin($pdo,$u,$nid,is_array($_POST['school_form_ids'] ?? null)?$_POST['school_form_ids']:[]);
      $pdo->commit();
      emit_event($id?'admin_subject_updated':'admin_subject_created',['target_id'=>$nid,'target_name'=>$name,'subject_code'=>$code,'school_form_ids'=>array_map('intval',(array)($_POST['school_form_ids'] ?? []))]);
      header('Location: '.$bp.'/admin/subjects.php'); exit;
    } catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $err=$e instanceof RuntimeException ? $e->getMessage() : 'Fach konnte nicht gespeichert werden. Bitte prüfen Sie die Eingaben.';
    }
  }
  if($a==='delete'){
    $id=(int)$_POST['id'];
    require_admin_subject_access($pdo,$u,$id);
    if(admin_subject_has_external_school_forms($pdo,$u,$id)){
      $err='Dieses Fach ist auch anderen Schulen zugeordnet und kann von diesem Administrationskonto nicht gelöscht werden.';
    } else {
      emit_event('admin_subject_deleted',['target_id'=>$id]); del('subjects',$id); header('Location: '.$bp.'/admin/subjects.php'); exit;
    }
  }
}
$schoolForms=admin_school_forms_load($pdo,$u,true);
$formsBySubject=[];
$formSql="SELECT ssf.subject_id,sf.id AS school_form_id,sf.code,sf.name,s.name AS school_name
          FROM subject_school_forms ssf
          JOIN school_forms sf ON sf.id=ssf.school_form_id
          JOIN schools s ON s.id=sf.school_id";
$formParams=[];
if(!$isSuperAdmin && $allowedSchoolIds){
  $formSql.=" WHERE sf.school_id IN (".implode(',',array_fill(0,count($allowedSchoolIds),'?')).")";
  $formParams=$allowedSchoolIds;
}
$formSql.=" ORDER BY s.name,sf.code";
$st=$pdo->prepare($formSql);
$st->execute($formParams);
$formMap=$st->fetchAll();
foreach($formMap as $mapping) $formsBySubject[(int)$mapping['subject_id']][]=$mapping;
$subjectSql="SELECT DISTINCT su.* FROM subjects su";
$subjectParams=[];
if(!$isSuperAdmin && $allowedSchoolIds){
  $subjectSql.=" JOIN subject_school_forms ssf ON ssf.subject_id=su.id
                 JOIN school_forms sf ON sf.id=ssf.school_form_id
                 WHERE sf.school_id IN (".implode(',',array_fill(0,count($allowedSchoolIds),'?')).")";
  $subjectParams=$allowedSchoolIds;
}
$subjectSql.=" ORDER BY su.code";
$st=$pdo->prepare($subjectSql);
$st->execute($subjectParams);
$subjects=$st->fetchAll();
$subjectExternalMap=[];
foreach($subjects as $subjectRow) $subjectExternalMap[(int)$subjectRow['id']]=admin_subject_has_external_school_forms($pdo,$u,(int)$subjectRow['id']);
$edit=null; if(!empty($_GET['edit'])){ $editId=(int)$_GET['edit']; require_admin_subject_access($pdo,$u,$editId); $st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");$st->execute([$editId]);$edit=$st->fetch(); }
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
<?php if(!($subjectExternalMap[(int)$s['id']] ?? false)): ?>
<a class="btn small secondary" href="<?php echo h($bp); ?>/admin/subjects.php?edit=<?php echo (int)$s['id']; ?>">Bearbeiten</a>
<?php else: ?>
<span class="muted" style="font-size:13px">schulübergreifend</span>
<?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen?');">
          <?php echo csrf_input(); ?>
<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
<button class="btn small danger" <?php echo ($subjectExternalMap[(int)$s['id']] ?? false)?'disabled':''; ?>>Löschen</button></form>
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
<div class="school-choice-list" style="max-height:260px;overflow:auto">
  <?php foreach($schoolForms as $form): $formId=(int)$form['id']; ?>
    <label class="school-choice <?php echo h(school_tone_class((int)$form['school_id'])); ?>">
      <input type="checkbox" name="school_form_ids[]" value="<?php echo $formId; ?>" <?php echo in_array($formId,$selectedSchoolFormIds,true)?'checked':''; ?>>
      <span class="school-choice-copy"><span class="school-choice-name"><?php echo h($form['school_name']); ?> · <?php echo h($form['code']); ?></span><span class="school-choice-detail"><?php echo h($form['name']); ?><?php echo (int)$form['active']===1?'':' · inaktiv'; ?></span></span>
    </label>
  <?php endforeach; ?>
  <?php if(!$schoolForms): ?><span class="muted">Zuerst unter „Schulen und Schulformen“ eine Schulform anlegen.</span><?php endif; ?>
</div>
<div style="height:12px"></div><button class="btn">Speichern</button>
<?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/subjects.php">Abbrechen</a><?php endif; ?>
</form></div></div></div>
<?php render_footer(); ?>
