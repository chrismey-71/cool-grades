<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/participation_pedagogical_mode.php';

$u=require_role('admin');
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

$scope=$_GET['scope'] ?? $_POST['scope'] ?? 'global';
if(!in_array($scope,['global','subject'],true)) $scope='global';

$subjects=$pdo->query("SELECT id,code,name FROM subjects ORDER BY code")->fetchAll();
$subject_id=(int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0);
if($scope==='subject' && !$subject_id && $subjects){ $subject_id=(int)$subjects[0]['id']; }

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

function next_admin_option_sort(PDO $pdo, string $type, string $scope, int $subject_id): int {
  if($scope === 'subject'){
    $st=$pdo->prepare("SELECT COALESCE(MAX(sort),0) AS m FROM participation_options WHERE opt_type=? AND scope='subject' AND subject_id=?");
    $st->execute([$type,$subject_id]);
  } else {
    $st=$pdo->prepare("SELECT COALESCE(MAX(sort),0) AS m FROM participation_options WHERE opt_type=? AND scope='global'");
    $st->execute([$type]);
  }
  $m=(int)($st->fetch()['m']??0);
  return $m + 10;
}

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';

  $sub = $scope==='subject' ? (int)($_POST['subject_id']??0) : 0;

  if($a==='add'){
    $label=trim($_POST['label']??'');
    $pedagogical_hint_mode=($type==='reason') ? participation_reason_mode_normalize((string)($_POST['pedagogical_hint_mode'] ?? 'auto')) : 'auto';
    if($label==='') $err='Bitte Bezeichnung eingeben.';
    else{
      $sort=next_admin_option_sort($pdo,$type,$scope,$sub?:0);
      $st=$pdo->prepare("INSERT INTO participation_options (opt_type,scope,teacher_id,subject_id,label,pedagogical_hint_mode,sort,active,archived) VALUES (?,?,?,?,?,?,?,1,0)");
      $st->execute([$type,$scope,null,$sub?:null,$label,$type==='reason' ? $pedagogical_hint_mode : null,$sort]);
      emit_event('admin_option_created',['type'=>$type,'scope'=>$scope,'subject_id'=>$sub?:null,'label'=>$label]);
      $msg='Option angelegt.';
    }
  }

  if($a==='edit'){
    $id=(int)($_POST['id']??0);
    $label=trim($_POST['label']??'');
    $pedagogical_hint_mode=($type==='reason') ? participation_reason_mode_normalize((string)($_POST['pedagogical_hint_mode'] ?? 'auto')) : 'auto';
    if($label==='') $err='Bitte Bezeichnung eingeben.';
    else{
      $st=$pdo->prepare("UPDATE participation_options SET label=?, pedagogical_hint_mode=? WHERE id=? AND scope=?");
      $st->execute([$label,$type==='reason' ? $pedagogical_hint_mode : null,$id,$scope]);
      emit_event('admin_option_updated',['id'=>$id,'type'=>$type,'scope'=>$scope]);
      $msg='Option gespeichert.';
    }
  }

  if($a==='toggle'){
    $id=(int)($_POST['id']??0);
    $st=$pdo->prepare("UPDATE participation_options SET active=1-active WHERE id=? AND scope=? AND IFNULL(archived,0)=0");
    $st->execute([$id,$scope]);
    emit_event('admin_option_toggled',['id'=>$id,'type'=>$type,'scope'=>$scope]);
    $msg='Status geändert.';
  }

  if($a==='delete'){
    $id=(int)($_POST['id']??0);
    $used=option_used_count($pdo,$id,$type);
    if($used>0){
      $st=$pdo->prepare("UPDATE participation_options SET archived=1, active=0 WHERE id=? AND scope=?");
      $st->execute([$id,$scope]);
      emit_event('admin_option_archived',['id'=>$id,'type'=>$type,'scope'=>$scope,'used'=>$used]);
      $msg='Option archiviert (bereits verwendet).';
    } else {
      $st=$pdo->prepare("DELETE FROM participation_options WHERE id=? AND scope=?");
      $st->execute([$id,$scope]);
      emit_event('admin_option_deleted',['id'=>$id,'type'=>$type,'scope'=>$scope]);
      $msg='Option gelöscht.';
    }
  }

  if($a==='restore'){
    $id=(int)($_POST['id']??0);
    $st=$pdo->prepare("UPDATE participation_options SET archived=0, active=1 WHERE id=? AND scope=?");
    $st->execute([$id,$scope]);
    emit_event('admin_option_restored',['id'=>$id,'type'=>$type,'scope'=>$scope]);
    $msg='Option wiederhergestellt.';
  }

  header('Location: '.$bp.'/admin/options.php?type='.$type.'&scope='.$scope.'&subject_id='.$subject_id.'&show_archived='.($show_archived?1:0));
  exit;
}

$where = $show_archived ? '1=1' : 'IFNULL(archived,0)=0';

if($scope==='global'){
  $st=$pdo->prepare("SELECT * FROM participation_options WHERE opt_type=? AND scope='global' AND $where ORDER BY IFNULL(archived,0) ASC, active DESC, sort, label");
  $st->execute([$type]);
} else {
  $st=$pdo->prepare("SELECT * FROM participation_options WHERE opt_type=? AND scope='subject' AND subject_id=? AND $where ORDER BY IFNULL(archived,0) ASC, active DESC, sort, label");
  $st->execute([$type,$subject_id]);
}
$options=$st->fetchAll();

