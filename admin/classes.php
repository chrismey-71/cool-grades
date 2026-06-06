<?php
require_once __DIR__.'/../lib/layout.php'; require_once __DIR__.'/../lib/events.php'; require_once __DIR__.'/_crud.php'; require_once __DIR__.'/../lib/assessment_systems.php'; require_once __DIR__.'/../lib/school_years.php'; require_once __DIR__.'/../lib/schools.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
$schoolYears=load_school_years($pdo,true);
$schoolForms=school_forms_load($pdo,true);
$schoolFormsById=school_forms_by_id($schoolForms);
$currentSchoolYearId=school_year_current_id($pdo);
$schoolYearFilter=(int)($_GET['school_period_set_id'] ?? $currentSchoolYearId);
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';
  if($a==='save'){ $id=$_POST['id']? (int)$_POST['id']:null;
    $name=trim($_POST['name']??''); $schoolFormId=(int)($_POST['school_form_id'] ?? 0); $year=(int)($_POST['year']??1); $label=trim($_POST['label']??'');
    $schoolForm=school_form_find($pdo,$schoolFormId);
    if(!$schoolForm){
      $schoolFormId=school_form_default_id($pdo);
      $schoolForm=school_form_find($pdo,$schoolFormId);
    }
    $type=(string)($schoolForm['code'] ?? 'HLS');
    $schoolPeriodSetId=(int)($_POST['school_period_set_id'] ?? $currentSchoolYearId);
    $assessmentSystem=trim((string)($_POST['assessment_system'] ?? 'yearly'));
    if(!class_assessment_system_is_valid($assessmentSystem)) $assessmentSystem='yearly';
    $nid=upsert('classes',[
      'name'=>$name,
      'school_period_set_id'=>$schoolPeriodSetId ?: null,
      'school_type'=>$type,
      'school_form_id'=>$schoolFormId ?: null,
      'year'=>$year,
      'label'=>$label,
      'assessment_system'=>$assessmentSystem,
    ],$id);
    emit_event($id?'admin_class_updated':'admin_class_created',['target_id'=>$nid,'target_name'=>$name,'school_period_set_id'=>$schoolPeriodSetId]); header('Location: '.$bp.'/admin/classes.php?school_period_set_id='.$schoolPeriodSetId); exit;
  }
  if($a==='delete'){ $id=(int)$_POST['id']; emit_event('admin_class_deleted',['target_id'=>$id]); del('classes',$id); header('Location: '.$bp.'/admin/classes.php'); exit; }
}
$classes=load_classes_for_admin($pdo,$schoolYearFilter,true);
$edit=null; if(!empty($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM classes WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }
render_header('Klassen',$u);
?>
<div class="grid">
<div class="col-12"><div class="card"><h1>Klassen</h1>
<div class="report-focus-block" style="margin-bottom:12px">
  <strong>Empfohlener Ablauf beim Schuljahreswechsel</strong>
  <div class="muted" style="margin-top:8px">
    Legen Sie zuerst das neue Schuljahr mit beiden Semestern unter
    <a href="<?php echo h($bp); ?>/admin/school_years.php">Schuljahre und Semester</a> an.
    Neue Klassen werden hier nur für wirklich neu eintretende 1. Klassen angelegt.
    Weitergeführte Klassen bitte nicht manuell neu anlegen oder umbenennen, sondern über den
    <a href="<?php echo h($bp); ?>/admin/school_year_transition.php">Schuljahreswechsel-Assistenten</a> erzeugen.
  </div>
</div>
<form method="get" class="row" style="align-items:end;margin-bottom:12px">
  <div>
    <label class="muted">Schuljahr</label>
    <select class="input" name="school_period_set_id" onchange="this.form.submit()">
      <?php foreach($schoolYears as $sy): ?>
        <option value="<?php echo (int)$sy['id']; ?>" <?php echo $schoolYearFilter===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['is_current']===1)?' · aktuell':'')); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label class="muted">&nbsp;</label><a class="btn secondary" href="<?php echo h($bp); ?>/admin/school_year_transition.php">Schuljahreswechsel</a></div>
</form>

<details class="accordion" <?php echo $edit?'open':''; ?> style="margin-top:12px">
<summary><span class="acc-title"><?php echo $edit?'Klasse bearbeiten':'Neue Einstiegsklasse anlegen'; ?></span></summary>
<div class="acc-body">
<?php if(!$edit): ?>
  <div class="flash info" style="margin-bottom:12px">
    Diesen Bereich bitte nur für neue Einstiegsklassen verwenden, z. B. neue 1. Klassen. Für 2., 3., 4. oder 5. Klassen aus dem Vorjahr bitte den Schuljahreswechsel-Assistenten nutzen, damit Vorjahresdaten historisch korrekt bleiben.
  </div>
<?php else: ?>
  <div class="flash info" style="margin-bottom:12px">
    Sie bearbeiten nur diese konkrete Klasseninstanz. Für eine Fortführung ins nächste Schuljahr, Vorgängerbezüge oder Archivstatus bitte den Schuljahreswechsel-Assistenten verwenden.
  </div>
