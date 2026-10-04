<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/competence_observations.php';

$u=require_role('admin');
$pdo=db();
$bp=cfg()['base_path'];

$categories=competence_category_labels();

$category=$_GET['category'] ?? $_POST['category'] ?? 'methoden';
if(!isset($categories[$category])) $category='methoden';

$show_archived = isset($_GET['show_archived']) && (string)$_GET['show_archived']==='1';

function next_competence_tag_sort(PDO $pdo, string $category): int {
  $st=$pdo->prepare("SELECT COALESCE(MAX(sort),0) AS m FROM competence_tags WHERE category=?");
  $st->execute([$category]);
  return (int)($st->fetch()['m']??0) + 10;
}

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';

  if($a==='add'){
    $label=trim($_POST['label']??'');
    if($label==='') $err='Bitte Bezeichnung eingeben.';
    else{
      try{
        $sort=next_competence_tag_sort($pdo,$category);
        $st=$pdo->prepare("INSERT INTO competence_tags (category,label,sort,active,archived) VALUES (?,?,?,1,0)");
        $st->execute([$category,$label,$sort]);
        emit_event('admin_competence_tag_created',['category'=>$category,'label'=>$label]);
        $msg='Kompetenz-Tag angelegt.';
      }catch(Exception $e){
        $err='Diese Bezeichnung existiert in dieser Kategorie bereits.';
      }
    }
  }

  if($a==='edit'){
    $id=(int)($_POST['id']??0);
    $label=trim($_POST['label']??'');
    if($label==='') $err='Bitte Bezeichnung eingeben.';
    else{
      try{
        $st=$pdo->prepare("UPDATE competence_tags SET label=? WHERE id=? AND category=?");
        $st->execute([$label,$id,$category]);
        emit_event('admin_competence_tag_updated',['id'=>$id,'category'=>$category,'label'=>$label]);
        $msg='Kompetenz-Tag gespeichert.';
      }catch(Exception $e){
        $err='Diese Bezeichnung existiert in dieser Kategorie bereits.';
      }
    }
  }

  if($a==='toggle'){
    $id=(int)($_POST['id']??0);
    $st=$pdo->prepare("UPDATE competence_tags SET active=1-active WHERE id=? AND category=? AND IFNULL(archived,0)=0");
    $st->execute([$id,$category]);
    emit_event('admin_competence_tag_toggled',['id'=>$id,'category'=>$category]);
    $msg='Status geändert.';
  }

  if($a==='delete'){
    $id=(int)($_POST['id']??0);
    $used=competence_tag_used_count($pdo,$id);
    if($used>0){
      $st=$pdo->prepare("UPDATE competence_tags SET archived=1, active=0 WHERE id=? AND category=?");
      $st->execute([$id,$category]);
      emit_event('admin_competence_tag_archived',['id'=>$id,'category'=>$category,'used'=>$used]);
      $msg='Tag archiviert (bereits in Beobachtungen verwendet).';
    } else {
      $st=$pdo->prepare("DELETE FROM competence_tags WHERE id=? AND category=?");
      $st->execute([$id,$category]);
      emit_event('admin_competence_tag_deleted',['id'=>$id,'category'=>$category]);
      $msg='Tag gelöscht.';
    }
  }

  if($a==='restore'){
    $id=(int)($_POST['id']??0);
    $st=$pdo->prepare("UPDATE competence_tags SET archived=0, active=1 WHERE id=? AND category=?");
    $st->execute([$id,$category]);
    emit_event('admin_competence_tag_restored',['id'=>$id,'category'=>$category]);
    $msg='Tag wiederhergestellt.';
  }

  header('Location: '.$bp.'/admin/competence_tags.php?category='.$category.'&show_archived='.($show_archived?1:0));
  exit;
}

$where = $show_archived ? '1=1' : 'IFNULL(archived,0)=0';
$st=$pdo->prepare("SELECT * FROM competence_tags WHERE category=? AND $where ORDER BY IFNULL(archived,0) ASC, active DESC, sort, label");
$st->execute([$category]);
$tags=$st->fetchAll();

