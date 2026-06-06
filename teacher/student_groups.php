<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/student_groups.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$edit_group_id=(int)($_GET['edit_group_id'] ?? $_POST['edit_group_id'] ?? 0);
$hide_assigned=((string)($_GET['hide_assigned'] ?? $_POST['hide_assigned'] ?? '1') !== '0');
$msg=(string)($_GET['msg'] ?? '');
$err=(string)($_GET['err'] ?? '');

$st=$pdo->prepare("SELECT DISTINCT c.id,c.name
                   FROM teacher_assignments ta
                   JOIN classes c ON c.id=ta.class_id
                   WHERE ta.teacher_id=?
                   ORDER BY c.name");
$st->execute([(int)$u['id']]);
$classes=$st->fetchAll();

$st=$pdo->prepare("SELECT DISTINCT s.id,s.code,s.name
                   FROM teacher_assignments ta
                   JOIN subjects s ON s.id=ta.subject_id
                   WHERE ta.teacher_id=?
                   ORDER BY s.code");
$st->execute([(int)$u['id']]);
$subjects=$st->fetchAll();

function teacher_student_groups_redirect(string $bp, int $classId, int $subjectId, bool $hideAssigned, string $msg = '', string $err = '', int $editGroupId = 0): void {
  $params=[
    'class_id'=>$classId,
    'subject_id'=>$subjectId,
    'hide_assigned'=>$hideAssigned ? 1 : 0,
  ];
  if($msg!=='') $params['msg']=$msg;
  if($err!=='') $params['err']=$err;
  if($editGroupId>0) $params['edit_group_id']=$editGroupId;
  header('Location: '.$bp.'/teacher/student_groups.php?'.http_build_query($params));
  exit;
}

$students=[];
$studentMap=[];
$groups=[];
$editGroup=null;
$assignedElsewhere=[];
$assignedElsewhereMap=[];

if($class_id>0 && $subject_id>0){
  require_teacher_assignment($u,$class_id,$subject_id);

  $st=$pdo->prepare("SELECT id, first_name, last_name
                     FROM students
                     WHERE class_id=? AND is_active=1
                     ORDER BY last_name, first_name");
  $st->execute([$class_id]);
  $students=$st->fetchAll();
  foreach($students as $studentRow){
    $studentMap[(int)$studentRow['id']]=$studentRow;
  }

  $groups=load_teacher_student_groups($pdo,(int)$u['id'],$class_id,$subject_id);
  if($edit_group_id>0){
    foreach($groups as $groupRow){
      if((int)$groupRow['id']===$edit_group_id){
        $editGroup=$groupRow;
        break;
      }
    }
  }

  $assignedElsewhere=teacher_student_group_assigned_student_ids($pdo,(int)$u['id'],$class_id,$subject_id,$editGroup ? (int)$editGroup['id'] : 0);
  $assignedElsewhereMap=array_fill_keys($assignedElsewhere,true);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');
  $class_id=(int)($_POST['class_id'] ?? $class_id);
  $subject_id=(int)($_POST['subject_id'] ?? $subject_id);
  $hide_assigned=((string)($_POST['hide_assigned'] ?? ($hide_assigned ? '1' : '0')) !== '0');

  if(!$class_id || !$subject_id){
    teacher_student_groups_redirect($bp,$class_id,$subject_id,$hide_assigned,'','Bitte zuerst Klasse und Fach wählen.');
  }

  require_teacher_assignment($u,$class_id,$subject_id);

  $st=$pdo->prepare("SELECT id, first_name, last_name
                     FROM students
                     WHERE class_id=? AND is_active=1
                     ORDER BY last_name, first_name");
  $st->execute([$class_id]);
  $students=$st->fetchAll();
  $studentMap=[];
  foreach($students as $studentRow){
    $studentMap[(int)$studentRow['id']]=$studentRow;
  }
  $groups=load_teacher_student_groups($pdo,(int)$u['id'],$class_id,$subject_id);

  try{
    if($action==='save_group'){
      $group_id=(int)($_POST['group_id'] ?? 0);
      $name=teacher_student_group_name((string)($_POST['name'] ?? ''));
      $note=teacher_student_group_note((string)($_POST['note'] ?? ''));
      $student_ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['student_ids'] ?? [])), static fn(int $id): bool => $id>0)));

      foreach($student_ids as $studentId){
        if(!isset($studentMap[$studentId])){
          throw new RuntimeException('Die ausgewählte Schüler:innenliste ist ungültig.');
        }
      }

      $pdo->beginTransaction();
      $savedGroupId=save_teacher_student_group($pdo,(int)$u['id'],$class_id,$subject_id,$name,$note,$student_ids,$group_id);
      $pdo->commit();

      emit_event('teacher_student_group_saved',[
        'group_id'=>$savedGroupId,
        'group_name'=>$name,
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'count'=>count($student_ids),
        'student_ids'=>$student_ids,
      ]);
      teacher_student_groups_redirect($bp,$class_id,$subject_id,$hide_assigned,'Gruppe gespeichert.');
    }

    if($action==='delete_group'){
      $group_id=(int)($_POST['group_id'] ?? 0);
      $pdo->beginTransaction();
      $deleted=delete_teacher_student_group($pdo,(int)$u['id'],$group_id);
      if(!$deleted){
        $pdo->rollBack();
        throw new RuntimeException('Gruppe nicht gefunden.');
      }
      $pdo->commit();

      emit_event('teacher_student_group_deleted',[
        'group_id'=>$group_id,
        'group_name'=>(string)($deleted['name'] ?? ''),
        'class_id'=>(int)($deleted['class_id'] ?? 0),
        'subject_id'=>(int)($deleted['subject_id'] ?? 0),
      ]);
      teacher_student_groups_redirect($bp,$class_id,$subject_id,$hide_assigned,'Gruppe gelöscht. Bewertungen bleiben unverändert.');
    }

    if($action==='create_random_groups'){
      $prefix=teacher_student_group_name((string)($_POST['group_prefix'] ?? 'Gruppe'));
      $note=teacher_student_group_note((string)($_POST['group_note'] ?? ''));
      $groupSize=(int)($_POST['group_size'] ?? 0);
      $onlyUnassigned=((string)($_POST['random_only_unassigned'] ?? '1') !== '0');

      if($prefix==='') $prefix='Gruppe';
      if($groupSize<1) throw new RuntimeException('Bitte eine sinnvolle Gruppengröße eingeben.');

      $eligibleIds=array_map(static fn(array $row): int => (int)$row['id'], $students);
      if($onlyUnassigned){
        $assignedIds=teacher_student_group_assigned_student_ids($pdo,(int)$u['id'],$class_id,$subject_id,0);
        if($assignedIds){
          $assignedMap=array_fill_keys(array_map('intval',$assignedIds),true);
          $eligibleIds=array_values(array_filter($eligibleIds, static fn(int $studentId): bool => !isset($assignedMap[$studentId])));
        }
      }

      if(!$eligibleIds){
        throw new RuntimeException('Es stehen keine passenden Schüler:innen für die Random-Zuordnung zur Verfügung.');
      }

      shuffle($eligibleIds);
      $partitions=teacher_student_group_partitions($eligibleIds,$groupSize);
      if(!$partitions){
        throw new RuntimeException('Es konnten keine Gruppen erzeugt werden.');
      }

      $existingNames=[];
      foreach($groups as $groupRow){
        $existingNames[]=function_exists('mb_strtolower')
          ? mb_strtolower((string)$groupRow['name'])
          : strtolower((string)$groupRow['name']);
      }

      $pdo->beginTransaction();
      $createdNames=[];
      foreach($partitions as $chunk){
        $name=teacher_student_group_next_name($prefix,$existingNames);
        save_teacher_student_group($pdo,(int)$u['id'],$class_id,$subject_id,$name,$note,$chunk,0);
        $createdNames[]=$name;
      }
      $pdo->commit();

      emit_event('teacher_student_groups_random_created',[
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'group_prefix'=>$prefix,
        'group_size'=>$groupSize,
        'group_count'=>count($createdNames),
        'count'=>count($eligibleIds),
      ]);
      teacher_student_groups_redirect($bp,$class_id,$subject_id,$hide_assigned,count($createdNames).' Gruppen zufällig erstellt.');
    }
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $err=$e->getMessage();
  }

  if($class_id>0 && $subject_id>0){
    $groups=load_teacher_student_groups($pdo,(int)$u['id'],$class_id,$subject_id);
    if($edit_group_id>0){
      foreach($groups as $groupRow){
        if((int)$groupRow['id']===$edit_group_id){
          $editGroup=$groupRow;
          break;
        }
      }
    }
    $assignedElsewhere=teacher_student_group_assigned_student_ids($pdo,(int)$u['id'],$class_id,$subject_id,$editGroup ? (int)$editGroup['id'] : 0);
    $assignedElsewhereMap=array_fill_keys($assignedElsewhere,true);
  }
}

