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
$plan_id=(int)($_GET['plan_id'] ?? $_POST['plan_id'] ?? 0);

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

function seating_plan_redirect(string $bp,int $classId,int $subjectId,int $planId=0,string $msg='',string $err=''): void {
  $params=['class_id'=>$classId,'subject_id'=>$subjectId];
  if($planId>0) $params['plan_id']=$planId;
  if($msg!=='') $params['msg']=$msg;
  if($err!=='') $params['err']=$err;
  header('Location: '.$bp.'/teacher/seating_plan.php?'.http_build_query($params));
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');

  try{
    if($action==='save_plan'){
      $name=(string)($_POST['name'] ?? '');
      $columns=(int)($_POST['columns'] ?? 0);
      $rows=(int)($_POST['rows'] ?? 0);
      if($columns<SEATING_PLAN_MIN_SIZE || $columns>SEATING_PLAN_MAX_SIZE || $rows<SEATING_PLAN_MIN_SIZE || $rows>SEATING_PLAN_MAX_SIZE){
        throw new RuntimeException('Bitte Spalten und Reihen zwischen '.SEATING_PLAN_MIN_SIZE.' und '.SEATING_PLAN_MAX_SIZE.' wählen.');
      }
      $result=save_seating_plan($pdo,(int)$u['id'],$class_id,$subject_id,$name,$columns,$rows,$plan_id);
      emit_event('teacher_seating_plan_saved',[
        'plan_id'=>$result['plan_id'],'class_id'=>$class_id,'subject_id'=>$subject_id,
        'name'=>seating_plan_name($name),'columns'=>$columns,'rows'=>$rows,'removed_seat_count'=>$result['removed_seat_count'],
      ]);
      $doneMsg = $result['removed_seat_count']>0
        ? 'Sitzplan gespeichert. '.$result['removed_seat_count'].' Platzzuweisung(en) außerhalb des neuen Rasters wurden entfernt.'
        : 'Sitzplan gespeichert.';
      seating_plan_redirect($bp,$class_id,$subject_id,(int)$result['plan_id'],$doneMsg);
    }

    if($action==='delete_plan'){
      $deleted=delete_seating_plan($pdo,(int)$u['id'],$plan_id);
      if(!$deleted) throw new RuntimeException('Sitzplan nicht gefunden.');
      emit_event('teacher_seating_plan_deleted',[
        'plan_id'=>$plan_id,'class_id'=>$class_id,'subject_id'=>$subject_id,'name'=>(string)($deleted['name'] ?? ''),
      ]);
      seating_plan_redirect($bp,$class_id,$subject_id,0,'Sitzplan gelöscht.');
    }

    if($action==='assign_seat' || $action==='unassign_seat'){
      $isAjax=!empty($_POST['ajax']);
      $plan=load_teacher_seating_plan_by_id($pdo,(int)$u['id'],$plan_id);
      if(!$plan) throw new RuntimeException('Sitzplan nicht gefunden.');
      $col=(int)($_POST['seat_col'] ?? 0);
      $row=(int)($_POST['seat_row'] ?? 0);
      if($col<1 || $col>(int)$plan['columns'] || $row<1 || $row>(int)$plan['rows']){
        throw new RuntimeException('Ungültiger Platz.');
      }

      if($action==='unassign_seat'){
        $prev=$plan['seats_by_position'][$col.'_'.$row] ?? null;
        unassign_seating_plan_seat($pdo,(int)$plan['id'],$col,$row);
        if($isAjax){
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok'=>true,'action'=>'unassign','target'=>['col'=>$col,'row'=>$row],'student_id'=>$prev['student_id'] ?? null]);
          exit;
        }
        seating_plan_redirect($bp,$class_id,$subject_id,$plan_id,'Platz geleert.');
      }

      $student_id=(int)($_POST['student_id'] ?? 0);
      if($student_id<=0) throw new RuntimeException('Bitte eine Schülerin/einen Schüler wählen.');
      $stu=$pdo->prepare("SELECT id,first_name,last_name FROM students WHERE id=? AND class_id=?");
      $stu->execute([$student_id,$class_id]);
      $studentRow=$stu->fetch();
      if(!$studentRow) throw new RuntimeException('Diese Person gehört nicht zu dieser Klasse.');

      // assign_seating_plan_seat() räumt automatisch sowohl den Zielplatz
      // (falls belegt) als auch den bisherigen Platz der Person (falls
      // vorhanden). Für die AJAX-Antwort merken wir uns das vorher, damit
      // die Seite beide Zellen ohne Neuladen aktualisieren kann.
      $vacatedTarget=$plan['seats_by_position'][$col.'_'.$row] ?? null;
      $previousSeat=$plan['seats_by_student'][$student_id] ?? null;

      assign_seating_plan_seat($pdo,(int)$plan['id'],$student_id,$col,$row);

      if($isAjax){
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
          'ok'=>true,'action'=>'assign',
          'student'=>['id'=>(int)$studentRow['id'],'first_name'=>(string)$studentRow['first_name'],'last_name'=>(string)$studentRow['last_name']],
          'target'=>['col'=>$col,'row'=>$row],
          'vacated_target_student_id'=>($vacatedTarget && (int)$vacatedTarget['student_id']!==$student_id) ? (int)$vacatedTarget['student_id'] : null,
          'cleared_source'=>($previousSeat && ((int)$previousSeat['col']!==$col || (int)$previousSeat['row']!==$row)) ? ['col'=>(int)$previousSeat['col'],'row'=>(int)$previousSeat['row']] : null,
        ]);
        exit;
      }
      seating_plan_redirect($bp,$class_id,$subject_id,$plan_id,'Platz zugewiesen.');
    }
  }catch(Throwable $e){
    if(($action==='assign_seat' || $action==='unassign_seat') && !empty($_POST['ajax'])){
      header('Content-Type: application/json; charset=utf-8');
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
      exit;
    }
    $err=$e->getMessage();
  }
}

