<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/assessment_summaries.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
if(!$id){ http_response_code(400); exit('Fehlende ID.'); }

$st=$pdo->prepare("SELECT e.*, c.name AS class_name, s.code AS subject_code
                   FROM exams e
                   JOIN classes c ON c.id=e.class_id
                   JOIN subjects s ON s.id=e.subject_id
                   WHERE e.id=? AND e.teacher_id=?");
$st->execute([$id,(int)$u['id']]);
$ex=$st->fetch();
if(!$ex){ http_response_code(404); exit('Eintrag nicht gefunden (oder nicht berechtigt).'); }
require_teacher_assignment($u,(int)$ex['class_id'],(int)$ex['subject_id']);
require_class_writable($pdo,(int)$ex['class_id']);

$class_id=(int)$ex['class_id'];
$subject_id=(int)$ex['subject_id'];

$students=load_class_students($pdo,$class_id,false);

$grades=[]; // [student_id]=>['grade'=>..., 'tendency'=>..., 'remark'=>...]
$st=$pdo->prepare("SELECT student_id, grade, tendency, remark FROM exam_grades WHERE exam_id=?");
$st->execute([$id]);
foreach($st->fetchAll() as $r){
  $grades[(int)$r['student_id']] = [
    'grade'=>(int)$r['grade'],
    'tendency'=>normalize_exam_grade_tendency((string)($r['tendency'] ?? '')),
    'remark'=>(string)($r['remark'] ?? ''),
  ];
}

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action'] ?? 'save';

  if($action==='delete'){
    $pdo->prepare("DELETE FROM exams WHERE id=? AND teacher_id=?")->execute([$id,(int)$u['id']]);
    emit_event('exam_deleted',['exam_id'=>$id,'class_id'=>$class_id,'subject_id'=>$subject_id]);
    header('Location: '.$bp.'/teacher/exams.php?class_id='.$class_id.'&subject_id='.$subject_id);
    exit;
  }

  if($action==='save'){
    $date=$_POST['exam_date'] ?? $ex['exam_date'];
    $title=trim((string)($_POST['title'] ?? $ex['title']));
    $exam_type=written_assessment_normalize_type((string)($_POST['exam_type'] ?? ($ex['exam_type'] ?? 'SA')));

    if(!$date) $err='Bitte Datum wählen.';
    elseif($title==='') $err='Bitte Titel eingeben.';

    if($err===''){
      $pdo->beginTransaction();
      try{
        // Update exam (exam_type column exists in current schema via ensure_schema)
        try{
          $pdo->prepare("UPDATE exams SET exam_date=?, title=?, exam_type=? WHERE id=? AND teacher_id=?")
              ->execute([$date,$title,$exam_type,$id,(int)$u['id']]);
        } catch(PDOException $pex){
          // Fallback if exam_type column not available
          $pdo->prepare("UPDATE exams SET exam_date=?, title=? WHERE id=? AND teacher_id=?")
              ->execute([$date,$title,$id,(int)$u['id']]);
        }

        // Replace grades
        $pdo->prepare("DELETE FROM exam_grades WHERE exam_id=?")->execute([$id]);
        $ins=$pdo->prepare("INSERT INTO exam_grades (exam_id,student_id,grade,tendency,remark) VALUES (?,?,?,?,?)");
        foreach($students as $s){
          $sid=(int)$s['id'];
          $g=$_POST['grade'][$sid] ?? '';
          if($g==='') continue;
          $g=(int)$g;
          if($g<1 || $g>5) continue;
          $tendency=normalize_exam_grade_tendency((string)($_POST['tendency'][$sid] ?? ''));
          $remark=trim((string)($_POST['remark'][$sid] ?? ''));
          if($tendency!=='') $tendency=mb_substr($tendency,0,120);
          $ins->execute([$id,$sid,$g,$tendency!==''?$tendency:null,$remark!==''?$remark:null]);
        }

        $pdo->commit();
        emit_event('exam_updated',['exam_id'=>$id,'class_id'=>$class_id,'subject_id'=>$subject_id]);
        $msg='Gespeichert.';

        // Reload
        $st=$pdo->prepare("SELECT e.*, c.name AS class_name, s.code AS subject_code
                           FROM exams e
                           JOIN classes c ON c.id=e.class_id
                           JOIN subjects s ON s.id=e.subject_id
                           WHERE e.id=? AND e.teacher_id=?");
        $st->execute([$id,(int)$u['id']]);
        $ex=$st->fetch();

        $grades=[];
        $st=$pdo->prepare("SELECT student_id, grade, tendency, remark FROM exam_grades WHERE exam_id=?");
        $st->execute([$id]);
        foreach($st->fetchAll() as $r){
          $grades[(int)$r['student_id']] = [
            'grade'=>(int)$r['grade'],
            'tendency'=>normalize_exam_grade_tendency((string)($r['tendency'] ?? '')),
            'remark'=>(string)($r['remark'] ?? ''),
          ];
        }

      }catch(Exception $e2){
        $pdo->rollBack();
        $err='Fehler beim Speichern: '.$e2->getMessage();
      }
    }
  }
}

$typeNow = written_assessment_normalize_type((string)($ex['exam_type'] ?? 'SA'));
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

render_header('Besondere schriftliche Leistungsfeststellung bearbeiten',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Besondere schriftliche Leistungsfeststellung bearbeiten</h1>
  <div class="muted"><?php echo h($ex['class_name']); ?> · <?php echo h($ex['subject_code']); ?></div>
  <?php if(legal_hints_enabled($u)): ?>
    <details class="accordion" id="writtenSummaryCard" data-summary-map="<?php echo h(json_encode($written_summary_map, JSON_UNESCAPED_UNICODE)); ?>" data-tooltip-map="<?php echo h(json_encode($written_tooltip_map, JSON_UNESCAPED_UNICODE)); ?>" style="margin-top:10px">
      <summary>
        <span class="acc-title" id="writtenSummaryRef" title="<?php echo h(written_assessment_summary_tooltip($typeNow)); ?>" style="cursor:help"><?php echo h(written_assessment_summary_ref($typeNow)); ?></span>
      </summary>
      <div class="acc-body">
        <div id="writtenSummaryText"><?php echo written_assessment_summary($typeNow); ?></div>
      </div>
    </details>
  <?php endif; ?>

  <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <form method="post" style="margin-top:12px" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <div class="row" style="align-items:end">
      <div>
        <label class="muted">Datum</label>
        <input class="input" type="date" name="exam_date" value="<?php echo h($ex['exam_date']); ?>" required>
      </div>
      <div>
        <label class="muted">Art</label>
        <select class="input" name="exam_type" style="max-width:220px">
          <?php foreach($written_type_options as $typeValue => $typeLabel): ?>
            <option value="<?php echo h($typeValue); ?>" <?php echo $typeNow===$typeValue?'selected':''; ?>><?php echo h($typeLabel); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1">
        <label class="muted">Titel</label>
        <input class="input" name="title" value="<?php echo h($ex['title']); ?>" required>
      </div>
    </div>

    <div style="height:12px"></div>
    <table class="table">
      <thead><tr><th>Schüler:in</th><th>Note (1–5)</th><th>Tendenz</th><th>Bemerkung</th></tr></thead>
      <tbody>
        <?php $tendency_choices = exam_grade_tendency_choices(); ?>
        <?php foreach($students as $s):
          $sid=(int)$s['id'];
          $row=$grades[$sid] ?? ['grade'=>'','tendency'=>'','remark'=>''];
          $g=$row['grade'] ?? '';
          $current_tendency=(string)($row['tendency'] ?? '');
        ?>
          <tr>
            <td><?php echo h($s['last_name'].', '.$s['first_name']); ?></td>
            <td>
              <select class="input" name="grade[<?php echo $sid; ?>]" style="max-width:140px">
                <option value="">–</option>
                <?php for($i=1;$i<=5;$i++): ?>
                  <option value="<?php echo $i; ?>" <?php echo ((string)$g===(string)$i)?'selected':''; ?>><?php echo $i; ?></option>
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
              <textarea class="input" name="remark[<?php echo $sid; ?>]" rows="2" placeholder="optional"><?php echo h((string)($row['remark'] ?? '')); ?></textarea>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="height:14px"></div>
    <button class="btn" name="action" value="save">Speichern</button>
    <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/exams.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>">Zur Übersicht</a>
    <button class="btn secondary" name="action" value="delete" onclick="return confirm('Leistungsfeststellung wirklich löschen?')">Löschen</button>
  </form>

</div></div></div>
<script>
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
