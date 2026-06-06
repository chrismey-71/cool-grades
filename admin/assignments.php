<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/school_years.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];

// filter
$filter_teacher=(int)($_GET['teacher_id'] ?? 0);
$currentSchoolYearId=school_year_current_id($pdo);
$schoolYearFilter=(int)($_GET['school_period_set_id'] ?? $currentSchoolYearId);
$show=$_GET['show'] ?? 'assigned'; // reserved

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $teacher_id=(int)$_POST['teacher_id'];
  $class_id=(int)$_POST['class_id'];
  $subject_id=(int)$_POST['subject_id'];
  $pdo->prepare("INSERT IGNORE INTO teacher_assignments (teacher_id,class_id,subject_id) VALUES (?,?,?)")
      ->execute([$teacher_id,$class_id,$subject_id]);
  $filter_teacher = $filter_teacher ?: $teacher_id;
  header('Location: '.$bp.'/admin/assignments.php?teacher_id='.$filter_teacher.'&school_period_set_id='.$schoolYearFilter);
  exit;
}

$teachers=$pdo->query("SELECT id,first_name,last_name FROM users WHERE role='teacher' AND is_active=1 ORDER BY last_name,first_name")->fetchAll();
$schoolYears=load_school_years($pdo,true);
$classes=load_classes_for_admin($pdo,$schoolYearFilter,false);
$subjects=$pdo->query("SELECT id,code,name FROM subjects ORDER BY code")->fetchAll();

$sql="SELECT ta.teacher_id,t.last_name,t.first_name,ta.class_id,c.name AS class_name,sp.label AS school_year_label,ta.subject_id,s.code
      FROM teacher_assignments ta
      JOIN users t ON t.id=ta.teacher_id AND t.role='teacher'
      JOIN classes c ON c.id=ta.class_id
      JOIN subjects s ON s.id=ta.subject_id
      LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
      WHERE c.school_period_set_id=?";
$params=[$schoolYearFilter];
if($filter_teacher){
  $sql.=" AND ta.teacher_id=?";
  $params[]=$filter_teacher;
}
$sql.=" ORDER BY t.last_name,t.first_name,c.name,s.code";

$st=$pdo->prepare($sql);
$st->execute($params);
$assignments=$st->fetchAll();

render_header('Zuweisungen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Zuweisungen (Lehrer:in ↔ Klasse ↔ Fach)</h1>
<p class="muted">Wähle zuerst eine Lehrkraft, um die bestehende Zuordnungsliste anzuzeigen.</p>

<form method="get" class="row" style="align-items:end;margin-top:10px">
  <div style="min-width:220px">
    <label class="muted">Schuljahr</label>
    <select class="input" name="school_period_set_id">
      <?php foreach($schoolYears as $sy): ?>
        <option value="<?php echo (int)$sy['id']; ?>" <?php echo $schoolYearFilter===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['is_current']===1)?' · aktuell':'')); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="min-width:320px">
    <label class="muted">Lehrer:in (Filter)</label>
    <select class="input" name="teacher_id">
      <option value="0" <?php echo $filter_teacher===0?'selected':''; ?>>– alle –</option>
      <?php foreach($teachers as $t): ?>
        <option value="<?php echo (int)$t['id']; ?>" <?php echo $filter_teacher===(int)$t['id']?'selected':''; ?>><?php echo h($t['last_name'].', '.$t['first_name']); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Anzeigen</button></div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><a class="btn secondary" href="<?php echo h($bp); ?>/admin/assignments.php">Alle</a></div>
</form>

<div style="height:12px"></div>
<h2>Neue Zuweisung</h2>
<form method="post" class="card" style="border-style:dashed;background:rgba(71,142,79,.06)" <?php echo dirty_form_attrs(); ?>>
  <?php echo csrf_input(); ?>
  <div class="row" style="align-items:end">
    <div>
      <label class="muted">Lehrer:in</label>
      <select class="input" name="teacher_id" required>
        <?php foreach($teachers as $t): ?>
          <option value="<?php echo (int)$t['id']; ?>" <?php echo ($filter_teacher && $filter_teacher===(int)$t['id'])?'selected':''; ?>><?php echo h($t['last_name'].', '.$t['first_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Klasse</label>
      <select class="input" name="class_id" required>
        <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Fach</label>
      <select class="input" name="subject_id" required>
        <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['code']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn">Zuweisen</button></div>
  </div>
</form>

<div style="height:14px"></div>
<h2>Bestehende Zuweisungen <?php echo $filter_teacher?'(gefiltert)':''; ?></h2>
<table class="table">
  <thead><tr><th>Lehrer:in</th><th>Klasse</th><th>Schuljahr</th><th>Fach</th></tr></thead>
  <tbody>
  <?php foreach($assignments as $a): ?>
    <tr>
      <td data-label="Lehrer:in"><?php echo h($a['last_name'].', '.$a['first_name']); ?></td>
      <td data-label="Klasse"><?php echo h($a['class_name']); ?></td>
      <td data-label="Schuljahr"><?php echo h((string)($a['school_year_label'] ?? '')); ?></td>
      <td data-label="Fach"><?php echo h($a['code']); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div style="height:12px"></div>
<a class="btn secondary" href="<?php echo h($bp); ?>/admin/index.php">Zurück</a>
</div></div></div>
<?php render_footer(); ?>
