<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('admin');
$pdo=db();
$bp=cfg()['base_path'] ?? '';

$schoolYears=load_school_years($pdo,true);
$currentSchoolYearId=school_year_current_id($pdo);

$sourceYearId=(int)($_REQUEST['source_school_period_set_id'] ?? $currentSchoolYearId);
$targetYearId=(int)($_REQUEST['target_school_period_set_id'] ?? 0);
$sourceClassId=(int)($_REQUEST['source_class_id'] ?? 0);
$targetClassName=trim((string)($_REQUEST['target_class_name'] ?? ''));
$targetClassId=0;
$assessmentSystem=trim((string)($_REQUEST['assessment_system'] ?? 'yearly'));
if(!class_assessment_system_is_valid($assessmentSystem)) $assessmentSystem='yearly';
$copyAssignments=(int)($_REQUEST['copy_assignments'] ?? 1) === 1;
$sourceStatusAfter=trim((string)($_REQUEST['source_status_after'] ?? 'archived'));
if(!in_array($sourceStatusAfter, ['active','archived','departed'], true)) $sourceStatusAfter='archived';

$msg='';
$err='';
$preview=null;

function _transition_classes_for_year(PDO $pdo, int $yearId): array {
  return load_classes_for_admin($pdo,$yearId,false);
}

function _transition_target_exists(PDO $pdo, int $yearId, string $name): ?array {
  $st=$pdo->prepare("SELECT * FROM classes WHERE school_period_set_id=? AND name=? LIMIT 1");
  $st->execute([$yearId,$name]);
  $row=$st->fetch();
  return $row ?: null;
}

function _transition_suggest_next_class_name(string $sourceName): string {
  $sourceName = trim($sourceName);
  if($sourceName === '') return '';
  if(preg_match('/^(\D*)(\d+)(.*)$/u', $sourceName, $m)){
    return $m[1].((int)$m[2] + 1).$m[3];
  }
  return $sourceName;
}

function _transition_status_values(string $status): array {
  if($status === 'departed') return ['is_archived'=>1, 'is_departed'=>1];
  if($status === 'active') return ['is_archived'=>0, 'is_departed'=>0];
  return ['is_archived'=>1, 'is_departed'=>0];
}

