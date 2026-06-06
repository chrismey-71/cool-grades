<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/participation_presets.php';
require_once __DIR__.'/../lib/oral_assessments.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$assessment_type=oral_assessment_normalize_type((string)($_GET['oral_type'] ?? $_POST['assessment_type'] ?? 'ORAL_EXAM'));

$st=$pdo->prepare("SELECT * FROM classes WHERE id=?");
$st->execute([$class_id]);
$class=$st->fetch();
$st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");
$st->execute([$subject_id]);
$subject=$st->fetch();
if(!$class||!$subject){ http_response_code(400); exit('Klasse/Fach ungültig.'); }
require_teacher_assignment($u,$class_id,$subject_id);
require_class_writable($pdo,$class_id);

$students=load_class_students($pdo,$class_id,false);
$impacts=load_participation_options($pdo,(int)$u['id'],$subject_id,'impact');
$impact_labels=[];
foreach($impacts as $o){ $impact_labels[(int)$o['id']] = (string)$o['label']; }

$msg='';
$err='';
$fieldErrors=[];
$notice=(string)($_GET['msg'] ?? '');
if($notice==='saved'){
  $msg=oral_assessment_type_label($assessment_type).' gespeichert.';
}

$form_student_id=(int)($_POST['student_id'] ?? 0);
$form_date=(string)($_POST['assessment_date'] ?? date('Y-m-d'));
$form_impact_id=(int)($_POST['impact_option_id'] ?? 0);
$form_topic_area=trim((string)($_POST['topic_area'] ?? ''));
$form_questions=trim((string)($_POST['questions'] ?? ''));
$form_category=trim((string)($_POST['category'] ?? ''));
$form_title=trim((string)($_POST['title'] ?? ''));

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();

  if(!$form_student_id){ $err='Bitte Schüler:in wählen.'; $fieldErrors['student_id']='Bitte Schüler:in wählen.'; }
  elseif(!$form_date){ $err='Bitte Datum wählen.'; $fieldErrors['assessment_date']='Bitte Datum wählen.'; }
  elseif(!$form_impact_id){ $err='Bitte Eindruck/Relevanz wählen.'; $fieldErrors['impact_option_id']='Bitte Eindruck/Relevanz wählen.'; }

  $impact_label=$impact_labels[$form_impact_id] ?? '';
  if($err==='' && $impact_label===''){ $err='Ungültiger Eindruck/Relevanz.'; $fieldErrors['impact_option_id']='Dieser Eindruck ist nicht gültig.'; }

  if($err===''){
    if($assessment_type==='ORAL_EXAM'){
      if($form_topic_area===''){ $err='Bitte Themengebiet eingeben.'; $fieldErrors['topic_area']='Bitte Themengebiet eingeben.'; }
      elseif($form_questions===''){ $err='Bitte Fragen erfassen.'; $fieldErrors['questions']='Bitte Fragen erfassen.'; }
    } else {
      if($form_category===''){ $err='Bitte Kategorie eingeben.'; $fieldErrors['category']='Bitte Kategorie eingeben.'; }
      elseif($form_title===''){ $err='Bitte Thema/Titel eingeben.'; $fieldErrors['title']='Bitte Thema/Titel eingeben.'; }
    }
  }

  if($err===''){
    try{
      $pdo->prepare("INSERT INTO oral_assessments
        (class_id,subject_id,teacher_id,student_id,assessment_type,assessment_date,impact_option_id,impact_label,topic_area,questions,category,title,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          $class_id,$subject_id,(int)$u['id'],$form_student_id,$assessment_type,$form_date,
          $form_impact_id,$impact_label,
          $assessment_type==='ORAL_EXAM' ? $form_topic_area : null,
          $assessment_type==='ORAL_EXAM' ? $form_questions : null,
          $assessment_type==='ORAL_EXERCISE' ? $form_category : null,
          $assessment_type==='ORAL_EXERCISE' ? $form_title : null,
          now_iso()
        ]);
      $oral_id=(int)$pdo->lastInsertId();
      emit_event('oral_assessment_created',[
        'oral_assessment_id'=>$oral_id,
        'oral_type'=>$assessment_type,
        'student_id'=>$form_student_id,
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'impact'=>$impact_label,
        'topic_area'=>$assessment_type==='ORAL_EXAM' ? $form_topic_area : null,
        'category'=>$assessment_type==='ORAL_EXERCISE' ? $form_category : null,
        'title'=>$assessment_type==='ORAL_EXERCISE' ? $form_title : null,
      ]);
      header('Location: '.$bp.'/teacher/oral_new.php?'.http_build_query([
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'oral_type'=>$assessment_type,
        'msg'=>'saved',
      ]));
      exit;
    }catch(Exception $e){
      $err='Fehler: '.$e->getMessage();
    }
  }
}