<?php endif; ?>
<form method="post" <?php echo dirty_form_attrs(); ?>>
<?php echo csrf_input(); ?>
<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo h($edit['id']??''); ?>">
<div class="settings-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
  <div class="settings-panel">
    <div class="settings-panel-title">Schuljahr und Name</div>
    <label class="muted">Schuljahr</label>
    <?php $sySel=(int)($edit['school_period_set_id'] ?? $schoolYearFilter); ?>
    <select class="input" name="school_period_set_id" required>
    <?php foreach($schoolYears as $sy): ?>
      <option value="<?php echo (int)$sy['id']; ?>" <?php echo $sySel===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['is_current']===1)?' · aktuell':'')); ?></option>
    <?php endforeach; ?>
    </select>
    <div class="muted" style="margin-top:6px;font-size:13px">Eine Klasse ist immer eine Klasseninstanz eines konkreten Schuljahres. Für den Schuljahreswechsel bitte den Assistenten verwenden.</div>
    <div style="height:10px"></div>
    <label class="muted">Name</label><input class="input" name="name" required value="<?php echo h($edit['name']??''); ?>" placeholder="z.B. 1FSB">
  </div>

  <div class="settings-panel">
    <div class="settings-panel-title">Stammdaten</div>
    <div class="row">
      <div><label class="muted">Schulform</label><select class="input" name="school_form_id" required>
      <?php
        $selectedFormId=(int)($edit['school_form_id'] ?? 0);
        if($selectedFormId<=0 && $edit){
          foreach($schoolForms as $form){
            if((string)$form['code'] === (string)($edit['school_type'] ?? '')){
              $selectedFormId=(int)$form['id'];
              break;
            }
          }
        }
        if($selectedFormId<=0) $selectedFormId=school_form_default_id($pdo);
      ?>
      <?php foreach($schoolForms as $form): ?>
        <option value="<?php echo (int)$form['id']; ?>" <?php echo $selectedFormId===(int)$form['id']?'selected':''; ?>><?php echo h(school_form_label($form).(((int)$form['active']===1)?'':' · inaktiv')); ?></option>
      <?php endforeach; ?>
      </select>
      <div class="muted" style="margin-top:6px;font-size:13px">Die Auswahl wird unter Einstellungen → Schulen und Schulformen gepflegt.</div></div>
      <div><label class="muted">Jahrgang</label><input class="input" type="number" min="1" max="5" name="year" value="<?php echo h($edit['year']??1); ?>"></div>
    </div>
    <div style="height:10px"></div><label class="muted">Label (optional)</label><input class="input" name="label" value="<?php echo h($edit['label']??''); ?>">
  </div>

  <div class="settings-panel">
    <div class="settings-panel-title">Beurteilung</div>
    <label class="muted">Beurteilungssystem</label>
    <?php $assessmentSystemValue=(string)($edit['assessment_system'] ?? 'yearly'); if(!class_assessment_system_is_valid($assessmentSystemValue)) $assessmentSystemValue='yearly'; ?>
    <select class="input" name="assessment_system" required>
    <?php foreach(class_assessment_system_options() as $value => $label): ?>
      <option value="<?php echo h($value); ?>" <?php echo $assessmentSystemValue===$value?'selected':''; ?>><?php echo h($label); ?></option>
    <?php endforeach; ?>
    </select>
    <div class="muted" style="margin-top:6px;font-size:13px">Steuert die Interpretation in der Abschlussbeurteilung. Leistungsdaten werden dadurch nicht verändert.</div>
    <div class="muted" style="margin-top:10px;font-size:13px">Vorgängerklasse und Status entstehen beim Schuljahreswechsel. Diese Seite dient bewusst nur der Stammdatenpflege einzelner Klasseninstanzen.</div>
  </div>
</div>
<div style="height:12px"></div><button class="btn">Speichern</button>
<?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/classes.php?school_period_set_id=<?php echo (int)$schoolYearFilter; ?>">Abbrechen</a><?php endif; ?>
</form>
</div>
</details>

<div style="height:14px"></div>
<div style="overflow-x:auto">
<table class="table" style="min-width:980px"><thead><tr><th>Klasse</th><th>Schuljahr</th><th>Schulform</th><th>Jahr</th><th>Beurteilungssystem</th><th>Status</th><th>Schüler:innen</th><th>Vorgänger</th><th>Aktion</th></tr></thead><tbody>
<?php foreach($classes as $c): ?><tr>
<td><?php echo h($c['name']); ?></td><td><?php echo h((string)($c['school_year_label'] ?? '')); ?></td><td><span class="badge"><?php $form=$schoolFormsById[(int)($c['school_form_id'] ?? 0)] ?? null; echo h($form ? (string)$form['code'] : (string)$c['school_type']); ?></span></td><td><?php echo h($c['year']); ?></td>
<td><?php echo h(class_assessment_system_label($c['assessment_system'] ?? null)); ?></td>
<td><?php echo class_archive_badge($c); ?></td>
<td><?php echo (int)($c['student_count'] ?? 0); ?></td>
<td><?php echo h((string)($c['predecessor_name'] ?? '')); ?></td>
<td style="white-space:nowrap">
<a class="btn small secondary" href="<?php echo h($bp); ?>/admin/classes.php?edit=<?php echo (int)$c['id']; ?>&school_period_set_id=<?php echo (int)$schoolYearFilter; ?>">Bearbeiten</a>
<form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen? Dadurch werden auch zugehörige Daten dieser Klasseninstanz gelöscht.');">
<?php echo csrf_input(); ?>
<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
<button class="btn small danger">Löschen</button></form>
</td></tr><?php endforeach; ?>
</tbody></table>
</div>
</div></div></div>
<?php render_footer(); ?>
