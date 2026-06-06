<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/_crud.php';
require_once __DIR__.'/../lib/school_years.php';
$u=require_role('admin'); $pdo=db(); $bp=cfg()['base_path'];

$currentSchoolYearId=school_year_current_id($pdo);
$schoolYearFilter=(int)($_GET['school_period_set_id'] ?? $currentSchoolYearId);
$schoolYears=load_school_years($pdo,true);
$classes=load_classes_for_admin($pdo,$schoolYearFilter,false);
$classFilter=$_GET['class_id'] ?? '';
$sort=$_GET['sort'] ?? 'last';
$orderBy = ($sort==='first') ? 's.first_name,s.last_name' : 's.last_name,s.first_name';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';
  if($a==='save'){
    $id=$_POST['id']?(int)$_POST['id']:null;
    $first=trim($_POST['first_name']??''); $last=trim($_POST['last_name']??'');
    $class_id=(int)($_POST['class_id']??0);
    $active=(int)($_POST['is_active']??1);
    $nid=upsert('students',['first_name'=>$first,'last_name'=>$last,'class_id'=>$class_id,'is_active'=>$active],$id);
    ensure_student_enrollment($pdo,$nid,$class_id,'active');
    emit_event($id?'admin_student_updated':'admin_student_created',['target_id'=>$nid,'target_name'=>"$last, $first",'class_id'=>$class_id]);
    header('Location: '.$bp.'/admin/students.php?school_period_set_id='.$schoolYearFilter.'&class_id='.$class_id.'&sort='.$sort); exit;
  }
  if($a==='delete'){
    $id=(int)$_POST['id']; emit_event('admin_student_deleted',['target_id'=>$id]); del('students',$id);
    header('Location: '.$bp.'/admin/students.php?school_period_set_id='.$schoolYearFilter.'&class_id='.$classFilter.'&sort='.$sort); exit;
  }
  if($a==='import'){
    if(!isset($_FILES['csv'])||$_FILES['csv']['error']!==UPLOAD_ERR_OK) die('CSV Upload fehlgeschlagen');
    $class_id=(int)($_POST['import_class_id']??0);
    $csv=file_get_contents($_FILES['csv']['tmp_name']);
    $lines=preg_split("/\r\n|\n|\r/",$csv);
    $count=0;
    foreach($lines as $i=>$line){
      if(trim($line)==='') continue;
      $parts=str_getcsv($line,';');
      if($i===0 && preg_match('/vorname/i',$parts[0]??'')) continue;
      $first=trim($parts[0]??''); $last=trim($parts[1]??'');
      if($first===''||$last==='') continue;
      $sid=upsert('students',['first_name'=>$first,'last_name'=>$last,'class_id'=>$class_id,'is_active'=>1],null);
      ensure_student_enrollment($pdo,$sid,$class_id,'active');
      $count++;
    }
    emit_event('admin_students_import',['class_id'=>$class_id,'count'=>$count]);
    header('Location: '.$bp.'/admin/students.php?school_period_set_id='.$schoolYearFilter.'&class_id='.$class_id.'&sort='.$sort); exit;
  }
}

