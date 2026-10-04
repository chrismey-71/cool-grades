<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/../lib/seating_plans.php';
require_once __DIR__.'/../lib/competence_observations.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];
$teacherId=(int)$u['id'];

$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$today=date('Y-m-d');

// AJAX-Speichern einer Tag-Auswahl (vgl. teacher/seating_plan.php - eine
// Seite für normales POST+Redirect und AJAX-JSON, gesteuert über den
// 'ajax'-Flag).
if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action'] ?? '')==='save_tags'){
  header('Content-Type: application/json; charset=utf-8');
  try{
    verify_csrf();
    if($class_id<=0 || $subject_id<=0) throw new RuntimeException('Bitte zuerst Klasse und Fach wählen.');
    require_teacher_active_assignment($u,$class_id,$subject_id);

    $student_id=(int)($_POST['student_id'] ?? 0);
    $stu=$pdo->prepare("SELECT id FROM students WHERE id=? AND class_id=?");
    $stu->execute([$student_id,$class_id]);
    if(!$stu->fetch()) throw new RuntimeException('Diese Person gehört nicht zu dieser Klasse.');

    $tagIdsRaw=(string)($_POST['tag_ids'] ?? '[]');
    $tagIds=json_decode($tagIdsRaw,true);
    if(!is_array($tagIds)) $tagIds=[];

    $saved=competence_save_student_tags($pdo,$teacherId,$student_id,$class_id,$subject_id,$today,$tagIds);

    emit_event('teacher_competence_observation_saved',[
      'class_id'=>$class_id,'subject_id'=>$subject_id,'student_id'=>$student_id,
      'observation_date'=>$today,'tag_count'=>count($saved),
    ]);

    echo json_encode(['ok'=>true,'student_id'=>$student_id,'tag_ids'=>$saved]);
  }catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

// Klassen-/Fach-Auswahl: dieselbe Quelle wie im Dashboard/Stundenerfassung -
// nur aktuell aktive Zuweisungen im laufenden Schuljahr des gewählten
// Arbeitsbereichs Schule (teacher_school_context_id).
$selectedSchoolId=teacher_school_context_id($pdo,$teacherId);
$currentSchoolYearId=school_year_current_id($pdo,$selectedSchoolId);
$classes=load_teacher_classes($pdo,$teacherId,$currentSchoolYearId,false,false,false,$selectedSchoolId);

// $subjectClassIds ordnet jedem Fach die Klassen zu, in denen die Lehrkraft
// es laut Zuweisung tatsaechlich unterrichtet - das Fach-Dropdown listet
// zunaechst weiterhin alle Faecher ueber alle Klassen hinweg, das Skript
// unten blendet aber nach Wahl der Klasse clientseitig sofort die dort
// nicht unterrichteten Faecher aus (gleiches Muster wie in reports.php).
// Feedback vom 2026-10-04: nach Klassenwahl sollen nur die dort tatsaechlich
// unterrichteten Faecher erscheinen.
$subjectSql="SELECT DISTINCT c.id AS class_id, s.id AS subject_id, s.code, s.name
             FROM teacher_assignments ta
             JOIN classes c ON c.id=ta.class_id
             JOIN school_forms sf ON sf.id=c.school_form_id
             JOIN subjects s ON s.id=ta.subject_id
             WHERE ta.teacher_id=? AND ta.status='active' AND c.school_period_set_id=? AND c.is_archived=0 AND c.is_departed=0";
$subjectParams=[$teacherId,$currentSchoolYearId];
if($selectedSchoolId>0){ $subjectSql.=" AND sf.school_id=?"; $subjectParams[]=$selectedSchoolId; }
$subjectSql.=" ORDER BY s.code";
$st=$pdo->prepare($subjectSql);
$st->execute($subjectParams);
$subjects=[];
$subjectClassIds=[];
foreach($st->fetchAll() as $row){
  $sid=(int)$row['subject_id'];
  if(!isset($subjectClassIds[$sid])){
    $subjectClassIds[$sid]=[];
    $subjects[]=['id'=>$sid,'code'=>$row['code'],'name'=>$row['name']];
  }
  $subjectClassIds[$sid][]=(int)$row['class_id'];
}

