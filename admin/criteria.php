<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__ . '/_crud.php';

$u = require_role('admin');
$pdo = db();
$bp = cfg()['base_path'];

$subjects = $pdo->query("SELECT id,code,name FROM subjects ORDER BY code")->fetchAll();

$msg='';
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';

  if($a==='create_set'){
    $name=trim($_POST['name']??'');
    $subject_id=(int)($_POST['subject_id']??0);
    if($name===''||!$subject_id){
      $err='Bitte Name und Fach wählen.';
    } else {
      $sid=upsert('criteria_sets',[ 'name'=>$name,'scope'=>'subject','subject_id'=>$subject_id,'teacher_id'=>null ],null);
      emit_event('admin_criteria_set_created',['target_id'=>$sid,'target_name'=>$name,'subject_id'=>$subject_id]);
      header('Location: '.$bp.'/admin/criteria.php?set='.$sid);
      exit;
    }
  }

  if($a==='add'){
    $set_id=(int)($_POST['set_id']??0);
    $label=trim($_POST['label']??'');
    $cat=trim($_POST['category']??'');
    if($label===''){
      $err='Bitte Kriterium eingeben.';
    } else {
      $cid=upsert('criteria',[ 'criteria_set_id'=>$set_id,'label'=>$label,'category'=>$cat,'active'=>1,'archived'=>0 ],null);
      emit_event('admin_criterion_created',['target_id'=>$cid,'target_name'=>$label,'set_id'=>$set_id]);
      header('Location: '.$bp.'/admin/criteria.php?set='.$set_id);
      exit;
    }
  }

  if($a==='toggle'){
    $id=(int)($_POST['id']??0);
    $set_id=(int)($_POST['set_id']??0);
    $pdo->prepare("UPDATE criteria SET active=1-active WHERE id=? AND IFNULL(archived,0)=0")->execute([$id]);
    emit_event('admin_criterion_toggled',['target_id'=>$id,'set_id'=>$set_id]);
    header('Location: '.$bp.'/admin/criteria.php?set='.$set_id);
    exit;
  }

  if($a==='delete'){
    $id=(int)($_POST['id']??0);
    $set_id=(int)($_POST['set_id']??0);
    $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_event_criteria WHERE criteria_id=?");
    $st->execute([$id]);
    $used=(int)($st->fetch()['c']??0);
    if($used>0){
      $pdo->prepare("UPDATE criteria SET archived=1, active=0 WHERE id=?")->execute([$id]);
      emit_event('admin_criterion_archived',['target_id'=>$id,'set_id'=>$set_id,'used'=>$used]);
    } else {
      $pdo->prepare("DELETE FROM criteria WHERE id=?")->execute([$id]);
      emit_event('admin_criterion_deleted',['target_id'=>$id,'set_id'=>$set_id]);
    }
    header('Location: '.$bp.'/admin/criteria.php?set='.$set_id);
    exit;
  }

  if($a==='restore'){
    $id=(int)($_POST['id']??0);
    $set_id=(int)($_POST['set_id']??0);
    $pdo->prepare("UPDATE criteria SET archived=0, active=1 WHERE id=?")->execute([$id]);
    emit_event('admin_criterion_restored',['target_id'=>$id,'set_id'=>$set_id]);
    header('Location: '.$bp.'/admin/criteria.php?set='.$set_id.'&show_archived=1');
    exit;
  }
}