$edit=null;
if(!empty($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM students WHERE id=?");$st->execute([(int)$_GET['edit']]);$edit=$st->fetch(); }

$where='WHERE c.school_period_set_id=?'; $params=[$schoolYearFilter];
if($classFilter!==''){ $where.=' AND ce.class_id=?'; $params[]=(int)$classFilter; }
$st=$pdo->prepare("SELECT s.*, c.name AS class_name, sp.label AS school_year_label, ce.status AS enrollment_status
                   FROM class_enrollments ce
                   JOIN students s ON s.id=ce.student_id
                   JOIN classes c ON c.id=ce.class_id
                   LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
                   $where ORDER BY $orderBy");
$st->execute($params); $students=$st->fetchAll();

render_header('Schüler:innen',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Schüler:innen</h1>
      <div class="row" style="align-items:end">
        <form method="get" style="flex:2">
          <label class="muted">Schuljahr</label>
          <select class="input" name="school_period_set_id" onchange="this.form.submit()">
            <?php foreach($schoolYears as $sy): ?>
              <option value="<?php echo (int)$sy['id']; ?>" <?php echo $schoolYearFilter===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['is_current']===1)?' · aktuell':'')); ?></option>
            <?php endforeach; ?>
          </select>
          <div style="height:8px"></div>
          <label class="muted">Klasse</label>
          <select class="input" name="class_id" onchange="this.form.submit()">
            <option value="">Alle</option>
            <?php foreach($classes as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ((string)$c['id']===(string)$classFilter)?'selected':''; ?>><?php echo h($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="sort" value="<?php echo h($sort); ?>">
        </form>
        <form method="get" style="flex:1">
          <label class="muted">Sortierung</label>
          <select class="input" name="sort" onchange="this.form.submit()">
            <option value="last" <?php echo $sort==='last'?'selected':''; ?>>Nachname</option>
            <option value="first" <?php echo $sort==='first'?'selected':''; ?>>Vorname</option>
          </select>
          <input type="hidden" name="class_id" value="<?php echo h($classFilter); ?>">
          <input type="hidden" name="school_period_set_id" value="<?php echo (int)$schoolYearFilter; ?>">
        </form>
      </div>

      <div style="height:10px"></div>
      <table class="table">
        <thead><tr><th>Name</th><th>Klasse</th><th>Schuljahr</th><th>Zuordnung</th><th>Status</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach($students as $s): ?>
          <tr>
            <td><?php echo h($s['last_name'].', '.$s['first_name']); ?></td>
            <td><span class="badge"><?php echo h($s['class_name']); ?></span></td>
            <td><?php echo h((string)($s['school_year_label'] ?? '')); ?></td>
            <td><?php echo h(enrollment_status_label((string)($s['enrollment_status'] ?? 'active'))); ?></td>
            <td><?php echo ((int)$s['is_active']===1)?'<span class="badge ok">aktiv</span>':'<span class="badge off">inaktiv</span>'; ?></td>
            <td>
              <a class="btn small secondary" href="<?php echo h($bp); ?>/admin/students.php?edit=<?php echo (int)$s['id']; ?>&school_period_set_id=<?php echo (int)$schoolYearFilter; ?>">Bearbeiten</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Wirklich löschen?');">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="btn small danger">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12 col-6">
    <div class="card">
      <h1><?php echo $edit?'Schüler:in bearbeiten':'Neue:r Schüler:in'; ?></h1>
      <form method="post" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo h($edit['id']??''); ?>">
        <div class="row">
          <div><label class="muted">Vorname</label><input class="input" name="first_name" required value="<?php echo h($edit['first_name']??''); ?>"></div>
          <div><label class="muted">Nachname</label><input class="input" name="last_name" required value="<?php echo h($edit['last_name']??''); ?>"></div>
        </div>
        <div style="height:10px"></div>
        <label class="muted">Klasse</label>
        <?php $sel=$edit['class_id']??($classFilter!==''?(int)$classFilter:null); ?>
        <select class="input" name="class_id" required>
          <?php foreach($classes as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo ((string)$c['id']===(string)$sel)?'selected':''; ?>><?php echo h($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
        <div style="height:10px"></div>
        <label class="muted">Aktiv</label>
        <?php $a=(int)($edit['is_active']??1); ?>
        <select class="input" name="is_active">
          <option value="1" <?php echo $a===1?'selected':''; ?>>aktiv</option>
          <option value="0" <?php echo $a===0?'selected':''; ?>>inaktiv</option>
        </select>
        <div style="height:12px"></div>
        <button class="btn">Speichern</button>
        <?php if($edit): ?><a class="btn secondary" href="<?php echo h($bp); ?>/admin/students.php">Abbrechen</a><?php endif; ?>
      </form>
    </div>
  </div>

  <div class="col-12 col-6">
    <div class="card">
      <h1>CSV-Import</h1>
      <p class="muted">CSV (Semikolon) Spalten: Vorname;Nachname (Header optional).</p>
      <form method="post" enctype="multipart/form-data" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="import">
        <label class="muted">Klasse</label>
        <select class="input" name="import_class_id" required>
          <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
        </select>
        <div style="height:10px"></div>
        <input class="input" type="file" name="csv" accept=".csv,text/csv" required>
        <div style="height:12px"></div>
        <button class="btn">Import starten</button>
      </form>
    </div>
  </div>
</div>
<?php render_footer(); ?>
