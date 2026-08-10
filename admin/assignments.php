<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/teacher_assignments.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/../lib/events.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];
$isSuperAdmin=admin_is_superadmin($pdo,$u);
$allowedSchoolIds=admin_assigned_school_ids($pdo,$u);

$filter_teacher=(int)($_GET['teacher_id'] ?? 0);
$currentSchoolYearId=school_year_current_id($pdo);
$schoolYearFilter=(int)($_GET['school_period_set_id'] ?? $currentSchoolYearId);
$schoolYears=array_values(array_filter(load_school_years($pdo,true),static function(array $sy) use ($isSuperAdmin,$allowedSchoolIds): bool {
  if($isSuperAdmin) return true;
  $schoolId=(int)($sy['school_id'] ?? 0);
  return $schoolId===0 || in_array($schoolId,$allowedSchoolIds,true);
}));
if(!admin_can_access_school_period($pdo,$u,$schoolYearFilter,true)){
  $schoolYearFilter=(int)($schoolYears[0]['id'] ?? 0);
}
$show=(string)($_GET['show'] ?? 'all');
if(!in_array($show,['all','active','ended'],true)) $show='all';
$msg=(string)($_GET['msg'] ?? '');
$err=(string)($_GET['err'] ?? '');

function assignment_redirect(string $bp, int $teacherId, int $schoolYearId, string $show, string $message = '', string $error = ''): void {
  $params=['teacher_id'=>$teacherId,'school_period_set_id'=>$schoolYearId,'show'=>$show];
  if($message!=='') $params['msg']=$message;
  if($error!=='') $params['err']=$error;
  header('Location: '.$bp.'/admin/assignments.php?'.http_build_query($params));
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? 'create');
  $postTeacherId=(int)($_POST['teacher_id'] ?? 0);
  $postSchoolYearId=(int)($_POST['school_period_set_id'] ?? $schoolYearFilter);
  $postShow=(string)($_POST['show'] ?? $show);
  if(!in_array($postShow,['all','active','ended'],true)) $postShow='all';

  if($action==='create'){
    $classId=(int)($_POST['class_id'] ?? 0);
    $subjectId=(int)($_POST['subject_id'] ?? 0);
    $teacherOk=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='teacher' AND is_active=1");
    $teacherOk->execute([$postTeacherId]);
    $classOk=$pdo->prepare("SELECT 1 FROM classes WHERE id=? AND school_period_set_id=?");
    $classOk->execute([$classId,$postSchoolYearId]);
    if(!$teacherOk->fetchColumn() || !$classOk->fetchColumn() || !admin_can_access_class($pdo,$u,$classId)){
      assignment_redirect($bp,$postTeacherId,$postSchoolYearId,$postShow,'','Bitte Lehrkraft, Klasse und Fach aus dem gewählten Schuljahr auswählen.');
    }
    if(!admin_can_manage_user($pdo,$u,$postTeacherId)){
      assignment_redirect($bp,$postTeacherId,$postSchoolYearId,$postShow,'','Keine Berechtigung für diese Lehrkraft.');
    }
    if(!teacher_assignment_context_allowed($pdo,$postTeacherId,$classId,$subjectId)){
      assignment_redirect($bp,$postTeacherId,$postSchoolYearId,$postShow,'','Diese Zuweisung ist nicht zulässig: Die Lehrkraft ist der Schule oder das Fach der Schulform dieser Klasse nicht zugeordnet.');
    }
    $st=$pdo->prepare("INSERT INTO teacher_assignments (teacher_id,class_id,subject_id,status,ended_at,ended_by,end_note)
                       VALUES (?,?,?,'active',NULL,NULL,NULL)
                       ON DUPLICATE KEY UPDATE status='active',ended_at=NULL,ended_by=NULL,end_note=NULL");
    $st->execute([$postTeacherId,$classId,$subjectId]);
    emit_event('teacher_assignment_activated',['teacher_id'=>$postTeacherId,'class_id'=>$classId,'subject_id'=>$subjectId]);
    assignment_redirect($bp,$postTeacherId,$postSchoolYearId,$postShow,'Zuweisung gespeichert bzw. reaktiviert.');
  }

  $assignmentId=(int)($_POST['assignment_id'] ?? 0);
  $st=$pdo->prepare("SELECT ta.id,ta.teacher_id,ta.class_id,ta.subject_id,ta.status,c.school_period_set_id
                     FROM teacher_assignments ta JOIN classes c ON c.id=ta.class_id WHERE ta.id=? LIMIT 1");
  $st->execute([$assignmentId]);
  $assignment=$st->fetch();
  if(!$assignment){
    assignment_redirect($bp,$postTeacherId,$postSchoolYearId,$postShow,'','Die Zuweisung wurde nicht gefunden.');
  }
  require_admin_class_access($pdo,$u,(int)$assignment['class_id']);
  require_admin_user_manage_access($pdo,$u,(int)$assignment['teacher_id']);
  $assignmentTeacherId=(int)$assignment['teacher_id'];
  $summaryMap=teacher_assignment_documentation_map($pdo,[$assignment]);
  $summary=$summaryMap[$assignmentTeacherId.':'.(int)$assignment['class_id'].':'.(int)$assignment['subject_id']] ?? ['has_documentation'=>false];
  $hasDocumentation=(bool)($summary['has_documentation'] ?? false);

  if($action==='delete'){
    if($hasDocumentation){
      assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'','Die Zuweisung enthält bereits Dokumentationen und kann daher nicht gelöscht werden. Bitte beenden Sie sie, damit die historische Einsicht erhalten bleibt.');
    }
    $pdo->prepare("DELETE FROM teacher_assignments WHERE id=?")->execute([$assignmentId]);
    emit_event('teacher_assignment_deleted',['teacher_id'=>$assignmentTeacherId,'class_id'=>(int)$assignment['class_id'],'subject_id'=>(int)$assignment['subject_id']]);
    assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'Zuweisung gelöscht.');
  }

  if($action==='end'){
    if(!$hasDocumentation){
      assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'','Diese Zuweisung enthält noch keine Dokumentationen. Sie kann endgültig gelöscht werden.');
    }
    $endNote=trim((string)($_POST['end_note'] ?? ''));
    if(function_exists('mb_substr')) $endNote=mb_substr($endNote,0,2000);
    else $endNote=substr($endNote,0,2000);
    $pdo->prepare("UPDATE teacher_assignments SET status='ended',ended_at=?,ended_by=?,end_note=? WHERE id=?")
        ->execute([now_iso(),(int)$u['id'],$endNote!==''?$endNote:null,$assignmentId]);
    emit_event('teacher_assignment_ended',[
      'teacher_id'=>$assignmentTeacherId,
      'class_id'=>(int)$assignment['class_id'],
      'subject_id'=>(int)$assignment['subject_id'],
      'documentation'=>teacher_assignment_documentation_text($summary),
    ]);
    assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'Zuweisung beendet. Die bisherigen Daten bleiben für die Lehrkraft im Lesemodus abrufbar.');
  }

  if($action==='reactivate'){
    if(!teacher_assignment_context_allowed($pdo,$assignmentTeacherId,(int)$assignment['class_id'],(int)$assignment['subject_id'])){
      assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'','Die Zuweisung kann nicht reaktiviert werden: Prüfen Sie zuerst Schulzuordnung der Lehrkraft und Schulform-Zuordnung des Fachs.');
    }
    $pdo->prepare("UPDATE teacher_assignments SET status='active',ended_at=NULL,ended_by=NULL,end_note=NULL WHERE id=?")
        ->execute([$assignmentId]);
    emit_event('teacher_assignment_reactivated',['teacher_id'=>$assignmentTeacherId,'class_id'=>(int)$assignment['class_id'],'subject_id'=>(int)$assignment['subject_id']]);
    assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'Zuweisung reaktiviert.');
  }

  assignment_redirect($bp,$assignmentTeacherId,$postSchoolYearId,$postShow,'','Unbekannte Aktion.');
}