$students=load_class_students($pdo,$class_id,false);
$plans=load_teacher_seating_plans($pdo,(int)$u['id'],$class_id,$subject_id);

$plan=null;
foreach($plans as $p){
  if((int)$p['id']===$plan_id){ $plan=$p; break; }
}
// Ohne explizite Auswahl automatisch den zuletzt verwendeten Sitzplan zeigen
// (die Liste ist bereits nach updated_at DESC sortiert) - außer der Link
// "+ neuer Sitzplan" wurde angeklickt (new=1), dann soll trotz vorhandener
// Sitzpläne ein leeres Formular erscheinen.
$forceNewPlan=!empty($_GET['new']);
if(!$plan && !$plan_id && !$forceNewPlan && $plans) $plan=$plans[0];

$notice_code=(string)($_GET['msg'] ?? '');
if($notice_code!=='') $msg=$notice_code;
if($err==='' && isset($_GET['err'])) $err=(string)$_GET['err'];

$isNewPlan = ($plan===null);
$name_value = $plan ? (string)$plan['name'] : '';
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
  <div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b> · Lehrkraft: <b><?php echo h($u['username'] ?? ''); ?></b></div>
  <p class="muted" style="margin-top:8px">Du kannst mehrere Sitzpläne für diese Klasse/dieses Fach anlegen, z.&nbsp;B. für unterschiedliche Räume oder für Gruppenstunden mit abweichender Sitzordnung. In der Mitarbeitserfassung wählst du dann aus, welcher Sitzplan gerade gelten soll.</p>

  <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <?php if($plans): ?>
    <div style="height:14px"></div>
    <div class="row" style="gap:8px;flex-wrap:wrap;align-items:center">
      <span class="muted"><b>Sitzplan:</b></span>
      <?php foreach($plans as $p): ?>
        <a class="btn small <?php echo ($plan && (int)$plan['id']===(int)$p['id']) ? '' : 'secondary'; ?>"
           href="<?php echo h($bp); ?>/teacher/seating_plan.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'plan_id'=>(int)$p['id']])); ?>">
          <?php echo h($p['name']); ?> (<?php echo (int)$p['columns']; ?>×<?php echo (int)$p['rows']; ?>)
        </a>
      <?php endforeach; ?>
      <a class="btn small utility-manage" href="<?php echo h($bp); ?>/teacher/seating_plan.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'new'=>1])); ?>#seatingPlanForm">+ neuer Sitzplan</a>
    </div>
  <?php endif; ?>

  <div style="height:16px"></div>
  <form method="post" id="seatingPlanForm" class="row" style="align-items:end;flex-wrap:wrap">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="save_plan">
    <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
    <input type="hidden" name="plan_id" value="<?php echo $plan ? (int)$plan['id'] : 0; ?>">
    <div>
      <label class="muted">Name</label>
      <input class="input" name="name" maxlength="120" value="<?php echo h($name_value); ?>" placeholder="z.B. Standard, EDV-Saal, Gruppe A" style="width:220px">
    </div>
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
      <button class="btn"><?php echo $isNewPlan ? 'Sitzplan anlegen' : 'Speichern'; ?></button>
    </div>
    <?php if(!$isNewPlan): ?>
      <div style="flex:0 0 auto">
        <label class="muted">&nbsp;</label>
        <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/seating_plan.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'new'=>1])); ?>">Neuen Sitzplan anlegen (statt bearbeiten)</a>
      </div>
      <div style="flex:0 0 auto">
        <label class="muted">&nbsp;</label>
        <form method="post" onsubmit="return confirm('Diesen Sitzplan wirklich löschen?');" style="margin:0">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="delete_plan">
          <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
          <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
          <input type="hidden" name="plan_id" value="<?php echo (int)$plan['id']; ?>">
          <button class="btn small danger" type="submit">Diesen Sitzplan löschen</button>
        </form>
      </div>
    <?php endif; ?>
  </form>

  <?php if(!$isNewPlan): ?>
    <style>
      .seat-cell{cursor:pointer;user-select:none}
      .seat-cell.seat-empty .muted{margin:auto;text-align:center;width:100%}
      .seat-cell.selected,.unplaced-chip.selected{outline:3px solid #2563eb;outline-offset:-2px}
      .unplaced-chip{cursor:pointer}
    </style>
    <div style="height:16px"></div>
    <div class="muted">Klicke auf eine Schülerin/einen Schüler unten, dann auf einen Platz. Ein Klick auf einen belegten Platz nimmt die dort sitzende Person auf, damit du sie direkt weitersetzen kannst.</div>
    <div id="seatSelectionHint" class="muted" style="min-height:20px;margin-top:6px"></div>
    <div style="height:10px"></div>
    <div class="seating-grid" id="seatingGrid" style="display:grid;grid-template-columns:repeat(<?php echo (int)$plan['columns']; ?>, minmax(120px,1fr));gap:10px;max-width:100%;overflow-x:auto">
      <?php for($r=1;$r<=(int)$plan['rows'];$r++): ?>
        <?php for($c=1;$c<=(int)$plan['columns'];$c++): ?>
          <?php $seat=$seatsByPosition[$c.'_'.$r] ?? null; ?>
          <div class="card seat-cell <?php echo $seat?'':'seat-empty'; ?>" data-col="<?php echo $c; ?>" data-row="<?php echo $r; ?>"
               data-student-id="<?php echo $seat?(int)$seat['student_id']:''; ?>"
               data-first-name="<?php echo $seat?h($seat['first_name']):''; ?>"
               data-last-name="<?php echo $seat?h($seat['last_name']):''; ?>"
               style="padding:8px;min-height:70px;display:flex;flex-direction:column;justify-content:space-between;background:<?php echo $seat?'#eef6ff':'#f8fafc'; ?>">
            <?php if($seat): ?>
              <div class="seat-name" style="font-size:13px"><b><?php echo h($seat['last_name'].', '.$seat['first_name']); ?></b></div>
              <button type="button" class="btn small danger seat-remove-btn" style="width:100%">entfernen</button>
            <?php else: ?>
              <div class="muted">– frei –</div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      <?php endfor; ?>
    </div>

    <div style="height:16px"></div>
    <div class="card" style="padding:12px">
      <b>Noch nicht platziert (<span id="unplacedCount"><?php echo count($unplacedStudents); ?></span>):</b>
      <div id="unplacedList" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach($unplacedStudents as $studentRow): ?>
          <button type="button" class="btn small secondary unplaced-chip"
                  data-student-id="<?php echo (int)$studentRow['id']; ?>"
                  data-first-name="<?php echo h($studentRow['first_name']); ?>"
                  data-last-name="<?php echo h($studentRow['last_name']); ?>">
            <?php echo h($studentRow['last_name'].', '.$studentRow['first_name']); ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <script>
    (function(){
      var bp=<?php echo json_encode($bp); ?>;
      var classId=<?php echo (int)$class_id; ?>;
      var subjectId=<?php echo (int)$subject_id; ?>;
      var planId=<?php echo (int)$plan['id']; ?>;
      var grid=document.getElementById('seatingGrid');
      var unplacedList=document.getElementById('unplacedList');
      var unplacedCountEl=document.getElementById('unplacedCount');
      var hintEl=document.getElementById('seatSelectionHint');
      var selected=null; // {id, firstName, lastName}

      function tokenEl(){ return document.querySelector('meta[name="csrf-token"]'); }

      function post(action, extra){
        var fd=new FormData();
        fd.append('action', action);
        fd.append('class_id', classId);
        fd.append('subject_id', subjectId);
        fd.append('plan_id', planId);
        fd.append('ajax', '1');
        var t=tokenEl();
        fd.append('_csrf', t?t.getAttribute('content'):'');
        for(var k in extra){ fd.append(k, extra[k]); }
        return fetch(bp+'/teacher/seating_plan.php', {method:'POST', body:fd, credentials:'same-origin'})
          .then(function(r){ return r.json().catch(function(){ return {ok:false,error:'Unerwartete Antwort.'}; }); });
      }

      function studentLabel(firstName, lastName){ return lastName+', '+firstName; }

      function updateHint(){
        hintEl.textContent = selected
          ? ('Ausgewählt: '+studentLabel(selected.firstName, selected.lastName)+' – jetzt auf einen Platz klicken (oder erneut anklicken zum Abbrechen).')
          : '';
      }

      function clearSelectionHighlight(){
        var prevChip=unplacedList.querySelector('.unplaced-chip.selected');
        if(prevChip) prevChip.classList.remove('selected');
        var prevSeat=grid.querySelector('.seat-cell.selected');
        if(prevSeat) prevSeat.classList.remove('selected');
      }

      function selectStudent(id, firstName, lastName, sourceEl){
        if(selected && selected.id===id){
          selected=null;
          clearSelectionHighlight();
          updateHint();
          return;
        }
        clearSelectionHighlight();
        selected={id:id, firstName:firstName, lastName:lastName};
        if(sourceEl) sourceEl.classList.add('selected');
        updateHint();
      }

      function addUnplacedChip(id, firstName, lastName){
        var btn=document.createElement('button');
        btn.type='button';
        btn.className='btn small secondary unplaced-chip';
        btn.setAttribute('data-student-id', id);
        btn.setAttribute('data-first-name', firstName);
        btn.setAttribute('data-last-name', lastName);
        btn.textContent=studentLabel(firstName, lastName);
        // alphabetisch einsortieren (Nachname, Vorname), wie die Server-Liste
        var label=studentLabel(firstName, lastName).toLowerCase();
        var chips=unplacedList.querySelectorAll('.unplaced-chip');
        var inserted=false;
        for(var i=0;i<chips.length;i++){
          var otherLabel=studentLabel(chips[i].getAttribute('data-last-name'), chips[i].getAttribute('data-first-name'));
          if(label < otherLabel.toLowerCase()){
            unplacedList.insertBefore(btn, chips[i]);
            inserted=true;
            break;
          }
        }
        if(!inserted) unplacedList.appendChild(btn);
        updateUnplacedCount();
      }

      function removeUnplacedChip(id){
        var chip=unplacedList.querySelector('.unplaced-chip[data-student-id="'+id+'"]');
        if(chip) chip.remove();
        updateUnplacedCount();
      }

      function updateUnplacedCount(){
        unplacedCountEl.textContent=unplacedList.querySelectorAll('.unplaced-chip').length;
      }

      function renderSeatCell(cell, id, firstName, lastName){
        cell.setAttribute('data-student-id', id || '');
        cell.setAttribute('data-first-name', firstName || '');
        cell.setAttribute('data-last-name', lastName || '');
        cell.innerHTML='';
        if(id){
          cell.classList.remove('seat-empty');
          cell.style.background='#eef6ff';
          var nameDiv=document.createElement('div');
          nameDiv.className='seat-name';
          nameDiv.style.fontSize='13px';
          var b=document.createElement('b');
          b.textContent=studentLabel(firstName, lastName);
          nameDiv.appendChild(b);
          var removeBtn=document.createElement('button');
          removeBtn.type='button';
          removeBtn.className='btn small danger seat-remove-btn';
          removeBtn.style.width='100%';
          removeBtn.textContent='entfernen';
          cell.appendChild(nameDiv);
          cell.appendChild(removeBtn);
        } else {
          cell.classList.add('seat-empty');
          cell.style.background='#f8fafc';
          var placeholder=document.createElement('div');
          placeholder.className='muted';
          placeholder.textContent='– frei –';
          cell.appendChild(placeholder);
        }
      }

      function findSeatCell(col, row){
        return grid.querySelector('.seat-cell[data-col="'+col+'"][data-row="'+row+'"]');
      }

      function findSeatCellByStudent(id){
        return grid.querySelector('.seat-cell[data-student-id="'+id+'"]');
      }

      function doAssign(col, row){
        if(!selected) return;
        var s=selected;
        post('assign_seat', {seat_col:col, seat_row:row, student_id:s.id}).then(function(res){
          if(!res || !res.ok){
            window.alert((res && res.error) ? res.error : 'Der Platz konnte nicht zugewiesen werden.');
            return;
          }
          // Alten Platz der ausgewählten Person leeren (falls vorhanden).
          var oldCell=findSeatCellByStudent(s.id);
          if(oldCell && oldCell!==findSeatCell(col,row)) renderSeatCell(oldCell, null, null, null);
          removeUnplacedChip(s.id);
          // Person, die vorher auf dem Zielplatz saß, wandert zurück in die Liste.
          if(res.vacated_target_student_id){
            var targetCellBefore=findSeatCell(col, row);
            var evictedFirst=targetCellBefore ? targetCellBefore.getAttribute('data-first-name') : '';
            var evictedLast=targetCellBefore ? targetCellBefore.getAttribute('data-last-name') : '';
            addUnplacedChip(res.vacated_target_student_id, evictedFirst, evictedLast);
          }
          var targetCell=findSeatCell(col, row);
          if(targetCell) renderSeatCell(targetCell, s.id, s.firstName, s.lastName);
          selected=null;
          clearSelectionHighlight();
          updateHint();
        });
      }

      function doUnassign(cell){
        var col=cell.getAttribute('data-col');
        var row=cell.getAttribute('data-row');
        var id=cell.getAttribute('data-student-id');
        var firstName=cell.getAttribute('data-first-name');
        var lastName=cell.getAttribute('data-last-name');
        if(!id) return;
        post('unassign_seat', {seat_col:col, seat_row:row}).then(function(res){
          if(!res || !res.ok){
            window.alert((res && res.error) ? res.error : 'Der Platz konnte nicht geleert werden.');
            return;
          }
          renderSeatCell(cell, null, null, null);
          addUnplacedChip(id, firstName, lastName);
          if(selected && String(selected.id)===String(id)){
            selected=null;
            clearSelectionHighlight();
            updateHint();
          }
        });
      }

      // Ein einziger Klick-Handler pro Container statt einem pro Zelle/Chip -
      // so muss nach jeder DOM-Änderung (Zuweisen/Entfernen) nichts neu
      // gebunden werden, und es können sich keine doppelten Listener auf
      // demselben Platz ansammeln, wenn er mehrfach belegt/geleert wird.
      unplacedList.addEventListener('click', function(e){
        var chip=e.target.closest('.unplaced-chip');
        if(!chip) return;
        selectStudent(chip.getAttribute('data-student-id'), chip.getAttribute('data-first-name'), chip.getAttribute('data-last-name'), chip);
      });

      grid.addEventListener('click', function(e){
        var removeBtn=e.target.closest('.seat-remove-btn');
        if(removeBtn){
          doUnassign(removeBtn.closest('.seat-cell'));
          return;
        }
        var cell=e.target.closest('.seat-cell');
        if(!cell) return;
        var occupied=!!cell.getAttribute('data-student-id');
        var col=cell.getAttribute('data-col');
        var row=cell.getAttribute('data-row');
        var id=cell.getAttribute('data-student-id');
        if(occupied && !selected){
          // Person am belegten Platz aufnehmen, um sie woanders hinzusetzen.
          selectStudent(id, cell.getAttribute('data-first-name'), cell.getAttribute('data-last-name'), cell);
          return;
        }
        if(occupied && selected && String(selected.id)===String(id)){
          // Wieder abwählen.
          selected=null;
          clearSelectionHighlight();
          updateHint();
          return;
        }
        if(selected) doAssign(col, row);
      });
    })();
    </script>
  <?php endif; ?>

  <div style="height:16px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/participation_new.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id])); ?>">Zurück zur Mitarbeitserfassung</a>
</div></div></div>
<?php render_footer(); ?>