function _transition_status_label(string $status): string {
  if($status === 'departed') return 'ausgeschieden';
  if($status === 'active') return 'aktiv lassen';
  return 'archivieren';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? 'preview');

  if($action==='update_class_status'){
    $statusClassId=(int)($_POST['status_class_id'] ?? 0);
    $newStatus=trim((string)($_POST['class_status'] ?? 'active'));
    if(!in_array($newStatus, ['active','archived','departed'], true)) $newStatus='active';
    if($statusClassId <= 0){
      $err='Bitte eine Klasse für die Statusänderung auswählen.';
    } else {
      $values=_transition_status_values($newStatus);
      $st=$pdo->prepare("UPDATE classes SET is_archived=?, is_departed=? WHERE id=?");
      $st->execute([(int)$values['is_archived'], (int)$values['is_departed'], $statusClassId]);
      emit_event('admin_class_status_updated',[
        'target_id'=>$statusClassId,
        'status'=>$newStatus,
      ]);
      $msg='Klassenstatus wurde aktualisiert.';
    }
  } else {
  $sourceClass=class_context($pdo,$sourceClassId);
  $targetYear=null;
  foreach($schoolYears as $sy){ if((int)$sy['id']===$targetYearId){ $targetYear=$sy; break; } }
  if(!$sourceClass || !$targetYear || $targetClassName===''){
    $err='Bitte Ausgangsklasse, Zielschuljahr und Zielklasse vollständig auswählen.';
  } else {
    $sourceStudents=load_class_students($pdo,$sourceClassId,true);
    $studentActions=(array)($_POST['student_action'] ?? []);
    $transferClass=(array)($_POST['transfer_class_id'] ?? []);
    $promote=[]; $repeat=[]; $left=[]; $transfer=[]; $unassigned=[];
    foreach($sourceStudents as $student){
      $sid=(int)$student['id'];
      $choice=(string)($studentActions[$sid] ?? 'promote');
      $label=(string)$student['last_name'].', '.(string)$student['first_name'];
      if($choice==='repeat') $repeat[]=['id'=>$sid,'name'=>$label];
      elseif($choice==='left') $left[]=['id'=>$sid,'name'=>$label];
      elseif($choice==='transfer') $transfer[]=['id'=>$sid,'name'=>$label,'class_id'=>(int)($transferClass[$sid] ?? 0)];
      elseif($choice==='unassigned') $unassigned[]=['id'=>$sid,'name'=>$label];
      else $promote[]=['id'=>$sid,'name'=>$label];
    }

    $existingTarget=_transition_target_exists($pdo,$targetYearId,$targetClassName);
    $preview=[
      'source_class'=>$sourceClass,
      'target_year'=>$targetYear,
      'target_name'=>$targetClassName,
      'existing_target'=>$existingTarget,
      'promote'=>$promote,
      'repeat'=>$repeat,
      'left'=>$left,
      'transfer'=>$transfer,
      'unassigned'=>$unassigned,
      'copy_assignments'=>$copyAssignments,
      'source_status_after'=>$sourceStatusAfter,
    ];

    if($action==='execute'){
      $pdo->beginTransaction();
      try{
        $target=$existingTarget;
        if(!$target){
          $ins=$pdo->prepare("INSERT INTO classes(school_period_set_id,name,school_type,school_form_id,year,label,assessment_system,predecessor_class_id,is_archived,is_departed)
                              VALUES(?,?,?,?,?,?,?,?,?,0)");
          $ins->execute([
            $targetYearId,
            $targetClassName,
            (string)$sourceClass['school_type'],
            (int)($sourceClass['school_form_id'] ?? 0) ?: null,
            max(1,(int)$sourceClass['year'] + 1),
            null,
            $assessmentSystem,
            $sourceClassId,
            0,
          ]);
          $targetClassId=(int)$pdo->lastInsertId();
        } else {
          $targetClassId=(int)$target['id'];
        }

        $now=now_iso();
        $enroll=$pdo->prepare("INSERT INTO class_enrollments(student_id,class_id,school_period_set_id,status,entry_date,created_at,updated_at)
                               VALUES(?,?,?,?,CURDATE(),?,?)
                               ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=VALUES(updated_at)");
        $setCurrent=$pdo->prepare("UPDATE students SET class_id=? WHERE id=?");
        foreach($promote as $student){
          $enroll->execute([(int)$student['id'],$targetClassId,$targetYearId,'active',$now,$now]);
          $setCurrent->execute([$targetClassId,(int)$student['id']]);
        }
        foreach($transfer as $student){
          $dest=(int)($student['class_id'] ?? 0);
          if($dest > 0){
            $st=$pdo->prepare("SELECT school_period_set_id FROM classes WHERE id=?");
            $st->execute([$dest]);
            $destYear=(int)($st->fetchColumn() ?: $targetYearId);
            $enroll->execute([(int)$student['id'],$dest,$destYear,'transferred',$now,$now]);
            $setCurrent->execute([$dest,(int)$student['id']]);
          }
        }
        $markSource=$pdo->prepare("UPDATE class_enrollments SET status=?, exit_date=CURDATE(), updated_at=? WHERE class_id=? AND student_id=?");
        foreach($repeat as $student){ $markSource->execute(['repeated',$now,$sourceClassId,(int)$student['id']]); }
        foreach($left as $student){ $markSource->execute(['left',$now,$sourceClassId,(int)$student['id']]); }
        foreach($unassigned as $student){ $markSource->execute(['archived',$now,$sourceClassId,(int)$student['id']]); }

        if($copyAssignments){
          $assignments=$pdo->prepare("SELECT teacher_id,subject_id FROM teacher_assignments WHERE class_id=?");
          $assignments->execute([$sourceClassId]);
          $insertAssignment=$pdo->prepare("INSERT IGNORE INTO teacher_assignments(teacher_id,class_id,subject_id) VALUES(?,?,?)");
          foreach($assignments->fetchAll() as $assignment){
            $insertAssignment->execute([(int)$assignment['teacher_id'],$targetClassId,(int)$assignment['subject_id']]);
          }
        }
        $sourceStatusValues=_transition_status_values($sourceStatusAfter);
        $pdo->prepare("UPDATE classes SET is_archived=?, is_departed=? WHERE id=?")->execute([
          (int)$sourceStatusValues['is_archived'],
          (int)$sourceStatusValues['is_departed'],
          $sourceClassId,
        ]);

        emit_event('admin_school_year_transition',[
          'source_class_id'=>$sourceClassId,
          'target_class_id'=>$targetClassId,
          'target_school_period_set_id'=>$targetYearId,
          'promoted_count'=>count($promote),
          'left_count'=>count($left),
          'repeat_count'=>count($repeat),
          'source_status_after'=>$sourceStatusAfter,
        ]);
        $pdo->commit();
        header('Location: '.$bp.'/admin/school_year_transition.php?msg=done&source_school_period_set_id='.$sourceYearId.'&target_school_period_set_id='.$targetYearId.'&source_class_id='.$sourceClassId);
        exit;
      }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        app_log('error','school year transition failed',['error'=>$e->getMessage()]);
        $err='Der Schuljahreswechsel konnte nicht gespeichert werden: '.$e->getMessage();
      }
    }
  }
  }
}