$setsSt=$pdo->query("SELECT cs.*, s.code AS subject_code, s.name AS subject_name
                     FROM criteria_sets cs
                     LEFT JOIN subjects s ON s.id=cs.subject_id
                     WHERE cs.scope='subject'
                     ORDER BY s.code, cs.id DESC");
$sets=$setsSt->fetchAll();

$setId=!empty($_GET['set'])?(int)$_GET['set']:(count($sets)?(int)$sets[0]['id']:0);
$show_archived = isset($_GET['show_archived']) && (string)$_GET['show_archived']==='1';

$current=null;
$criteria=[];
if($setId){
  $st=$pdo->prepare("SELECT cs.*, s.code AS subject_code, s.name AS subject_name
                     FROM criteria_sets cs
                     LEFT JOIN subjects s ON s.id=cs.subject_id
                     WHERE cs.id=? AND cs.scope='subject'");
  $st->execute([$setId]);
  $current=$st->fetch();
  if($current){
    $where = $show_archived ? '1=1' : 'IFNULL(c.archived,0)=0';
    $st=$pdo->prepare("SELECT c.*, (
                        SELECT COUNT(*) FROM participation_event_criteria pec WHERE pec.criteria_id=c.id
                      ) AS used_count
                      FROM criteria c
                      WHERE c.criteria_set_id=? AND $where
                      ORDER BY IFNULL(c.archived,0) ASC, c.active DESC, c.category, c.label");
    $st->execute([$setId]);
    $criteria=$st->fetchAll();
  }
}

render_header('Kriterien (Admin)',$u);
?>
<div class="grid">
  <div class="col-12"><div class="card">
    <h1>Fach-Kriterien-Sets (global)</h1>
    <p class="muted">Diese Sets gelten für alle Lehrkräfte (fachbezogen). Lehrkräfte können zusätzlich eigene Sets anlegen.</p>

    <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

    <div class="card-grid" style="margin-top:10px">
      <?php foreach($sets as $s): ?>
        <?php
          $title = ($s['subject_code']??'').': '.$s['name'];
          $meta  = ($s['subject_name']??'');
        ?>
        <a class="set-card <?php echo ($setId===(int)$s['id'])?'active':''; ?>" href="<?php echo h($bp); ?>/admin/criteria.php?set=<?php echo (int)$s['id']; ?>">
          <div style="font-weight:900"><?php echo h($title); ?></div>
          <div class="meta"><?php echo h($meta); ?></div>
        </a>
      <?php endforeach; ?>

      <div class="set-card" style="border-style:dashed">
        <div style="font-weight:900">Neues Set</div>
        <div class="meta">für ein Fach</div>
        <div style="height:10px"></div>
        <form method="post" <?php echo dirty_form_attrs(); ?>>
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="create_set">
          <label class="muted">Name</label>
          <input class="input" name="name" required placeholder="z.B. Basis-Kriterien">
          <div style="height:8px"></div>
          <label class="muted">Fach</label>
          <select class="input" name="subject_id" required>
            <?php foreach($subjects as $sub): ?><option value="<?php echo (int)$sub['id']; ?>"><?php echo h($sub['code'].' – '.$sub['name']); ?></option><?php endforeach; ?>
          </select>
          <div style="height:10px"></div>
          <button class="btn small">Anlegen</button>
        </form>
      </div>
    </div>

    <div style="height:12px"></div>
    <a class="btn secondary" href="<?php echo h($bp); ?>/admin/manage.php">Zurück</a>
  </div></div>

  <div class="col-12"><div class="card">
    <h1><?php echo h($current ? ($current['subject_code'].' – '.$current['name']) : 'Kein Set'); ?></h1>

    <?php if($current): ?>
      <form method="post" class="card" style="background:rgba(71,142,79,.06);border-style:dashed" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
        <div class="row">
          <div style="flex:1"><label class="muted">Kriterium</label><input class="input" name="label" required></div>
          <div style="flex:1"><label class="muted">Kategorie (optional)</label><input class="input" name="category"></div>
        </div>
        <div style="height:10px"></div>
        <button class="btn small">Hinzufügen</button>
        <a class="btn small secondary" href="<?php echo h($bp); ?>/admin/criteria.php?set=<?php echo (int)$setId; ?>&show_archived=<?php echo $show_archived?0:1; ?>">
          <?php echo $show_archived?'Archiv ausblenden':'Archiv anzeigen'; ?>
        </a>
      </form>

      <div style="height:10px"></div>
      <?php if($criteria): ?>
        <table class="table">
          <thead><tr><th>Kriterium</th><th>Kategorie</th><th>Status</th><th>Benutzt</th><th>Aktion</th></tr></thead>
          <tbody>
          <?php foreach($criteria as $c): ?>
            <?php $arch = (int)($c['archived']??0)===1; $used=(int)($c['used_count']??0); ?>
            <tr>
              <td data-label="Kriterium"><?php echo h($c['label']); ?></td>
              <td data-label="Kategorie" class="muted"><?php echo h($c['category']??''); ?></td>
              <td data-label="Status">
                <?php
                  if($arch) echo '<span class="badge warn">archiviert</span>';
                  else echo ($c['active']?'<span class="badge good">aktiv</span>':'<span class="badge warn">inaktiv</span>');
                ?>
              </td>
              <td data-label="Benutzt"><?php echo $used>0 ? h((string)$used) : '–'; ?></td>
              <td data-label="Aktion" class="actions">
                <?php if(!$arch): ?>
                  <form method="post" class="inline-form">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                    <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
                    <button class="btn small secondary"><?php echo $c['active']?'Deaktivieren':'Aktivieren'; ?></button>
                  </form>
                  <form method="post" class="inline-form" onsubmit="return confirm('Kriterium löschen/archivieren? (Benutzte Kriterien werden archiviert)')">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                    <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
                    <button class="btn small danger" type="submit">Löschen</button>
                  </form>
                <?php else: ?>
                  <form method="post" class="inline-form">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                    <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
                    <button class="btn small secondary" type="submit">Wiederherstellen</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="muted">Noch keine Kriterien. Füge Kriterien hinzu.</p>
      <?php endif; ?>

    <?php else: ?>
      <p class="muted">Lege oben ein Set an, um Kriterien zu verwalten.</p>
    <?php endif; ?>
  </div></div>
</div>
<?php render_footer(); ?>