render_header('Picklisten (Admin)',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Picklisten / Optionen (Admin)</h1>
  <p class="muted">Hier verwaltest du die <b>globalen</b> (und optional <b>fachbezogenen</b>) Optionen. Lehrkräfte können globale Einträge kopieren und lokal anpassen.</p>

  <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

  <div class="row" style="align-items:end;margin-top:10px">
    <div>
      <label class="muted">Liste</label>
      <select class="input" onchange="location.href='<?php echo h($bp); ?>/admin/options.php?scope=<?php echo h($scope); ?>&subject_id=<?php echo (int)$subject_id; ?>&show_archived=<?php echo $show_archived?1:0; ?>&type='+this.value">
        <?php foreach($types as $k=>$v): ?><option value="<?php echo h($k); ?>" <?php echo $type===$k?'selected':''; ?>><?php echo h($v); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="muted">Scope</label>
      <select class="input" onchange="location.href='<?php echo h($bp); ?>/admin/options.php?type=<?php echo h($type); ?>&show_archived=<?php echo $show_archived?1:0; ?>&scope='+this.value+'&subject_id=<?php echo (int)$subject_id; ?>'">
        <option value="global" <?php echo $scope==='global'?'selected':''; ?>>global</option>
        <option value="subject" <?php echo $scope==='subject'?'selected':''; ?>>subject (fachbezogen)</option>
      </select>
    </div>
    <div <?php echo $scope==='subject'?'':'style="display:none"'; ?> id="subWrap">
      <label class="muted">Fach</label>
      <select class="input" onchange="location.href='<?php echo h($bp); ?>/admin/options.php?type=<?php echo h($type); ?>&scope=subject&show_archived=<?php echo $show_archived?1:0; ?>&subject_id='+this.value">
        <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $subject_id===(int)$s['id']?'selected':''; ?>><?php echo h($s['code'].' – '.$s['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto">
      <a class="btn secondary" href="<?php echo h($bp); ?>/admin/options.php?type=<?php echo h($type); ?>&scope=<?php echo h($scope); ?>&subject_id=<?php echo (int)$subject_id; ?>&show_archived=<?php echo $show_archived?0:1; ?>">
        <?php echo $show_archived?'Archiv ausblenden':'Archiv anzeigen'; ?>
      </a>
    </div>
  </div>

  <div style="height:12px"></div>
  <form method="post" class="card" style="border-style:dashed;background:rgba(71,142,79,.06)" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="type" value="<?php echo h($type); ?>">
    <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
    <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
    <div class="row" style="align-items:end">
      <div style="flex:1">
        <label class="muted">Bezeichnung</label>
        <input class="input" name="label" required>
      </div>
      <?php if($type==='reason'): ?>
      <div>
        <label class="muted">Didaktische Tendenz</label>
        <select class="input" name="pedagogical_hint_mode">
          <?php foreach($reason_mode_choices as $mode_value=>$mode_label): ?>
            <option value="<?php echo h($mode_value); ?>"><?php echo h($mode_label); ?></option>
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
  <?php if($options): ?>
    <table class="table">
      <thead><tr><th style="width:44px">&nbsp;</th><th>Bezeichnung</th><?php if($type==='reason'): ?><th>Tendenz</th><?php endif; ?><th>Status</th><th>Aktion</th></tr></thead>
      <tbody id="adminOptionsBody">
      <?php foreach($options as $o): ?>
        <?php
          $arch=(int)($o['archived']??0)===1;
          $modeValue=participation_reason_mode_normalize((string)($o['pedagogical_hint_mode'] ?? 'auto'));
        ?>
        <tr data-id="<?php echo (int)$o['id']; ?>" <?php echo $arch?'':'draggable="true" class="sortable-row"'; ?>>
          <td style="width:44px"><?php if(!$arch): ?><span class="drag-handle" title="Ziehen">☰</span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td data-label="Bezeichnung">
            <form method="post" class="inline-form">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="type" value="<?php echo h($type); ?>">
              <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
              <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
              <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
              <input class="input" style="min-width:280px" name="label" value="<?php echo h($o['label']); ?>" <?php echo $arch?'disabled':''; ?>>
              <?php if($type==='reason'): ?>
                <select class="input" name="pedagogical_hint_mode" style="min-width:180px" <?php echo $arch?'disabled':''; ?>>
                  <?php foreach($reason_mode_choices as $mode_key=>$mode_label): ?>
                    <option value="<?php echo h($mode_key); ?>" <?php echo $modeValue===$mode_key?'selected':''; ?>><?php echo h($mode_label); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
              <?php if(!$arch): ?><button class="btn small secondary">Speichern</button><?php endif; ?>
            </form>
          </td>
          <?php if($type==='reason'): ?>
          <td data-label="Tendenz">
            <span class="muted"><?php echo h($reason_mode_choices[$modeValue] ?? $reason_mode_choices['auto']); ?></span>
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
              <form method="post" class="inline-form">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small secondary"><?php echo (int)$o['active']===1?'Deaktivieren':'Aktivieren'; ?></button>
              </form>
              <form method="post" class="inline-form" onsubmit="return confirm('Option löschen/archivieren? (Benutzte Optionen werden archiviert)')">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
                <button class="btn small danger">Löschen</button>
              </form>
            <?php else: ?>
              <form method="post" class="inline-form">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="type" value="<?php echo h($type); ?>">
                <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
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
    <p class="muted">Noch keine Optionen.</p>
  <?php endif; ?>

  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/admin/manage.php">Zurück</a>
</div></div></div>
<script>
(function(){
  var body=document.getElementById('adminOptionsBody');
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
    fd.append('scope', '<?php echo h($scope); ?>');
    fd.append('subject_id', '<?php echo (int)$subject_id; ?>');
    ids.forEach(function(id){ fd.append('ids[]', id); });
    fd.append('_csrf', tokenEl.getAttribute('content') || '');

    fetch('<?php echo h($bp); ?>/admin/options_reorder.php', {method:'POST', body:fd, credentials:'same-origin'})
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
