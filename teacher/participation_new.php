<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/lbvo.php';
require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/participation_presets.php';
require_once __DIR__.'/../lib/participation_observation_groups.php';
require_once __DIR__.'/../lib/participation_pedagogical_mode.php';
require_once __DIR__.'/../lib/student_groups.php';
require_once __DIR__.'/../lib/assessment_summaries.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);

// Lesson context can be passed from Stundenerfassung or chosen here
$lesson_id=(int)($_GET['lesson_id'] ?? $_POST['fixed_lesson_id'] ?? 0);

// Optional: slot map for quick switching between multiple UEs (format: "UE:ID,UE:ID")
$slot_map_str=(string)($_GET['slot_map'] ?? '');
$slot_links=[];
if($slot_map_str!==''){
  foreach(explode(',', $slot_map_str) as $pair){
    if(preg_match('/^(\d{1,2}):(\d+)$/',trim($pair),$m)){
      $slot_links[(int)$m[1]]=(int)$m[2];
    }
  }
  if($slot_links) ksort($slot_links);
}

$pre_student_id=(int)($_GET['student_id'] ?? 0);
$pre_student_ids=[];
if(isset($_GET['student_ids'])){
  foreach(explode(',', (string)$_GET['student_ids']) as $x){
    $x=trim($x);
    if($x!=='' && ctype_digit($x)) $pre_student_ids[]=(int)$x;
  }
}
$selected_student_group_id=(int)($_GET['student_group_id'] ?? 0);

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
require_teacher_assignment($u,$class_id,$subject_id);
require_class_writable($pdo,$class_id);

// Recent lessons for this teacher+class+subject
$st=$pdo->prepare("SELECT id, lesson_date, lesson_unit, topic FROM lesson_sessions WHERE class_id=? AND subject_id=? ORDER BY lesson_date DESC, id DESC LIMIT 25");
$st->execute([$class_id,$subject_id]);
$recent_lessons=$st->fetchAll();

// Fixed lesson context (optional)
$lesson=null;
if($lesson_id){
  $st=$pdo->prepare("SELECT * FROM lesson_sessions WHERE id=? AND class_id=? AND subject_id=?");
  $st->execute([$lesson_id,$class_id,$subject_id]);
  $lesson=$st->fetch();
  if(!$lesson){ http_response_code(404); exit('Stunde nicht gefunden.'); }
}

// Students
$students=load_class_students($pdo,$class_id,false);
$studentGroups=load_teacher_student_groups($pdo,(int)$u['id'],$class_id,$subject_id);
$selectedStudentGroup=null;
if($selected_student_group_id>0){
  foreach($studentGroups as $studentGroupRow){
    if((int)$studentGroupRow['id']===$selected_student_group_id){
      $selectedStudentGroup=$studentGroupRow;
      break;
    }
  }
  if($selectedStudentGroup){
    $pre_student_ids=array_values(array_unique(array_merge($pre_student_ids,array_map('intval',(array)($selectedStudentGroup['member_ids'] ?? [])))));
  }
}

$criteria=load_participation_criteria($pdo,(int)$u['id'],$subject_id);

// Editable picklists
$reasons=load_participation_options($pdo,(int)$u['id'],$subject_id,'reason');
$impacts=load_participation_options($pdo,(int)$u['id'],$subject_id,'impact');
$perfs=load_participation_options($pdo,(int)$u['id'],$subject_id,'performance');
$groups=load_participation_options($pdo,(int)$u['id'],$subject_id,'observation_group');
$socials=load_participation_options($pdo,(int)$u['id'],$subject_id,'social_form');
$phases=load_participation_options($pdo,(int)$u['id'],$subject_id,'phase');
$homeworks=load_participation_options($pdo,(int)$u['id'],$subject_id,'homework');

$quick_enabled = ((string)($u['pref_participation_quick_pick_enabled'] ?? '1') !== '0');
$quick_limit = (int)($u['pref_participation_quick_pick_limit'] ?? 10);
if($quick_limit < 1) $quick_limit = 10;
if($quick_limit > 30) $quick_limit = 30;

