<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/participation_presets.php';
require_once __DIR__.'/../lib/oral_assessments.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
if(!$id){ http_response_code(400); exit('Fehlende ID.'); }

$st=$pdo->prepare("SELECT oa.*, c.name AS class_name, s.code AS subject_code, st.last_name, st.first_name
                   FROM oral_assessments oa
                   JOIN classes c ON c.id=oa.class_id
                   JOIN subjects s ON s.id=oa.subject_id
                   JOIN students st ON st.id=oa.student_id
                   WHERE oa.id=? AND oa.teacher_id=?");
$st->execute([$id,(int)$u['id']]);
$oral=$st->fetch();
if(!$oral){ http_response_code(404); exit('Eintrag nicht gefunden (oder nicht berechtigt).'); }
require_teacher_assignment($u,(int)$oral['class_id'],(int)$oral['subject_id']);
require_class_writable($pdo,(int)$oral['class_id']);

$class_id=(int)$oral['class_id'];
$subject_id=(int)$oral['subject_id'];

$students=load_class_students($pdo,$class_id,false);
$impacts=load_participation_options($pdo,(int)$u['id'],$subject_id,'impact');
$impact_labels=[];
foreach($impacts as $o){ $impact_labels[(int)$o['id']] = (string)$o['label']; }

$form_type=oral_assessment_normalize_type((string)$oral['assessment_type']);
$form_student_id=(int)$oral['student_id'];
$form_date=(string)$oral['assessment_date'];
$form_impact_id=(int)($oral['impact_option_id'] ?? 0);
$form_topic_area=(string)($oral['topic_area'] ?? '');
$form_questions=(string)($oral['questions'] ?? '');
$form_category=(string)($oral['category'] ?? '');
$form_title=(string)($oral['title'] ?? '');

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? 'save');

  if($action==='delete'){
    $pdo->prepare("DELETE FROM oral_assessments WHERE id=? AND teacher_id=?")->execute([$id,(int)$u['id']]);
    emit_event('oral_assessment_deleted',[
      'oral_assessment_id'=>$id,
      'oral_type'=>$form_type,
      'student_id'=>$form_student_id,
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
    ]);
    header('Location: '.$bp.'/teacher/orals.php?'.http_build_query([
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
      'oral_type'=>$form_type,
    ]));
    exit;
  }

  if($action==='save'){
    $form_type=oral_assessment_normalize_type((string)($_POST['assessment_type'] ?? $form_type));
    $form_student_id=(int)($_POST['student_id'] ?? $form_student_id);
    $form_date=(string)($_POST['assessment_date'] ?? $form_date);
    $form_impact_id=(int)($_POST['impact_option_id'] ?? $form_impact_id);
    $form_topic_area=trim((string)($_POST['topic_area'] ?? ''));
    $form_questions=trim((string)($_POST['questions'] ?? ''));
    $form_category=trim((string)($_POST['category'] ?? ''));
    $form_title=trim((string)($_POST['title'] ?? ''));

    if(!$form_student_id) $err='Bitte Schüler:in wählen.';
    elseif(!$form_date) $err='Bitte Datum wählen.';
    elseif(!$form_impact_id) $err='Bitte Eindruck/Relevanz wählen.';

    $impact_label=$impact_labels[$form_impact_id] ?? '';
    if($err==='' && $impact_label==='') $err='Ungültiger Eindruck/Relevanz.';

    if($err===''){
      if($form_type==='ORAL_EXAM'){
        if($form_topic_area==='') $err='Bitte Themengebiet eingeben.';
        elseif($form_questions==='') $err='Bitte Fragen erfassen.';
      } else {
        if($form_category==='') $err='Bitte Kategorie eingeben.';
        elseif($form_title==='') $err='Bitte Thema/Titel eingeben.';
      }
    }

    if($err===''){
      try{
        $pdo->prepare("UPDATE oral_assessments
                       SET student_id=?, assessment_type=?, assessment_date=?, impact_option_id=?, impact_label=?,
                           topic_area=?, questions=?, category=?, title=?
                       WHERE id=? AND teacher_id=?")
          ->execute([
            $form_student_id,$form_type,$form_date,$form_impact_id,$impact_label,
            $form_type==='ORAL_EXAM' ? $form_topic_area : null,
            $form_type==='ORAL_EXAM' ? $form_questions : null,
            $form_type==='ORAL_EXERCISE' ? $form_category : null,
            $form_type==='ORAL_EXERCISE' ? $form_title : null,
            $id,(int)$u['id']
          ]);
        emit_event('oral_assessment_updated',[
          'oral_assessment_id'=>$id,
          'oral_type'=>$form_type,
          'student_id'=>$form_student_id,
          'class_id'=>$class_id,
          'subject_id'=>$subject_id,
          'impact'=>$impact_label,
          'topic_area'=>$form_type==='ORAL_EXAM' ? $form_topic_area : null,
          'category'=>$form_type==='ORAL_EXERCISE' ? $form_category : null,
          'title'=>$form_type==='ORAL_EXERCISE' ? $form_title : null,
        ]);
        $msg='Gespeichert.';

        $st=$pdo->prepare("SELECT oa.*, c.name AS class_name, s.code AS subject_code, st.last_name, st.first_name
                           FROM oral_assessments oa
                           JOIN classes c ON c.id=oa.class_id
                           JOIN subjects s ON s.id=oa.subject_id
                           JOIN students st ON st.id=oa.student_id
                           WHERE oa.id=? AND oa.teacher_id=?");
        $st->execute([$id,(int)$u['id']]);
        $oral=$st->fetch();
        $form_type=oral_assessment_normalize_type((string)$oral['assessment_type']);
        $form_student_id=(int)$oral['student_id'];
        $form_date=(string)$oral['assessment_date'];
        $form_impact_id=(int)($oral['impact_option_id'] ?? 0);
        $form_topic_area=(string)($oral['topic_area'] ?? '');
        $form_questions=(string)($oral['questions'] ?? '');
        $form_category=(string)($oral['category'] ?? '');
        $form_title=(string)($oral['title'] ?? '');
      }catch(Exception $e){
        $err='Fehler beim Speichern: '.$e->getMessage();
      }
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

render_header('Besondere mündliche Leistungsfeststellung bearbeiten',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Besondere mündliche Leistungsfeststellung bearbeiten</h1>
  <div class="muted"><?php echo h($oral['class_name']); ?> · <?php echo h($oral['subject_code']); ?> · Schüler:in <b><?php echo h($oral['last_name'].', '.$oral['first_name']); ?></b></div>
  <?php if(legal_hints_enabled($u)): ?>
    <details class="accordion" id="oralSummaryCard" data-summary-exam="<?php echo h($summary_map['ORAL_EXAM']); ?>" data-summary-exercise="<?php echo h($summary_map['ORAL_EXERCISE']); ?>" data-tooltip-exam="<?php echo h($summary_tooltip_map['ORAL_EXAM']); ?>" data-tooltip-exercise="<?php echo h($summary_tooltip_map['ORAL_EXERCISE']); ?>" style="margin-top:10px">
      <summary>
        <span class="acc-title" id="oralSummaryRef" title="<?php echo h(oral_assessment_summary_tooltip($form_type)); ?>" style="cursor:help"><?php echo h(oral_assessment_summary_ref($form_type)); ?></span>
      </summary>
      <div class="acc-body">
        <div id="oralSummaryText"><?php echo oral_assessment_summary($form_type); ?></div>
      </div>
    </details>
  <?php endif; ?>

  <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <form method="post" style="margin-top:12px" <?php echo dirty_form_attrs($_SERVER['REQUEST_METHOD']==='POST' && $err!==''); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <div class="row" style="align-items:end">
      <div>
        <label class="muted">Datum</label>
        <input class="input" type="date" name="assessment_date" value="<?php echo h($form_date); ?>" required>
      </div>
      <div>
        <label class="muted">Art</label>
        <select class="input" name="assessment_type" id="oralTypeSelect" style="max-width:260px">
          <?php foreach(oral_assessment_types() as $type=>$label): ?>
            <option value="<?php echo h($type); ?>" <?php echo $form_type===$type?'selected':''; ?>><?php echo h($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1">
        <label class="muted">Schüler:in</label>
        <select class="input" name="student_id" required>
          <?php foreach($students as $s): $sid=(int)$s['id']; ?>
            <option value="<?php echo $sid; ?>" <?php echo $sid===$form_student_id?'selected':''; ?>><?php echo h($s['last_name'].', '.$s['first_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="height:12px"></div>
    <label class="muted">Eindruck/Relevanz</label>
    <select class="input" name="impact_option_id" required>
      <option value="0">Bitte wählen…</option>
      <?php foreach($impacts as $o): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo $form_impact_id===(int)$o['id']?'selected':''; ?>><?php echo h($o['label']); ?></option>
      <?php endforeach; ?>
    </select>

    <div id="oralExamFields" style="<?php echo $form_type==='ORAL_EXAM'?'':'display:none'; ?>">
      <div style="height:12px"></div>
      <label class="muted">Themengebiet</label>
      <input class="input" name="topic_area" value="<?php echo h($form_topic_area); ?>">

      <div style="height:12px"></div>
      <label class="muted">Fragen</label>
      <textarea class="input" name="questions" rows="5"><?php echo h($form_questions); ?></textarea>
    </div>

    <div id="oralExerciseFields" style="<?php echo $form_type==='ORAL_EXERCISE'?'':'display:none'; ?>">
      <div style="height:12px"></div>
      <label class="muted">Kategorie</label>
      <input class="input" name="category" value="<?php echo h($form_category); ?>">

      <div style="height:12px"></div>
      <label class="muted">Thema / Titel</label>
      <input class="input" name="title" value="<?php echo h($form_title); ?>">
    </div>

    <div style="height:14px"></div>
    <button class="btn" name="action" value="save">Speichern</button>
    <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/orals.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>&oral_type=<?php echo h($form_type); ?>">Zur Übersicht</a>
    <button class="btn secondary" name="action" value="delete" onclick="return confirm('Leistungsfeststellung wirklich löschen?')">Löschen</button>
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
