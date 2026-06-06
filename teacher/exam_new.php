<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/assessment_summaries.php';
require_once __DIR__.'/../lib/student_groups.php';
require_once __DIR__.'/../lib/school_years.php';
$u=require_role('teacher'); $pdo=db(); $bp=cfg()['base_path'];
$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$exam_type=strtoupper(trim((string)($_GET['exam_type'] ?? $_POST['exam_type'] ?? 'SA')));
$exam_type=written_assessment_normalize_type($exam_type);
$st=$pdo->prepare("SELECT * FROM classes WHERE id=?");$st->execute([$class_id]);$class=$st->fetch();
$st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");$st->execute([$subject_id]);$subject=$st->fetch();
if(!$class||!$subject){http_response_code(400);exit('Klasse/Fach ungültig.');}
require_teacher_assignment($u,$class_id,$subject_id);
require_class_writable($pdo,$class_id);
$students=load_class_students($pdo,$class_id,false);
$studentGroups=load_teacher_student_groups($pdo,(int)$u['id'],$class_id,$subject_id);

$msg='';$err='';
$fieldErrors=[];
$entry_mode=(string)($_GET['entry_mode'] ?? $_POST['entry_mode'] ?? 'table');
if(!in_array($entry_mode,['table','single'],true)) $entry_mode='table';
$selected_student_id=(int)($_GET['student_id'] ?? $_POST['selected_student_id'] ?? 0);
if($selected_student_id<=0 && $students) $selected_student_id=(int)$students[0]['id'];
$form_date=(string)($_POST['exam_date'] ?? date('Y-m-d'));
$form_title=trim((string)($_POST['title'] ?? written_assessment_type_label($exam_type)));
$grade_values=[];
$tendency_values=[];
$remark_values=[];
foreach($students as $s){
  $sid=(int)$s['id'];
  $grade_values[$sid]=(string)($_POST['grade'][$sid] ?? '');
  $tendency_values[$sid]=normalize_exam_grade_tendency((string)($_POST['tendency'][$sid] ?? ''));
  $remark_values[$sid]=trim((string)($_POST['remark'][$sid] ?? ''));
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $date=$form_date;
  $title=$form_title;
  if($date===''){
    $err='Bitte Datum wählen.';
    $fieldErrors['exam_date']='Bitte Datum wählen.';
  }
  if($title===''){
    $err='Bitte Titel eingeben.';
    $fieldErrors['title']='Bitte Titel eingeben.';
  }
  if($entry_mode==='single' && $selected_student_id<=0){
    $err='Bitte Schüler:in wählen.';
    $fieldErrors['selected_student_id']='Bitte Schüler:in wählen.';
  }
  $hasGrade=false;
  foreach($grade_values as $g){ if((string)$g!==''){ $hasGrade=true; break; } }
  if(!$hasGrade){
    $err='Bitte mindestens eine Note erfassen.';
    $fieldErrors['grades']='Leere Noten werden nicht gespeichert. Bitte mindestens eine Note auswählen.';
  }

  if($err===''){
    $pdo->beginTransaction();
    try{
      $pdo->prepare("INSERT INTO exams (class_id,subject_id,teacher_id,exam_type,exam_date,title,created_at) VALUES (?,?,?,?,?,?,?)")
          ->execute([$class_id,$subject_id,(int)$u['id'],$exam_type,$date,$title,now_iso()]);
      $eid=(int)$pdo->lastInsertId();
      $ins=$pdo->prepare("INSERT INTO exam_grades (exam_id,student_id,grade,tendency,remark) VALUES (?,?,?,?,?)");
      foreach($students as $s){
        $sid=(int)$s['id']; $g=$grade_values[$sid] ?? '';
        if($g==='') continue;
        $tendency=normalize_exam_grade_tendency((string)($tendency_values[$sid] ?? ''));
        $remark=trim((string)($remark_values[$sid] ?? ''));
        if($tendency!=='') $tendency=mb_substr($tendency,0,120);
        $ins->execute([$eid,$sid,(int)$g,$tendency!==''?$tendency:null,$remark!==''?$remark:null]);
      }
      $pdo->commit();
      emit_event('exam_created',['class_id'=>$class_id,'subject_id'=>$subject_id,'exam_id'=>$eid,'title'=>$title]);
      $msg='Leistungsfeststellung gespeichert.';
      $form_date=date('Y-m-d');
      $form_title=written_assessment_type_label($exam_type);
      foreach($students as $s){
        $sid=(int)$s['id'];
        $grade_values[$sid]='';
        $tendency_values[$sid]='';
        $remark_values[$sid]='';
      }
    }catch(Exception $e){$pdo->rollBack(); $err='Fehler: '.$e->getMessage();}
  }
}