render_header('Kompetenz-Tags (Admin)',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Kompetenz-Tags (Admin)</h1>
  <p class="muted">Hier verwaltest du die feste Tag-Liste je Kompetenzkategorie, die Lehrkräfte in der <b>Kompetenz-Beobachtung</b> (<code>Kompetenz-Beobachtung</code> im Lehrkraft-Dashboard) auswählen können.</p>

  <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

  <div class="row" style="align-items:end;margin-top:10px">
    <div>
      <label class="muted">Kategorie</label>
      <select class="input" onchange="location.href='<?php echo h($bp); ?>/admin/competence_tags.php?show_archived=<?php echo $show_archived?1:0; ?>&category='+this.value">
        <?php foreach($categories as $k=>$v): ?><option value="<?php echo h($k); ?>" <?php echo $category===$k?'selected':''; ?>><?php echo h($v); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 0 auto">
      <a class="btn secondary" href="<?php echo h($bp); ?>/admin/competence_tags.php?category=<?php echo h($category); ?>&show_archived=<?php echo $show_archived?0:1; ?>">
        <?php echo $show_archived?'Archiv ausblenden':'Archiv anzeigen'; ?>
      </a>
    </div>
  </div>

  <div style="height:12px"></div>
  <form method="post" class="card" style="border-style:dashed;background:rgba(71,142,79,.06)" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="category" value="<?php echo h($category); ?>">
    <div class="row" style="align-items:end">
      <div style="flex:1">
        <label class="muted">Bezeichnung</label>
        <input class="input" name="label" required>
      </div>
      <div style="flex:0 0 auto">
        <label class="muted">Reihenfolge</label>
        <div class="muted" style="font-size:12px;padding:10px 0">per Drag &amp; Drop</div>
      </div>
      <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn small">Hinzufügen</button></div>
    </div>
  </form>

  <div style="height:10px"></div>
  <?php if($tags): ?>
    <table class="table">
      <thead><tr><th style="width:44px">&nbsp;</th><th>Bezeichnung</th><th>Status</th><th>Aktion</th></tr></thead>
      <tbody id="competenceTagsBody">
      <?php foreach($tags as $t): ?>
        <?php $arch=(int)($t['archived']??0)===1; ?>
        <tr data-id="<?php echo (int)$t['id']; ?>" <?php echo $arch?'':'draggable="true" class="sortable-row"'; ?>>
          <td style="width:44px"><?php if(!$arch): ?><span class="drag-handle" title="Ziehen">☰</span><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td data-label="Bezeichnung">
            <form method="post" class="inline-form">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="edit">
              <input type="hidden" name="category" value="<?php echo h($category); ?>">
              <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
              <input class="input" style="min-width:320px" name="label" value="<?php echo h($t['label']); ?>" <?php echo $arch?'disabled':''; ?>>
              <?php if(!$arch): ?><button class="btn small secondary">Speichern</button><?php endif; ?>
            </form>
          </td>
          <td data-label="Status">
            <?php
              if($arch) echo '<span class="badge warn">archiviert</span>';
              else echo ((int)$t['active']===1?'<span class="badge good">aktiv</span>':'<span class="badge warn">inaktiv</span>');
            ?>
          </td>
          <td data-label="Aktion" class="actions">
            <?php if(!$arch): ?>
              <form method="post" class="inline-form">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="category" value="<?php echo h($category); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="btn small secondary"><?php echo (int)$t['active']===1?'Deaktivieren':'Aktivieren'; ?></button>
              </form>
              <form method="post" class="inline-form" onsubmit="return confirm('Tag löschen/archivieren? (Bereits verwendete Tags werden archiviert)')">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="category" value="<?php echo h($category); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="btn small danger">Löschen</button>
              </form>
            <?php else: ?>
              <form method="post" class="inline-form">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="category" value="<?php echo h($category); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="btn small secondary">Wiederherstellen</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted">Noch keine Tags in dieser Kategorie.</p>
  <?php endif; ?>

  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h($bp); ?>/admin/manage.php">Zurück</a>
</div></div></div>
<script>
(function(){
  var body=document.getElementById('competenceTagsBody');
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
    fd.append('category', '<?php echo h($category); ?>');
    ids.forEach(function(id){ fd.append('ids[]', id); });
    fd.append('_csrf', tokenEl.getAttribute('content') || '');

    fetch('<?php echo h($bp); ?>/admin/competence_tags_reorder.php', {method:'POST', body:fd, credentials:'same-origin'})
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