$formName=$editGroup ? (string)$editGroup['name'] : '';
$formNote=$editGroup ? (string)($editGroup['note'] ?? '') : '';
$selectedMemberIds=$editGroup ? array_map('intval',(array)($editGroup['member_ids'] ?? [])) : [];

render_header('Gruppenverwaltung',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Gruppenverwaltung</h1>
  <p class="muted">Gruppen werden pro <b>Klasse + Fach</b> und nur für dich als Lehrkraft gespeichert. Beim Löschen bleiben alle bisherigen Bewertungen vollständig erhalten.</p>

  <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <form method="get" class="row" style="align-items:end;margin-top:12px" <?php echo teacher_assignment_guard_attrs($u); ?>>
    <div>
      <label class="muted">Klasse</label>
      <select class="input" name="class_id">
        <option value="0">–</option>
        <?php foreach($classes as $classRow): ?>
          <option value="<?php echo (int)$classRow['id']; ?>" <?php echo $class_id===(int)$classRow['id']?'selected':''; ?>><?php echo h($classRow['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Fach</label>
      <select class="input" name="subject_id">
        <option value="0">–</option>
        <?php foreach($subjects as $subjectRow): ?>
          <option value="<?php echo (int)$subjectRow['id']; ?>" <?php echo $subject_id===(int)$subjectRow['id']?'selected':''; ?>><?php echo h($subjectRow['code'].' – '.$subjectRow['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto">
      <label class="muted" style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="hide_assigned" value="1" <?php echo $hide_assigned?'checked':''; ?>>
        <span>Schon vergebene Schüler:innen ausblenden</span>
      </label>
    </div>
    <div style="flex:0 0 auto">
      <label class="muted">&nbsp;</label>
      <button class="btn">Anzeigen</button>
    </div>
  </form>

  <?php if(!$class_id || !$subject_id): ?>
    <div class="card" style="margin-top:14px">
      <div class="muted">Bitte zuerst Klasse und Fach wählen.</div>
    </div>
  <?php else: ?>
    <div class="grid" style="margin-top:14px">
      <div class="col-12 col-md-7">
        <details class="accordion contrast-panel section-entry" open>
          <summary><span class="acc-title"><?php echo $editGroup ? 'Gruppe bearbeiten' : 'Gruppe manuell anlegen'; ?></span></summary>
          <div class="acc-body">
          <div class="muted" style="font-size:13px">
            <?php if($hide_assigned): ?>
              Bereits in anderen Gruppen vergebene Schüler:innen werden in dieser Liste ausgeblendet.
            <?php else: ?>
              Bereits in anderen Gruppen vergebene Schüler:innen bleiben sichtbar markiert.
            <?php endif; ?>
          </div>
          <form method="post" style="margin-top:12px" <?php echo dirty_form_attrs(); ?>>
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save_group">
            <input type="hidden" name="group_id" value="<?php echo $editGroup ? (int)$editGroup['id'] : 0; ?>">
            <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
            <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
            <input type="hidden" name="hide_assigned" value="<?php echo $hide_assigned ? 1 : 0; ?>">

            <div class="row" style="align-items:end">
              <div style="flex:1">
                <label class="muted">Gruppenname</label>
                <input class="input" name="name" maxlength="120" required value="<?php echo h($formName); ?>" placeholder="z.B. Diskussion 1">
              </div>
            </div>

            <div style="height:10px"></div>
            <label class="muted">Anmerkungen</label>
            <textarea class="input" name="note" rows="3" placeholder="z.B. fixe Sitzgruppe oder Rechercheteam"><?php echo h($formNote); ?></textarea>

            <div style="height:12px"></div>
            <div class="row" style="justify-content:space-between;align-items:end">
              <div class="muted"><b>Mitglieder auswählen</b></div>
              <div class="row" style="gap:8px;align-items:center">
                <button type="button" class="btn small secondary" onclick="setGroupMembers(true)">Alle sichtbaren wählen</button>
                <button type="button" class="btn small secondary" onclick="setGroupMembers(false)">Auswahl leeren</button>
              </div>
            </div>

            <div style="height:10px"></div>
            <div class="multi-grid">
              <?php foreach($students as $studentRow): ?>
                <?php
                  $studentId=(int)$studentRow['id'];
                  $isSelected=in_array($studentId,$selectedMemberIds,true);
                  $isAssignedElsewhere=isset($assignedElsewhereMap[$studentId]);
                  if($hide_assigned && $isAssignedElsewhere && !$isSelected) continue;
                ?>
                <label class="multi-item" style="<?php echo $isAssignedElsewhere && !$isSelected ? 'border-color:#f6ad55;background:#fffaf0;' : ''; ?>">
                  <input class="groupMemberCb" type="checkbox" name="student_ids[]" value="<?php echo $studentId; ?>" <?php echo $isSelected?'checked':''; ?>>
                  <span>
                    <?php echo h($studentRow['last_name'].', '.$studentRow['first_name']); ?>
                    <?php if($isAssignedElsewhere && !$isSelected): ?><span class="small muted"> · schon in anderer Gruppe</span><?php endif; ?>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>

            <div style="height:14px"></div>
            <button class="btn"><?php echo $editGroup ? 'Gruppe speichern' : 'Gruppe anlegen'; ?></button>
            <?php if($editGroup): ?>
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/student_groups.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'hide_assigned'=>$hide_assigned?1:0])); ?>">Bearbeiten abbrechen</a>
            <?php endif; ?>
          </form>
          </div>
        </details>
      </div>

      <div class="col-12 col-md-5">
        <details class="accordion contrast-panel section-selection">
          <summary><span class="acc-title">Random-Zuordnung</span></summary>
          <div class="acc-body">
          <div class="muted" style="font-size:13px">Gruppen werden zufällig erzeugt. Wenn die Anzahl nicht genau aufgeht, verteilt die App die Schüler:innen so gleichmäßig wie möglich.</div>

          <form method="post" style="margin-top:12px" <?php echo dirty_form_attrs(); ?>>
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="create_random_groups">
            <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
            <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
            <input type="hidden" name="hide_assigned" value="<?php echo $hide_assigned ? 1 : 0; ?>">

            <div>
              <label class="muted">Namenspräfix</label>
              <input class="input" name="group_prefix" maxlength="120" value="Gruppe" placeholder="z.B. Diskussion">
            </div>

            <div style="height:10px"></div>
            <label class="muted">Anmerkungen für alle neu erzeugten Gruppen</label>
            <textarea class="input" name="group_note" rows="3" placeholder="z.B. Zufällig für Partnerarbeit erstellt"></textarea>

            <div style="height:10px"></div>
            <div class="row" style="align-items:end">
              <div>
                <label class="muted">Gewünschte Gruppengröße</label>
                <input class="input" type="number" min="1" max="<?php echo max(1,count($students)); ?>" name="group_size" value="3" required>
              </div>
              <div style="flex:1">
                <label class="muted" style="display:flex;gap:8px;align-items:center">
                  <input type="checkbox" name="random_only_unassigned" value="1" <?php echo $hide_assigned?'checked':''; ?>>
                  <span>Nur noch nicht vergebene Schüler:innen berücksichtigen</span>
                </label>
              </div>
            </div>

            <div style="height:12px"></div>
            <button class="btn">Zufällig verteilen</button>
          </form>
          </div>
        </details>
      </div>
    </div>

    <div class="card" style="margin-top:14px">
      <h2 style="margin:0 0 8px 0">Bestehende Gruppen</h2>
      <?php if(!$groups): ?>
        <div class="muted">Für diese Klasse und dieses Fach sind noch keine Gruppen angelegt.</div>
      <?php else: ?>
        <div class="grid" style="margin-top:12px">
          <?php foreach($groups as $groupRow): ?>
            <div class="col-12 col-md-6">
              <div class="card" style="padding:14px;height:100%">
                <div class="row" style="justify-content:space-between;align-items:start;gap:12px">
                  <div>
                    <h3 style="margin:0"><?php echo h($groupRow['name']); ?></h3>
                    <div class="muted" style="font-size:13px"><?php echo (int)($groupRow['member_count'] ?? 0); ?> Mitglieder</div>
                  </div>
                </div>
                <?php if(trim((string)($groupRow['note'] ?? ''))!==''): ?>
                  <div style="margin-top:8px" class="muted"><?php echo nl2br(h((string)$groupRow['note'])); ?></div>
                <?php endif; ?>
                <div style="height:10px"></div>
                <div class="muted">
                  <?php
                    $memberNames=[];
                    foreach((array)($groupRow['members'] ?? []) as $memberRow){
                      $memberNames[]=(string)$memberRow['last_name'].', '.(string)$memberRow['first_name'];
                    }
                    echo h(implode(' · ', $memberNames));
                  ?>
                </div>
                <div style="height:12px"></div>
                <div class="row" style="gap:8px;flex-wrap:wrap">
                  <a class="btn small secondary" href="<?php echo h($bp); ?>/teacher/student_groups.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'hide_assigned'=>$hide_assigned?1:0,'edit_group_id'=>(int)$groupRow['id']])); ?>">Bearbeiten</a>
                  <a class="btn small secondary" href="<?php echo h($bp); ?>/teacher/participation_new.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'student_group_id'=>(int)$groupRow['id']])); ?>">Zur Mitarbeit</a>
                  <form method="post" onsubmit="return confirm('Diese Gruppe wirklich löschen? Die bisherigen Bewertungen bleiben erhalten.');" style="margin:0">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="delete_group">
                    <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
                    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                    <input type="hidden" name="hide_assigned" value="<?php echo $hide_assigned ? 1 : 0; ?>">
                    <input type="hidden" name="group_id" value="<?php echo (int)$groupRow['id']; ?>">
                    <button class="btn small danger">Löschen</button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/manage.php">Zurück zur Verwaltung</a>

  <script>
  function setGroupMembers(on){
    document.querySelectorAll('.groupMemberCb').forEach(cb=>{ cb.checked = !!on; });
  }
  </script>
</div></div></div>
<?php render_footer(); ?>
