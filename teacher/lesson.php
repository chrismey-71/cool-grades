<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/school_years.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$class_id=(int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
$lesson_id=(int)($_GET['lesson_id'] ?? 0);
$sort=(string)($_GET['sort'] ?? $_POST['sort'] ?? 'date_desc');

$sort_options = [
  'date_desc' => ['label'=>'Datum neu zuerst', 'sql'=>"ls.lesson_date DESC, CAST(COALESCE(NULLIF(ls.lesson_unit,''),'0') AS UNSIGNED) DESC, ls.id DESC"],
  'date_asc' => ['label'=>'Datum alt zuerst', 'sql'=>"ls.lesson_date ASC, CAST(COALESCE(NULLIF(ls.lesson_unit,''),'0') AS UNSIGNED) ASC, ls.id ASC"],
  'unit_asc' => ['label'=>'UE aufsteigend', 'sql'=>"CAST(COALESCE(NULLIF(ls.lesson_unit,''),'0') AS UNSIGNED) ASC, ls.lesson_date DESC, ls.id DESC"],
  'unit_desc' => ['label'=>'UE absteigend', 'sql'=>"CAST(COALESCE(NULLIF(ls.lesson_unit,''),'0') AS UNSIGNED) DESC, ls.lesson_date DESC, ls.id DESC"],
  'topic_asc' => ['label'=>'Thema A-Z', 'sql'=>"COALESCE(ls.topic,'') ASC, ls.lesson_date DESC, ls.id DESC"],
];
if(!isset($sort_options[$sort])) $sort='date_desc';

// Assignable classes/subjects
$classes=load_teacher_classes($pdo,(int)$u['id'],school_year_current_id($pdo),false,false);

$st=$pdo->prepare("SELECT DISTINCT s.id,s.code,s.name FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id WHERE ta.teacher_id=? ORDER BY s.code");
$st->execute([(int)$u['id']]);
$subjects=$st->fetchAll();

// Parse slot map for quick navigation (format: "UE:ID,UE:ID")
$slot_map_str=(string)($_GET['slot_map'] ?? '');
$slot_links=[];
if($slot_map_str!==''){
  foreach(explode(',',$slot_map_str) as $pair){
    if(preg_match('/^(\d{1,2}):(\d+)$/',trim($pair),$m)){
      $slot_links[(int)$m[1]]=(int)$m[2];
    }
  }
  if($slot_links) ksort($slot_links);
}

$msg=(string)($_GET['msg'] ?? '');
$err=(string)($_GET['err'] ?? '');

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');

  if($action==='update_lesson'){
    $lesson_id=(int)($_POST['lesson_id'] ?? 0);
    $sort=(string)($_POST['sort'] ?? $sort);
    if(!isset($sort_options[$sort])) $sort='date_desc';

    if(!$lesson_id){
      $err='Stunde nicht gefunden.';
    } else {
      $st=$pdo->prepare("SELECT ls.*, c.name AS class_name, s.code AS subject_code, s.name AS subject_name
                         FROM lesson_sessions ls
                         JOIN classes c ON c.id=ls.class_id
                         JOIN subjects s ON s.id=ls.subject_id
                         WHERE ls.id=?");
      $st->execute([$lesson_id]);
      $ls_edit=$st->fetch();

      if(!$ls_edit){
        $err='Stunde nicht gefunden.';
      } else {
        $class_id=(int)$ls_edit['class_id'];
        $subject_id=(int)$ls_edit['subject_id'];
        require_teacher_assignment($u,$class_id,$subject_id);
        require_class_writable($pdo,$class_id);

        $date=(string)($_POST['lesson_date'] ?? '');
        $lesson_unit=trim((string)($_POST['lesson_unit'] ?? ''));
        $topic=trim((string)($_POST['topic'] ?? ''));

        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
          $err='Bitte ein gültiges Datum wählen.';
        } elseif($lesson_unit===''){
          $err='Bitte eine Unterrichtsstunde/UE angeben.';
        } elseif(!preg_match('/^\d{1,2}$/',$lesson_unit) || (int)$lesson_unit < 1 || (int)$lesson_unit > 12){
          $err='UE muss zwischen 1 und 12 liegen.';
        } else {
          try{
            $st=$pdo->prepare("UPDATE lesson_sessions
                               SET lesson_date=?, lesson_unit=?, topic=?
                               WHERE id=?");
            $st->execute([$date,$lesson_unit,$topic!==''?$topic:null,$lesson_id]);

            emit_event('lesson_updated',[
              'lesson_id'=>$lesson_id,
              'class_id'=>$class_id,
              'subject_id'=>$subject_id,
              'lesson_date'=>$date,
              'lesson_unit'=>$lesson_unit,
              'topic'=>$topic!=='' ? $topic : null,
            ]);

            header('Location: '.$bp.'/teacher/lesson.php?'.http_build_query([
              'class_id'=>$class_id,
              'subject_id'=>$subject_id,
              'lesson_id'=>$lesson_id,
              'sort'=>$sort,
              'msg'=>'updated',
            ]));
            exit;
          }catch(PDOException $e){
            $err='Speichern nicht möglich: Für dieses Datum und diese UE gibt es in Klasse und Fach bereits eine Stunde.';
          }
        }
      }
    }

    header('Location: '.$bp.'/teacher/lesson.php?'.http_build_query([
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
      'lesson_id'=>$lesson_id,
      'sort'=>$sort,
      'err'=>$err ?: 'Speichern nicht möglich.',
    ]));
    exit;
  }

  if($action==='create_lesson'){
    $class_id=(int)($_POST['class_id'] ?? 0);
    $subject_id=(int)($_POST['subject_id'] ?? 0);
    $sort=(string)($_POST['sort'] ?? $sort);
    if(!isset($sort_options[$sort])) $sort='date_desc';

    if(!$class_id||!$subject_id){
      $err='Bitte Klasse und Fach wählen.';
    } else {
      require_teacher_assignment($u,$class_id,$subject_id);
      require_class_writable($pdo,$class_id);

      $date=$_POST['lesson_date'] ?? date('Y-m-d');
      $unit_raw=trim($_POST['lesson_unit'] ?? '');
      $topic=trim($_POST['topic'] ?? '');

      // Accept single UE ("3"), list ("3,4,6"), or range ("3-4").
      $units=[];
      if($unit_raw===''){
        $err='Bitte eine Unterrichtsstunde/UE angeben (z.B. 3, 3-4 oder 3,4).';
      } else {
        $clean=preg_replace('/\s+/','',$unit_raw);
        $parts=array_filter(explode(',',$clean), fn($p)=>$p!=='');
        foreach($parts as $p){
          if(preg_match('/^(\d{1,2})-(\d{1,2})$/',$p,$m)){
            $a=(int)$m[1]; $b=(int)$m[2];
            if($a<1||$b<1){ $err='UE muss >= 1 sein.'; break; }
            if($a>$b){ [$a,$b]=[$b,$a]; }
            for($i=$a;$i<=$b;$i++) $units[]=$i;
          } elseif(preg_match('/^\d{1,2}$/',$p)){
            $units[]=(int)$p;
          } else {
            $err='Bitte UE als Zahl (z.B. 3), Liste (3,4) oder Bereich (3-4) eingeben.';
            break;
          }
        }
      }

      if(!$err){
        $units=array_values(array_unique(array_filter($units, fn($n)=>$n>0)));
        sort($units);
        foreach($units as $n){
          if($n<1 || $n>12){ $err='UE muss zwischen 1 und 12 liegen.'; break; }
        }
      }

      if(!$err){
        $slot_map=[]; // unit => lesson_id
        $any_existing=false;

        foreach($units as $unit){
          // Re-use existing slot if already created (class+subject+date+unit)
          $st=$pdo->prepare("SELECT id FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date=? AND lesson_unit=? LIMIT 1");
          $st->execute([$class_id,$subject_id,$date,(string)$unit]);
          $existing=(int)($st->fetchColumn() ?: 0);
          if($existing){
            $slot_map[$unit]=$existing;
            $any_existing=true;
            continue;
          }

          try{
            $st=$pdo->prepare("INSERT INTO lesson_sessions (teacher_id,class_id,subject_id,lesson_date,lesson_unit,topic,created_at) VALUES (?,?,?,?,?,?,?)");
            $st->execute([(int)$u['id'],$class_id,$subject_id,$date,(string)$unit,$topic?:null,now_iso()]);
            $slot_map[$unit]=(int)$pdo->lastInsertId();
          }catch(PDOException $e){
            // In case a UNIQUE constraint exists and another request inserted the slot concurrently
            $st=$pdo->prepare("SELECT id FROM lesson_sessions WHERE class_id=? AND subject_id=? AND lesson_date=? AND lesson_unit=? LIMIT 1");
            $st->execute([$class_id,$subject_id,$date,(string)$unit]);
            $id=(int)($st->fetchColumn() ?: 0);
            if(!$id) throw $e;
            $slot_map[$unit]=$id;
          }
        }

        if(!$slot_map){
          $err='Es konnte keine Stunde angelegt/gefunden werden.';
        } else {
          ksort($slot_map);
          $first_unit=array_key_first($slot_map);
          $lesson_id=(int)$slot_map[$first_unit];

          // Encode slot map as "UE:ID,UE:ID" so participation_new can offer quick switching.
          $pairs=[];
          foreach($slot_map as $ue=>$id){ $pairs[]=$ue.':'.$id; }

          $q=[
            'class_id'=>$class_id,
            'subject_id'=>$subject_id,
            'lesson_id'=>$lesson_id,
            'msg'=>count($slot_map)>1 ? 'multi' : ($any_existing ? 'exists' : ''),
          ];
          if(count($slot_map)>1) $q['slot_map']=implode(',',$pairs);

          header('Location: '.$bp.'/teacher/participation_new.php?'.http_build_query($q));
          exit;
        }
      }
    }

    // If we reach here, show the form again with an error.
    header('Location: '.$bp.'/teacher/lesson.php?'.http_build_query([
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
      'sort'=>$sort,
      'err'=>$err
    ]));
    exit;
  }
}

// Optional selected slot from direct navigation (e.g. from participation page)
$ls=null;
if($lesson_id){
  $st=$pdo->prepare("SELECT ls.*, c.name AS class_name, s.code AS subject_code, s.name AS subject_name
                     FROM lesson_sessions ls
                     JOIN classes c ON c.id=ls.class_id
                     JOIN subjects s ON s.id=ls.subject_id
                     WHERE ls.id=?");
  $st->execute([$lesson_id]);
  $ls=$st->fetch();
  if(!$ls){ http_response_code(404); exit('Stunde nicht gefunden.'); }
  $class_id=(int)$ls['class_id'];
  $subject_id=(int)$ls['subject_id'];
  require_teacher_assignment($u,$class_id,$subject_id);
}

if($class_id && $subject_id){
  require_teacher_assignment($u,$class_id,$subject_id);
}

$class_name='';
foreach($classes as $c){ if((int)$c['id']===$class_id){ $class_name=(string)$c['name']; break; } }
$subject_code=''; $subject_name='';
foreach($subjects as $s){
  if((int)$s['id']===$subject_id){
    $subject_code=(string)$s['code'];
    $subject_name=(string)$s['name'];
    break;
  }
}
if($ls){
  $class_name=(string)($ls['class_name'] ?? $class_name);
  $subject_code=(string)($ls['subject_code'] ?? $subject_code);
  $subject_name=(string)($ls['subject_name'] ?? $subject_name);
}

$lessons=[];
if($class_id && $subject_id){
  $order_by = $sort_options[$sort]['sql'];
  $st=$pdo->prepare("SELECT ls.*, COUNT(pe.id) AS usage_count
                     FROM lesson_sessions ls
                     LEFT JOIN participation_events pe ON pe.lesson_id=ls.id
                     WHERE ls.class_id=? AND ls.subject_id=?
                     GROUP BY ls.id
                     ORDER BY $order_by
                     LIMIT 300");
  $st->execute([$class_id,$subject_id]);
  $lessons=$st->fetchAll();
}

$compact_forms = compact_entry_forms_enabled($u);

render_header('Stundenerfassung',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Stundenerfassung</h1>

  <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>
  <?php if($msg==='deleted'): ?><div class="flash success">Stunde wurde gelöscht.</div><?php endif; ?>
  <?php if($msg==='updated'): ?><div class="flash success">Stunde wurde gespeichert.</div><?php endif; ?>

  <?php if(!$compact_forms): ?><div class="muted">Lege zuerst eine Stunde an. Darunter kannst du Klasse und Fach für die Anzeige bestehender Stunden wählen.</div><?php endif; ?>
  <?php accordion_section_start($compact_forms, 'Stunde anlegen', true, 'margin-top:0', '', 'contrast-panel section-entry'); ?>
  <?php if($compact_forms): ?><div class="muted">Lege eine Stunde an. Nach dem Speichern wirst du direkt zur Mitarbeit-Erfassung weitergeleitet.</div><div style="height:12px"></div><?php endif; ?>
  <form method="post" class="row contrast-form section-entry" style="align-items:end;<?php echo $compact_forms?'':'margin-top:12px'; ?>" <?php echo dirty_form_attrs(); ?> <?php echo teacher_assignment_guard_attrs($u); ?>>
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="create_lesson">
      <input type="hidden" name="sort" value="<?php echo h($sort); ?>">

      <div>
        <label class="muted">Klasse</label>
        <select class="input" name="class_id" required>
          <option value="">Bitte wählen…</option>
          <?php foreach($classes as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo ($class_id===(int)$c['id'])?'selected':''; ?>><?php echo h($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="muted">Fach</label>
        <select class="input" name="subject_id" required>
          <option value="">Bitte wählen…</option>
          <?php foreach($subjects as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo ($subject_id===(int)$s['id'])?'selected':''; ?>><?php echo h($s['code']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="muted">Datum</label>
        <input class="input" type="date" name="lesson_date" value="<?php echo h($_GET['lesson_date'] ?? date('Y-m-d')); ?>">
      </div>

      <div>
        <label class="muted">UE/Stunde</label>
        <input class="input" type="text" name="lesson_unit" placeholder="z.B. 3 oder 3-4 oder 3,4" inputmode="numeric" required>
        <div class="muted" style="font-size:.85em;margin-top:4px">Format: <b>3</b> · <b>3-4</b> · <b>3,4,6</b></div>
      </div>

      <div style="flex:1">
        <label class="muted">Thema (optional)</label>
        <input class="input" name="topic" placeholder="z.B. Arbeitsvertrag">
      </div>

      <div style="flex:0 0 auto">
        <label class="muted">&nbsp;</label>
        <button class="btn">Weiter zur Mitarbeit</button>
      </div>
  </form>
  <?php accordion_section_end($compact_forms); ?>

  <?php if(!$compact_forms): ?><div style="height:14px"></div><h2>Klasse und Fach</h2><?php endif; ?>
  <?php accordion_section_start($compact_forms, 'Klasse und Fach', false, 'margin-top:12px', '', 'contrast-panel section-selection'); ?>
  <?php if($compact_forms): ?><div class="muted">Wähle Klasse und Fach. Darunter kannst du bestehende Stunden sortiert bearbeiten und filtern.</div><div style="height:12px"></div><?php endif; ?>
  <form method="get" class="row contrast-form section-selection" style="align-items:end" <?php echo teacher_assignment_guard_attrs($u); ?>>
    <div>
      <label class="muted">Klasse</label>
      <select class="input" name="class_id" required>
        <option value="">Bitte wählen…</option>
        <?php foreach($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo ($class_id===(int)$c['id'])?'selected':''; ?>><?php echo h($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="muted">Fach</label>
      <select class="input" name="subject_id" required>
        <option value="">Bitte wählen…</option>
        <?php foreach($subjects as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>" <?php echo ($subject_id===(int)$s['id'])?'selected':''; ?>><?php echo h($s['code']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="muted">Sortierung</label>
      <select class="input" name="sort">
        <?php foreach($sort_options as $key=>$opt): ?>
          <option value="<?php echo h($key); ?>" <?php echo $sort===$key?'selected':''; ?>><?php echo h($opt['label']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="flex:0 0 auto">
      <label class="muted">&nbsp;</label>
      <button class="btn secondary">Anzeigen</button>
    </div>
  </form>
  <?php accordion_section_end($compact_forms); ?>

  <?php if($class_id && $subject_id): ?>
    <?php if(!$compact_forms): ?><div style="height:14px"></div><h2>Bisherige Stunden</h2><?php endif; ?>
    <?php accordion_section_start($compact_forms, 'Bisherige Stunden', true, 'margin-top:12px', '', 'contrast-panel section-overview'); ?>
    <div class="contrast-block section-overview">
    <div class="muted">
      Angezeigt für Klasse <b><?php echo h($class_name ?: ('#'.$class_id)); ?></b>
      · Fach <b><?php echo h($subject_code ?: ('#'.$subject_id)); ?></b>
      <?php if($subject_name): ?> (<?php echo h($subject_name); ?>)<?php endif; ?>
    </div>
    <div class="small muted" style="margin-top:6px">Löschen ist nur möglich, wenn noch keine Mitarbeitseinträge mit dieser Stunde verknüpft sind.</div>

    <div style="height:10px"></div>
    <?php if($lessons): ?>
      <table class="table">
        <thead>
          <tr>
            <th>Datum</th>
            <th>UE</th>
            <th>Thema</th>
            <th>Einträge</th>
            <th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($lessons as $row): ?>
            <?php
              $row_lesson_id=(int)$row['id'];
              $row_usage=(int)($row['usage_count'] ?? 0);
              $row_form_id='lessonRow'.$row_lesson_id;
              $row_selected=($lesson_id===$row_lesson_id);
              $delete_return=$bp.'/teacher/lesson.php?'.http_build_query([
                'class_id'=>$class_id,
                'subject_id'=>$subject_id,
                'sort'=>$sort,
                'msg'=>'deleted',
              ]);
            ?>
            <tr<?php echo $row_selected?' style="background:var(--brand-primary-soft-alt)"':''; ?>>
              <td data-label="Datum">
                <input form="<?php echo h($row_form_id); ?>" class="input" type="date" name="lesson_date" value="<?php echo h((string)$row['lesson_date']); ?>" required>
              </td>
              <td data-label="UE" style="min-width:110px">
                <input form="<?php echo h($row_form_id); ?>" class="input" type="text" name="lesson_unit" value="<?php echo h((string)$row['lesson_unit']); ?>" inputmode="numeric" required>
              </td>
              <td data-label="Thema">
                <input form="<?php echo h($row_form_id); ?>" class="input" type="text" name="topic" value="<?php echo h((string)($row['topic'] ?? '')); ?>" placeholder="Thema">
              </td>
              <td data-label="Einträge">
                <?php if($row_usage>0): ?>
                  <span class="badge good"><?php echo (int)$row_usage; ?></span>
                <?php else: ?>
                  <span class="muted">0</span>
                <?php endif; ?>
              </td>
              <td data-label="Aktion" class="actions">
                <form method="post" id="<?php echo h($row_form_id); ?>" class="inline-form">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="action" value="update_lesson">
                  <input type="hidden" name="lesson_id" value="<?php echo (int)$row_lesson_id; ?>">
                  <input type="hidden" name="sort" value="<?php echo h($sort); ?>">
                </form>
                <button form="<?php echo h($row_form_id); ?>" class="btn small secondary">Speichern</button>
                <a class="btn small secondary" href="<?php echo h($bp); ?>/teacher/participation_new.php?<?php echo h(http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'lesson_id'=>$row_lesson_id])); ?>">Zur Mitarbeit</a>
                <?php if($row_usage===0): ?>
                  <form method="post" action="<?php echo h($bp); ?>/teacher/lesson_delete.php" class="inline-form" onsubmit="return confirm('Diese Stunde wirklich löschen?');">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="lesson_id" value="<?php echo (int)$row_lesson_id; ?>">
                    <input type="hidden" name="return" value="<?php echo h($delete_return); ?>">
                    <button class="btn small danger">Löschen</button>
                  </form>
                <?php else: ?>
                  <span class="small muted">Löschen gesperrt</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="muted">Für diese Klasse und dieses Fach sind noch keine Stunden angelegt.</div>
    <?php endif; ?>
    </div>
    <?php accordion_section_end($compact_forms); ?>
  <?php endif; ?>

  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
</div></div></div>
<?php render_footer(); ?>
