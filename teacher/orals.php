<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/oral_assessments.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? 0);
$school_period_set_id=(int)($_GET['school_period_set_id'] ?? school_year_current_id($pdo));
$from=$_GET['from'] ?? date('Y-m-01');
$to=$_GET['to'] ?? date('Y-m-d');
$oral_type=strtoupper(trim((string)($_GET['oral_type'] ?? 'ALL')));
if(!in_array($oral_type,['ALL','ORAL_EXAM','ORAL_EXERCISE'],true)) $oral_type='ALL';

$schoolYears=load_school_years($pdo,true);
$classes=load_teacher_classes($pdo,(int)$u['id'],$school_period_set_id,true,false);

$st=$pdo->prepare("SELECT DISTINCT s.id,s.code,s.name FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id WHERE ta.teacher_id=? ORDER BY s.code");
$st->execute([(int)$u['id']]);
$subjects=$st->fetchAll();

$rows=[];
$classContext=null;
if($class_id && $subject_id){
  require_teacher_assignment($u,$class_id,$subject_id);
  $classContext=class_context($pdo,$class_id);

  $sql="SELECT oa.id, oa.assessment_date, oa.assessment_type, oa.impact_label, oa.topic_area, oa.questions, oa.category, oa.title,
               st.last_name, st.first_name
        FROM oral_assessments oa
        JOIN students st ON st.id=oa.student_id
        WHERE oa.teacher_id=? AND oa.class_id=? AND oa.subject_id=?
          AND oa.assessment_date BETWEEN ? AND ?";
  $params=[(int)$u['id'],$class_id,$subject_id,$from,$to];
  if($oral_type!=='ALL'){
    $sql.=" AND oa.assessment_type=?";
    $params[]=$oral_type;
  }
  $sql.=" ORDER BY oa.assessment_date DESC, oa.id DESC";
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $rows=$st->fetchAll();
}

$summary_type=$oral_type==='ALL' ? 'ALL' : oral_assessment_normalize_type($oral_type);
$new_type=$oral_type==='ORAL_EXERCISE' ? 'ORAL_EXERCISE' : 'ORAL_EXAM';

render_header('Besondere mündliche Leistungsfeststellungen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Besondere mündliche Leistungsfeststellungen – bearbeiten</h1>
  <div class="muted">Hier findest du mündliche Prüfungen und mündliche Übungen getrennt von den schriftlichen Leistungsfeststellungen.</div>
  <?php if(legal_hints_enabled($u)): ?>
    <details class="accordion" style="margin-top:10px">
      <summary>
        <span class="acc-title" title="<?php echo h(oral_assessment_summary_tooltip($summary_type)); ?>" style="cursor:help"><?php echo h(oral_assessment_summary_ref($summary_type)); ?></span>
      </summary>
      <div class="acc-body">
        <?php echo oral_assessment_summary($summary_type); ?>
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
      <select class="input" name="oral_type" style="min-width:220px">
        <option value="ALL" <?php echo $oral_type==='ALL'?'selected':''; ?>>Alle</option>
        <option value="ORAL_EXAM" <?php echo $oral_type==='ORAL_EXAM'?'selected':''; ?>>mündliche Prüfung</option>
        <option value="ORAL_EXERCISE" <?php echo $oral_type==='ORAL_EXERCISE'?'selected':''; ?>>mündliche Übung</option>
      </select>
    </div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Anzeigen</button></div>

    <?php if($class_id && $subject_id && (!isset($classContext) || !$classContext || !class_is_readonly($classContext))): ?>
      <div style="flex:0 0 auto"><label class="muted">&nbsp;</label>
        <a class="btn" href="<?php echo h($bp); ?>/teacher/oral_new.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>&oral_type=<?php echo h($new_type); ?>">Neu anlegen</a>
      </div>
    <?php endif; ?>
  </form>

  <?php if($rows): ?>
    <div style="height:12px"></div>
    <table class="table">
      <thead><tr><th>Datum</th><th>Art</th><th>Schüler:in</th><th>Eindruck/Relevanz</th><th>Details</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach($rows as $row): ?>
        <tr>
          <td><?php echo h($row['assessment_date']); ?></td>
          <td><?php echo h(oral_assessment_type_label((string)$row['assessment_type'])); ?></td>
          <td><?php echo h($row['last_name'].', '.$row['first_name']); ?></td>
          <td><?php echo h((string)($row['impact_label'] ?? '—')); ?></td>
          <td><?php echo h(oral_assessment_detail($row)); ?></td>
          <td><a class="btn small" href="<?php echo h($bp); ?>/teacher/oral_edit.php?id=<?php echo (int)$row['id']; ?>">Bearbeiten</a></td>
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
<?php render_footer(); ?>