$class=null; $subject=null; $err='';
if($class_id>0 && $subject_id>0){
  $st=$pdo->prepare("SELECT * FROM classes WHERE id=?");
  $st->execute([$class_id]);
  $class=$st->fetch();
  $st=$pdo->prepare("SELECT * FROM subjects WHERE id=?");
  $st->execute([$subject_id]);
  $subject=$st->fetch();
  if(!$class || !$subject){
    $err='Klasse/Fach ungültig.';
    $class_id=0; $subject_id=0;
  } else {
    require_teacher_active_assignment($u,$class_id,$subject_id);
  }
}

$tagsByCategory=competence_tags_by_category($pdo);
$categoryLabels=competence_category_labels();

$students=[]; $plan=null; $seatGridCells=[];
$studentsData=[];
if($class && $subject){
  $students=load_class_students($pdo,$class_id,false);
  $plans=load_teacher_seating_plans($pdo,$teacherId,$class_id,$subject_id);
  $plan=$plans[0] ?? null; // zuletzt verwendeter Sitzplan dieser Klasse/dieses Fachs, rein lesend

  $dayData=competence_observations_for_day($pdo,$teacherId,$class_id,$subject_id,$today);

  foreach($students as $stu){
    $sid=(int)$stu['id'];
    $studentsData[$sid]=[
      'id'=>$sid,
      'name'=>$stu['last_name'].', '.$stu['first_name'],
      'tagIds'=>$dayData[$sid]['tag_ids'] ?? [],
    ];
  }

  if($plan){
    for($r=1;$r<=(int)$plan['rows'];$r++){
      for($c=1;$c<=(int)$plan['columns'];$c++){
        $seat=$plan['seats_by_position'][$c.'_'.$r] ?? null;
        $seatGridCells[]=$seat ? ['student_id'=>(int)$seat['student_id']] : ['student_id'=>0];
      }
    }
  }
}

