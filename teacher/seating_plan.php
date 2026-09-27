<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/seating_plans.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);

$st=$pdo->prepare("SELECT * FROM classes WHERE id=?");
$st->execute([$class_id]);
$class=$st->fetch();
$st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");
$st->execute([$subject_id]);
$subject=$st->fetch();
if(!$class||!$subject){
  http_response_code(400);
  exit('Klasse/Fach ungültig.');
}
require_teacher_active_assignment($u,$class_id,$subject_id);

$msg='';
$err='';

function seating_plan_redirect(string $bp,int $classId,int $subjectId,string $msg='',string $err=''): void {
  $params=['class_id'=>$classId,'subject_id'=>$subjectId];
  if($msg!=='') $params['msg']=$msg;
  if($err!=='') $params['err']=$err;
  header('Location: '.$bp.'/teacher/seating_plan.php?'.http_build_query($params));
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');

  try{
    if($action==='set_dimensions'){
      $columns=(int)($_POST['columns'] ?? 0);
      $rows=(int)($_POST['rows'] ?? 0);
      if($columns<SEATING_PLAN_MIN_SIZE || $columns>SEATING_PLAN_MAX_SIZE || $rows<SEATING_PLAN_MIN_SIZE || $rows>SEATING_PLAN_MAX_SIZE){
        throw new RuntimeException('Bitte Spalten und Reihen zwischen '.SEATING_PLAN_MIN_SIZE.' und '.SEATING_PLAN_MAX_SIZE.' wählen.');
      }
      $result=save_seating_plan_dimensions($pdo,(int)$u['id'],$class_id,$subject_id,$columns,$rows);
      emit_event('teacher_seating_plan_resized',[
        'plan_id'=>$result['plan_id'],'class_id'=>$class_id,'subject_id'=>$subject_id,
        'columns'=>$columns,'rows'=>$rows,'removed_seat_count'=>$result['removed_seat_count'],
      ]);
      $doneMsg = $result['removed_seat_count']>0
        ? 'Sitzplan aktualisiert. '.$result['removed_seat_count'].' Platzzuweisung(en) außerhalb des neuen Rasters wurden entfernt.'
        : 'Sitzplan aktualisiert.';
      seating_plan_redirect($bp,$class_id,$subject_id,$doneMsg);
    }

    if($action==='assign_seat' || $action==='unassign_seat'){
      $plan=load_teacher_seating_plan($pdo,(int)$u['id'],$class_id,$subject_id);
      if(!$plan) throw new RuntimeException('Bitte zuerst Spalten/Reihen festlegen.');
      $col=(int)($_POST['seat_col'] ?? 0);
      $row=(int)($_POST['seat_row'] ?? 0);
      if($col<1 || $col>(int)$plan['columns'] || $row<1 || $row>(int)$plan['rows']){
        throw new RuntimeException('Ungültiger Platz.');
      }
      if($action==='unassign_seat'){
        unassign_seating_plan_seat($pdo,(int)$plan['id'],$col,$row);
        seating_plan_redirect($bp,$class_id,$subject_id,'Platz geleert.');
      }
      $student_id=(int)($_POST['student_id'] ?? 0);
      if($student_id<=0) throw new RuntimeException('Bitte eine Schülerin/einen Schüler wählen.');
      $stu=$pdo->prepare("SELECT id FROM students WHERE id=? AND class_id=?");
      $stu->execute([$student_id,$class_id]);
      if(!$stu->fetch()) throw new RuntimeException('Diese Person gehört nicht zu dieser Klasse.');
      assign_seating_plan_seat($pdo,(int)$plan['id'],$student_id,$col,$row);
      seating_plan_redirect($bp,$class_id,$subject_id,'Platz zugewiesen.');
    }
  }catch(Throwable $e){
    $err=$e->getMessage();
  }
}

$students=load_class_students($pdo,$class_id,false);
$plan=load_teacher_seating_plan($pdo,(int)$u['id'],$class_id,$subject_id);
$notice_code=(string)($_GET['msg'] ?? '');
if($notice_code!=='') $msg=$notice_code;
if($err==='' && isset($_GET['err'])) $err=(string)$_GET['err'];

$columns_value = $plan ? (int)$plan['columns'] : 4;
$rows_value = $plan ? (int)$plan['rows'] : 4;
$seatsByPosition = $plan ? $plan['seats_by_position'] : [];
$seatsByStudent = $plan ? $plan['seats_by_student'] : [];