$quick=[];
if($quick_enabled){
  try{
    $quick_rotation_seed = (((int)date('z')) + ((int)$u['id'] * 7) + ($class_id * 13) + ($subject_id * 17)) % 9973;
    $quickRows=[];
    foreach($students as $studentRow){
      $quickRows[]=[
        'student_id'=>(int)$studentRow['id'],
        'last_name'=>(string)$studentRow['last_name'],
        'first_name'=>(string)$studentRow['first_name'],
        'participation_count'=>0,
        'last_event_date'=>null,
      ];
    }

    $statsByStudent=[];
    $st=$pdo->prepare("SELECT student_id, COUNT(*) AS participation_count, MAX(event_date) AS last_event_date
                       FROM participation_events
                       WHERE teacher_id=? AND class_id=? AND subject_id=?
                       GROUP BY student_id");
    $st->execute([(int)$u['id'],$class_id,$subject_id]);
    foreach($st->fetchAll() as $statRow){
      $statsByStudent[(int)$statRow['student_id']]=[
        'participation_count'=>(int)($statRow['participation_count'] ?? 0),
        'last_event_date'=>(string)($statRow['last_event_date'] ?? ''),
      ];
    }

    foreach($quickRows as &$quickRow){
      $sid=(int)$quickRow['student_id'];
      if(isset($statsByStudent[$sid])){
        $quickRow['participation_count']=$statsByStudent[$sid]['participation_count'];
        $quickRow['last_event_date']=$statsByStudent[$sid]['last_event_date'] !== '' ? $statsByStudent[$sid]['last_event_date'] : null;
      }
    }
    unset($quickRow);

    usort($quickRows, static function(array $a, array $b): int {
      $countCmp=((int)$a['participation_count']) <=> ((int)$b['participation_count']);
      if($countCmp !== 0) return $countCmp;

      $aHasDate=!empty($a['last_event_date']);
      $bHasDate=!empty($b['last_event_date']);
      if($aHasDate !== $bHasDate) return $aHasDate ? 1 : -1;

      if($aHasDate && $bHasDate){
        $dateCmp=strcmp((string)$a['last_event_date'], (string)$b['last_event_date']);
        if($dateCmp !== 0) return $dateCmp;
      }

      $lastCmp=strcasecmp((string)$a['last_name'], (string)$b['last_name']);
      if($lastCmp !== 0) return $lastCmp;
      return strcasecmp((string)$a['first_name'], (string)$b['first_name']);
    });

    $quickBuckets=[];
    foreach($quickRows as $row){
      $bucketKey=(int)($row['participation_count'] ?? 0).'|'.(string)($row['last_event_date'] ?? '');
      if(!isset($quickBuckets[$bucketKey])) $quickBuckets[$bucketKey]=[];
      $quickBuckets[$bucketKey][]=$row;
    }

    $quick=[];
    foreach($quickBuckets as $bucketRows){
      $count=count($bucketRows);
      if($count>1){
        $offset=$quick_rotation_seed % $count;
        if($offset>0){
          $bucketRows=array_merge(array_slice($bucketRows,$offset), array_slice($bucketRows,0,$offset));
        }
      }
      foreach($bucketRows as $row){
        $quick[]=$row;
        if(count($quick)>=$quick_limit) break 2;
      }
    }
  }catch(Exception $quickEx){
    $quick=[];
    app_log('warn','participation_new quick pick failed',[
      'teacher_id'=>(int)$u['id'],
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
      'msg'=>$quickEx->getMessage(),
    ]);
  }
}

$presets=load_participation_presets($pdo,(int)$u['id'],$subject_id);
$selected_preset_id=(int)($_GET['preset_id'] ?? $_POST['preset_id'] ?? 0);
$preset_name_input=trim((string)($_POST['preset_name'] ?? ''));
$simple_entry_mode = simplified_participation_entry_enabled($u);

$msg='';
$err='';
$errors=[];

// Notice codes from redirects (e.g., from lesson.php)
$notice_code=(string)($_GET['msg'] ?? '');
$saved_count=(int)($_GET['saved_count'] ?? 0);
if(isset($_GET['err']) && $_GET['err']!=='') $err=(string)$_GET['err'];
if($saved_count>0) $msg='Gespeichert für '.$saved_count.' Schüler:in(nen).';
elseif($selectedStudentGroup) $msg='Gruppe vorausgewählt: '.$selectedStudentGroup['name'];

// keep selections on validation errors
$sel_student_ids = [];
$sel_criteria_ids = [];
$sel_perf_ids = [];
$sel_group_ids = [];

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $form_action=trim((string)($_POST['auto_action'] ?? ''));
  if($form_action==='') $form_action=(string)($_POST['action'] ?? 'save_entry');
  if($simple_entry_mode && in_array($form_action, ['apply_preset','save_preset','update_preset'], true)) $form_action='save_entry';
  // If the browser blocks submission (HTML required fields etc.), this code won't run.
  // We log each POST to help diagnose silent issues.
  app_log('info','participation_new POST',[
    'teacher_id'=>(int)$u['id'],
    'class_id'=>$class_id,
    'subject_id'=>$subject_id,
    'lesson_id'=>$lesson_id,
    'action'=>$form_action,
    'ip'=>($_SERVER['REMOTE_ADDR'] ?? null),
  ]);
  // Optional: choose or create a lesson here (only if not fixed via GET)
  $chosen_lesson_id=(int)($_POST['lesson_id'] ?? 0);
  $create_lesson = (string)($_POST['create_lesson'] ?? '')==='1';

  if(!$lesson_id){
    if($create_lesson){
      $lesson_date = $_POST['lesson_date'] ?? date('Y-m-d');
      $lesson_unit = trim($_POST['lesson_unit'] ?? '');
      $topic      = trim($_POST['topic'] ?? '');

      if($lesson_unit==='' || !preg_match('/^\d{1,2}$/',$lesson_unit)){
        $errors[]='Bitte eine einzelne Unterrichtsstunde/UE als Zahl eingeben (z.B. 3).';
      } else {
        // Re-use existing slot if already created (class+subject+date+unit)
        $st=$pdo->prepare("SELECT id FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date=? AND lesson_unit=? LIMIT 1");
        $st->execute([$class_id,$subject_id,$lesson_date,$lesson_unit]);
        $lesson_id=(int)($st->fetchColumn() ?: 0);

        if(!$lesson_id){
          try{
            $st=$pdo->prepare("INSERT INTO lesson_sessions (teacher_id,class_id,subject_id,lesson_date,lesson_unit,topic,created_at) VALUES (?,?,?,?,?,?,?)");
            $st->execute([(int)$u['id'],$class_id,$subject_id,$lesson_date,$lesson_unit,$topic?:null,now_iso()]);
            $lesson_id=(int)$pdo->lastInsertId();
          }catch(PDOException $e){
            $st=$pdo->prepare("SELECT id FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date=? AND lesson_unit=? LIMIT 1");
            $st->execute([$class_id,$subject_id,$lesson_date,$lesson_unit]);
            $lesson_id=(int)($st->fetchColumn() ?: 0);
            if(!$lesson_id) throw $e;
          }
        }

        $st=$pdo->prepare("SELECT * FROM lesson_sessions WHERE id=?");
        $st->execute([$lesson_id]);
        $lesson=$st->fetch();
      }
    } elseif($chosen_lesson_id){
      $st=$pdo->prepare("SELECT * FROM lesson_sessions WHERE id=? AND class_id=? AND subject_id=?");
      $st->execute([$chosen_lesson_id,$class_id,$subject_id]);
      $lesson=$st->fetch();
      if(!$lesson){ $err='Ausgewählte Stunde ist ungültig.'; }
      else $lesson_id=(int)$lesson['id'];
    }
  }

  $date=$_POST['event_date'] ?? ($lesson['lesson_date'] ?? date('Y-m-d'));
  $reason_id=(int)($_POST['reason_option_id'] ?? 0);
  $impact_id=(int)($_POST['impact_option_id'] ?? 0);

  $social_id=(int)($_POST['social_form_option_id'] ?? 0);
  $phase_id=(int)($_POST['phase_option_id'] ?? 0);
  $hw_id=(int)($_POST['homework_option_id'] ?? 0);

  $reason_text=trim($_POST['reason_text'] ?? '');
  $note=trim($_POST['note'] ?? '');

  $sel_student_ids=$_POST['student_ids'] ?? [];
  $sel_criteria_ids=$_POST['criteria_ids'] ?? [];
  $sel_perf_ids=$_POST['performance_option_ids'] ?? [];
  $sel_group_ids=$_POST['group_option_ids'] ?? [];
  $selected_preset_id=(int)($_POST['preset_id'] ?? 0);
  $preset_name_input=trim((string)($_POST['preset_name'] ?? ''));

  if($simple_entry_mode){
    $social_id = 0;
    $phase_id = 0;
    $hw_id = 0;
    $sel_criteria_ids = [];
    $note = '';
  }

  if($form_action==='apply_preset'){
    $preset=$selected_preset_id>0 ? find_participation_preset($pdo,(int)$u['id'],$selected_preset_id) : null;
    if($preset && (int)$preset['subject_id']!==$subject_id) $preset=null;
    if($selected_preset_id>0 && !$preset){
      $err='Ausgewähltes Preset wurde nicht gefunden.';
    } else {
      apply_participation_preset_to_request($preset['payload'] ?? []);
      $reason_id=(int)($_POST['reason_option_id'] ?? 0);
      $impact_id=(int)($_POST['impact_option_id'] ?? 0);
      $social_id=(int)($_POST['social_form_option_id'] ?? 0);
      $phase_id=(int)($_POST['phase_option_id'] ?? 0);
      $hw_id=(int)($_POST['homework_option_id'] ?? 0);
      $reason_text=trim($_POST['reason_text'] ?? '');
      $note=trim($_POST['note'] ?? '');
      $sel_student_ids=$_POST['student_ids'] ?? [];
      $sel_criteria_ids=$_POST['criteria_ids'] ?? [];
      $sel_perf_ids=$_POST['performance_option_ids'] ?? [];
      $sel_group_ids=$_POST['group_option_ids'] ?? [];
      if(!$sel_group_ids && $preset){
        $preset_reason_label='';
        foreach($reasons as $r){ if((int)$r['id']===$reason_id){ $preset_reason_label=(string)$r['label']; break; } }
        $sel_group_ids=participation_observation_group_ids_from_payload(
          $groups,
          $criteria,
          (array)($preset['payload'] ?? []),
          $preset_reason_label
        );
        $_POST['group_option_ids']=$sel_group_ids;
      }
      $preset_name_input=$preset ? (string)$preset['name'] : '';
      if($preset) $msg='Preset angewendet: '.$preset_name_input;
    }
  }

  if($form_action==='save_preset' || $form_action==='update_preset'){
    $update_preset_id=0;
    if($form_action==='update_preset'){
      $existing_preset=$selected_preset_id>0 ? find_participation_preset($pdo,(int)$u['id'],$selected_preset_id) : null;
      if($existing_preset && (int)$existing_preset['subject_id']!==$subject_id) $existing_preset=null;
      if(!$existing_preset){
        $err='Bitte zuerst ein vorhandenes Preset auswählen.';
      } else {
        $update_preset_id=(int)$existing_preset['id'];
        if($preset_name_input==='') $preset_name_input=(string)$existing_preset['name'];
      }
    }
    if($err===''){
      if($preset_name_input===''){
        $err='Bitte einen Namen für das Preset eingeben.';
      } else {
        $payload=participation_preset_payload_from_request($_POST);
        $preset_name_input=participation_preset_name($preset_name_input);
        $selected_preset_id=save_participation_preset($pdo,(int)$u['id'],$subject_id,$preset_name_input,$payload,$update_preset_id);
        $presets=load_participation_presets($pdo,(int)$u['id'],$subject_id);
        $msg=($form_action==='update_preset') ? 'Preset aktualisiert: '.$preset_name_input : 'Preset gespeichert: '.$preset_name_input;
        app_log('info',($form_action==='update_preset') ? 'participation preset updated' : 'participation preset saved',[
          'teacher_id'=>(int)$u['id'],
          'subject_id'=>$subject_id,
          'preset_id'=>$selected_preset_id,
          'preset_name'=>$preset_name_input,
        ]);
      }
    }
  }

  // resolve labels (snapshot)
  $reason_label='';
  foreach($reasons as $r){ if((int)$r['id']===$reason_id){$reason_label=$r['label'];break;} }
  $impact_label=''; foreach($impacts as $r){ if((int)$r['id']===$impact_id){$impact_label=$r['label'];break;} }
  $sel_group_ids=array_values(array_unique(array_filter(array_map('intval',(array)$sel_group_ids), fn($v)=>$v>0)));

  if($form_action==='save_entry' && $err===''){
    if(!$sel_student_ids) $err='Bitte mindestens eine/n Schüler:in auswählen.';
    elseif(!$reason_id) $err='Bitte Grund wählen.';
    elseif(!$impact_id) $err='Bitte Eindruck/Relevanz wählen.';
    elseif(!$sel_group_ids) $err='Bitte mindestens einen Beobachtungsbereich wählen.';
    elseif(count($sel_group_ids)>2) $err='Bitte höchstens zwei Beobachtungsbereiche wählen.';
    elseif($simple_entry_mode && $reason_text==='') $err='Bitte eine kurze Beobachtung eingeben.';
  }

  if($form_action==='save_entry' && $err!==''){
    app_log('warn','participation_new validation failed',[
      'teacher_id'=>(int)$u['id'],
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
      'lesson_id'=>$lesson_id,
      'err'=>$err,
    ]);
  }

  if($form_action==='save_entry' && $err===''){
    $pdo->beginTransaction();
    try{
      $ins=$pdo->prepare("INSERT INTO participation_events
        (student_id,teacher_id,class_id,subject_id,lesson_id,event_date,reason_option_id,reason_label,impact_option_id,rating,
         social_form_option_id,phase_option_id,homework_option_id,reason_text,note,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $linkC=$pdo->prepare("INSERT IGNORE INTO participation_event_criteria (event_id,criteria_id) VALUES (?,?)");
      $linkO=$pdo->prepare("INSERT IGNORE INTO participation_event_options (event_id,option_id) VALUES (?,?)");

      foreach($sel_student_ids as $sid){
        $ins->execute([
          (int)$sid,(int)$u['id'],$class_id,$subject_id,$lesson_id?:null,$date,
          $reason_id,$reason_label,$impact_id,$impact_label,
          $social_id?:null,$phase_id?:null,$hw_id?:null,
          $reason_text?:null,$note?:null,now_iso()
        ]);
        $eid=(int)$pdo->lastInsertId();
        foreach($sel_criteria_ids as $cid){ $linkC->execute([$eid,(int)$cid]); }
        foreach($sel_group_ids as $oid){ $linkO->execute([$eid,(int)$oid]); }
        foreach($sel_perf_ids as $oid){ $linkO->execute([$eid,(int)$oid]); }

        // LBV auto-tags (a-e) based on context; editable later in participation_edit.php
        $phase_label=''; foreach($phases as $p){ if((int)$p['id']===$phase_id){$phase_label=$p['label'];break;} }
        $hw_label=''; foreach($homeworks as $hwo){ if((int)$hwo['id']===$hw_id){$hw_label=$hwo['label'];break;} }

        // criteria labels
        $crit_labels=[];
        if($sel_criteria_ids){
          $in='('.implode(',',array_fill(0,count($sel_criteria_ids),'?')).')';
          $stc=$pdo->prepare("SELECT label FROM criteria WHERE id IN $in");
          $stc->execute(array_map('intval',$sel_criteria_ids));
          foreach($stc->fetchAll() as $rr){ $crit_labels[]=$rr['label']; }
        }
        foreach(participation_option_labels_by_ids($groups,$sel_group_ids) as $group_label){ $crit_labels[]=$group_label; }

        $perf_labels=[];
        if($sel_perf_ids){
          $in='('.implode(',',array_fill(0,count($sel_perf_ids),'?')).')';
          $stp=$pdo->prepare("SELECT label FROM participation_options WHERE id IN $in");
          $stp->execute(array_map('intval',$sel_perf_ids));
          foreach($stp->fetchAll() as $rr){ $perf_labels[]=$rr['label']; }
        }

        $tags=lbvo_auto_tags($reason_label,$phase_label,$hw_label,$crit_labels,$perf_labels,$note,$reason_text);
        $insT=$pdo->prepare("INSERT IGNORE INTO participation_event_lbvo (event_id,tag,source,created_at) VALUES (?,?, 'auto', ?)");
        foreach($tags as $t){ $insT->execute([$eid,$t,now_iso()]); }
      }

      $pdo->commit();
      emit_event('participation_recorded',[
        'class_id'=>$class_id,'subject_id'=>$subject_id,'count'=>count($sel_student_ids),
        'reason'=>$reason_label,'impact'=>$impact_label,'lesson_id'=>$lesson_id?:null
      ]);
      app_log('info','participation_new saved',[
        'teacher_id'=>(int)$u['id'],
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'lesson_id'=>$lesson_id?:null,
        'count'=>count($sel_student_ids),
      ]);
      $return_query=[
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'saved_count'=>count($sel_student_ids),
      ];
      if($lesson_id) $return_query['lesson_id']=$lesson_id;
      if($slot_map_str!=='') $return_query['slot_map']=$slot_map_str;
      if($notice_code!=='') $return_query['msg']=$notice_code;
      header('Location: '.$bp.'/teacher/participation_new.php?'.http_build_query($return_query));
      exit;
    }catch(Exception $e){
      $pdo->rollBack();
      $err='Fehler beim Speichern: '.$e->getMessage();
      app_log('error','participation_new save failed',[
        'teacher_id'=>(int)$u['id'],
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'lesson_id'=>$lesson_id?:null,
        'ex'=>get_class($e),
        'msg'=>$e->getMessage(),
      ]);
    }
  }
}

// Default checked values (GET prefill OR POST)
$prefill_student_ids = [];
if($pre_student_id) $prefill_student_ids[]=$pre_student_id;
foreach($pre_student_ids as $x){ $prefill_student_ids[]=$x; }
$checked_students = ($_SERVER['REQUEST_METHOD']==='POST') ? array_map('intval',(array)$sel_student_ids) : $prefill_student_ids;
$checked_criteria = ($_SERVER['REQUEST_METHOD']==='POST') ? array_map('intval',(array)$sel_criteria_ids) : [];
$checked_perf     = ($_SERVER['REQUEST_METHOD']==='POST') ? array_map('intval',(array)$sel_perf_ids) : [];
$checked_groups   = ($_SERVER['REQUEST_METHOD']==='POST') ? array_map('intval',(array)$sel_group_ids) : [];
$participation_form_dirty_initial = ($_SERVER['REQUEST_METHOD']==='POST');
$compact_forms = compact_entry_forms_enabled($u);
$hours_section_open = $lesson_id>0 || ((string)($_POST['create_lesson'] ?? '') === '1') || (isset($_POST['lesson_id']) && (int)$_POST['lesson_id']>0);
$preset_section_open = $selected_preset_id>0 || $preset_name_input!=='';
$performance_section_open = !empty($checked_perf);
$context_section_open = (int)($_POST['social_form_option_id'] ?? 0)>0
  || (int)($_POST['phase_option_id'] ?? 0)>0
  || (int)($_POST['homework_option_id'] ?? 0)>0
  || trim((string)($_POST['note'] ?? ''))!=='';
$group_section_open = !empty($checked_groups) || $_SERVER['REQUEST_METHOD']==='POST';
$details_section_open = !empty($checked_criteria);
$students_section_open = !empty($checked_students);
$current_reason_id=(int)($_POST['reason_option_id'] ?? 0);
$current_phase_id=(int)($_POST['phase_option_id'] ?? 0);
$current_impact_id=(int)($_POST['impact_option_id'] ?? 0);
$current_suggested_mode=participation_pedagogical_mode_suggestion($reasons,$phases,$current_reason_id,$current_phase_id);
$current_impact_label='';
foreach($impacts as $impactOption){
  if((int)($impactOption['id'] ?? 0)===$current_impact_id){
    $current_impact_label=(string)($impactOption['label'] ?? '');
    break;
  }
}
$current_hint=participation_pedagogical_hint($current_suggested_mode, participation_impact_kind_from_label($current_impact_label));

render_header('Mitarbeit',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Mitarbeit erfassen</h1>
  <div class="muted">Klasse: <b><?php echo h($class['name']); ?></b> · Fach: <b><?php echo h($subject['code']); ?></b>
    <?php if($lesson): ?> · Stunde: <b><?php echo h($lesson['lesson_date']); ?><?php echo $lesson['lesson_unit']?(' · UE '.$lesson['lesson_unit']):''; ?></b><?php endif; ?>
  </div>
  <?php if($simple_entry_mode): ?>
    <div class="card" style="margin-top:10px;border-color:#cfe5ff;background:#eef6ff">
      <b>Vereinfachte Eingabe aktiv</b><br>
      Es wird nur die Alltagsebene der Mitarbeitserfassung angezeigt: Datum, Grund/Anlass, Eindruck/Relevanz, Beobachtungsbereich, Leistungsart, kurze Beobachtung und Schüler:innen. Die fachliche Tiefe mit Unterrichtskontext und Detailkriterien bleibt ausgeblendet.
    </div>
  <?php endif; ?>
  <?php if(legal_hints_enabled($u)): ?>
    <details class="accordion" style="margin-top:10px">
      <summary>
        <span class="acc-title" title="<?php echo h(participation_summary_tooltip()); ?>" style="cursor:help"><?php echo h(participation_summary_ref()); ?></span>
      </summary>
      <div class="acc-body">
        <?php echo participation_summary(); ?>
      </div>
    </details>
  <?php endif; ?>

  <?php if($notice_code==='exists'): ?><div class="flash info">Diese Stunde existiert bereits – du bearbeitest den vorhandenen Slot.</div><?php endif; ?>

  <?php if($notice_code==='multi' && $slot_links): ?>
    <div class="card" style="margin-top:10px;border-color:#cfe5ff;background:#eef6ff">
      Mehrfach-UE angelegt/gefunden. Schnell wechseln:
      <span style="display:inline-flex;gap:8px;flex-wrap:wrap;margin-left:8px;vertical-align:middle">
        <?php foreach($slot_links as $ue=>$id): ?>
          <a class="btn small <?php echo ((int)$lesson_id===(int)$id)?'':'secondary'; ?>" href="<?php echo h($bp); ?>/teacher/participation_new.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'lesson_id'=>$id,'slot_map'=>$slot_map_str,'msg'=>'multi'])); ?>">UE <?php echo (int)$ue; ?></a>
        <?php endforeach; ?>
      </span>
    </div>
  <?php endif; ?>

  <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

  <?php
    // Allow deleting a lesson slot only when there are no participation entries linked.
    $can_delete_lesson=false;
    if($lesson_id){
      $st=$pdo->prepare("SELECT COUNT(*) FROM participation_events WHERE lesson_id=?");
      $st->execute([(int)$lesson_id]);
      $can_delete_lesson=((int)$st->fetchColumn()===0);
    }
  ?>

  <?php if($lesson_id && $lesson && $can_delete_lesson): ?>
    <div class="card" style="margin-top:10px;border-color:#ffe3b0;background:#fff6e6">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
          <b>Hinweis:</b> Zu dieser Stunde sind noch keine Einträge gespeichert.
          Du kannst sie daher löschen (falls du dich vertippt hast).
        </div>
        <form method="post" action="<?php echo h($bp); ?>/teacher/lesson_delete.php" onsubmit="return confirm('Diese Stunde wirklich löschen?');" style="margin:0">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="lesson_id" value="<?php echo (int)$lesson_id; ?>">
          <input type="hidden" name="return" value="<?php echo h($bp.'/teacher/lesson.php?msg=deleted'); ?>">
          <button class="btn danger small">Stunde löschen</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" id="participationForm" style="margin-top:10px" <?php echo dirty_form_attrs($participation_form_dirty_initial); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="auto_action" id="autoAction" value="">
    <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">

    <?php if($lesson_id): ?>
      <input type="hidden" name="fixed_lesson_id" value="<?php echo (int)$lesson_id; ?>">
    <?php endif; ?>

    <?php if(!$simple_entry_mode): ?>
    <?php accordion_section_start($compact_forms, 'Stundenkontext (optional)', $hours_section_open, 'margin-top:12px', '', 'contrast-panel section-hours'); ?>
    <fieldset class="multi-field contrast-panel section-hours" style="<?php echo $compact_forms?'margin-top:0':'margin-top:12px'; ?>">
      <?php if(!$compact_forms): ?><legend>Stundenkontext (optional)</legend><?php endif; ?>

      <?php if($lesson_id && $lesson): ?>
        <div class="muted">Verknüpft mit: <b><?php echo h($lesson['lesson_date']); ?><?php echo $lesson['lesson_unit']?(' · UE '.$lesson['lesson_unit']):''; ?><?php echo $lesson['topic']?(' · '.$lesson['topic']):''; ?></b></div>
        <div class="small muted" style="margin-top:6px">Tipp: Stunde (Thema/UE) verwaltest du unter <a href="<?php echo h($bp); ?>/teacher/lesson.php?lesson_id=<?php echo (int)$lesson_id; ?>">Stundenerfassung</a>.</div>
      <?php else: ?>
        <div class="row" style="align-items:end">
          <div style="flex:1">
            <label class="muted">Bestehende Stunde auswählen</label>
            <select class="input" name="lesson_id" id="lessonSelect">
              <option value="0">– keine Verknüpfung –</option>
              <?php foreach($recent_lessons as $ls): ?>
                <?php
                  $txt=$ls['lesson_date'];
                  if($ls['lesson_unit']) $txt.=' · UE '.$ls['lesson_unit'];
                  if($ls['topic']) $txt.=' · '.$ls['topic'];
                ?>
                <option value="<?php echo (int)$ls['id']; ?>" data-date="<?php echo h($ls['lesson_date']); ?>" <?php echo (isset($_POST['lesson_id']) && (int)$_POST['lesson_id']===(int)$ls['id'])?'selected':''; ?>><?php echo h($txt); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small muted" style="margin-top:6px">Oder du legst hier direkt eine neue Stunde an (ohne extra Seite).</div>
          </div>
          <div style="flex:0 0 auto">
            <label class="muted" style="display:flex;gap:10px;align-items:center">
              <input type="checkbox" name="create_lesson" value="1" id="createLessonToggle" <?php echo (isset($_POST['create_lesson']) && (string)$_POST['create_lesson']==='1')?'checked':''; ?>>
              <span><b>Neue Stunde anlegen</b></span>
            </label>
          </div>
        </div>

        <div id="createLessonFields" style="margin-top:10px;<?php echo (isset($_POST['create_lesson']) && (string)$_POST['create_lesson']==='1')?'':'display:none'; ?>">
          <div class="row" style="align-items:end">
            <div>
              <label class="muted">Datum</label>
              <input class="input" type="date" name="lesson_date" value="<?php echo h($_POST['lesson_date'] ?? date('Y-m-d')); ?>">
            </div>
            <div>
              <label class="muted">UE/Stunde</label>
              <input class="input" type="number" min="1" max="12" name="lesson_unit" id="lessonUnit" placeholder="z.B. 3" value="<?php echo h($_POST['lesson_unit'] ?? ''); ?>">
            </div>
            <div style="flex:1">
              <label class="muted">Thema (optional)</label>
              <input class="input" name="topic" placeholder="z.B. Arbeitsvertrag" value="<?php echo h($_POST['topic'] ?? ''); ?>">
            </div>
          </div>
        </div>
      <?php endif; ?>
    </fieldset>
    <?php accordion_section_end($compact_forms); ?>
    <?php endif; ?>

    <?php if(!$simple_entry_mode): ?>
    <?php if(!$compact_forms): ?><div style="height:12px"></div><?php endif; ?>
    <?php accordion_section_start($compact_forms, 'Preset (ohne Stunde, Datum und Schüler:innen)', $preset_section_open, 'margin-top:12px', '', 'contrast-panel section-preset'); ?>
    <fieldset class="multi-field contrast-panel section-preset" style="<?php echo $compact_forms?'margin-top:0':''; ?>">
      <?php if(!$compact_forms): ?><legend>Preset (ohne Stunde, Datum und Schüler:innen)</legend><?php endif; ?>
      <div class="row" style="align-items:end">
        <div style="flex:1 1 280px">
          <label class="muted">Gespeichertes Preset</label>
          <select class="input" name="preset_id" id="presetSelect">
            <option value="0">– Preset wählen –</option>
            <?php foreach($presets as $preset): ?>
              <option value="<?php echo (int)$preset['id']; ?>" <?php echo $selected_preset_id===(int)$preset['id']?'selected':''; ?>>
                <?php echo h($preset['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:0 0 auto">
          <label class="muted">&nbsp;</label>
          <button class="btn secondary" name="action" value="apply_preset" formnovalidate>Preset anwenden</button>
        </div>
      </div>
      <div class="preset-save-panel">
        <div style="flex:1 1 260px">
          <label class="muted">Presetname</label>
          <input class="input" name="preset_name" value="<?php echo h($preset_name_input); ?>" maxlength="120" placeholder="z.B. Reflexion mit Begriffen">
          <div class="small muted" style="margin-top:5px">Erst Grund, Eindruck, Beobachtungsbereich, Leistungsart und Details ausfüllen oder ändern – danach hier als Preset speichern.</div>
        </div>
        <div class="preset-save-actions">
          <button class="btn preset-save-new" name="action" value="save_preset" formnovalidate>Als neues Preset speichern</button>
          <button class="btn secondary preset-update-existing" name="action" value="update_preset" formnovalidate <?php echo $selected_preset_id>0?'':'disabled'; ?> title="<?php echo $selected_preset_id>0?'Aktualisiert das ausgewählte Preset mit der aktuellen Formularauswahl.':'Bitte zuerst ein Preset auswählen und anwenden.'; ?>">
            Ausgewähltes Preset aktualisieren
          </button>
        </div>
      </div>
      <div class="small muted" style="margin-top:6px">Ein Preset füllt typische Felder erst nach Klick auf „Preset anwenden“. Gespeichert werden Grund, Eindruck, Beobachtungsbereich, Leistungsart, Sozialform, Unterrichtsphase, Hausübung, Kurzbeschreibung, Notiz und Kriterien. Stunde, Datum und Schüler:innen bleiben bewusst außen vor. Presets gelten pro Fach, nicht pro Klasse.</div>
    </fieldset>
    <?php accordion_section_end($compact_forms); ?>
    <?php endif; ?>

    <div class="contrast-block section-core">
    <div class="row">
      <div>
        <label class="muted">Datum</label>
        <input class="input" type="date" name="event_date" id="eventDate" value="<?php echo h($_POST['event_date'] ?? ($lesson['lesson_date'] ?? date('Y-m-d'))); ?>">
      </div>

      <div>
        <label class="muted">Grund/Anlass</label>
        <select class="input" name="reason_option_id" id="reasonSelect" required>
          <option value="0">Bitte wählen…</option>
          <?php foreach($reasons as $o): ?>
            <?php
              $suggest_ids=participation_observation_group_ids_from_reason_and_criteria($groups,(string)$o['label'],[],[],2);
              $pedagogical_suggest=participation_reason_mode_normalize((string)($o['pedagogical_hint_mode'] ?? 'auto'));
              if($pedagogical_suggest==='auto'){
                $pedagogical_suggest=participation_pedagogical_mode_suggestion_from_labels((string)$o['label'], '');
              }
            ?>
            <option value="<?php echo (int)$o['id']; ?>" data-auto-suggest="<?php echo h(implode(',',$suggest_ids)); ?>" data-suggest-mode="<?php echo h($pedagogical_suggest); ?>" <?php echo (isset($_POST['reason_option_id']) && (int)$_POST['reason_option_id']===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
          <?php endforeach; ?>
        </select>
        <div class="small muted hint">Bezeichnungen anpassen: <a href="<?php echo h($bp); ?>/teacher/options.php?type=reason&amp;subject_id=<?php echo (int)$subject_id; ?>">Picklisten</a></div>
      </div>

      <div>
        <label class="muted">Eindruck/Relevanz</label>
        <select class="input" name="impact_option_id" id="impactSelect" required>
          <option value="0">Bitte wählen…</option>
          <?php foreach($impacts as $o): ?>
            <?php $impact_kind=participation_impact_kind_from_label((string)$o['label']); ?>
            <option value="<?php echo (int)$o['id']; ?>" data-impact-kind="<?php echo h($impact_kind); ?>" <?php echo (isset($_POST['impact_option_id']) && (int)$_POST['impact_option_id']===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      </div>
      <div id="pedagogicalHintBox" class="flash <?php echo $current_hint['level']==='error' ? 'error' : 'info'; ?>" style="margin-top:10px">
        <?php echo h($current_hint['text']); ?>
      </div>
    </div>

    <?php if(!$compact_forms): ?><div style="height:12px"></div><?php endif; ?>
    <?php accordion_section_start($compact_forms, 'Leistungsart (Mehrfach)', $performance_section_open, 'margin-top:12px', '', 'contrast-panel section-performance'); ?>
    <fieldset class="multi-field contrast-panel section-performance" style="<?php echo $compact_forms?'margin-top:0':''; ?>">
      <?php if(!$compact_forms): ?><legend>Leistungsart (Mehrfach)</legend><?php endif; ?>
      <div class="multi-grid">
        <?php foreach($perfs as $o): ?>
          <label class="multi-item">
            <input type="checkbox" name="performance_option_ids[]" value="<?php echo (int)$o['id']; ?>" <?php echo in_array((int)$o['id'],$checked_perf,true)?'checked':''; ?>>
            <span><?php echo h($o['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="small muted hint">Bezeichnungen anpassen: <a href="<?php echo h($bp); ?>/teacher/options.php?type=performance&amp;subject_id=<?php echo (int)$subject_id; ?>">Picklisten</a></div>
      <div class="small muted hint">Die Leistungsart beschreibt, welche Form der Leistung beobachtet wurde. Die Auswahl erscheint später in Auswertung und PDF, löst aber keine Beurteilung aus.</div>
    </fieldset>
    <?php accordion_section_end($compact_forms); ?>

    <?php if(!$simple_entry_mode): ?>
    <div style="height:12px"></div>
    <details class="accordion contrast-panel section-context" <?php echo $context_section_open?'open':''; ?> style="margin-top:12px">
      <summary><span class="acc-title">Unterrichtskontext</span></summary>
      <div class="acc-body">
        <fieldset class="multi-field contrast-panel section-context" style="margin-top:0">
          <legend>Unterrichtskontext</legend>
          <div class="row">
            <div>
              <label class="muted">Sozialform</label>
              <select class="input" name="social_form_option_id">
                <option value="0">–</option>
                <?php foreach($socials as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo (isset($_POST['social_form_option_id']) && (int)$_POST['social_form_option_id']===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="muted">Unterrichtsphase</label>
              <select class="input" name="phase_option_id" id="phaseSelect">
                <option value="0">–</option>
                <?php foreach($phases as $o): ?>
                  <?php $phase_suggest=participation_pedagogical_mode_suggestion_from_labels('', (string)$o['label']); ?>
                  <option value="<?php echo (int)$o['id']; ?>" data-suggest-mode="<?php echo h($phase_suggest); ?>" <?php echo (isset($_POST['phase_option_id']) && (int)$_POST['phase_option_id']===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="muted">Hausübung-Status</label>
              <select class="input" name="homework_option_id">
                <option value="0">–</option>
                <?php foreach($homeworks as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo (isset($_POST['homework_option_id']) && (int)$_POST['homework_option_id']===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div style="height:12px"></div>
          <label class="muted">Notiz (optional)</label>
          <textarea class="input" name="note" rows="3" placeholder="1–2 Sätze als Beleg/Beobachtung."><?php echo h($_POST['note'] ?? ''); ?></textarea>
        </fieldset>
      </div>
    </details>
    <?php endif; ?>

    <div class="contrast-block section-context">
      <label class="muted">Kurze Beobachtung / Anlass</label>
      <textarea class="input" name="reason_text" rows="3" <?php echo $simple_entry_mode?'required':''; ?> placeholder="z.B. Fallbeispiel nachvollziehbar erklärt."><?php echo h($_POST['reason_text'] ?? ''); ?></textarea>
      <div class="small muted" style="margin-top:6px">
        <?php if($simple_entry_mode): ?>
          Die vereinfachte Eingabe dokumentiert die laufende Beobachtung mit Anlass, Beobachtungsbereich und Leistungsart. Die fachliche Tiefe bleibt verborgen, damit die Erfassung im Unterricht schneller bleibt.
        <?php else: ?>
          Kurz und alltagsnah dokumentieren. Wenn du fachlich genauer festhalten möchtest, nutze darunter die optionalen fachlichen Details.
        <?php endif; ?>
      </div>
    </div>

    <?php if(!$compact_forms): ?><div style="height:12px"></div><?php endif; ?>
    <?php accordion_section_start($compact_forms, 'Beobachtungsbereich', $group_section_open, 'margin-top:12px', '', 'contrast-panel section-context'); ?>
    <fieldset class="multi-field contrast-panel section-context" style="<?php echo $compact_forms?'margin-top:0':''; ?>">
      <?php if(!$compact_forms): ?><legend>Beobachtungsbereich</legend><?php endif; ?>
      <div class="multi-grid">
        <?php foreach($groups as $o): ?>
          <label class="multi-item">
            <input class="groupCb" type="checkbox" name="group_option_ids[]" value="<?php echo (int)$o['id']; ?>" <?php echo in_array((int)$o['id'],$checked_groups,true)?'checked':''; ?>>
            <span><?php echo h($o['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="small muted" style="margin-top:6px">
        <?php if($simple_entry_mode): ?>
          Wähle einen Hauptbereich, bei Bedarf zusätzlich einen zweiten. Der Beobachtungsbereich hilft später bei der zusammenfassenden Auswertung.
        <?php else: ?>
          Wähle einen Hauptbereich, bei Bedarf zusätzlich einen zweiten. Die feineren fachlichen Kriterien bleiben unten verfügbar und sind nur bei Bedarf nötig.
        <?php endif; ?>
      </div>
    </fieldset>
    <?php accordion_section_end($compact_forms); ?>

    <?php if(!$simple_entry_mode): ?>
    <div style="height:14px"></div>
    <?php $criteria_total=count($criteria); $criteria_selected_total=count($checked_criteria); ?>
    <details class="accordion contrast-panel section-criteria" <?php echo $details_section_open?'open':''; ?>>
      <summary>
        <span class="acc-title">Kriterien (fachspezifisch / LBV-orientiert)</span>
        <span class="acc-meta"><?php if($criteria_total>0): ?><span class="badge"><?php echo (int)$criteria_selected_total; ?>/<?php echo (int)$criteria_total; ?></span><?php endif; ?></span>
      </summary>
      <div class="acc-body">
        <fieldset class="multi-field contrast-panel section-criteria" style="margin-top:0">
          <legend>Kriterien (fachspezifisch / LBV-orientiert)</legend>
          <div class="muted">Die Detailkriterien bleiben erhalten, sind aber nicht mehr die Haupteingabe. Sie dienen der fachlichen Präzisierung und erscheinen später zusammengefasst in Auswertung und Abschlussbeurteilung. Eigene Kriterien-Sets kannst du unter <a href="<?php echo h($bp); ?>/teacher/criteria.php">Kriterien</a> anlegen.</div>
          <div style="height:10px"></div>
          <?php if(!$criteria): ?>
            <div class="muted">Noch keine Kriterien vorhanden. Lege ein Set an und füge Vorschläge ein.</div>
          <?php else: ?>
            <?php
              $criteria_by_cat=[];
              foreach($criteria as $c){
                $cat=trim((string)($c['category'] ?? ''));
                if($cat==='') $cat='Allgemein';
                if(!isset($criteria_by_cat[$cat])) $criteria_by_cat[$cat]=[];
                $criteria_by_cat[$cat][]=$c;
              }
            ?>
            <div class="criteria-accordion">
              <?php foreach($criteria_by_cat as $cat=>$list): ?>
                <?php
                  $sel=0;
                  foreach($list as $cc){ if(in_array((int)$cc['id'],$checked_criteria,true)) $sel++; }
                ?>
                <details class="accordion" <?php echo $sel>0?'open':''; ?>>
                  <summary>
                    <span class="acc-title"><?php echo h($cat); ?></span>
                    <span class="acc-meta">
                      <span class="badge"><?php echo (int)$sel; ?>/<?php echo (int)count($list); ?></span>
                    </span>
                  </summary>
                  <div class="acc-body">
                    <div class="multi-grid">
                      <?php foreach($list as $c): ?>
                        <label class="multi-item">
                          <input type="checkbox" name="criteria_ids[]" value="<?php echo (int)$c['id']; ?>" <?php echo in_array((int)$c['id'],$checked_criteria,true)?'checked':''; ?>>
                          <span><?php echo h($c['label']); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      </div>
    </details>
    <?php endif; ?>

    <?php if(!$compact_forms): ?><div style="height:14px"></div><h2>Schüler:innen auswählen (Mehrfach)</h2><?php endif; ?>
    <?php accordion_section_start($compact_forms, 'Schüler:innen auswählen (Mehrfach)', $students_section_open, 'margin-top:14px', '', 'contrast-panel section-students'); ?>
    <fieldset class="multi-field contrast-panel section-students" style="<?php echo $compact_forms?'margin-top:0':''; ?>">
      <?php if(!$compact_forms): ?><legend>Auswahl</legend><?php endif; ?>
      <div class="muted">Tipp: meistens 3–10 Schüler:innen pro Woche auswählen.</div>

      <?php if($quick): ?>
        <div style="height:10px"></div>
        <div class="muted"><b>Quick-Pick:</b> Schüler:innen mit den wenigsten oder keinen bisherigen Mitarbeitsbewertungen</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
          <?php foreach($quick as $q): ?>
            <button type="button" class="btn small secondary" onclick="toggleStudent(<?php echo (int)$q['student_id']; ?>)"><?php echo h($q['last_name'].', '.$q['first_name']); ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if($studentGroups): ?>
        <div style="height:10px"></div>
        <div class="muted"><b>Gruppen:</b> Eine Gruppe auswählen und danach bei Bedarf einzelne Schüler:innen ergänzen oder entfernen.</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
          <?php foreach($studentGroups as $studentGroup): ?>
            <button
              type="button"
              class="btn small secondary"
              title="<?php echo h(trim((string)($studentGroup['note'] ?? '')) !== '' ? (string)$studentGroup['note'] : $studentGroup['name']); ?>"
              onclick='applyStudentGroup(<?php echo json_encode(array_map("intval",(array)($studentGroup["member_ids"] ?? []))); ?>)'>
              <?php echo h($studentGroup['name']); ?> (<?php echo (int)($studentGroup['member_count'] ?? 0); ?>)
            </button>
          <?php endforeach; ?>
          <button type="button" class="btn small utility-clear" onclick="clearStudentSelection()">Auswahl leeren</button>
          <a class="btn small utility-manage" href="<?php echo h($bp); ?>/teacher/student_groups.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id])); ?>">Gruppen verwalten</a>
        </div>
      <?php endif; ?>

      <div style="height:10px"></div>
      <label class="muted">Suche</label>
      <input class="input" id="studentSearch" placeholder="Name tippen…" oninput="filterStudents()">

      <div style="height:10px"></div>
      <div class="multi-grid" id="studentGrid">
        <?php foreach($students as $s): ?>
          <?php $sid=(int)$s['id']; ?>
          <label class="multi-item studentItem" data-name="<?php echo h(strtolower($s['last_name'].' '.$s['first_name'])); ?>">
            <input class="studentCb" id="stu_<?php echo $sid; ?>" type="checkbox" name="student_ids[]" value="<?php echo $sid; ?>" <?php echo in_array($sid,$checked_students,true)?'checked':''; ?>>
            <span><?php echo h($s['last_name'].', '.$s['first_name']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php accordion_section_end($compact_forms); ?>

    <div class="participation-save-bar">
      <div>
        <strong>Mitarbeit speichern</strong>
        <div class="small muted">Bitte vor dem Speichern Datum, Anlass, Eindruck, Beobachtungsbereich und Schüler:innen prüfen.</div>
      </div>
      <div class="row" style="gap:8px;flex-wrap:wrap;justify-content:flex-end">
        <button class="btn" name="action" value="save_entry">Mitarbeit speichern</button>
        <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
        <?php if($lesson_id): ?><a class="btn secondary" href="<?php echo h($bp); ?>/teacher/lesson.php?lesson_id=<?php echo (int)$lesson_id; ?>">Zur Stunde</a><?php endif; ?>
      </div>
    </div>
  </form>

  <script>
  function filterStudents(){
    const q=(document.getElementById('studentSearch').value||'').toLowerCase().trim();
    document.querySelectorAll('.studentItem').forEach(el=>{
      const name=(el.getAttribute('data-name')||'');
      el.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
  }
  function toggleStudent(id){
    const cb=document.getElementById('stu_'+id);
    if(cb){ cb.checked=!cb.checked; }
  }
  function applyStudentGroup(ids){
    const selected=new Set((ids||[]).map(v=>parseInt(v,10)).filter(v=>Number.isInteger(v) && v>0));
    document.querySelectorAll('.studentCb').forEach(cb=>{
      cb.checked=selected.has(parseInt(cb.value,10));
    });
  }
  function clearStudentSelection(){
    document.querySelectorAll('.studentCb').forEach(cb=>{ cb.checked=false; });
  }
  function autoApplyPreset(){
    const form=document.getElementById('participationForm');
    const action=document.getElementById('autoAction');
    if(!form || !action) return;
    action.value='apply_preset';
    if(window.CoolGradesDirtyForms && typeof window.CoolGradesDirtyForms.suppressNextNavigation === 'function'){
      window.CoolGradesDirtyForms.suppressNextNavigation();
    }
    form.submit();
  }

  function selectedGroupCheckboxes(){
    return Array.from(document.querySelectorAll('.groupCb:checked'));
  }

  function suggestedPedagogicalMode(){
    const reason=document.getElementById('reasonSelect');
    const phase=document.getElementById('phaseSelect');
    let suggested='formative';
    if(reason){
      const opt=reason.options[reason.selectedIndex];
      suggested=((opt && opt.getAttribute('data-suggest-mode')) || suggested);
    }
    if(phase){
      const opt=phase.options[phase.selectedIndex];
      const phaseSuggested=(opt && opt.getAttribute('data-suggest-mode')) || '';
      if(phaseSuggested){
        suggested=phaseSuggested;
      }
    }
    return suggested === 'summative' ? 'summative' : 'formative';
  }

  function updatePedagogicalHint(){
    const suggested=suggestedPedagogicalMode();
    const reason=document.getElementById('reasonSelect');
    const phase=document.getElementById('phaseSelect');
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

  function applyReasonSuggestion(force){
    const reason=document.getElementById('reasonSelect');
    const boxes=Array.from(document.querySelectorAll('.groupCb'));
    if(!reason || !boxes.length) return;
    if(!force && selectedGroupCheckboxes().length>0) return;
    const opt=reason.options[reason.selectedIndex];
    const ids=((opt && opt.getAttribute('data-auto-suggest')) || '')
      .split(',')
      .map(v=>parseInt(v,10))
      .filter(v=>Number.isInteger(v) && v>0)
      .slice(0,2);
    boxes.forEach(cb=>{ cb.checked = ids.includes(parseInt(cb.value,10)); });
  }

  (function(){
    const form=document.getElementById('participationForm');
    const boxes=Array.from(document.querySelectorAll('.groupCb'));
    if(boxes.length){
      boxes.forEach(cb=>{
        cb.addEventListener('change', ()=>{
          const checked=selectedGroupCheckboxes();
          if(checked.length>2){
            cb.checked=false;
            alert('Bitte höchstens zwei Beobachtungsbereiche auswählen.');
          }
        });
      });
    }
    const reason=document.getElementById('reasonSelect');
    if(reason){
      reason.addEventListener('change', ()=>{
        applyReasonSuggestion(true);
        updatePedagogicalHint();
      });
      applyReasonSuggestion(false);
    }
    const phase=document.getElementById('phaseSelect');
    if(phase){
      phase.addEventListener('change', updatePedagogicalHint);
    }
    const impact=document.getElementById('impactSelect');
    if(impact) impact.addEventListener('change', updatePedagogicalHint);
    updatePedagogicalHint();
    if(form){
      form.addEventListener('submit', (ev)=>{
        const submitter=ev.submitter || document.activeElement;
        const actionValue=(submitter && submitter.name==='action') ? submitter.value : ((document.getElementById('autoAction') || {}).value || '');
        if(['apply_preset','save_preset','update_preset'].includes(actionValue)) return;
        const checked=selectedGroupCheckboxes();
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

  // Lesson helpers
  (function(){
    const toggle=document.getElementById('createLessonToggle');
    const fields=document.getElementById('createLessonFields');
    const sel=document.getElementById('lessonSelect');
    const eventDate=document.getElementById('eventDate');
    const lessonUnit=document.getElementById('lessonUnit');

    function setCreateLessonEnabled(on){
      if(!lessonUnit) return;
      // Prevent hidden required fields from blocking submit: disable when not used
      lessonUnit.disabled = !on;
      lessonUnit.required = !!on;
      const d = document.querySelector('input[name="lesson_date"]');
      if(d) d.disabled = !on;
      const t = document.querySelector('input[name="topic"]');
      if(t) t.disabled = !on;
    }

    if(toggle && fields){
      toggle.addEventListener('change', ()=>{
        fields.style.display = toggle.checked ? '' : 'none';
        if(toggle.checked && sel){ sel.value='0'; }
        setCreateLessonEnabled(toggle.checked);
      });
      // init
      setCreateLessonEnabled(toggle.checked);
    }

    if(sel && eventDate){
      sel.addEventListener('change', ()=>{
        const opt = sel.options[sel.selectedIndex];
        const d = opt ? opt.getAttribute('data-date') : '';
        if(d && !(toggle && toggle.checked)){
          // set date to lesson date (convenience)
          eventDate.value = d;
        }
      });
    }
  })();
  </script>
</div></div></div>
<?php render_footer(); ?>
