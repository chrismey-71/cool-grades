<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/participation_options.php';
require_once __DIR__.'/../lib/participation_pedagogical_mode.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$types=[
  'reason'=>'Grund/Anlass',
  'impact'=>'Eindruck/Relevanz',
  'performance'=>'Leistungsart',
  'social_form'=>'Sozialform',
  'phase'=>'Unterrichtsphase',
  'homework'=>'Hausübung-Status'
];
$type=$_GET['type'] ?? $_POST['type'] ?? 'reason';
if(!isset($types[$type])) $type='reason';

$st=$pdo->prepare("SELECT DISTINCT s.id,s.code,s.name
                   FROM teacher_assignments ta
                   JOIN subjects s ON s.id=ta.subject_id
                   WHERE ta.teacher_id=?
                   ORDER BY s.code");
$st->execute([(int)$u['id']]);
$subjects=$st->fetchAll();
$allowed_subject_ids=array_map('intval', array_column($subjects,'id'));

$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
if($subject_id>0 && !in_array($subject_id,$allowed_subject_ids,true)) $subject_id=0;

$show_archived = isset($_GET['show_archived']) && (string)$_GET['show_archived']==='1';
$reason_mode_choices=participation_reason_mode_choices();

function option_used_count(PDO $pdo, int $id, string $type): int {
  switch($type){
    case 'performance':
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_event_options WHERE option_id=?");
      $st->execute([$id]);
      return (int)($st->fetch()['c']??0);
    case 'reason':
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_events WHERE reason_option_id=?");
      $st->execute([$id]);
      return (int)($st->fetch()['c']??0);
    case 'impact':
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_events WHERE impact_option_id=?");
      $st->execute([$id]);
      return (int)($st->fetch()['c']??0);
    case 'social_form':
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_events WHERE social_form_option_id=?");
      $st->execute([$id]);
      return (int)($st->fetch()['c']??0);
    case 'phase':
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_events WHERE phase_option_id=?");
      $st->execute([$id]);
      return (int)($st->fetch()['c']??0);
    case 'homework':
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_events WHERE homework_option_id=?");
      $st->execute([$id]);
      return (int)($st->fetch()['c']??0);
    default:
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_event_options WHERE option_id=?");
      $st->execute([$id]);
      $c=(int)($st->fetch()['c']??0);
      $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_events WHERE reason_option_id=? OR impact_option_id=? OR social_form_option_id=? OR phase_option_id=? OR homework_option_id=?");
      $st->execute([$id,$id,$id,$id,$id]);
      return $c + (int)($st->fetch()['c']??0);
  }
}

function teacher_options_redirect(string $bp, string $type, int $subject_id, bool $show_archived, string $msg=''): void {
  $params=[
    'type'=>$type,
    'subject_id'=>$subject_id,
    'show_archived'=>$show_archived?1:0,
  ];
  if($msg!=='') $params['msg']=$msg;
  header('Location: '.$bp.'/teacher/options.php?'.http_build_query($params));
  exit;
}

function teacher_options_info_text(array $bundle, int $subject_id): string {
  if(!empty($bundle['has_exact_teacher_context'])){
    if($subject_id>0){
      return 'Du bearbeitest bereits deine eigene Liste für dieses Fach. Die wirksamen Standardoptionen wurden in deine Liste übernommen; weitere Änderungen betreffen nur noch diese Fachliste.';
    }
    return 'Du bearbeitest bereits deine eigene allgemeine Liste für alle Fächer. Diese Liste ersetzt für dich die Standardoptionen.';
  }

  $source=(string)($bundle['primary_source_key'] ?? 'global');
  if($source==='teacher_general' && $subject_id>0){
    return 'Aktuell werden deine allgemeinen Optionen für alle Fächer verwendet. Bei der ersten Änderung wird daraus automatisch eine eigene Liste nur für dieses Fach.';
  }
  if($source==='subject_default'){
    return 'Aktuell werden fachbezogene Standardoptionen verwendet. Bei der ersten Änderung wird daraus automatisch deine eigene Liste für dieses Fach.';
  }
  return 'Aktuell werden globale Standardoptionen verwendet. Bei der ersten Änderung wird daraus automatisch deine eigene Liste für diesen Bereich.';
}

$msg_map=[
  'added'=>'Option gespeichert.',
  'saved'=>'Option gespeichert.',
  'toggled'=>'Status geändert.',
  'deleted'=>'Option gelöscht.',
  'restored'=>'Option wiederhergestellt.',
  'reordered'=>'Reihenfolge gespeichert.',
];
$msg=$msg_map[(string)($_GET['msg'] ?? '')] ?? '';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=(string)($_POST['action'] ?? '');
  $subject_id=(int)($_POST['subject_id'] ?? $subject_id);
  if($subject_id>0 && !in_array($subject_id,$allowed_subject_ids,true)) $subject_id=0;

  try{
    if($a==='add'){
      $label=trim((string)($_POST['label'] ?? ''));
      $pedagogical_hint_mode=($type==='reason') ? participation_reason_mode_normalize((string)($_POST['pedagogical_hint_mode'] ?? 'auto')) : 'auto';
      if($label===''){
        $err='Bitte Bezeichnung eingeben.';
      } else {
        $pdo->beginTransaction();
        materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
        $existing=participation_option_find_exact_by_label($pdo,(int)$u['id'],$subject_id,$type,$label);
        if($existing){
          $sort=next_teacher_participation_option_sort($pdo,$type,(int)$u['id'],$subject_id);
          $pdo->prepare("UPDATE participation_options
                         SET archived=0, active=1, sort=?, pedagogical_hint_mode=?
                         WHERE id=? AND scope='teacher' AND teacher_id=?")
              ->execute([$sort,$type==='reason' ? $pedagogical_hint_mode : null,(int)$existing['id'],(int)$u['id']]);
        } else {
          $sort=next_teacher_participation_option_sort($pdo,$type,(int)$u['id'],$subject_id);
          $pdo->prepare("INSERT INTO participation_options
                         (opt_type,scope,teacher_id,subject_id,label,pedagogical_hint_mode,sort,active,archived,created_at)
                         VALUES (?,?,?,?,?,?,?,1,0,?)")
              ->execute([$type,'teacher',(int)$u['id'],participation_option_target_subject_id($subject_id),$label,$type==='reason' ? $pedagogical_hint_mode : null,$sort,now_iso()]);
        }
        emit_event('teacher_option_created',['type'=>$type,'label'=>$label,'subject_id'=>$subject_id?:null]);
        $pdo->commit();
        teacher_options_redirect($bp,$type,$subject_id,$show_archived,'added');
      }
    }

    if($a==='edit'){
      $display_id=(int)($_POST['id'] ?? 0);
      $label=trim((string)($_POST['label'] ?? ''));
      $pedagogical_hint_mode=($type==='reason') ? participation_reason_mode_normalize((string)($_POST['pedagogical_hint_mode'] ?? 'auto')) : 'auto';
      if($label===''){
        $err='Bitte Bezeichnung eingeben.';
      } else {
        $pdo->beginTransaction();
        $mat=materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
        $target_id=(int)($mat['map'][$display_id] ?? 0);
        if(!$target_id){
          $pdo->rollBack();
          $err='Option nicht gefunden.';
        } else {
          $dup=participation_option_find_exact_by_label($pdo,(int)$u['id'],$subject_id,$type,$label);
          if($dup && (int)$dup['id']!==$target_id){
            $pdo->rollBack();
            $err='Diese Bezeichnung existiert bereits.';
          } else {
            $pdo->prepare("UPDATE participation_options SET label=?, pedagogical_hint_mode=? WHERE id=? AND scope='teacher' AND teacher_id=?")
                ->execute([$label,$type==='reason' ? $pedagogical_hint_mode : null,$target_id,(int)$u['id']]);
            emit_event('teacher_option_updated',['id'=>$target_id,'type'=>$type,'subject_id'=>$subject_id?:null]);
            $pdo->commit();
            teacher_options_redirect($bp,$type,$subject_id,$show_archived,'saved');
          }
        }
      }
    }

    if($a==='toggle'){
      $display_id=(int)($_POST['id'] ?? 0);
      $pdo->beginTransaction();
      $mat=materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
      $target_id=(int)($mat['map'][$display_id] ?? 0);
      if(!$target_id){
        $pdo->rollBack();
        $err='Option nicht gefunden.';
      } else {
        $pdo->prepare("UPDATE participation_options
                       SET active=1-active
                       WHERE id=? AND scope='teacher' AND teacher_id=? AND IFNULL(archived,0)=0")
            ->execute([$target_id,(int)$u['id']]);
        emit_event('teacher_option_toggled',['id'=>$target_id,'type'=>$type,'subject_id'=>$subject_id?:null]);
        $pdo->commit();
        teacher_options_redirect($bp,$type,$subject_id,$show_archived,'toggled');
      }
    }

    if($a==='delete'){
      $display_id=(int)($_POST['id'] ?? 0);
      $pdo->beginTransaction();
      $mat=materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
      $target_id=(int)($mat['map'][$display_id] ?? 0);
      if(!$target_id){
        $pdo->rollBack();
        $err='Option nicht gefunden.';
      } else {
        $used=option_used_count($pdo,$target_id,$type);
        $pdo->prepare("UPDATE participation_options
                       SET archived=1, active=0
                       WHERE id=? AND scope='teacher' AND teacher_id=?")
            ->execute([$target_id,(int)$u['id']]);
        emit_event($used>0?'teacher_option_archived':'teacher_option_deleted',[
          'id'=>$target_id,
          'type'=>$type,
          'subject_id'=>$subject_id?:null,
          'used'=>$used
        ]);
        $pdo->commit();
        teacher_options_redirect($bp,$type,$subject_id,$show_archived,'deleted');
      }
    }

    if($a==='restore'){
      $display_id=(int)($_POST['id'] ?? 0);
      $pdo->beginTransaction();
      $mat=materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
      $target_id=(int)($mat['map'][$display_id] ?? 0);
      if(!$target_id){
        $pdo->rollBack();
        $err='Option nicht gefunden.';
      } else {
        $pdo->prepare("UPDATE participation_options
                       SET archived=0, active=1
                       WHERE id=? AND scope='teacher' AND teacher_id=?")
            ->execute([$target_id,(int)$u['id']]);
        emit_event('teacher_option_restored',['id'=>$target_id,'type'=>$type,'subject_id'=>$subject_id?:null]);
        $pdo->commit();
        teacher_options_redirect($bp,$type,$subject_id,$show_archived,'restored');
      }
    }

    if($a==='move'){
      $display_id=(int)($_POST['id'] ?? 0);
      $direction=(string)($_POST['direction'] ?? '');
      if(!in_array($direction,['up','down'],true)){
        $err='Ungültige Sortierrichtung.';
      } else {
        $pdo->beginTransaction();
        $mat=materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
        $target_id=(int)($mat['map'][$display_id] ?? 0);
        if(!$target_id){
          $pdo->rollBack();
          $err='Option nicht gefunden.';
        } else {
          $targetSubjectId=participation_option_target_subject_id($subject_id);
          if($targetSubjectId===null){
            $st=$pdo->prepare("SELECT id FROM participation_options
                               WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id IS NULL AND IFNULL(archived,0)=0
                               ORDER BY sort,label,id");
            $st->execute([$type,(int)$u['id']]);
          } else {
            $st=$pdo->prepare("SELECT id FROM participation_options
                               WHERE opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id=? AND IFNULL(archived,0)=0
                               ORDER BY sort,label,id");
            $st->execute([$type,(int)$u['id'],$targetSubjectId]);
          }
          $ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
          $idx=array_search($target_id,$ids,true);
          if($idx!==false){
            $swapIdx=$direction==='up' ? $idx-1 : $idx+1;
            if(isset($ids[$swapIdx])){
              $tmp=$ids[$idx];
              $ids[$idx]=$ids[$swapIdx];
              $ids[$swapIdx]=$tmp;
              $upd=$pdo->prepare("UPDATE participation_options SET sort=? WHERE id=? AND scope='teacher' AND teacher_id=?");
              $sort=10;
              foreach($ids as $id){
                $upd->execute([$sort,$id,(int)$u['id']]);
                $sort+=10;
              }
            }
          }
          emit_event('teacher_option_reordered',['type'=>$type,'subject_id'=>$subject_id?:null,'method'=>'buttons']);
          $pdo->commit();
          teacher_options_redirect($bp,$type,$subject_id,$show_archived,'reordered');
        }
      }
    }
  }catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    $err='Fehler: '.$e->getMessage();
  }
}

$bundle=participation_option_effective_bundle($pdo,(int)$u['id'],$subject_id,$type,$show_archived);
$effective_opts=$bundle['rows'];
$info_text=teacher_options_info_text($bundle,$subject_id);

render_header('Picklisten',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Picklisten / Optionen</h1>
  <p class="muted">Du bearbeitest hier immer die für dich wirksame Liste. Sobald du etwas änderst, wird die aktuelle Standardliste automatisch in deine eigene Liste übernommen.</p>

  <div class="row" style="align-items:end;margin-top:10px">
    <div>
      <label class="muted">Liste</label>
      <select class="input" onchange="location.href='<?php echo h($bp); ?>/teacher/options.php?type='+this.value+'&subject_id=<?php echo (int)$subject_id; ?>&show_archived=<?php echo $show_archived?1:0; ?>'">
        <?php foreach($types as $k=>$v): ?><option value="<?php echo h($k); ?>" <?php echo $type===$k?'selected':''; ?>><?php echo h($v); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Fach (optional)</label>
      <select class="input" onchange="location.href='<?php echo h($bp); ?>/teacher/options.php?type=<?php echo h($type); ?>&subject_id='+this.value+'&show_archived=<?php echo $show_archived?1:0; ?>'">
        <option value="0" <?php echo $subject_id===0?'selected':''; ?>>– alle Fächer –</option>
        <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $subject_id===(int)$s['id']?'selected':''; ?>><?php echo h($s['code'].' – '.$s['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto">
      <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/options.php?type=<?php echo h($type); ?>&subject_id=<?php echo (int)$subject_id; ?>&show_archived=<?php echo $show_archived?0:1; ?>">
        <?php echo $show_archived?'Archiv ausblenden':'Archiv anzeigen'; ?>
      </a>
    </div>
  </div>

  <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

  <div class="card" style="margin-top:12px;border-color:#cfe5ff;background:#eef6ff">
    <b>Aktueller Stand:</b>
    <div class="muted" style="margin-top:6px"><?php echo h($info_text); ?></div>
    <div class="small muted" style="margin-top:6px">Quelle im Moment: <b><?php echo h($bundle['primary_source_label']); ?></b></div>
  </div>

  <div style="height:12px"></div>
  <h2>Optionen für dich</h2>

  <form method="post" class="card" style="border-style:dashed;background:rgba(71,142,79,.06)" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="type" value="<?php echo h($type); ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
    <div class="row" style="align-items:end">
      <div style="flex:1">
        <label class="muted">Bezeichnung</label>
        <input class="input" name="label" required placeholder="z.B. Mitarbeit freiwillig">
      </div>
      <?php if($type==='reason'): ?>
      <div style="min-width:220px">
        <label class="muted">Didaktische Tendenz</label>
        <select class="input" name="pedagogical_hint_mode">
          <?php foreach($reason_mode_choices as $modeValue=>$modeLabel): ?>
            <option value="<?php echo h($modeValue); ?>"><?php echo h($modeLabel); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div style="flex:0 0 auto">
        <label class="muted">Reihenfolge</label>
        <div class="muted" style="font-size:12px;padding:10px 0">per Drag &amp; Drop</div>
      </div>
      <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn small">Hinzufügen</button></div>
    </div>
  </form>

  <div style="height:10px"></div>
  <?php if($effective_opts): ?>
    <table class="table">
      <thead><tr><th style="width:44px">&nbsp;</th><th>Bezeichnung</th><?php if($type==='reason'): ?><th>Tendenz</th><?php endif; ?><th>Status</th><th>Aktion</th></tr></thead>
      <tbody id="teacherOptionsBody">
      <?php foreach($effective_opts as $o): ?>
        <?php
          $arch=(int)($o['archived']??0)===1;
          $source_label=participation_option_source_label((string)($o['source_key'] ?? 'global'));
          $modeValue=participation_reason_mode_normalize((string)($o['pedagogical_hint_mode'] ?? 'auto'));
        ?>
        <tr data-id="<?php echo (int)$o['id']; ?>" <?php echo $arch?'':'draggable="true" class="sortable-row"'; ?>>
          <td style="width:44px"><?php if(!$arch): ?><span class="drag-handle" title="Ziehen">☰</span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td data-label="Bezeichnung">
            <form method="post" class="inline-form">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="type" value="<?php echo h($type); ?>">
              <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
              <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
              <input class="input" style="min-width:260px" name="label" value="<?php echo h($o['label']); ?>" <?php echo $arch?'disabled':''; ?>>
              <?php if($type==='reason'): ?>
                <select class="input" name="pedagogical_hint_mode" style="min-width:180px" <?php echo $arch?'disabled':''; ?>>
                  <?php foreach($reason_mode_choices as $choiceValue=>$choiceLabel): ?>
                    <option value="<?php echo h($choiceValue); ?>" <?php echo $modeValue===$choiceValue?'selected':''; ?>><?php echo h($choiceLabel); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
              <?php if(!$arch): ?><button class="btn small secondary">Speichern</button><?php endif; ?>
            </form>
            <div class="small muted" style="margin-top:4px">Aktuelle Quelle: <?php echo h($source_label); ?></div>
          </td>
          <?php if($type==='reason'): ?>
          <td data-label="Tendenz">
            <span class="muted"><?php echo h($reason_mode_choices[$modeValue] ?? 'automatisch'); ?></span>
          </td>
          <?php endif; ?>
          <td data-label="Status">
            <?php
              if($arch) echo '<span class="badge warn">archiviert</span>';
              else echo ((int)$o['active']===1?'<span class="badge good">aktiv</span>':'<span class="badge warn">inaktiv</span>');
            ?>
          </td>
          <td data-label="Aktion" class="actions">
            <?php if(!$arch): ?>
              <form method="post" class="inline-form" title="Eine Position nach oben">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="direction" value="up">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small secondary" aria-label="<?php echo h($o['label']); ?> nach oben verschieben">↑</button>
              </form>
              <form method="post" class="inline-form" title="Eine Position nach unten">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="direction" value="down">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small secondary" aria-label="<?php echo h($o['label']); ?> nach unten verschieben">↓</button>
              </form>
              <form method="post" class="inline-form">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small secondary"><?php echo (int)$o['active']===1?'Deaktivieren':'Aktivieren'; ?></button>
              </form>
              <form method="post" class="inline-form" onsubmit="return confirm('Option wirklich löschen? Sie kann über das Archiv wiederhergestellt werden.');">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small danger">Löschen</button>
              </form>
            <?php else: ?>
              <form method="post" class="inline-form">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small secondary">Wiederherstellen</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">Für diese Liste sind aktuell keine Optionen vorhanden. Beim ersten Hinzufügen wird eine eigene Liste für dich angelegt.</p>
  <?php endif; ?>

  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
</div></div></div>

<script>
(function(){
  var body=document.getElementById('teacherOptionsBody');
  if(!body) return;
  var dragSrc=null;
  var armedRow=null;

  function rowFromEvent(e){
    var tr=e.target;
    while(tr && tr.tagName!=='TR') tr=tr.parentNode;
    return tr;
  }

  function handleFromEvent(e){
    if(!e.target || !e.target.closest) return null;
    return e.target.closest('.drag-handle');
  }

  body.addEventListener('mousedown', function(e){
    var handle=handleFromEvent(e);
    armedRow=handle ? rowFromEvent(e) : null;
  });

  body.addEventListener('mouseup', function(){
    armedRow=null;
  });

  body.addEventListener('dragstart', function(e){
    var tr=rowFromEvent(e);
    if(!tr || !tr.classList.contains('sortable-row')) { e.preventDefault(); return; }
    if(armedRow !== tr && !handleFromEvent(e)) { e.preventDefault(); return; }
    dragSrc=tr;
    tr.classList.add('dragging');
    e.dataTransfer.effectAllowed='move';
    try{ e.dataTransfer.setData('text/plain', tr.getAttribute('data-id')||''); }catch(err){}
  });

  body.addEventListener('dragend', function(e){
    var tr=rowFromEvent(e);
    if(tr) tr.classList.remove('dragging');
    dragSrc=null;
    armedRow=null;
  });

  body.addEventListener('dragover', function(e){
    if(!dragSrc) return;
    e.preventDefault();
    e.dataTransfer.dropEffect='move';
    var tr=rowFromEvent(e);
    if(!tr || tr===dragSrc || !tr.classList.contains('sortable-row')) return;
    var rect=tr.getBoundingClientRect();
    var next=(e.clientY-rect.top) > (rect.height/2);
    body.insertBefore(dragSrc, next ? tr.nextSibling : tr);
  });

  body.addEventListener('drop', function(e){
    if(!dragSrc) return;
    e.preventDefault();
    dragSrc.classList.remove('dragging');
    dragSrc=null;

    var ids=[].map.call(body.querySelectorAll('tr.sortable-row'), function(tr){ return tr.getAttribute('data-id'); }).filter(Boolean);
    if(!ids.length) return;

    var tokenEl=document.querySelector('meta[name="csrf-token"]');
    if(!tokenEl) return;

    var fd=new FormData();
    fd.append('type', '<?php echo h($type); ?>');
    fd.append('subject_id', '<?php echo (int)$subject_id; ?>');
    ids.forEach(function(id){ fd.append('ids[]', id); });
    fd.append('_csrf', tokenEl.getAttribute('content') || '');

    fetch('<?php echo h($bp); ?>/teacher/options_reorder.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return {ok:false}; }); })
      .then(function(j){
        if(!j || !j.ok){
          window.alert('Die Reihenfolge konnte nicht gespeichert werden.');
          return;
        }
        window.location.reload();
      })
      .catch(function(){
        window.alert('Die Reihenfolge konnte nicht gespeichert werden.');
      });
  });
})();
</script>

<?php render_footer(); ?>