render_header('Kompetenz-Beobachtung',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Kompetenz-Beobachtung erfassen</h1>
  <p class="muted">Schnelle, optionale Notizen zu Methoden-, Sozial- und Selbst-/Personalkompetenz während des Unterrichts - unabhängig von der täglichen Mitarbeitserfassung und ohne Einfluss auf die Mitarbeitsnote. Die Auswertung über ein Semester findest du unter „Berichte &amp; Auswertungen“.</p>

  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <form method="get" class="row" style="align-items:end;margin-top:12px" <?php echo teacher_assignment_guard_attrs($u); ?>>
    <div>
      <label class="muted">Klasse</label>
      <select class="input" name="class_id" id="cqClassSelect">
        <option value="0">–</option>
        <?php foreach($classes as $classRow): ?>
          <option value="<?php echo (int)$classRow['id']; ?>" <?php echo $class_id===(int)$classRow['id']?'selected':''; ?>><?php echo h($classRow['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Fach</label>
      <select class="input" name="subject_id" id="cqSubjectSelect">
        <option value="0">–</option>
        <?php foreach($subjects as $subjectRow): ?>
          <option value="<?php echo (int)$subjectRow['id']; ?>" data-class-ids="<?php echo h(implode(',', $subjectClassIds[$subjectRow['id']] ?? [])); ?>" <?php echo $subject_id===(int)$subjectRow['id']?'selected':''; ?>><?php echo h($subjectRow['code'].' – '.$subjectRow['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto">
      <label class="muted">&nbsp;</label>
      <button class="btn">Anzeigen</button>
    </div>
    <div style="flex:0 0 auto">
      <label class="muted">&nbsp;</label>
      <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Fertig – zum Dashboard</a>
    </div>
  </form>
  <script>
  (() => {
    const classSelect=document.getElementById('cqClassSelect');
    const subjectSelect=document.getElementById('cqSubjectSelect');
    if(!classSelect || !subjectSelect) return;
    const updateSubjects=()=>{
      const classId=classSelect.value;
      let selectedIsAllowed=false;
      [...subjectSelect.options].forEach(option=>{
        if(option.value==='0'){ option.hidden=false; option.disabled=false; if(option.selected) selectedIsAllowed=true; return; }
        const classIds=String(option.dataset.classIds || '').split(',');
        const allowed=classId==='0' || classIds.includes(classId);
        option.hidden=!allowed;
        option.disabled=!allowed;
        if(allowed && option.selected) selectedIsAllowed=true;
      });
      if(!selectedIsAllowed){
        const placeholder=[...subjectSelect.options].find(option=>option.value==='0');
        if(placeholder) placeholder.selected=true;
      }
    };
    classSelect.addEventListener('change',updateSubjects);
    updateSubjects();
  })();
  </script>

  <?php if(!$class || !$subject): ?>
    <div class="card" style="margin-top:14px">
      <div class="muted">Bitte zuerst Klasse und Fach wählen.</div>
    </div>
  <?php else: ?>
    <div class="cq-mode-banner">
      <span>Kompetenz-Modus für heute, <?php echo h(date('d.m.Y')); ?> – unabhängig von der Sitzordnung, es wird nichts an einem Sitzplan verändert.</span>
    </div>

    <?php if($plan): ?>
      <p class="cq-hint-line muted">Klicke auf eine Schülerin/einen Schüler, um eine Kompetenz-Notiz zu hinterlegen. Der grüne Punkt zeigt, wer heute schon erfasst wurde. Die Anordnung entspricht deinem zuletzt verwendeten Sitzplan „<?php echo h($plan['name']); ?>“ (<?php echo (int)$plan['columns']; ?>×<?php echo (int)$plan['rows']; ?>) – rein zur Orientierung, hier lässt sich nichts umsetzen oder löschen.</p>
      <div class="cq-seat-grid" style="grid-template-columns:repeat(<?php echo (int)$plan['columns']; ?>,1fr)">
        <?php foreach($seatGridCells as $cell): $sid=$cell['student_id']; ?>
          <?php if($sid<=0): ?>
            <div class="cq-seat-cell cq-seat-empty"><span class="cq-sname muted">– frei –</span></div>
          <?php else: ?>
            <div class="cq-seat-cell" data-student-id="<?php echo $sid; ?>" onclick="cqOpenPopover(<?php echo $sid; ?>)">
              <div><span class="cq-sname"><?php echo h($studentsData[$sid]['name'] ?? ''); ?></span><span class="cq-tagcount"></span></div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="flash info">Für diese Klasse/dieses Fach existiert noch kein Sitzplan – du erfasst daher über die Schülerliste. Ein Sitzplan lässt sich jederzeit unter „Sitzplan“ anlegen.</div>
      <div class="cq-roster">
        <?php foreach($students as $stu): $sid=(int)$stu['id']; ?>
          <div class="cq-roster-row" data-student-id="<?php echo $sid; ?>">
            <div><div class="cq-rname"><?php echo h($stu['last_name'].', '.$stu['first_name']); ?></div><div class="cq-rmeta muted"></div></div>
            <button type="button" class="btn small secondary" onclick="cqOpenPopover(<?php echo $sid; ?>)">+ Tag</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div></div></div>

<?php if($class && $subject): ?>
<div class="cq-popover-backdrop" id="cqBackdrop">
  <div class="cq-popover">
    <div class="cq-popover-head"><strong id="cqPopoverName">—</strong><button type="button" class="cq-popover-close" onclick="cqClosePopover()">&times;</button></div>
    <div class="muted" style="font-size:12px;margin-bottom:12px">Optional, nicht verpflichtend – unabhängig von der täglichen Mitarbeitserfassung.</div>
    <?php foreach($categoryLabels as $cat=>$label): ?>
      <?php if(!empty($tagsByCategory[$cat])): ?>
        <div class="cq-tag-group">
          <div class="cq-tag-group-label cq-cat-<?php echo h($cat); ?>"><span class="cq-dot cq-cat-<?php echo h($cat); ?>"></span><?php echo h($label); ?></div>
          <div class="cq-chip-row">
            <?php foreach($tagsByCategory[$cat] as $tag): ?>
              <div class="cq-chip cq-cat-<?php echo h($cat); ?>" data-tag-id="<?php echo (int)$tag['id']; ?>" onclick="cqToggleTag(this)"><?php echo h($tag['label']); ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="cq-popover-actions">
      <button type="button" class="btn secondary" onclick="cqClosePopover()">Abbrechen</button>
      <button type="button" class="btn main" onclick="cqSavePopover()">Speichern</button>
    </div>
  </div>
</div>

<script>
(function(){
  var bp=<?php echo json_encode($bp); ?>;
  var classId=<?php echo (int)$class_id; ?>;
  var subjectId=<?php echo (int)$subject_id; ?>;
  var students=<?php echo json_encode(array_values($studentsData)); ?>;
  var studentsById={};
  students.forEach(function(s){ studentsById[s.id]=s; });
  var currentStudentId=null;

  function tokenEl(){ return document.querySelector('meta[name="csrf-token"]'); }

  function post(action, extra){
    var fd=new FormData();
    fd.append('action', action);
    fd.append('class_id', classId);
    fd.append('subject_id', subjectId);
    var t=tokenEl();
    fd.append('_csrf', t?t.getAttribute('content'):'');
    for(var k in extra){ fd.append(k, extra[k]); }
    return fetch(bp+'/teacher/competence_quick.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return {ok:false,error:'Unerwartete Antwort.'}; }); });
  }

  function renderNoteState(studentId){
    var s=studentsById[studentId];
    if(!s) return;
    var hasNote = s.tagIds && s.tagIds.length>0;
    var seatCell=document.querySelector('.cq-seat-cell[data-student-id="'+studentId+'"]');
    if(seatCell){
      seatCell.classList.toggle('cq-has-note', hasNote);
      var countEl=seatCell.querySelector('.cq-tagcount');
      if(countEl) countEl.textContent = hasNote ? (s.tagIds.length+' Notiz(en)') : '';
    }
    var rosterRow=document.querySelector('.cq-roster-row[data-student-id="'+studentId+'"]');
    if(rosterRow){
      rosterRow.classList.toggle('cq-has-note', hasNote);
      var metaEl=rosterRow.querySelector('.cq-rmeta');
      if(metaEl) metaEl.textContent = hasNote ? (s.tagIds.length+' Notiz(en) heute') : 'noch keine Notiz heute';
    }
  }

  window.cqOpenPopover = function(studentId){
    var s=studentsById[studentId];
    if(!s) return;
    currentStudentId=studentId;
    document.getElementById('cqPopoverName').textContent=s.name;
    document.querySelectorAll('.cq-chip').forEach(function(chip){
      var tagId=parseInt(chip.getAttribute('data-tag-id'),10);
      chip.classList.toggle('selected', s.tagIds.indexOf(tagId)!==-1);
    });
    document.getElementById('cqBackdrop').classList.add('open');
  };
  window.cqClosePopover = function(){
    document.getElementById('cqBackdrop').classList.remove('open');
    currentStudentId=null;
  };
  window.cqToggleTag = function(chip){
    chip.classList.toggle('selected');
  };
  window.cqSavePopover = function(){
    if(!currentStudentId) return;
    var tagIds=[];
    document.querySelectorAll('.cq-chip.selected').forEach(function(chip){
      tagIds.push(parseInt(chip.getAttribute('data-tag-id'),10));
    });
    var studentId=currentStudentId;
    post('save_tags', {student_id: studentId, tag_ids: JSON.stringify(tagIds)}).then(function(res){
      if(!res.ok){ alert(res.error || 'Speichern fehlgeschlagen.'); return; }
      studentsById[studentId].tagIds = res.tag_ids || [];
      renderNoteState(studentId);
      cqClosePopover();
    });
  };

  document.getElementById('cqBackdrop').addEventListener('click', function(e){ if(e.target===this) cqClosePopover(); });

  students.forEach(function(s){ renderNoteState(s.id); });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