$written_summary_map = [
  'SA' => written_assessment_summary('SA'),
  'TEST' => written_assessment_summary('TEST'),
  'REVIEW' => written_assessment_summary('REVIEW'),
  'TASK' => written_assessment_summary('TASK'),
  'OTHER' => written_assessment_summary('OTHER'),
];
$written_tooltip_map = [
  'SA' => written_assessment_summary_tooltip('SA'),
  'TEST' => written_assessment_summary_tooltip('TEST'),
  'REVIEW' => written_assessment_summary_tooltip('REVIEW'),
  'TASK' => written_assessment_summary_tooltip('TASK'),
  'OTHER' => written_assessment_summary_tooltip('OTHER'),
];
$written_type_options = written_assessment_types();
$compact_forms = compact_entry_forms_enabled($u);

render_header('Besondere schriftliche Leistungsfeststellung',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Besondere schriftliche Leistungsfeststellung</h1>
<div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b></div>
<?php if(legal_hints_enabled($u)): ?>
<details class="accordion" id="writtenSummaryCard" data-summary-map="<?php echo h(json_encode($written_summary_map, JSON_UNESCAPED_UNICODE)); ?>" data-tooltip-map="<?php echo h(json_encode($written_tooltip_map, JSON_UNESCAPED_UNICODE)); ?>" style="margin-top:10px">
  <summary>
    <span class="acc-title" id="writtenSummaryRef" title="<?php echo h(written_assessment_summary_tooltip($exam_type)); ?>" style="cursor:help"><?php echo h(written_assessment_summary_ref($exam_type)); ?></span>
  </summary>
  <div class="acc-body">
    <div id="writtenSummaryText"><?php echo written_assessment_summary($exam_type); ?></div>
  </div>
</details>
<?php endif; ?>
<?php if($msg): ?><div class="card" style="margin-top:10px;border-color:#bfe5cd;background:#e8f5ee"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="margin-top:10px;border-color:#ffc6c0;background:#ffeceb"><?php echo h($err); ?></div><?php endif; ?>

<form method="post" style="margin-top:10px" <?php echo dirty_form_attrs(); ?>>
<input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
<input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>"><input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
<?php accordion_section_start($compact_forms, 'Stammdaten', $_SERVER['REQUEST_METHOD']==='POST', 'margin-top:0', '', 'contrast-panel section-selection'); ?>
<div class="row contrast-form section-selection">
  <div>
    <label class="muted">Datum</label>
    <input class="input" type="date" name="exam_date" value="<?php echo h($form_date); ?>">
    <?php if(!empty($fieldErrors['exam_date'])): ?><div class="field-error"><?php echo h($fieldErrors['exam_date']); ?></div><?php endif; ?>
  </div>
  <div>
    <label class="muted">Art</label>
    <select class="input" name="exam_type" style="max-width:220px">
      <?php foreach($written_type_options as $typeValue => $typeLabel): ?>
        <option value="<?php echo h($typeValue); ?>" <?php echo $exam_type===$typeValue?'selected':''; ?>><?php echo h($typeLabel); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="muted">Titel</label>
    <input class="input" name="title" value="<?php echo h($form_title); ?>" required>
    <?php if(!empty($fieldErrors['title'])): ?><div class="field-error"><?php echo h($fieldErrors['title']); ?></div><?php endif; ?>
  </div>
</div>
<?php accordion_section_end($compact_forms); ?>
<?php if(!$compact_forms): ?><div style="height:12px"></div><?php endif; ?>
<?php accordion_section_start($compact_forms, 'Noten (1–5)', $_SERVER['REQUEST_METHOD']==='POST', 'margin-top:12px', '', 'contrast-panel section-rating'); ?>
<div class="contrast-block section-rating">
<?php $tendency_choices = exam_grade_tendency_choices(); ?>
<div class="entry-mode-switch">
  <label><input type="radio" name="entry_mode" value="table" <?php echo $entry_mode==='table'?'checked':''; ?> onchange="toggleWrittenEntryMode()"> Klassentabelle</label>
  <label><input type="radio" name="entry_mode" value="single" <?php echo $entry_mode==='single'?'checked':''; ?> onchange="toggleWrittenEntryMode()"> Einzelne Schüler:in</label>
</div>
<div class="small muted" style="margin-top:8px">Leere Noten werden nicht gespeichert. Der Einzelmodus eignet sich für nachgetragene oder individuelle schriftliche Leistungen; für Tests der ganzen Klasse bleibt die Klassentabelle schneller.</div>
<?php if(!empty($fieldErrors['grades'])): ?><div class="field-error" style="margin-top:8px"><?php echo h($fieldErrors['grades']); ?></div><?php endif; ?>

<fieldset id="writtenTableMode" <?php echo $entry_mode==='single'?'disabled':''; ?> style="border:0;padding:0;margin:0;<?php echo $entry_mode==='single'?'display:none':''; ?>">
  <div class="row" style="align-items:end;margin-top:12px">
    <div style="flex:1">
      <label class="muted">Schüler:in suchen</label>
      <input class="input" id="writtenStudentSearch" placeholder="Name tippen…" oninput="filterWrittenRows()">
    </div>
    <?php if($studentGroups): ?>
      <div style="flex:1">
        <label class="muted">Gruppe anzeigen</label>
        <select class="input" id="writtenGroupFilter" onchange="filterWrittenRows()">
          <option value="">alle Schüler:innen</option>
          <?php foreach($studentGroups as $group): ?>
            <option value="<?php echo h(implode(',', array_map('intval', (array)$group['member_ids']))); ?>"><?php echo h($group['name']); ?> (<?php echo (int)$group['member_count']; ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
  </div>

  <table class="table written-grade-table" style="margin-top:12px"><thead><tr><th>Schüler:in</th><th>Note (1–5)</th><th>Tendenz</th><th>Bemerkung</th></tr></thead><tbody>
  <?php foreach($students as $s): $sid=(int)$s['id']; $current_tendency=(string)($tendency_values[$sid] ?? ''); ?>
  <tr class="written-grade-row" data-student-id="<?php echo $sid; ?>" data-name="<?php echo h(strtolower($s['last_name'].' '.$s['first_name'])); ?>"><td><strong><?php echo h($s['last_name'].', '.$s['first_name']); ?></strong></td>
  <td>
  <select class="input" name="grade[<?php echo $sid; ?>]" style="max-width:140px">
  <option value="">–</option>
  <?php for($i=1;$i<=5;$i++): ?>
    <option value="<?php echo $i; ?>" <?php echo ($grade_values[$sid]!=='' && (string)$grade_values[$sid]===(string)$i)?'selected':''; ?>><?php echo $i; ?></option>
  <?php endfor; ?>
  </select>
  </td>
  <td>
    <select class="input" name="tendency[<?php echo $sid; ?>]" style="max-width:160px">
      <option value="">–</option>
      <?php foreach($tendency_choices as $value=>$label): ?>
        <option value="<?php echo h($value); ?>" <?php echo $current_tendency===$value?'selected':''; ?>><?php echo h($label); ?></option>
      <?php endforeach; ?>
      <?php if($current_tendency!=='' && !isset($tendency_choices[$current_tendency])): ?>
        <option value="<?php echo h($current_tendency); ?>" selected><?php echo h($current_tendency); ?> (bisheriger Wert)</option>
      <?php endif; ?>
    </select>
  </td>
  <td>
    <textarea class="input" name="remark[<?php echo $sid; ?>]" rows="2" placeholder="optional"><?php echo h($remark_values[$sid] ?? ''); ?></textarea>
  </td></tr>
  <?php endforeach; ?>
  </tbody></table>
</fieldset>

<fieldset id="writtenSingleMode" <?php echo $entry_mode==='table'?'disabled':''; ?> class="single-written-card" style="border:0;padding:0;<?php echo $entry_mode==='single'?'':'display:none'; ?>;margin-top:12px">
  <input type="hidden" name="selected_student_id" value="<?php echo (int)$selected_student_id; ?>">
  <label class="muted">Schüler:in</label>
  <select class="input" id="singleStudentSelect" onchange="switchSingleStudent(this.value)">
    <?php foreach($students as $s): $sid=(int)$s['id']; ?>
      <option value="<?php echo $sid; ?>" <?php echo $selected_student_id===$sid?'selected':''; ?>><?php echo h($s['last_name'].', '.$s['first_name']); ?></option>
    <?php endforeach; ?>
  </select>
  <?php if(!empty($fieldErrors['selected_student_id'])): ?><div class="field-error"><?php echo h($fieldErrors['selected_student_id']); ?></div><?php endif; ?>
  <div class="grid" style="margin-top:12px">
    <?php foreach($students as $s): $sid=(int)$s['id']; $current_tendency=(string)($tendency_values[$sid] ?? ''); ?>
      <div class="col-12 single-student-grade-fields" data-student-id="<?php echo $sid; ?>" style="<?php echo $selected_student_id===$sid?'':'display:none'; ?>">
        <div class="row" style="align-items:end">
          <div>
            <label class="muted">Note (1–5)</label>
            <select class="input" name="grade[<?php echo $sid; ?>]" style="max-width:180px">
              <option value="">–</option>
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?php echo $i; ?>" <?php echo ($grade_values[$sid]!=='' && (string)$grade_values[$sid]===(string)$i)?'selected':''; ?>><?php echo $i; ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label class="muted">Tendenz</label>
            <select class="input" name="tendency[<?php echo $sid; ?>]" style="max-width:200px">
              <option value="">–</option>
              <?php foreach($tendency_choices as $value=>$label): ?>
                <option value="<?php echo h($value); ?>" <?php echo $current_tendency===$value?'selected':''; ?>><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:1">
            <label class="muted">Bemerkung</label>
            <textarea class="input" name="remark[<?php echo $sid; ?>]" rows="2" placeholder="optional"><?php echo h($remark_values[$sid] ?? ''); ?></textarea>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</fieldset>
</div>
<?php accordion_section_end($compact_forms); ?>
<div style="height:14px"></div>
<button class="btn">Speichern</button>
<a class="btn secondary" href="<?php echo h($bp); ?>/teacher/exams.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>">Bearbeiten/Übersicht</a>
<a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
</form>
</div></div></div>
<script>
function toggleWrittenEntryMode(){
  var selected=document.querySelector('input[name="entry_mode"]:checked');
  var mode=selected ? selected.value : 'table';
  var table=document.getElementById('writtenTableMode');
  var single=document.getElementById('writtenSingleMode');
  if(table){
    table.style.display = mode === 'table' ? '' : 'none';
    table.disabled = mode !== 'table';
  }
  if(single){
    single.style.display = mode === 'single' ? '' : 'none';
    single.disabled = mode !== 'single';
  }
}
function switchSingleStudent(id){
  var hidden=document.querySelector('input[name="selected_student_id"]');
  if(hidden) hidden.value=id;
  document.querySelectorAll('.single-student-grade-fields').forEach(function(el){
    el.style.display = el.getAttribute('data-student-id') === String(id) ? '' : 'none';
  });
}
function filterWrittenRows(){
  var q=(document.getElementById('writtenStudentSearch')?.value || '').toLowerCase().trim();
  var groupValue=(document.getElementById('writtenGroupFilter')?.value || '').trim();
  var groupIds=groupValue ? new Set(groupValue.split(',').filter(Boolean)) : null;
  document.querySelectorAll('.written-grade-row').forEach(function(row){
    var matchesText=!q || (row.getAttribute('data-name') || '').includes(q);
    var matchesGroup=!groupIds || groupIds.has(row.getAttribute('data-student-id') || '');
    row.style.display = matchesText && matchesGroup ? '' : 'none';
  });
}
toggleWrittenEntryMode();
(function(){
  var select=document.querySelector('select[name="exam_type"]');
  var card=document.getElementById('writtenSummaryCard');
  var ref=document.getElementById('writtenSummaryRef');
  var text=document.getElementById('writtenSummaryText');
  if(!select || !card || !ref || !text) return;
  var summaryMap={};
  var tooltipMap={};
  try{ summaryMap=JSON.parse(card.getAttribute('data-summary-map') || '{}'); }catch(e){}
  try{ tooltipMap=JSON.parse(card.getAttribute('data-tooltip-map') || '{}'); }catch(e){}

  function render(){
    var type=select.value || 'SA';
    ref.textContent = type === 'SA' ? '§ 7 LBV' : '§ 8 LBV';
    ref.title = tooltipMap[type] || '';
    text.innerHTML = summaryMap[type] || '';
  }

  select.addEventListener('change', render);
  render();
})();
</script>
<?php render_footer(); ?>
