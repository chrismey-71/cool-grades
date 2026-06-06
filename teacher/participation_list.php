<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/lbvo.php';
require_once __DIR__.'/../lib/school_years.php';
$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id']??0);
$subject_id=(int)($_GET['subject_id']??0);
$school_period_set_id=(int)($_GET['school_period_set_id'] ?? school_year_current_id($pdo));

// Filters
$from=$_GET['from'] ?? date('Y-m-01');
$to=$_GET['to'] ?? date('Y-m-d');
$q=trim($_GET['q'] ?? '');

$student_id=(int)($_GET['student_id'] ?? 0);
$lesson_id=(int)($_GET['lesson_id'] ?? 0); // 0=all, -1=without lesson, >0 specific lesson
$msg=(string)($_GET['msg'] ?? '');
$err=(string)($_GET['err'] ?? '');

$schoolYears=load_school_years($pdo,true);
$classes=load_teacher_classes($pdo,(int)$u['id'],$school_period_set_id,true,false);
$subjects=load_teacher_subjects($pdo,(int)$u['id'],$class_id);

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');
  if($action==='lbvo_recalc_all'){
    $post_class_id=(int)($_POST['class_id'] ?? 0);
    $post_subject_id=(int)($_POST['subject_id'] ?? 0);
    $returnParams=[
      'class_id'=>$post_class_id,
      'subject_id'=>$post_subject_id,
      'school_period_set_id'=>(int)($_POST['school_period_set_id'] ?? $school_period_set_id),
      'from'=>(string)($_POST['from'] ?? $from),
      'to'=>(string)($_POST['to'] ?? $to),
      'student_id'=>(int)($_POST['student_id'] ?? 0),
      'lesson_id'=>(int)($_POST['lesson_id'] ?? 0),
      'q'=>trim((string)($_POST['q'] ?? '')),
    ];

    if(!$post_class_id || !$post_subject_id){
      header('Location: '.$bp.'/teacher/participation_list.php?'.http_build_query(array_merge($returnParams,['err'=>'Bitte zuerst Klasse und Fach wählen.'])));
      exit;
    }

    require_teacher_assignment($u,$post_class_id,$post_subject_id);

    $eventsSt=$pdo->prepare("SELECT id, reason_label, phase_option_id, homework_option_id, note, reason_text
                             FROM participation_events
                             WHERE teacher_id=? AND class_id=? AND subject_id=?");
    $eventsSt->execute([(int)$u['id'],$post_class_id,$post_subject_id]);
    $eventRows=$eventsSt->fetchAll();

    $recalcCount=count($eventRows);
    if($recalcCount>0){
      $optLabel=[];
      foreach($pdo->query("SELECT id, label FROM participation_options") as $row){
        $optLabel[(int)$row['id']] = (string)$row['label'];
      }

      $criteriaByEvent=[];
      $critSt=$pdo->prepare("SELECT pec.event_id, c.label
                             FROM participation_event_criteria pec
                             JOIN participation_events pe ON pe.id=pec.event_id
                             JOIN criteria c ON c.id=pec.criteria_id
                             WHERE pe.teacher_id=? AND pe.class_id=? AND pe.subject_id=?
                             ORDER BY pec.event_id, c.label");
      $critSt->execute([(int)$u['id'],$post_class_id,$post_subject_id]);
      foreach($critSt->fetchAll() as $row){
        $criteriaByEvent[(int)$row['event_id']][] = (string)$row['label'];
      }

      $groupSt=$pdo->prepare("SELECT peo.event_id, po.label
                              FROM participation_event_options peo
                              JOIN participation_events pe ON pe.id=peo.event_id
                              JOIN participation_options po ON po.id=peo.option_id
                              WHERE pe.teacher_id=? AND pe.class_id=? AND pe.subject_id=? AND po.opt_type='observation_group'
                              ORDER BY peo.event_id, po.sort, po.label");
      $groupSt->execute([(int)$u['id'],$post_class_id,$post_subject_id]);
      foreach($groupSt->fetchAll() as $row){
        $criteriaByEvent[(int)$row['event_id']][] = (string)$row['label'];
      }

      $perfByEvent=[];
      $perfSt=$pdo->prepare("SELECT peo.event_id, po.label
                             FROM participation_event_options peo
                             JOIN participation_events pe ON pe.id=peo.event_id
                             JOIN participation_options po ON po.id=peo.option_id
                             WHERE pe.teacher_id=? AND pe.class_id=? AND pe.subject_id=? AND po.opt_type='performance'
                             ORDER BY peo.event_id, po.sort, po.label");
      $perfSt->execute([(int)$u['id'],$post_class_id,$post_subject_id]);
      foreach($perfSt->fetchAll() as $row){
        $perfByEvent[(int)$row['event_id']][] = (string)$row['label'];
      }

      $pdo->beginTransaction();
      try{
        $delSt=$pdo->prepare("DELETE pel
                              FROM participation_event_lbvo pel
                              JOIN participation_events pe ON pe.id=pel.event_id
                              WHERE pe.teacher_id=? AND pe.class_id=? AND pe.subject_id=?
                                AND pel.source IN ('manual','auto')");
        $delSt->execute([(int)$u['id'],$post_class_id,$post_subject_id]);

        $insSt=$pdo->prepare("INSERT IGNORE INTO participation_event_lbvo (event_id,tag,source,created_at) VALUES (?,?, 'auto', ?)");
        foreach($eventRows as $row){
          $eventId=(int)$row['id'];
          $phaseLabel = !empty($row['phase_option_id']) ? ($optLabel[(int)$row['phase_option_id']] ?? '') : '';
          $homeworkLabel = !empty($row['homework_option_id']) ? ($optLabel[(int)$row['homework_option_id']] ?? '') : '';
          $tags = lbvo_auto_tags(
            $row['reason_label'] ?? '',
            $phaseLabel,
            $homeworkLabel,
            $criteriaByEvent[$eventId] ?? [],
            $perfByEvent[$eventId] ?? [],
            $row['note'] ?? '',
            $row['reason_text'] ?? ''
          );
          foreach($tags as $tag){
            $insSt->execute([$eventId,$tag,now_iso()]);
          }
        }
        $pdo->commit();
      }catch(Exception $e){
        $pdo->rollBack();
        header('Location: '.$bp.'/teacher/participation_list.php?'.http_build_query(array_merge($returnParams,['err'=>'LBV-Tags konnten nicht neu berechnet werden: '.$e->getMessage()])));
        exit;
      }
    }

    emit_event('participation_lbvo_recalc',[
      'class_id'=>$post_class_id,
      'subject_id'=>$post_subject_id,
      'count'=>$recalcCount,
    ]);
    header('Location: '.$bp.'/teacher/participation_list.php?'.http_build_query(array_merge($returnParams,['msg'=>'LBV-Tags für '.$recalcCount.' Einträge neu berechnet. Manuelle Tags wurden dabei entfernt.'])));
    exit;
  }
}

$events=[];
$students=[];
$lessons=[];
$manual=$auto=[];

if($class_id && $subject_id){
  require_teacher_assignment($u,$class_id,$subject_id);

  // Students for dropdown
  $students=load_class_students($pdo,$class_id,false);

  // Lessons for dropdown (within timeframe)
  $st=$pdo->prepare("SELECT id, lesson_date, lesson_unit, topic FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date BETWEEN ? AND ? ORDER BY lesson_date DESC, CAST(lesson_unit AS UNSIGNED) DESC, id DESC LIMIT 250");
  $st->execute([$class_id,$subject_id,$from,$to]);
  $lessons=$st->fetchAll();

  $sql="SELECT pe.id, pe.event_date, pe.reason_label, pe.rating,
               st.last_name, st.first_name,
               ls.lesson_date AS lesson_date, ls.lesson_unit AS lesson_unit, ls.topic AS lesson_topic
        FROM participation_events pe
        JOIN students st ON st.id=pe.student_id
        LEFT JOIN lesson_sessions ls ON ls.id=pe.lesson_id
        WHERE pe.teacher_id=? AND pe.class_id=? AND pe.subject_id=?
          AND pe.event_date BETWEEN ? AND ?";
  $params=[(int)$u['id'],$class_id,$subject_id,$from,$to];

  if($student_id>0){
    $sql.=" AND pe.student_id=?";
    $params[]=$student_id;
  }

  if($lesson_id>0){
    $sql.=" AND pe.lesson_id=?";
    $params[]=$lesson_id;
  } elseif($lesson_id<0){
    $sql.=" AND pe.lesson_id IS NULL";
  }

  if($q!==''){
    $sql.=" AND (st.last_name LIKE ? OR st.first_name LIKE ? OR CONCAT(st.last_name,' ',st.first_name) LIKE ?)";
    $like='%'.$q.'%';
    $params[]=$like; $params[]=$like; $params[]=$like;
  }

  $sql.=" ORDER BY pe.event_date DESC, pe.id DESC LIMIT 400";

  $st=$pdo->prepare($sql);
  $st->execute($params);
  $events=$st->fetchAll();

  if($events){
    $ids=array_map(fn($e)=>(int)$e['id'],$events);
    $in='('.implode(',',array_fill(0,count($ids),'?')).')';

    $st=$pdo->prepare("SELECT event_id, GROUP_CONCAT(tag ORDER BY tag SEPARATOR '') AS tags
                       FROM participation_event_lbvo
                       WHERE event_id IN $in AND source='manual'
                       GROUP BY event_id");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){ $manual[(int)$r['event_id']]=$r['tags']; }

    $st=$pdo->prepare("SELECT event_id, GROUP_CONCAT(tag ORDER BY tag SEPARATOR '') AS tags
                       FROM participation_event_lbvo
                       WHERE event_id IN $in AND source='auto'
                       GROUP BY event_id");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){ $auto[(int)$r['event_id']]=$r['tags']; }
  }
}

render_header('Mitarbeit bearbeiten',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Mitarbeit – Einträge bearbeiten</h1>
<div class="muted">Wähle zuerst <b>Klasse + Fach</b>. Danach kannst du zusätzlich nach <b>Unterrichtsstunde</b> und <b>Schüler:in</b> filtern.</div>

<?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="flash error" style="margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

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
      <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id===(int)$c['id']?'selected':''; ?>><?php echo h($c['name'].(class_is_readonly($c)?' · Archiv':'')); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="muted">Fach</label>
    <select class="input" name="subject_id">
      <option value="0">–</option>
      <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $subject_id===(int)$s['id']?'selected':''; ?>><?php echo h($s['code']); ?></option><?php endforeach; ?>
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
  <div style="flex:1">
    <label class="muted">Unterrichtsstunde (optional)</label>
    <select class="input" name="lesson_id">
      <option value="0">– alle Stunden –</option>
      <option value="-1" <?php echo $lesson_id===-1?'selected':''; ?>>– ohne Stunde –</option>
      <?php foreach($lessons as $ls):
        $txt=$ls['lesson_date'];
        if($ls['lesson_unit']) $txt.=' · UE '.$ls['lesson_unit'];
        if($ls['topic']) $txt.=' · '.$ls['topic'];
      ?>
        <option value="<?php echo (int)$ls['id']; ?>" <?php echo $lesson_id===(int)$ls['id']?'selected':''; ?>><?php echo h($txt); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="flex:1">
    <label class="muted">Schüler:in (optional)</label>
    <select class="input" name="student_id">
      <option value="0">– alle –</option>
      <?php foreach($students as $sx): ?>
        <option value="<?php echo (int)$sx['id']; ?>" <?php echo $student_id===(int)$sx['id']?'selected':''; ?>><?php echo h($sx['last_name'].', '.$sx['first_name']); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="flex:1">
    <label class="muted">Suche (optional)</label>
    <input class="input" name="q" value="<?php echo h($q); ?>" placeholder="z.B. Mayer">
  </div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Anzeigen</button></div>
</form>

<?php if($class_id && $subject_id): ?>
  <form method="post" style="margin-top:12px" onsubmit="return confirm('Alle LBV-Tags für diese Klasse und dieses Fach neu berechnen? Manuelle Tags werden entfernt.');">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="lbvo_recalc_all">
    <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
    <input type="hidden" name="school_period_set_id" value="<?php echo (int)$school_period_set_id; ?>">
    <input type="hidden" name="from" value="<?php echo h($from); ?>">
    <input type="hidden" name="to" value="<?php echo h($to); ?>">
    <input type="hidden" name="student_id" value="<?php echo (int)$student_id; ?>">
    <input type="hidden" name="lesson_id" value="<?php echo (int)$lesson_id; ?>">
    <input type="hidden" name="q" value="<?php echo h($q); ?>">
    <button class="btn secondary">LBV-Tags für Klasse/Fach neu ermitteln</button>
    <div class="small muted" style="margin-top:6px">Die Neuberechnung umfasst alle Einträge der gewählten Klasse und des gewählten Fachs. Aktive Zusatzfilter dienen nur der Ansicht.</div>
  </form>
<?php endif; ?>

<?php if($events): ?>
<div style="height:12px"></div>
<table class="table">
  <thead><tr><th>Datum</th><th>Stunde</th><th>Schüler:in</th><th>Grund</th><th>Eindruck</th><th>LBV</th><th>Aktion</th></tr></thead>
  <tbody>
    <?php foreach($events as $ev):
      $eid=(int)$ev['id'];
      $tags = $manual[$eid] ?? ($auto[$eid] ?? '');
    ?>
      <tr>
        <td data-label="Datum"><?php echo h($ev['event_date']); ?></td>
        <td data-label="Stunde">
          <?php if($ev['lesson_date']): ?>
            <?php echo h($ev['lesson_date']); ?><?php echo $ev['lesson_unit']?(' · UE '.$ev['lesson_unit']):''; ?>
            <?php if($ev['lesson_topic']): ?><br><span class="muted" style="font-size:12px"><?php echo h($ev['lesson_topic']); ?></span><?php endif; ?>
          <?php else: ?>
            <span class="muted">–</span>
          <?php endif; ?>
        </td>
        <td data-label="Schüler:in"><?php echo h($ev['last_name'].', '.$ev['first_name']); ?></td>
        <td data-label="Grund"><?php echo h($ev['reason_label']); ?></td>
        <td data-label="Eindruck"><?php echo h($ev['rating']); ?></td>
        <td data-label="LBV"><?php echo $tags? h($tags) : '–'; ?></td>
        <td data-label="Aktion"><a class="btn small" href="<?php echo h($bp); ?>/teacher/participation_edit.php?id=<?php echo $eid; ?>">Bearbeiten</a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php elseif($class_id && $subject_id): ?>
  <div style="height:12px" class="muted">Keine Einträge gefunden.</div>
<?php else: ?>
  <div style="height:12px" class="muted">Bitte Klasse/Fach wählen und Zeitraum einstellen.</div>
<?php endif; ?>

<div style="height:12px"></div>
<a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
</div></div></div>
<?php render_footer(); ?>