$unplacedStudents=[];
foreach($students as $studentRow){
  if(!isset($seatsByStudent[(int)$studentRow['id']])) $unplacedStudents[]=$studentRow;
}

render_header('Sitzplan',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Sitzplan</h1>
  <div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b> · Lehrkraft: <b><?php echo h($u['username'] ?? ($u['last_name'] ?? '')); ?></b></div>
  <p class="muted" style="margin-top:8px">Der Sitzplan gilt für dich als Lehrkraft in dieser Klasse/Fach-Kombination. In der Mitarbeitserfassung kannst du damit statt der Liste eine Anzeige wie im Klassenraum verwenden.</p>

  <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <form method="post" class="row" style="align-items:end;margin-top:14px">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="set_dimensions">
    <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
    <div>
      <label class="muted">Spalten</label>
      <input class="input" type="number" min="<?php echo SEATING_PLAN_MIN_SIZE; ?>" max="<?php echo SEATING_PLAN_MAX_SIZE; ?>" name="columns" value="<?php echo (int)$columns_value; ?>" style="width:90px">
    </div>
    <div>
      <label class="muted">Reihen</label>
      <input class="input" type="number" min="<?php echo SEATING_PLAN_MIN_SIZE; ?>" max="<?php echo SEATING_PLAN_MAX_SIZE; ?>" name="rows" value="<?php echo (int)$rows_value; ?>" style="width:90px">
    </div>
    <div style="flex:0 0 auto">
      <label class="muted">&nbsp;</label>
      <button class="btn"><?php echo $plan ? 'Anwenden' : 'Sitzplan anlegen'; ?></button>
    </div>
    <?php if(!$plan): ?>
      <div class="muted" style="flex:1 1 260px">Klassische Tischanordnung nach Reihen und Spalten. Weitere Anordnungen (z.B. Gruppentische/Inseln) sind für später vorgesehen.</div>
    <?php endif; ?>
  </form>

  <?php if($plan): ?>
    <div style="height:16px"></div>
    <div class="muted">Klicke auf einen freien Platz, um eine Person zuzuweisen, oder auf „entfernen“ bei einem belegten Platz.</div>
    <div style="height:10px"></div>
    <div class="seating-grid" style="display:grid;grid-template-columns:repeat(<?php echo (int)$plan['columns']; ?>, minmax(120px,1fr));gap:10px;max-width:100%;overflow-x:auto">
      <?php for($r=1;$r<=(int)$plan['rows'];$r++): ?>
        <?php for($c=1;$c<=(int)$plan['columns'];$c++): ?>
          <?php $seat=$seatsByPosition[$c.'_'.$r] ?? null; ?>
          <div class="card" style="padding:8px;min-height:70px;display:flex;flex-direction:column;justify-content:space-between;background:<?php echo $seat?'#eef6ff':'#f8fafc'; ?>">
            <?php if($seat): ?>
              <div style="font-size:13px"><b><?php echo h($seat['last_name'].', '.$seat['first_name']); ?></b></div>
              <form method="post" style="margin-top:6px">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="unassign_seat">
                <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="seat_col" value="<?php echo $c; ?>">
                <input type="hidden" name="seat_row" value="<?php echo $r; ?>">
                <button class="btn small danger" type="submit" style="width:100%">entfernen</button>
              </form>
            <?php else: ?>
              <form method="post" style="display:flex;flex-direction:column;gap:6px;height:100%;justify-content:space-between">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="assign_seat">
                <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="seat_col" value="<?php echo $c; ?>">
                <input type="hidden" name="seat_row" value="<?php echo $r; ?>">
                <select class="input" name="student_id" style="font-size:12px">
                  <option value="0">– frei –</option>
                  <?php foreach($unplacedStudents as $studentRow): ?>
                    <option value="<?php echo (int)$studentRow['id']; ?>"><?php echo h($studentRow['last_name'].', '.$studentRow['first_name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn small secondary" type="submit" style="width:100%">setzen</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      <?php endfor; ?>
    </div>

    <?php if($unplacedStudents): ?>
      <div style="height:16px"></div>
      <div class="card" style="padding:12px">
        <b>Noch nicht platziert (<?php echo count($unplacedStudents); ?>):</b>
        <div class="muted" style="margin-top:4px">
          <?php
            $names=[];
            foreach($unplacedStudents as $studentRow){ $names[]=$studentRow['last_name'].', '.$studentRow['first_name']; }
            echo h(implode(' · ', $names));
          ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div style="height:16px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/participation_new.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id])); ?>">Zurück zur Mitarbeitserfassung</a>
</div></div></div>
<?php render_footer(); ?>
