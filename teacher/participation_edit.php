<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/lbvo.php';
require_once __DIR__.'/../lib/participation_presets.php';
require_once __DIR__.'/../lib/participation_observation_groups.php';
require_once __DIR__.'/../lib/participation_pedagogical_mode.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
if(!$id){ http_response_code(400); exit('Fehlende ID.'); }

// Load event (teacher-owned)
$st=$pdo->prepare("SELECT pe.*, st.last_name, st.first_name, c.name AS class_name, s.code AS subject_code
                   FROM participation_events pe
                   JOIN students st ON st.id=pe.student_id
                   JOIN classes c ON c.id=pe.class_id
                   JOIN subjects s ON s.id=pe.subject_id
                   WHERE pe.id=? AND pe.teacher_id=?");
$st->execute([$id,(int)$u['id']]);
$e=$st->fetch();
if(!$e){ http_response_code(404); exit('Eintrag nicht gefunden (oder nicht berechtigt).'); }
require_teacher_assignment($u,(int)$e['class_id'],(int)$e['subject_id']);
require_class_writable($pdo,(int)$e['class_id']);

$class_id=(int)$e['class_id'];
$subject_id=(int)$e['subject_id'];

// Students
$students=load_class_students($pdo,$class_id,false);

// Lessons (recent)
$st=$pdo->prepare("SELECT id, lesson_date, lesson_unit, topic FROM lesson_sessions WHERE class_id=? AND subject_id=? ORDER BY lesson_date DESC, CAST(lesson_unit AS UNSIGNED) DESC, id DESC LIMIT 60");
$st->execute([$class_id,$subject_id]);
$recent_lessons=$st->fetchAll();

// Criteria (exclude archived)
$st=$pdo->prepare("SELECT c.id,c.label,c.category, cs.scope
                   FROM criteria c
                   JOIN criteria_sets cs ON cs.id=c.criteria_set_id
                   WHERE c.active=1 AND IFNULL(c.archived,0)=0 AND (
                     (cs.scope='teacher' AND cs.teacher_id=? AND cs.subject_id=?)
                     OR (cs.scope='subject' AND cs.subject_id=?)
                   )
                   ORDER BY (cs.scope='teacher') DESC, c.category, c.label");
$st->execute([(int)$u['id'],$subject_id,$subject_id]);
$criteria=$st->fetchAll();

$reasons=load_participation_options($pdo,(int)$u['id'],$subject_id,'reason');
$impacts=load_participation_options($pdo,(int)$u['id'],$subject_id,'impact');
$perfs=load_participation_options($pdo,(int)$u['id'],$subject_id,'performance');
$groups=load_participation_options($pdo,(int)$u['id'],$subject_id,'observation_group');
$socials=load_participation_options($pdo,(int)$u['id'],$subject_id,'social_form');
$phases=load_participation_options($pdo,(int)$u['id'],$subject_id,'phase');
$homeworks=load_participation_options($pdo,(int)$u['id'],$subject_id,'homework');

// Build id->label maps
$optLabel=[];
foreach([$reasons,$impacts,$perfs,$groups,$socials,$phases,$homeworks] as $arr){
  foreach($arr as $o){ $optLabel[(int)$o['id']] = (string)$o['label']; }
}
$critLabel=[];
foreach($criteria as $c){ $critLabel[(int)$c['id']] = (string)(($c['category']?($c['category'].': '):'').$c['label']); }

function get_tags(PDO $pdo,int $event_id,string $source): array {
  $st=$pdo->prepare("SELECT tag FROM participation_event_lbvo WHERE event_id=? AND source=? ORDER BY tag");
  $st->execute([$event_id,$source]);
  return array_map(fn($r)=>$r['tag'],$st->fetchAll());
}

$selCriteria=[];
$selPerfs=[];
$selGroups=[];
$st=$pdo->prepare("SELECT criteria_id FROM participation_event_criteria WHERE event_id=?");
$st->execute([$id]);
foreach($st->fetchAll() as $r){ $selCriteria[]=(int)$r['criteria_id']; }
$selPerfs=participation_event_option_ids_by_type($pdo,$id,'performance');
$selGroups=participation_event_option_ids_by_type($pdo,$id,'observation_group');
if(!$selGroups){
  $selGroups=participation_observation_group_ids_from_reason_and_criteria(
    $groups,
    (string)($e['reason_label'] ?? ''),
    $criteria,
    $selCriteria,
    2
  );
}

$manual=get_tags($pdo,$id,'manual');
$auto=get_tags($pdo,$id,'auto');

$form_student_id=(int)$e['student_id'];
$form_event_date=(string)$e['event_date'];
$form_lesson_id=(int)($e['lesson_id'] ?? 0);
$form_reason_id=(int)($e['reason_option_id'] ?? 0);
$form_impact_id=(int)($e['impact_option_id'] ?? 0);
$form_social_id=(int)($e['social_form_option_id'] ?? 0);
$form_phase_id=(int)($e['phase_option_id'] ?? 0);
$form_hw_id=(int)($e['homework_option_id'] ?? 0);
$form_reason_text=(string)($e['reason_text'] ?? '');
$form_note=(string)($e['note'] ?? '');
$preset_name_input=trim((string)($_POST['preset_name'] ?? ''));

$msg='';
$err='';
$action='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action'] ?? 'save';

  if($action==='save' || $action==='save_preset'){
    $form_student_id=(int)($_POST['student_id'] ?? $e['student_id']);
    $form_event_date=(string)($_POST['event_date'] ?? $e['event_date']);
    $form_lesson_id=(int)($_POST['lesson_id'] ?? 0);
    $form_reason_id=(int)($_POST['reason_option_id'] ?? 0);
    $form_impact_id=(int)($_POST['impact_option_id'] ?? 0);
    $form_social_id=(int)($_POST['social_form_option_id'] ?? 0);
    $form_phase_id=(int)($_POST['phase_option_id'] ?? 0);
    $form_hw_id=(int)($_POST['homework_option_id'] ?? 0);
    $form_reason_text=trim((string)($_POST['reason_text'] ?? ''));
    $form_note=trim((string)($_POST['note'] ?? ''));
    $selCriteria=array_map('intval',(array)($_POST['criteria_ids'] ?? []));
    $selPerfs=array_map('intval',(array)($_POST['performance_option_ids'] ?? []));
    $selGroups=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['group_option_ids'] ?? [])), fn($v)=>$v>0)));
  }

  if($action==='delete'){
    $pdo->prepare("DELETE FROM participation_events WHERE id=? AND teacher_id=?")->execute([$id,(int)$u['id']]);
    emit_event('participation_deleted',['event_id'=>$id,'class_id'=>$class_id,'subject_id'=>$subject_id]);
    header('Location: '.$bp.'/teacher/participation_list.php?class_id='.$class_id.'&subject_id='.$subject_id);
    exit;
  }

  if($action==='lbvo_recalc'){
    $pdo->beginTransaction();
    try{
      $pdo->prepare("DELETE FROM participation_event_lbvo WHERE event_id=? AND source='manual'")->execute([$id]);
      $pdo->prepare("DELETE FROM participation_event_lbvo WHERE event_id=? AND source='auto'")->execute([$id]);

      $phase_label = $e['phase_option_id'] ? ($optLabel[(int)$e['phase_option_id']] ?? '') : '';
      $hw_label    = $e['homework_option_id'] ? ($optLabel[(int)$e['homework_option_id']] ?? '') : '';

      $cLabs=[]; foreach($selCriteria as $cid){ $cLabs[] = $critLabel[$cid] ?? ''; }
      foreach(participation_option_labels_by_ids($groups,$selGroups) as $group_label){ $cLabs[] = $group_label; }
      $pLabs=[]; foreach($selPerfs as $oid){ $pLabs[] = $optLabel[$oid] ?? ''; }

      $tags=lbvo_auto_tags($e['reason_label'],$phase_label,$hw_label,$cLabs,$pLabs,$e['note']??'', $e['reason_text']??'');
      $insT=$pdo->prepare("INSERT IGNORE INTO participation_event_lbvo (event_id,tag,source,created_at) VALUES (?,?, 'auto', ?)");
      foreach($tags as $t){ $insT->execute([$id,$t,now_iso()]); }

      $pdo->commit();
      emit_event('participation_lbvo_recalc',['event_id'=>$id]);
      header('Location: '.$bp.'/teacher/participation_edit.php?id='.$id);
      exit;
    }catch(Exception $ex){ $pdo->rollBack(); $err='Fehler: '.$ex->getMessage(); }
  }

  if($action==='lbvo_save'){
    $sel=$_POST['lbvo'] ?? [];
    $allowed=['a','b','c','d','e'];
    $sel=array_values(array_unique(array_filter($sel, fn($x)=>in_array($x,$allowed,true))));
    $pdo->beginTransaction();
    try{
      $pdo->prepare("DELETE FROM participation_event_lbvo WHERE event_id=? AND source='manual'")->execute([$id]);
      $ins=$pdo->prepare("INSERT IGNORE INTO participation_event_lbvo (event_id,tag,source,created_at) VALUES (?,?, 'manual', ?)");
      foreach($sel as $t){ $ins->execute([$id,$t,now_iso()]); }
      $pdo->commit();
      emit_event('participation_lbvo_updated',['event_id'=>$id,'tags'=>implode('',$sel)]);
      $msg='LBV-Tags gespeichert.';
      $manual=get_tags($pdo,$id,'manual');
    }catch(Exception $ex){ $pdo->rollBack(); $err='Fehler: '.$ex->getMessage(); }
  }

  if($action==='save'){
    $new_student_id=$form_student_id;
    $event_date=$form_event_date;

    $new_lesson_id=$form_lesson_id ?: null;

    $reason_id=$form_reason_id;
    $impact_id=$form_impact_id;
    $social_id=$form_social_id;
    $phase_id=$form_phase_id;
    $hw_id=$form_hw_id;

    $reason_text=$form_reason_text;
    $note=$form_note;

    if(!$new_student_id) $err='Bitte Schüler:in wählen.';
    elseif(!$event_date) $err='Bitte Datum wählen.';
    elseif(!$reason_id) $err='Bitte Grund wählen.';
    elseif(!$impact_id) $err='Bitte Eindruck/Relevanz wählen.';
    elseif(!$selGroups) $err='Bitte mindestens einen Beobachtungsbereich wählen.';
    elseif(count($selGroups)>2) $err='Bitte höchstens zwei Beobachtungsbereiche wählen.';

    if($err===''){
      $reason_label = $optLabel[$reason_id] ?? '';
      $impact_label = $optLabel[$impact_id] ?? '';
      if($reason_label==='') $err='Ungültiger Grund.';
      elseif($impact_label==='') $err='Ungültiger Eindruck.';
    }

    if($err===''){
      $pdo->beginTransaction();
      try{
        $pdo->prepare("UPDATE participation_events
          SET student_id=?, event_date=?, lesson_id=?,
              reason_option_id=?, reason_label=?,
              impact_option_id=?, rating=?,
              social_form_option_id=?, phase_option_id=?, homework_option_id=?,
              reason_text=?, note=?
          WHERE id=? AND teacher_id=?")
          ->execute([
            $new_student_id, $event_date, $new_lesson_id,
            $reason_id, $reason_label,
            $impact_id, $impact_label,
            ($social_id?:null), ($phase_id?:null), ($hw_id?:null),
            ($reason_text?:null), ($note?:null),
            $id, (int)$u['id']
          ]);

        $pdo->prepare("DELETE FROM participation_event_criteria WHERE event_id=?")->execute([$id]);
        if($selCriteria){
          $ins=$pdo->prepare("INSERT IGNORE INTO participation_event_criteria (event_id,criteria_id) VALUES (?,?)");
          foreach($selCriteria as $cid){ if($cid>0) $ins->execute([$id,$cid]); }
        }

        $pdo->prepare("DELETE peo
                       FROM participation_event_options peo
                       JOIN participation_options po ON po.id=peo.option_id
                       WHERE peo.event_id=? AND po.opt_type IN ('performance','observation_group')")->execute([$id]);
        if($selPerfs || $selGroups){
          $ins=$pdo->prepare("INSERT IGNORE INTO participation_event_options (event_id,option_id) VALUES (?,?)");
          foreach($selGroups as $oid){ if($oid>0) $ins->execute([$id,$oid]); }
          foreach($selPerfs as $oid){ if($oid>0) $ins->execute([$id,$oid]); }
        }

        $phase_label = $phase_id ? ($optLabel[$phase_id] ?? '') : '';
        $hw_label    = $hw_id ? ($optLabel[$hw_id] ?? '') : '';

        $cLabs=[]; foreach($selCriteria as $cid){ $cLabs[] = $critLabel[$cid] ?? ''; }
        foreach(participation_option_labels_by_ids($groups,$selGroups) as $group_label){ $cLabs[] = $group_label; }
        $pLabs=[]; foreach($selPerfs as $oid){ $pLabs[] = $optLabel[$oid] ?? ''; }

        $tags=lbvo_auto_tags($reason_label,$phase_label,$hw_label,$cLabs,$pLabs,$note,$reason_text);
        $pdo->prepare("DELETE FROM participation_event_lbvo WHERE event_id=? AND source='auto'")->execute([$id]);
        $insT=$pdo->prepare("INSERT IGNORE INTO participation_event_lbvo (event_id,tag,source,created_at) VALUES (?,?, 'auto', ?)");
        foreach($tags as $t){ $insT->execute([$id,$t,now_iso()]); }

        $pdo->commit();
        emit_event('participation_updated',['event_id'=>$id,'class_id'=>$class_id,'subject_id'=>$subject_id]);
        $msg='Eintrag gespeichert.';

        // Reload event
        $st=$pdo->prepare("SELECT pe.*, st.last_name, st.first_name, c.name AS class_name, s.code AS subject_code
                           FROM participation_events pe
                           JOIN students st ON st.id=pe.student_id
                           JOIN classes c ON c.id=pe.class_id
                           JOIN subjects s ON s.id=pe.subject_id
                           WHERE pe.id=? AND pe.teacher_id=?");
        $st->execute([$id,(int)$u['id']]);
        $e=$st->fetch();

        // Reload selections
        $selCriteria=[]; $selPerfs=[]; $selGroups=[];
        $st=$pdo->prepare("SELECT criteria_id FROM participation_event_criteria WHERE event_id=?");
        $st->execute([$id]);
        foreach($st->fetchAll() as $r){ $selCriteria[]=(int)$r['criteria_id']; }
        $selPerfs=participation_event_option_ids_by_type($pdo,$id,'performance');
        $selGroups=participation_event_option_ids_by_type($pdo,$id,'observation_group');
        if(!$selGroups){
          $selGroups=participation_observation_group_ids_from_reason_and_criteria(
            $groups,
            (string)($e['reason_label'] ?? ''),
            $criteria,
            $selCriteria,
            2
          );
        }

        $auto=get_tags($pdo,$id,'auto');
        $manual=get_tags($pdo,$id,'manual');

        $form_student_id=(int)$e['student_id'];
        $form_event_date=(string)$e['event_date'];
        $form_lesson_id=(int)($e['lesson_id'] ?? 0);
        $form_reason_id=(int)($e['reason_option_id'] ?? 0);
        $form_impact_id=(int)($e['impact_option_id'] ?? 0);
        $form_social_id=(int)($e['social_form_option_id'] ?? 0);
        $form_phase_id=(int)($e['phase_option_id'] ?? 0);
        $form_hw_id=(int)($e['homework_option_id'] ?? 0);
        $form_reason_text=(string)($e['reason_text'] ?? '');
        $form_note=(string)($e['note'] ?? '');

      }catch(Exception $ex){
        $pdo->rollBack();
        $err='Fehler beim Speichern: '.$ex->getMessage();
      }
    }
  }

  if($action==='save_preset'){
    if($preset_name_input===''){
      $err='Bitte einen Namen für das Preset eingeben.';
    } else {
      $payload=[
        'reason_option_id'=>$form_reason_id,
        'impact_option_id'=>$form_impact_id,
        'performance_option_ids'=>array_values(array_filter($selPerfs, fn($v)=>$v>0)),
        'group_option_ids'=>array_values(array_filter($selGroups, fn($v)=>$v>0)),
        'social_form_option_id'=>$form_social_id,
        'phase_option_id'=>$form_phase_id,
        'homework_option_id'=>$form_hw_id,
        'reason_text'=>$form_reason_text,
        'note'=>$form_note,
        'criteria_ids'=>array_values(array_filter($selCriteria, fn($v)=>$v>0)),
      ];
      $preset_name_input=participation_preset_name($preset_name_input);
      $preset_id=save_participation_preset($pdo,(int)$u['id'],$subject_id,$preset_name_input,$payload);
      emit_event('teacher_preset_saved',[
        'preset_id'=>$preset_id,
        'preset_name'=>$preset_name_input,
        'subject_id'=>$subject_id,
        'source'=>'participation_edit',
        'event_id'=>$id,
      ]);
      $msg='Preset gespeichert: '.$preset_name_input;
    }
  }
}

$effective = $manual ?: $auto;
$main_form_dirty_initial = ($_SERVER['REQUEST_METHOD']==='POST' && ($action==='save_preset' || ($action==='save' && $err!=='')));
$details_open = $form_social_id>0 || $form_phase_id>0 || $form_hw_id>0 || trim($form_note)!=='' || !empty($selCriteria);
$current_suggested_mode=participation_pedagogical_mode_suggestion($reasons,$phases,$form_reason_id,$form_phase_id);
$current_impact_label='';
foreach($impacts as $impactOption){
  if((int)($impactOption['id'] ?? 0)===$form_impact_id){
    $current_impact_label=(string)($impactOption['label'] ?? '');
    break;
  }
}
$current_hint=participation_pedagogical_hint($current_suggested_mode, participation_impact_kind_from_label($current_impact_label));

$student_name='';
foreach($students as $s){
  if((int)$s['id']===$form_student_id){
    $student_name=(string)$s['last_name'].', '.(string)$s['first_name'];
    break;
  }
}
if($student_name==='') $student_name=(string)$e['last_name'].', '.(string)$e['first_name'];

render_header('Mitarbeit bearbeiten',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Mitarbeitseintrag bearbeiten</h1>
  <div class="muted">
    <?php echo h($e['class_name']); ?> · <?php echo h($e['subject_code']); ?> · <?php echo h($form_event_date); ?><br>
    Schüler:in: <b><?php echo h($student_name); ?></b>
  </div>

  <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

  <form method="post" style="margin-top:12px" <?php echo dirty_form_attrs($main_form_dirty_initial); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <div class="row" style="align-items:end">
      <div style="flex:1">
        <label class="muted">Schüler:in</label>
        <select class="input" name="student_id" required>
          <?php foreach($students as $s): $sid=(int)$s['id']; ?>
            <option value="<?php echo $sid; ?>" <?php echo $sid===$form_student_id?'selected':''; ?>><?php echo h($s['last_name'].', '.$s['first_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="muted">Datum</label>
        <input class="input" type="date" name="event_date" value="<?php echo h($form_event_date); ?>" required>
      </div>
      <div style="flex:1">
        <label class="muted">Unterrichtsstunde (optional)</label>
        <select class="input" name="lesson_id">
          <option value="0">– keine Verknüpfung –</option>
          <?php foreach($recent_lessons as $ls):
            $txt=$ls['lesson_date'];
            if($ls['lesson_unit']) $txt.=' · UE '.$ls['lesson_unit'];
            if($ls['topic']) $txt.=' · '.$ls['topic'];
          ?>
            <option value="<?php echo (int)$ls['id']; ?>" <?php echo ($form_lesson_id===(int)$ls['id'])?'selected':''; ?>><?php echo h($txt); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="height:12px"></div>
    <div class="row">
      <div>
        <label class="muted">Grund/Anlass</label>
        <select class="input" name="reason_option_id" id="reasonSelect" required>
          <option value="0">Bitte wählen…</option>
          <?php foreach($reasons as $o): ?>
            <?php
              $pedagogical_suggest=participation_reason_mode_normalize((string)($o['pedagogical_hint_mode'] ?? 'auto'));
              if($pedagogical_suggest==='auto'){
                $pedagogical_suggest=participation_pedagogical_mode_suggestion_from_labels((string)$o['label'], '');
              }
            ?>
            <option value="<?php echo (int)$o['id']; ?>" data-suggest-mode="<?php echo h($pedagogical_suggest); ?>" <?php echo ($form_reason_id===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="muted">Eindruck/Relevanz</label>
        <select class="input" name="impact_option_id" id="impactSelect" required>
          <option value="0">Bitte wählen…</option>
          <?php foreach($impacts as $o): ?>
            <?php $impact_kind=participation_impact_kind_from_label((string)$o['label']); ?>
            <option value="<?php echo (int)$o['id']; ?>" data-impact-kind="<?php echo h($impact_kind); ?>" <?php echo ($form_impact_id===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div id="pedagogicalHintBox" class="flash <?php echo $current_hint['level']==='error' ? 'error' : 'info'; ?>" style="margin-top:10px">
      <?php echo h($current_hint['text']); ?>
    </div>

    <div style="height:12px"></div>
    <fieldset class="multi-field">
      <legend>Leistungsart (Mehrfach)</legend>
      <div class="multi-grid">
        <?php foreach($perfs as $o): $oid=(int)$o['id']; ?>
          <label class="multi-item">
            <input type="checkbox" name="performance_option_ids[]" value="<?php echo $oid; ?>" <?php echo in_array($oid,$selPerfs,true)?'checked':''; ?>>
            <span><?php echo h($o['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div style="height:12px"></div>
    <label class="muted">Kurze Beobachtung / Anlass</label>
    <input class="input" name="reason_text" value="<?php echo h($form_reason_text); ?>" placeholder="z.B. Falllösung sauber erklärt.">

    <div style="height:12px"></div>
    <fieldset class="multi-field">
      <legend>Beobachtungsbereich</legend>
      <div class="multi-grid">
        <?php foreach($groups as $o): $oid=(int)$o['id']; ?>
          <label class="multi-item">
            <input class="groupCb" type="checkbox" name="group_option_ids[]" value="<?php echo $oid; ?>" <?php echo in_array($oid,$selGroups,true)?'checked':''; ?>>
            <span><?php echo h($o['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="small muted" style="margin-top:6px">Ein Hauptbereich genügt meistens. Ein zweiter Bereich ist nur als Ergänzung gedacht.</div>
    </fieldset>

    <div style="height:12px"></div>
    <details class="accordion" <?php echo ($form_social_id>0 || $form_phase_id>0 || $form_hw_id>0 || trim($form_note)!=='')?'open':''; ?>>
      <summary><span class="acc-title">Unterrichtskontext</span></summary>
      <div class="acc-body">
        <fieldset class="multi-field" style="margin-top:0">
          <legend>Unterrichtskontext</legend>
          <div class="row">
            <div>
              <label class="muted">Sozialform</label>
              <select class="input" name="social_form_option_id">
                <option value="0">–</option>
                <?php foreach($socials as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ($form_social_id===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="muted">Unterrichtsphase</label>
              <select class="input" name="phase_option_id" id="phaseSelect">
                <option value="0">–</option>
                <?php foreach($phases as $o): ?>
                  <?php $phase_suggest=participation_pedagogical_mode_suggestion_from_labels('', (string)$o['label']); ?>
                  <option value="<?php echo (int)$o['id']; ?>" data-suggest-mode="<?php echo h($phase_suggest); ?>" <?php echo ($form_phase_id===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="muted">Hausübung-Status</label>
              <select class="input" name="homework_option_id">
                <option value="0">–</option>
                <?php foreach($homeworks as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ($form_hw_id===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div style="height:12px"></div>
          <label class="muted">Notiz (optional)</label>
          <textarea class="input" name="note" rows="3" placeholder="1–2 Sätze als Beleg/Beobachtung."><?php echo h($form_note); ?></textarea>
        </fieldset>
      </div>
    </details>

    <div style="height:12px"></div>
    <details class="accordion" <?php echo !empty($selCriteria)?'open':''; ?>>
      <summary><span class="acc-title">Kriterien (fachspezifisch / LBV-orientiert)</span></summary>
      <div class="acc-body">
        <fieldset class="multi-field" style="margin-top:0">
          <legend>Kriterien (fachspezifisch / LBV-orientiert)</legend>
          <?php if(!$criteria): ?>
            <div class="muted">Noch keine Kriterien vorhanden.</div>
          <?php else: ?>
            <div class="multi-grid">
              <?php foreach($criteria as $c): $cid=(int)$c['id']; ?>
                <label class="multi-item">
                  <input type="checkbox" name="criteria_ids[]" value="<?php echo $cid; ?>" <?php echo in_array($cid,$selCriteria,true)?'checked':''; ?>>
                  <span><?php echo h(($c['category']?($c['category'].': '):'').$c['label']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      </div>
    </details>

    <div style="height:12px"></div>
    <fieldset class="multi-field">
      <legend>Als Fach-Preset speichern</legend>
      <div class="row" style="align-items:end">
        <div style="flex:1">
          <label class="muted">Preset-Name</label>
          <input class="input" name="preset_name" value="<?php echo h($preset_name_input); ?>" maxlength="120" placeholder="z.B. Reflexion mit Begriffen">
        </div>
        <div style="flex:0 0 auto">
          <label class="muted">&nbsp;</label>
          <button class="btn secondary" name="action" value="save_preset" formnovalidate>Aktuelle Auswahl als Preset speichern</button>
        </div>
      </div>
      <div class="small muted" style="margin-top:6px">Gespeichert werden Grund, Eindruck, Beobachtungsbereich, Leistungsart, Sozialform, Unterrichtsphase, Hausübung, Kurzbeschreibung, Notiz und Kriterien. Schüler:in, Datum und Stunde bleiben außen vor. Presets gelten pro Fach.</div>
    </fieldset>

    <div style="height:14px"></div>
    <button class="btn" name="action" value="save">Speichern</button>
    <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/participation_list.php?class_id=<?php echo (int)$class_id; ?>&subject_id=<?php echo (int)$subject_id; ?>">Zur Liste</a>
    <button class="btn secondary" name="action" value="delete" onclick="return confirm('Eintrag wirklich löschen?')">Löschen</button>
  </form>

  <script>
  (function(){
    const form=document.querySelector('form[method="post"]');
    const boxes=Array.from(document.querySelectorAll('.groupCb'));
    const reason=document.getElementById('reasonSelect');
    const phase=document.getElementById('phaseSelect');

    function suggestedPedagogicalMode(){
      let suggested='formative';
      if(reason){
        const opt=reason.options[reason.selectedIndex];
        suggested=((opt && opt.getAttribute('data-suggest-mode')) || suggested);
      }
      if(phase){
        const opt=phase.options[phase.selectedIndex];
        const phaseSuggested=(opt && opt.getAttribute('data-suggest-mode')) || '';
        if(phaseSuggested) suggested=phaseSuggested;
      }
      return suggested === 'summative' ? 'summative' : 'formative';
    }

    function updatePedagogicalHint(){
      const suggested=suggestedPedagogicalMode();
      const impact=document.getElementById('impactSelect');
      const box=document.getElementById('pedagogicalHintBox');
      if(!box) return;
      const hasSignal=(reason && parseInt(reason.value||'0',10)>0) || (phase && parseInt(phase.value||'0',10)>0);
      if(!hasSignal){
        box.style.display='none';
        return;
      }
      let impactKind='';
      if(impact){
        const opt=impact.options[impact.selectedIndex];
        impactKind=(opt && opt.getAttribute('data-impact-kind')) || '';
      }
      box.style.display='';
      if(suggested === 'summative'){
        box.className='flash info';
        box.innerHTML='Auswahl eher <strong style="color:#b7791f">summativ</strong>; <strong style="color:#b7791f">negative</strong> Erfassung bei Eindruck/Relevanz ist hier möglich.';
        return;
      }
      if(impactKind === 'negative'){
        box.className='flash error';
        box.innerHTML='Auswahl eher <strong style="color:#c53030">formativ</strong>; daher <strong style="color:#c53030">keine negative</strong> Erfassung von Eindruck/Relevanz.';
        return;
      }
      box.className='flash info';
      box.innerHTML='Auswahl eher <strong style="color:#2f855a">formativ</strong>; daher <strong style="color:#2f855a">keine negative</strong> Erfassung von Eindruck/Relevanz.';
    }

    if(!boxes.length) return;
    boxes.forEach(cb=>{
      cb.addEventListener('change', ()=>{
        const checked=boxes.filter(box=>box.checked);
        if(checked.length>2){
          cb.checked=false;
          alert('Bitte höchstens zwei Beobachtungsbereiche auswählen.');
        }
      });
    });
    if(reason){
      reason.addEventListener('change', updatePedagogicalHint);
    }
    if(phase){
      phase.addEventListener('change', updatePedagogicalHint);
    }
    const impact=document.getElementById('impactSelect');
    if(impact) impact.addEventListener('change', updatePedagogicalHint);
    updatePedagogicalHint();
    if(form){
      form.addEventListener('submit', (ev)=>{
        const submitter=ev.submitter;
        if(submitter && submitter.value==='save_preset') return;
        const checked=boxes.filter(box=>box.checked);
        if(checked.length===0){
          ev.preventDefault();
          alert('Bitte mindestens einen Beobachtungsbereich auswählen.');
        } else if(checked.length>2){
          ev.preventDefault();
          alert('Bitte höchstens zwei Beobachtungsbereiche auswählen.');
        }
      });
    }
  })();
  </script>

  <div style="height:16px"></div>
  <h2>LBV-Tags (a–e)</h2>
  <div class="muted">Auto-Tags werden aus dem Eintrag abgeleitet. Manuelle Tags überschreiben automatisch.</div>

  <?php
    $labels=[
      'a'=>'a – Eingebundene muendliche/schriftliche/praktische/graphische Leistung',
      'b'=>'b – Sicherung des Unterrichtsertrages / Hausuebungen',
      'c'=>'c – Erarbeitung neuer Lehrstoffe',
      'd'=>'d – Erfassen und Verstehen',
      'e'=>'e – Einordnen und Anwenden'
    ];
  ?>

  <form method="post" style="margin-top:10px" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

    <fieldset class="multi-field">
      <legend>Auswahl</legend>
      <div class="multi-grid">
        <?php foreach($labels as $k=>$lab): ?>
          <label class="multi-item">
            <input type="checkbox" name="lbvo[]" value="<?php echo h($k); ?>" <?php echo in_array($k,$effective,true)?'checked':''; ?>>
            <span><?php echo h($lab); ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="small muted" style="margin-top:8px">
        Auto: <?php echo $auto? h(implode('',$auto)) : '–'; ?> ·
        Manuell: <?php echo $manual? h(implode('',$manual)) : '–'; ?>
      </div>
    </fieldset>

    <div style="height:12px"></div>
    <button class="btn" name="action" value="lbvo_save">LBV-Tags speichern</button>
    <button class="btn secondary" name="action" value="lbvo_recalc" onclick="return confirm('Auto-Tags neu berechnen und manuelle Tags entfernen?')">Auto neu berechnen</button>
  </form>

</div></div></div>
<?php render_footer(); ?>
