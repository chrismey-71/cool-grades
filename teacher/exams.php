<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/assessment_summaries.php';
require_once __DIR__.'/../lib/school_years.php';
$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? 0);
$school_period_set_id=(int)($_GET['school_period_set_id'] ?? school_year_current_id($pdo));

$from=$_GET['from'] ?? date('Y-m-01');
$to=$_GET['to'] ?? date('Y-m-d');
$exam_type=strtoupper(trim((string)($_GET['exam_type'] ?? 'ALL')));
if($exam_type !== 'ALL'){
  $exam_type = written_assessment_normalize_type($exam_type);
}

$summary_type=$exam_type==='ALL' ? 'ALL' : written_assessment_normalize_type($exam_type);
$written_summary_map = [
  'ALL' => written_assessment_summary('ALL'),
  'SA' => written_assessment_summary('SA'),
  'TEST' => written_assessment_summary('TEST'),
  'REVIEW' => written_assessment_summary('REVIEW'),
  'TASK' => written_assessment_summary('TASK'),
  'OTHER' => written_assessment_summary('OTHER'),
];
$written_tooltip_map = [
  'ALL' => written_assessment_summary_tooltip('ALL'),
  'SA' => written_assessment_summary_tooltip('SA'),
  'TEST' => written_assessment_summary_tooltip('TEST'),
  'REVIEW' => written_assessment_summary_tooltip('REVIEW'),
  'TASK' => written_assessment_summary_tooltip('TASK'),
  'OTHER' => written_assessment_summary_tooltip('OTHER'),
];
$written_type_options = written_assessment_types();

$schoolYears=load_school_years($pdo,true);
$classes=load_teacher_classes($pdo,(int)$u['id'],$school_period_set_id,true,false);

$st=$pdo->prepare("SELECT DISTINCT s.id,s.code,s.name FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id WHERE ta.teacher_id=? ORDER BY s.code");
$st->execute([(int)$u['id']]);
$subjects=$st->fetchAll();

$exams=[];
$classContext=null;
if($class_id && $subject_id){
  require_teacher_assignment($u,$class_id,$subject_id);
  $classContext=class_context($pdo,$class_id);

  $sql="SELECT e.id,e.exam_date,e.title, e.exam_type
        FROM exams e
        WHERE e.teacher_id=? AND e.class_id=? AND e.subject_id=?
          AND e.exam_date BETWEEN ? AND ?";
  $params=[(int)$u['id'],$class_id,$subject_id,$from,$to];

  if($exam_type!=='ALL'){
    $sql.=" AND UPPER(IFNULL(e.exam_type,'SA'))=?";
    $params[]=$exam_type;
  }

  $sql.=" ORDER BY e.exam_date DESC, e.id DESC";
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $exams=$st->fetchAll();

  // grade counts
  if($exams){
    $ids=array_map(fn($r)=>(int)$r['id'],$exams);
    $in='('.implode(',',array_fill(0,count($ids),'?')).')';
    $st=$pdo->prepare("SELECT exam_id, COUNT(*) AS cnt FROM exam_grades WHERE exam_id IN $in GROUP BY exam_id");
    $st->execute($ids);
    $cnt=[];
    foreach($st->fetchAll() as $r){ $cnt[(int)$r['exam_id']] = (int)$r['cnt']; }
    foreach($exams as &$r){ $r['cnt']=$cnt[(int)$r['id']] ?? 0; }
    unset($r);
  }
}