if(isset($_GET['msg']) && (string)$_GET['msg']==='done'){
  $msg='Schuljahreswechsel wurde durchgeführt. Alte Leistungsdaten bleiben bei der Ausgangsklasse erhalten.';
}

$sourceClasses=_transition_classes_for_year($pdo,$sourceYearId);
if($targetYearId <= 0){
  foreach($schoolYears as $sy){
    if((int)$sy['id'] !== $sourceYearId){
      $targetYearId = (int)$sy['id'];
      break;
    }
  }
}
$targetClasses=$targetYearId>0 ? _transition_classes_for_year($pdo,$targetYearId) : [];
$statusClasses=load_classes_for_admin($pdo,0,true);
$sourceStudents=$sourceClassId>0 ? load_class_students($pdo,$sourceClassId,true) : [];
$selectedSourceClass=class_context($pdo,$sourceClassId);
if($targetClassName==='' && $selectedSourceClass){
  $targetClassName=_transition_suggest_next_class_name((string)$selectedSourceClass['name']);
}
if($selectedSourceClass && !class_assessment_system_is_valid($assessmentSystem)){
  $assessmentSystem=(string)($selectedSourceClass['assessment_system'] ?? 'yearly');
}

render_header('Schuljahreswechsel',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Schuljahreswechsel</h1>
<p class="muted">Der Assistent legt neue Klassen- und Klassenzuordnungen für ein neues Schuljahr an. Alte Klassen und Leistungsdaten werden nicht umbenannt, nicht verschoben und nicht kopiert.</p>
<div class="report-focus-block" style="margin-bottom:12px">
  <strong>Ablauf</strong>
  <div class="muted" style="margin-top:8px">
    1. Neues Schuljahr vorher unter <a href="<?php echo h($bp); ?>/admin/school_years.php">Schuljahre und Semester</a> anlegen.
    2. Hier die aktuelle Ausgangsklasse wählen.
    3. Zielklasse benennen. Die App schlägt aus der ersten Zahl automatisch die nächste Klassenstufe vor, z. B. 2FSB → 3FSB.
    4. Danach nur noch Sonderfälle bei Schüler:innen markieren.
  </div>
</div>
<?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

<form method="post" <?php echo dirty_form_attrs(); ?>>
<?php echo csrf_input(); ?>
<input type="hidden" name="action" value="preview">
<div class="grid">
  <div class="col-12 col-md-6">
    <div class="settings-panel">
      <div class="settings-panel-title">1. Aktuelle Klasse auswählen</div>
      <label class="muted">Ausgangsschuljahr</label>
      <select class="input" name="source_school_period_set_id" onchange="this.form.submit()">
        <?php foreach($schoolYears as $sy): ?>
          <option value="<?php echo (int)$sy['id']; ?>" <?php echo $sourceYearId===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label']); ?></option>
        <?php endforeach; ?>
      </select>
      <div style="height:10px"></div>
      <label class="muted">Ausgangsklasse</label>
      <select class="input" name="source_class_id" onchange="this.form.submit()">
        <option value="0">Bitte wählen…</option>
        <?php foreach($sourceClasses as $class): ?>
          <option value="<?php echo (int)$class['id']; ?>" <?php echo $sourceClassId===(int)$class['id']?'selected':''; ?>><?php echo h($class['name'].' · '.class_status_label($class)); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="col-12 col-md-6">
    <div class="settings-panel">
      <div class="settings-panel-title">2. Neue Klasse im Zielschuljahr</div>
      <label class="muted">Zielschuljahr</label>
      <select class="input" name="target_school_period_set_id">
        <option value="0">Bitte wählen…</option>
        <?php foreach($schoolYears as $sy): ?>
          <option value="<?php echo (int)$sy['id']; ?>" <?php echo $targetYearId===(int)$sy['id']?'selected':''; ?>><?php echo h($sy['label'].(((int)$sy['is_current']===1)?' · aktuell':'')); ?></option>
        <?php endforeach; ?>
      </select>
      <div style="height:10px"></div>
      <label class="muted">Neue Klassenbezeichnung</label>
      <input class="input" name="target_class_name" value="<?php echo h($targetClassName); ?>" placeholder="z. B. 3FSB">
      <div class="muted" style="margin-top:6px;font-size:13px">Vorschlag aus der Ausgangsklasse. Bitte bei Bedarf anpassen, z. B. bei Zusammenlegung oder Umbenennung.</div>
      <div style="height:10px"></div>
      <label class="muted">Beurteilungssystem</label>
      <select class="input" name="assessment_system">
        <?php foreach(class_assessment_system_options() as $value=>$label): ?>
          <option value="<?php echo h($value); ?>" <?php echo $assessmentSystem===$value?'selected':''; ?>><?php echo h($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<?php if($sourceStudents): ?>
  <div style="height:14px"></div>
  <h2>3. Schüler:innen übernehmen</h2>
  <div class="muted" style="margin-bottom:10px">
    Standard ist „in Zielklasse übernehmen“. Nur Sonderfälle müssen geändert werden: Wiederholung, Abgang, Wechsel in eine andere bestehende Klasse oder vorerst keine Zuordnung.
  </div>
  <table class="table">
    <thead><tr><th>Schüler:in</th><th>Aktion</th><th>Andere Zielklasse bei Klassenwechsel</th></tr></thead>
    <tbody>
    <?php foreach($sourceStudents as $student): $sid=(int)$student['id']; ?>
      <tr>
        <td><?php echo h($student['last_name'].', '.$student['first_name']); ?></td>
        <td>
          <select class="input" name="student_action[<?php echo $sid; ?>]">
            <option value="promote">in Zielklasse übernehmen</option>
            <option value="repeat">bleibt / wiederholt</option>
            <option value="left">verlässt Schule</option>
            <option value="transfer">wechselt in andere Klasse</option>
            <option value="unassigned">noch nicht zuordnen</option>
          </select>
        </td>
        <td>
          <select class="input" name="transfer_class_id[<?php echo $sid; ?>]">
            <option value="0">nur ausfüllen, wenn „wechselt in andere Klasse“ gewählt ist</option>
            <?php foreach($targetClasses as $targetClass): ?>
              <option value="<?php echo (int)$targetClass['id']; ?>"><?php echo h($targetClass['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div style="height:14px"></div>
<div class="settings-panel">
  <div class="settings-panel-title">4. Optionen</div>
  <label><input type="checkbox" name="copy_assignments" value="1" <?php echo $copyAssignments?'checked':''; ?>> Lehrer:innen-/Fachzuordnungen auf Zielklasse übernehmen</label>
  <div style="height:8px"></div>
  <label class="muted">Status der Ausgangsklasse nach Durchführung</label>
  <select class="input" name="source_status_after">
    <option value="archived" <?php echo $sourceStatusAfter==='archived'?'selected':''; ?>>archivieren / als Vorjahresklasse behalten</option>
    <option value="departed" <?php echo $sourceStatusAfter==='departed'?'selected':''; ?>>als ausgeschieden markieren / nicht mehr bei Lehrer:innen anzeigen</option>
    <option value="active" <?php echo $sourceStatusAfter==='active'?'selected':''; ?>>aktiv lassen / Sonderfall</option>
  </select>
  <div class="muted" style="margin-top:8px">Archivierte Klassen bleiben für Auswertungen und PDF-Berichte sichtbar, sind aber für neue Erfassungen gesperrt. Ausgeschiedene Klassen werden zusätzlich aus der normalen Lehrer:innenauswahl ausgeblendet.</div>
</div>

<div style="height:14px"></div>
<button class="btn secondary">Vorschau erzeugen</button>
<?php if($preview): ?>
  <button class="btn" name="action" value="execute" onclick="return confirm('Schuljahreswechsel jetzt durchführen? Alte Leistungsdaten bleiben unverändert.');">Schuljahreswechsel durchführen</button>
<?php endif; ?>
<a class="btn secondary" href="<?php echo h($bp); ?>/admin/classes.php">Zurück zu Klassen</a>
</form>

<details class="accordion" style="margin-top:14px">
  <summary><span class="acc-title">Klassenstatus korrigieren</span></summary>
  <div class="acc-body">
    <div class="muted" style="margin-bottom:10px">
      Verwenden Sie diesen Bereich nur für Korrekturen, z. B. wenn eine Vorjahresklasse wieder aktiviert oder eine Abschlussklasse nachträglich als ausgeschieden markiert werden soll.
    </div>
    <form method="post" class="row" style="align-items:end" <?php echo dirty_form_attrs(); ?>>
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="update_class_status">
      <div style="min-width:280px">
        <label class="muted">Klasse</label>
        <select class="input" name="status_class_id" required>
          <option value="0">Bitte wählen…</option>
          <?php foreach($statusClasses as $class): ?>
            <option value="<?php echo (int)$class['id']; ?>"><?php echo h(class_display_name($class).' · '.class_status_label($class)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="min-width:260px">
        <label class="muted">Neuer Status</label>
        <select class="input" name="class_status" required>
          <option value="active">aktiv</option>
          <option value="archived">archiviert / Vorjahresklasse</option>
          <option value="departed">ausgeschieden</option>
        </select>
      </div>
      <div>
        <button class="btn secondary">Status speichern</button>
      </div>
    </form>
  </div>
</details>

<?php if($preview): ?>
  <div class="report-focus-block" style="margin-top:16px">
    <strong>Vorschau</strong>
    <div class="muted" style="margin-top:8px">
      Ziel: <b><?php echo h($preview['target_name']); ?></b> im Schuljahr <b><?php echo h((string)$preview['target_year']['label']); ?></b>.
      <?php if($preview['existing_target']): ?> Die Zielklasse existiert bereits; vorhandene Zuordnungen werden nicht verdoppelt.<?php endif; ?>
    </div>
    <div class="report-kv" style="margin-top:10px">
      <div class="item"><span class="label">Übernahme</span><strong><?php echo count($preview['promote']); ?></strong></div>
      <div class="item"><span class="label">bleibt / wiederholt</span><strong><?php echo count($preview['repeat']); ?></strong></div>
      <div class="item"><span class="label">verlässt Schule</span><strong><?php echo count($preview['left']); ?></strong></div>
      <div class="item"><span class="label">Klassenwechsel</span><strong><?php echo count($preview['transfer']); ?></strong></div>
      <div class="item"><span class="label">noch offen</span><strong><?php echo count($preview['unassigned']); ?></strong></div>
      <div class="item"><span class="label">Status Ausgangsklasse</span><strong><?php echo h(_transition_status_label((string)$preview['source_status_after'])); ?></strong></div>
    </div>
    <div class="muted" style="margin-top:10px">Leistungsdaten werden nicht kopiert. Sie bleiben bei der Ausgangsklasse und sind über das Vorjahr auswertbar.</div>
  </div>
<?php endif; ?>
</div></div></div>
<?php render_footer(); ?>