$teacherSql="SELECT id,first_name,last_name FROM users WHERE role='teacher' AND is_active=1";
$teacherParams=[];
if(!$isSuperAdmin && $allowedSchoolIds){
  $teacherSql.=" AND EXISTS (
                  SELECT 1 FROM teacher_schools ts_scope
                  WHERE ts_scope.teacher_id=users.id
                    AND ts_scope.school_id IN (".implode(',',array_fill(0,count($allowedSchoolIds),'?')).")
                )";
  $teacherParams=$allowedSchoolIds;
}
$teacherSql.=" ORDER BY last_name,first_name";
$st=$pdo->prepare($teacherSql);
$st->execute($teacherParams);
$teachers=$st->fetchAll();
$assignableClasses=[];
$assignableSubjects=[];
$subjectFormMap=[];
if($filter_teacher>0){
  $st=$pdo->prepare("SELECT c.id,c.name,c.school_form_id,sf.code AS school_form_code,sf.name AS school_form_name,s.id AS school_id,s.name AS school_name
                     FROM classes c
                     JOIN school_forms sf ON sf.id=c.school_form_id
                     JOIN schools s ON s.id=sf.school_id
                     JOIN teacher_schools ts ON ts.school_id=s.id AND ts.teacher_id=?
                     WHERE c.school_period_set_id=? AND c.is_archived=0 AND c.is_departed=0
                     ".(!$isSuperAdmin && $allowedSchoolIds ? "AND s.id IN (".implode(',',array_fill(0,count($allowedSchoolIds),'?')).")" : "")."
                     ORDER BY s.name,sf.code,c.name");
  $st->execute(array_merge([$filter_teacher,$schoolYearFilter],(!$isSuperAdmin && $allowedSchoolIds)?$allowedSchoolIds:[]));
  $assignableClasses=$st->fetchAll();
  $formIds=array_values(array_unique(array_map(static fn(array $row): int => (int)$row['school_form_id'],$assignableClasses)));
  if($formIds){
    $in=implode(',',array_fill(0,count($formIds),'?'));
    $st=$pdo->prepare("SELECT DISTINCT su.id,su.code,su.name
                       FROM subjects su
                       JOIN subject_school_forms ssf ON ssf.subject_id=su.id
                       WHERE ssf.school_form_id IN ($in)
                       ORDER BY su.code,su.name");
    $st->execute($formIds);
    $assignableSubjects=$st->fetchAll();
    $mapping=$pdo->query("SELECT subject_id,school_form_id FROM subject_school_forms")->fetchAll();
    foreach($mapping as $row) $subjectFormMap[(int)$row['subject_id']][]=(int)$row['school_form_id'];
  }
}

$sql="SELECT ta.id,ta.teacher_id,t.last_name,t.first_name,ta.class_id,c.name AS class_name,sp.label AS school_year_label,
             ta.subject_id,s.code,s.name AS subject_name,ta.status,ta.ended_at,ta.end_note,
             school.name AS school_name,sf.code AS school_form_code,
             ended.last_name AS ended_by_last_name,ended.first_name AS ended_by_first_name
      FROM teacher_assignments ta
      JOIN users t ON t.id=ta.teacher_id AND t.role='teacher'
      JOIN classes c ON c.id=ta.class_id
      JOIN subjects s ON s.id=ta.subject_id
      LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
      LEFT JOIN school_forms sf ON sf.id=c.school_form_id
      LEFT JOIN schools school ON school.id=sf.school_id
      LEFT JOIN users ended ON ended.id=ta.ended_by
      WHERE c.school_period_set_id=?";
$params=[$schoolYearFilter];
if(!$isSuperAdmin && $allowedSchoolIds){
  $sql.=" AND school.id IN (".implode(',',array_fill(0,count($allowedSchoolIds),'?')).")";
  $params=array_merge($params,$allowedSchoolIds);
}
if($filter_teacher){ $sql.=" AND ta.teacher_id=?"; $params[]=$filter_teacher; }
if($show==='active'){ $sql.=" AND ta.status='active'"; }
if($show==='ended'){ $sql.=" AND ta.status='ended'"; }
$sql.=" ORDER BY CASE WHEN ta.status='active' THEN 0 ELSE 1 END,t.last_name,t.first_name,c.name,s.code";
$st=$pdo->prepare($sql);
$st->execute($params);
$assignments=$st->fetchAll();
$documentationMap=teacher_assignment_documentation_map($pdo,$assignments);

render_header('Zuweisungen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Zuweisungen (Lehrer:in ↔ Klasse ↔ Fach)</h1>
<p class="muted">Aktive Zuweisungen erlauben neue Einträge. Beendete Zuweisungen bleiben für die bisherige Lehrkraft lesbar, damit Dokumentationen, Auswertungen und PDFs erhalten bleiben.</p>

<?php if($msg!==''): ?><div class="notice ok"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err!==''): ?><div class="notice error"><?php echo h($err); ?></div><?php endif; ?>

<form method="get" class="row" style="align-items:end;margin-top:10px">
  <div style="min-width:220px"><label class="muted">Schuljahr</label><select class="input" name="school_period_set_id"><?php foreach($schoolYears as $sy): ?><option value="<?php echo (int)$sy['id']; ?>" <?php echo $schoolYearFilter===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['is_current']===1)?' · aktuell':'')); ?></option><?php endforeach; ?></select></div>
  <div style="min-width:280px"><label class="muted">Lehrer:in (Filter)</label><select class="input" name="teacher_id"><option value="0" <?php echo $filter_teacher===0?'selected':''; ?>>– alle –</option><?php foreach($teachers as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo $filter_teacher===(int)$t['id']?'selected':''; ?>><?php echo h($t['last_name'].', '.$t['first_name']); ?></option><?php endforeach; ?></select></div>
  <div style="min-width:190px"><label class="muted">Status</label><select class="input" name="show"><option value="all" <?php echo $show==='all'?'selected':''; ?>>Alle Zuweisungen</option><option value="active" <?php echo $show==='active'?'selected':''; ?>>Nur aktive</option><option value="ended" <?php echo $show==='ended'?'selected':''; ?>>Nur beendete</option></select></div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Anzeigen</button></div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><a class="btn secondary" href="<?php echo h($bp); ?>/admin/assignments.php">Alle</a></div>
</form>

<div style="height:12px"></div>
<h2>Neue Zuweisung</h2>
<?php if($filter_teacher===0): ?>
  <div class="notice info">Wählen Sie oben zuerst eine Lehrkraft und klicken Sie auf „Anzeigen“. Danach werden nur Klassen ihrer Schulen und die passenden Fächer der jeweiligen Schulform angeboten.</div>
<?php elseif(!$assignableClasses): ?>
  <div class="notice info">Für diese Lehrkraft gibt es im gewählten Schuljahr keine aktiven Klassen an ihren zugeordneten Schulen. Prüfen Sie Schulzuordnung, Klassenstatus und Schuljahr.</div>
<?php elseif(!$assignableSubjects): ?>
  <div class="notice info">Für die Schulformen der verfügbaren Klassen sind noch keine Fächer zugeordnet. Pflegen Sie dies unter „Fächer“.</div>
<?php else: ?>
<form method="post" class="card" style="border-style:dashed;background:rgba(71,142,79,.06)" <?php echo dirty_form_attrs(); ?>>
  <?php echo csrf_input(); ?>
  <input type="hidden" name="action" value="create"><input type="hidden" name="school_period_set_id" value="<?php echo (int)$schoolYearFilter; ?>"><input type="hidden" name="show" value="<?php echo h($show); ?>">
  <div class="row" style="align-items:end">
    <div><label class="muted">Lehrer:in</label><input class="input" readonly value="<?php $selectedTeacher=array_values(array_filter($teachers,static fn(array $t): bool => (int)$t['id']===$filter_teacher)); echo h($selectedTeacher ? $selectedTeacher[0]['last_name'].', '.$selectedTeacher[0]['first_name'] : ''); ?>"><input type="hidden" name="teacher_id" value="<?php echo (int)$filter_teacher; ?>"></div>
    <div class="school-selection" data-school-selection><label class="school-selection-label">Klasse, Schule und Schulform</label><select class="input school-select" name="class_id" id="assignment-class" required><?php foreach($assignableClasses as $c): ?><option value="<?php echo (int)$c['id']; ?>" data-school-form-id="<?php echo (int)$c['school_form_id']; ?>" data-school-tone="<?php echo h(school_tone_class((int)$c['school_id'])); ?>"><?php echo h($c['name'].' · '.$c['school_name'].' · '.$c['school_form_code']); ?></option><?php endforeach; ?></select><div class="school-selection-note">Die Fachauswahl wird anschließend auf diese Schulform eingeschränkt.</div></div>
    <div><label class="muted">Fach</label><select class="input" name="subject_id" id="assignment-subject" required><?php foreach($assignableSubjects as $s): $formIds=$subjectFormMap[(int)$s['id']] ?? []; ?><option value="<?php echo (int)$s['id']; ?>" data-school-form-ids="<?php echo h(implode(',',$formIds)); ?>"><?php echo h($s['code'].' · '.$s['name']); ?></option><?php endforeach; ?></select><div class="muted" style="margin-top:5px;font-size:12px">Es werden nur Fächer der Schulform der gewählten Klasse angezeigt.</div></div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn">Zuweisen</button></div>
  </div>
</form>
<script>
(() => {
  const classSelect=document.getElementById('assignment-class');
  const subjectSelect=document.getElementById('assignment-subject');
  if(!classSelect || !subjectSelect) return;
  const updateSubjects=()=>{
    const current=classSelect.options[classSelect.selectedIndex];
    const formId=current ? String(current.dataset.schoolFormId || '') : '';
    let selectedIsAllowed=false;
    [...subjectSelect.options].forEach(option=>{
      const forms=String(option.dataset.schoolFormIds || '').split(',');
      const allowed=forms.includes(formId);
      option.hidden=!allowed;
      option.disabled=!allowed;
      if(allowed && option.selected) selectedIsAllowed=true;
    });
    if(!selectedIsAllowed){
      const first=[...subjectSelect.options].find(option=>!option.disabled);
      if(first) first.selected=true;
    }
  };
  classSelect.addEventListener('change',updateSubjects);
  updateSubjects();
})();
</script>
<?php endif; ?>

<div style="height:14px"></div>
<h2>Bestehende Zuweisungen <?php echo $filter_teacher?'(gefiltert)':''; ?></h2>
<div class="muted" style="margin-bottom:8px">Eine Zuweisung mit Dokumentationen wird beendet statt gelöscht. Dadurch bleiben die bisherigen Leistungsdaten ausschließlich für die zugewiesene Lehrkraft abrufbar.</div>
<table class="table">
  <thead><tr><th>Lehrer:in</th><th>Klasse</th><th>Schule / Schulform</th><th>Schuljahr</th><th>Fach</th><th>Status</th><th>Dokumentationen</th><th>Aktion</th></tr></thead>
  <tbody>
  <?php foreach($assignments as $a): $key=(int)$a['teacher_id'].':'.(int)$a['class_id'].':'.(int)$a['subject_id']; $summary=$documentationMap[$key] ?? ['has_documentation'=>false]; $ended=(string)$a['status']==='ended'; ?>
    <tr>
      <td data-label="Lehrer:in"><?php echo h($a['last_name'].', '.$a['first_name']); ?></td><td data-label="Klasse"><?php echo h($a['class_name']); ?></td><td data-label="Schule / Schulform"><?php echo h(trim((string)($a['school_name'] ?? '').' · '.(string)($a['school_form_code'] ?? ''),' ·')); ?></td><td data-label="Schuljahr"><?php echo h((string)($a['school_year_label'] ?? '')); ?></td><td data-label="Fach"><?php echo h($a['code']); ?></td>
      <td data-label="Status"><?php if($ended): ?><span class="badge off">beendet · Lesemodus</span><?php else: ?><span class="badge ok">aktiv</span><?php endif; ?><?php if($ended && !empty($a['ended_at'])): ?><div class="muted" style="margin-top:4px">beendet am <?php echo h((string)$a['ended_at']); ?></div><?php endif; ?></td>
      <td data-label="Dokumentationen"><?php echo h(teacher_assignment_documentation_text($summary)); ?></td>
      <td data-label="Aktion">
        <?php if($ended): ?>
          <form method="post" class="inline-form"><input type="hidden" name="action" value="reactivate"><?php echo csrf_input(); ?><input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>"><input type="hidden" name="teacher_id" value="<?php echo (int)$a['teacher_id']; ?>"><input type="hidden" name="school_period_set_id" value="<?php echo (int)$schoolYearFilter; ?>"><input type="hidden" name="show" value="<?php echo h($show); ?>"><button class="btn small secondary">Reaktivieren</button></form>
        <?php elseif(!empty($summary['has_documentation'])): ?>
          <form method="post" class="inline-form" onsubmit="return confirm('Zuweisung beenden? Neue Eingaben werden gesperrt. Bisherige Daten bleiben für diese Lehrkraft im Lesemodus abrufbar.');"><input type="hidden" name="action" value="end"><?php echo csrf_input(); ?><input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>"><input type="hidden" name="teacher_id" value="<?php echo (int)$a['teacher_id']; ?>"><input type="hidden" name="school_period_set_id" value="<?php echo (int)$schoolYearFilter; ?>"><input type="hidden" name="show" value="<?php echo h($show); ?>"><button class="btn small secondary">Zuweisung beenden</button></form>
        <?php else: ?>
          <form method="post" class="inline-form" onsubmit="return confirm('Diese Zuweisung wirklich löschen? Es sind keine Dokumentationen vorhanden.');"><input type="hidden" name="action" value="delete"><?php echo csrf_input(); ?><input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>"><input type="hidden" name="teacher_id" value="<?php echo (int)$a['teacher_id']; ?>"><input type="hidden" name="school_period_set_id" value="<?php echo (int)$schoolYearFilter; ?>"><input type="hidden" name="show" value="<?php echo h($show); ?>"><button class="btn small danger">Zuweisung löschen</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if(!$assignments): ?><tr><td colspan="8" class="muted">Keine Zuweisungen für diese Auswahl vorhanden.</td></tr><?php endif; ?>
  </tbody>
</table>

<div style="height:12px"></div><a class="btn secondary" href="<?php echo h($bp); ?>/admin/index.php">Zurück</a>
</div></div></div>
<?php render_footer(); ?>
