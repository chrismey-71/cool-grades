<?php
require_once __DIR__.'/../lib/layout.php'; require_once __DIR__.'/../lib/events.php'; require_once __DIR__.'/_crud.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';
  if($a==='save'){ $id=$_POST['id']?(int)$_POST['id']:null; $code=strtoupper(trim($_POST['code']??'')); $name=trim($_POST['name']??'');
    $schularbeitRaw = $_POST['is_schularbeit_subject'] ?? '';
    $schularbeitValue = ($schularbeitRaw === '') ? null : ((int)$schularbeitRaw === 1 ? 1 : 0);
    $nid=upsert('subjects',['code'=>$code,'name'=>$name,'is_schularbeit_subject'=>$schularbeitValue],$id);
    emit_event($id?'admin_subject_updated':'admin_subject_created',['target_id'=>$nid,'target_name'=>$name,'subject_code'=>$code]);
    header('Location: '.$bp.'/admin/subjects.php'); exit;
  }
  if($a==='delete'){ $id=(int)$_POST['id']; emit_event('admin_subject_deleted',['target_id'=>$id]); del('subjects',$id); header('Location: '.$bp.'/admin/subjects.php'); exit; }
}
$subjects=$pdo->query("SELECT * FROM subjects ORDER BY code")->fetchAll();
$edit=null; if(!empty($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }
render_header('Fächer',$u);
?>
<div class="grid">
<div class="col-12 col-6"><div class="card"><h1>Fächer</h1>
<table class="table"><thead><tr><th>Code</th><th>Name</th><th>Schularbeitsfach</th><th>Aktion</th></tr></thead><tbody>
<?php foreach($subjects as $s): ?><tr>
<td><span class="badge"><?php echo h($s['code']); ?></span></td><td><?php echo h($s['name']); ?></td>
<td>
  <?php
    $status = $s['is_schularbeit_subject'];
    if($status === null){ echo '<span class="muted">Nicht festgelegt</span>'; }
    else echo ((int)$status === 1) ? 'Ja' : 'Nein';
  ?>
</td>
<td>
<a class="btn small secondary" href="<?php echo h($bp); ?>/admin/subjects.php?edit=<?php echo (int)$s['id']; ?>">Bearbeiten</a>
        <form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen?');">
          <?php echo csrf_input(); ?>
<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
<button class="btn small danger">Löschen</button></form>
</td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="col-12 col-6"><div class="card"><h1><?php echo $edit?'Bearbeiten':'Neu'; ?></h1>
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
<div style="height:12px"></div><button class="btn">Speichern</button>
<?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/subjects.php">Abbrechen</a><?php endif; ?>
</form></div></div></div>
<?php render_footer(); ?>