render_header('Besondere schriftliche Leistungsfeststellungen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Besondere schriftliche Leistungsfeststellungen – bearbeiten</h1>
  <div class="muted">Hier findest du alle besonderen schriftlichen Leistungsfeststellungen (Schularbeit + Test) und kannst sie bearbeiten.</div>
  <?php if(legal_hints_enabled($u)): ?>
    <details class="accordion" id="writtenSummaryCard" data-summary-map="<?php echo h(json_encode($written_summary_map, JSON_UNESCAPED_UNICODE)); ?>" data-tooltip-map="<?php echo h(json_encode($written_tooltip_map, JSON_UNESCAPED_UNICODE)); ?>" style="margin-top:10px">
      <summary>
        <span class="acc-title" id="writtenSummaryRef" title="<?php echo h(written_assessment_summary_tooltip($summary_type)); ?>" style="cursor:help"><?php echo h(written_assessment_summary_ref($summary_type)); ?></span>
      </summary>
      <div class="acc-body">
        <div id="writtenSummaryText"><?php echo written_assessment_summary($summary_type); ?></div>
      </div>
    </details>
  <?php endif; ?>

  <form method="get" class="row" style="align-items:end;margin-top:12px" <?php echo teacher_assignment_guard_attrs($u); ?>>
    <div>
      <label class="muted">Schuljahr</label>
      <select class="input" name="school_period_set_id">
        <?php foreach($schoolYears as $sy): ?>
          <option value="<?php echo (int)$sy['id']; ?>" <?php echo $school_period_set_id===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['archived']===1)?' · Archiv':'')); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Klasse</label>
      <select class="input" name="class_id">
        <option value="0">–</option>
        <?php foreach($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id===(int)$c['id']?'selected':''; ?>><?php echo h($c['name'].(class_is_readonly($c)?' · Archiv':'')); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Fach</label>
      <select class="input" name="subject_id">
        <option value="0">–</option>
        <?php foreach($subjects as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>" <?php echo $subject_id===(int)$s['id']?'selected':''; ?>><?php echo h($s['code']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Von</label>
      <input class="input" type="date" name="from" value="<?php echo h($from); ?>">
    </div>
    <div>
      <label class="muted">Bis</label>
      <input class="input" type="date" name="to" value="<?php echo h($to); ?>">
    </div>
    <div>
      <label class="muted">Art</label>
      <select class="input" name="exam_type" style="min-width:170px">
        <option value="ALL" <?php echo $exam_type==='ALL'?'selected':''; ?>>Alle</option>
        <?php foreach($written_type_options as $typeValue => $typeLabel): ?>
          <option value="<?php echo h($typeValue); ?>" <?php echo $exam_type===$typeValue?'selected':''; ?>><?php echo h($typeLabel); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Anzeigen</button></div>

    <?php if($class_id && $subject_id && (!$classContext || !class_is_readonly($classContext))): ?>
      <div style="flex:0 0 auto"><label class="muted">&nbsp;</label>
        <a class="btn" href="<?php echo h($bp); ?>/teacher/exam_new.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>">Neu anlegen</a>
      </div>
    <?php endif; ?>
  </form>

  <?php if($exams): ?>
    <div style="height:12px"></div>
    <table class="table">
      <thead><tr><th>Datum</th><th>Art</th><th>Titel</th><th>Noten</th><th>Aktion</th></tr></thead>
      <tbody>
        <?php foreach($exams as $ex):
          $t=written_assessment_normalize_type((string)($ex['exam_type'] ?? 'SA'));
          $tlabel = written_assessment_type_label($t);
        ?>
          <tr>
            <td><?php echo h($ex['exam_date']); ?></td>
            <td><?php echo h($tlabel); ?></td>
            <td><?php echo h($ex['title']); ?></td>
            <td><?php echo (int)($ex['cnt'] ?? 0); ?></td>
            <td><a class="btn small" href="<?php echo h($bp); ?>/teacher/exam_edit.php?id=<?php echo (int)$ex['id']; ?>">Bearbeiten</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php elseif($class_id && $subject_id): ?>
    <div style="height:12px" class="muted">Keine Einträge gefunden.</div>
  <?php else: ?>
    <div style="height:12px" class="muted">Bitte Klasse/Fach wählen.</div>
  <?php endif; ?>

  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
</div></div></div>
<script>
(function(){
  var select=document.querySelector('select[name="exam_type"]');
  var card=document.getElementById('writtenSummaryCard');
  var ref=document.getElementById('writtenSummaryRef');
  var text=document.getElementById('writtenSummaryText');
  if(!select || !card || !ref || !text) return;

  function render(){
    var type=select.value || 'ALL';
    var summaryMap={};
    var tooltipMap={};
    try{ summaryMap=JSON.parse(card.getAttribute('data-summary-map') || '{}'); }catch(e){}
    try{ tooltipMap=JSON.parse(card.getAttribute('data-tooltip-map') || '{}'); }catch(e){}
    ref.textContent = type === 'ALL' ? '§ 7 und § 8 LBV' : (type === 'SA' ? '§ 7 LBV' : '§ 8 LBV');
    ref.title = tooltipMap[type] || '';
    text.innerHTML = summaryMap[type] || '';
  }

  select.addEventListener('change', render);
  render();
})();
</script>
<?php render_footer(); ?>