$summary_map=[
  'ORAL_EXAM'=>oral_assessment_summary('ORAL_EXAM'),
  'ORAL_EXERCISE'=>oral_assessment_summary('ORAL_EXERCISE'),
];
$summary_tooltip_map=[
  'ORAL_EXAM'=>oral_assessment_summary_tooltip('ORAL_EXAM'),
  'ORAL_EXERCISE'=>oral_assessment_summary_tooltip('ORAL_EXERCISE'),
];
$compact_forms = compact_entry_forms_enabled($u);

render_header('Besondere mündliche Leistungsfeststellung',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Besondere mündliche Leistungsfeststellung</h1>
<div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b></div>
<?php if(legal_hints_enabled($u)): ?>
<details class="accordion" id="oralSummaryCard" data-summary-exam="<?php echo h($summary_map['ORAL_EXAM']); ?>" data-summary-exercise="<?php echo h($summary_map['ORAL_EXERCISE']); ?>" data-tooltip-exam="<?php echo h($summary_tooltip_map['ORAL_EXAM']); ?>" data-tooltip-exercise="<?php echo h($summary_tooltip_map['ORAL_EXERCISE']); ?>" style="margin-top:10px">
  <summary>
    <span class="acc-title" id="oralSummaryRef" title="<?php echo h(oral_assessment_summary_tooltip($assessment_type)); ?>" style="cursor:help"><?php echo h(oral_assessment_summary_ref($assessment_type)); ?></span>
  </summary>
  <div class="acc-body">
    <div id="oralSummaryText"><?php echo oral_assessment_summary($assessment_type); ?></div>
  </div>
</details>
<?php endif; ?>
<?php if($msg): ?><div class="card" style="margin-top:10px;border-color:#bfe5cd;background:#e8f5ee"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="margin-top:10px;border-color:#ffc6c0;background:#ffeceb"><?php echo h($err); ?></div><?php endif; ?>

<form method="post" style="margin-top:10px" <?php echo dirty_form_attrs($_SERVER['REQUEST_METHOD']==='POST' && $err!==''); ?>>
<?php echo csrf_input(); ?>
<input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
<input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
<?php accordion_section_start($compact_forms, 'Basisdaten', $form_student_id>0 || $_SERVER['REQUEST_METHOD']==='POST', 'margin-top:0', '', 'contrast-panel section-selection'); ?>
<div class="row contrast-form section-selection">
  <div>
    <label class="muted">Datum</label>
    <input class="input" type="date" name="assessment_date" value="<?php echo h($form_date); ?>" required>
    <?php if(!empty($fieldErrors['assessment_date'])): ?><div class="field-error"><?php echo h($fieldErrors['assessment_date']); ?></div><?php endif; ?>
  </div>
  <div>
    <label class="muted">Art</label>
    <select class="input" name="assessment_type" id="oralTypeSelect" style="max-width:260px">
      <?php foreach(oral_assessment_types() as $type=>$label): ?>
        <option value="<?php echo h($type); ?>" <?php echo $assessment_type===$type?'selected':''; ?>><?php echo h($label); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="flex:1">
    <label class="muted">Schüler:in</label>
    <select class="input" name="student_id" required>
      <option value="0">Bitte wählen…</option>
      <?php foreach($students as $s): $sid=(int)$s['id']; ?>
        <option value="<?php echo $sid; ?>" <?php echo $sid===$form_student_id?'selected':''; ?>><?php echo h($s['last_name'].', '.$s['first_name']); ?></option>
      <?php endforeach; ?>
    </select>
    <?php if(!empty($fieldErrors['student_id'])): ?><div class="field-error"><?php echo h($fieldErrors['student_id']); ?></div><?php endif; ?>
  </div>
</div>
<?php accordion_section_end($compact_forms); ?>

<?php if(!$compact_forms): ?><div style="height:12px"></div><?php endif; ?>
<?php accordion_section_start($compact_forms, 'Eindruck/Relevanz', $form_impact_id>0 || $_SERVER['REQUEST_METHOD']==='POST', 'margin-top:12px', '', 'contrast-panel section-rating'); ?>
<div class="row contrast-form section-rating">
  <div style="flex:1">
    <label class="muted">Eindruck/Relevanz</label>
    <select class="input" name="impact_option_id" required>
      <option value="0">Bitte wählen…</option>
      <?php foreach($impacts as $o): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo $form_impact_id===(int)$o['id']?'selected':''; ?>><?php echo h($o['label']); ?></option>
      <?php endforeach; ?>
    </select>
    <?php if(!empty($fieldErrors['impact_option_id'])): ?><div class="field-error"><?php echo h($fieldErrors['impact_option_id']); ?></div><?php endif; ?>
  </div>
</div>
<?php accordion_section_end($compact_forms); ?>

<?php if(!$compact_forms): ?><div style="height:12px"></div><?php endif; ?>
<?php accordion_section_start($compact_forms, 'Details zur Leistungsfeststellung', $form_topic_area!=='' || $form_questions!=='' || $form_category!=='' || $form_title!=='' || $_SERVER['REQUEST_METHOD']==='POST', 'margin-top:12px', '', 'contrast-panel section-details'); ?>
<div class="contrast-block section-details">
<div id="oralExamFields" style="<?php echo $assessment_type==='ORAL_EXAM'?'':'display:none'; ?>">
  <div style="height:12px"></div>
  <label class="muted">Themengebiet</label>
  <input class="input" name="topic_area" value="<?php echo h($form_topic_area); ?>" placeholder="z.B. Arbeitsrecht: Beendigung des Dienstverhältnisses">
  <?php if(!empty($fieldErrors['topic_area'])): ?><div class="field-error"><?php echo h($fieldErrors['topic_area']); ?></div><?php endif; ?>

  <div style="height:12px"></div>
  <label class="muted">Fragen</label>
  <textarea class="input" name="questions" rows="5" placeholder="z.B. Frage 1 ..., Frage 2 ..."><?php echo h($form_questions); ?></textarea>
  <?php if(!empty($fieldErrors['questions'])): ?><div class="field-error"><?php echo h($fieldErrors['questions']); ?></div><?php endif; ?>
</div>

<div id="oralExerciseFields" style="<?php echo $assessment_type==='ORAL_EXERCISE'?'':'display:none'; ?>">
  <div style="height:12px"></div>
  <label class="muted">Kategorie</label>
  <input class="input" name="category" value="<?php echo h($form_category); ?>" placeholder="z.B. Referat, Redeübung, Präsentation">
  <?php if(!empty($fieldErrors['category'])): ?><div class="field-error"><?php echo h($fieldErrors['category']); ?></div><?php endif; ?>

  <div style="height:12px"></div>
  <label class="muted">Thema / Titel</label>
  <input class="input" name="title" value="<?php echo h($form_title); ?>" placeholder="z.B. Rechte und Pflichten im Lehrvertrag">
  <?php if(!empty($fieldErrors['title'])): ?><div class="field-error"><?php echo h($fieldErrors['title']); ?></div><?php endif; ?>
</div>
</div>
<?php accordion_section_end($compact_forms); ?>

<div style="height:14px"></div>
<button class="btn">Speichern</button>
<a class="btn secondary" href="<?php echo h($bp); ?>/teacher/orals.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>&oral_type=<?php echo h($assessment_type); ?>">Bearbeiten/Übersicht</a>
<a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
</form>

<script>
(function(){
  var select=document.getElementById('oralTypeSelect');
  var examFields=document.getElementById('oralExamFields');
  var exerciseFields=document.getElementById('oralExerciseFields');
  var summaryCard=document.getElementById('oralSummaryCard');
  var summaryText=document.getElementById('oralSummaryText');
  var summaryRef=document.getElementById('oralSummaryRef');
  if(!select || !examFields || !exerciseFields || !summaryCard || !summaryText || !summaryRef) return;

  function render(){
    var type=select.value === 'ORAL_EXERCISE' ? 'ORAL_EXERCISE' : 'ORAL_EXAM';
    examFields.style.display = type === 'ORAL_EXAM' ? '' : 'none';
    exerciseFields.style.display = type === 'ORAL_EXERCISE' ? '' : 'none';
    summaryRef.textContent = type === 'ORAL_EXAM' ? '§ 5 LBV' : '§ 6 LBV';
    summaryRef.title = type === 'ORAL_EXAM'
      ? (summaryCard.getAttribute('data-tooltip-exam') || '')
      : (summaryCard.getAttribute('data-tooltip-exercise') || '');
    summaryText.innerHTML = type === 'ORAL_EXAM'
      ? (summaryCard.getAttribute('data-summary-exam') || '')
      : (summaryCard.getAttribute('data-summary-exercise') || '');
  }

  select.addEventListener('change', render);
  render();
})();
</script>
</div></div></div>
<?php render_footer(); ?>
