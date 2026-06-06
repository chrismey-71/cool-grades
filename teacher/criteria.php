<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__ . '/../admin/_crud.php';

$u = require_role('teacher');
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
      $sid=upsert('criteria_sets',[
        'name'=>$name,
        'scope'=>'teacher',
        'subject_id'=>$subject_id,
        'teacher_id'=>(int)$u['id']
      ],null);
      emit_event('teacher_criteria_set_created',['target_id'=>$sid,'target_name'=>$name,'subject_id'=>$subject_id]);
      header('Location: '.$bp.'/teacher/criteria.php?set='.$sid);
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
      emit_event('teacher_criterion_created',['target_id'=>$cid,'target_name'=>$label,'set_id'=>$set_id]);
      header('Location: '.$bp.'/teacher/criteria.php?set='.$set_id);
      exit;
    }
  }

  if($a==='toggle'){
    $id=(int)($_POST['id']??0);
    $set_id=(int)($_POST['set_id']??0);
    $pdo->prepare("UPDATE criteria SET active=1-active WHERE id=? AND IFNULL(archived,0)=0")->execute([$id]);
    emit_event('teacher_criterion_toggled',['target_id'=>$id,'set_id'=>$set_id]);
    header('Location: '.$bp.'/teacher/criteria.php?set='.$set_id);
    exit;
  }

  if($a==='delete'){
    $id=(int)($_POST['id']??0);
    $set_id=(int)($_POST['set_id']??0);
    // hard delete only if unused, else archive (soft delete)
    $st=$pdo->prepare("SELECT COUNT(*) AS c FROM participation_event_criteria WHERE criteria_id=?");
    $st->execute([$id]);
    $used=(int)($st->fetch()['c']??0);

    if($used>0){
      $pdo->prepare("UPDATE criteria SET archived=1, active=0 WHERE id=?")->execute([$id]);
      emit_event('teacher_criterion_archived',['target_id'=>$id,'set_id'=>$set_id,'used'=>$used]);
    } else {
      $pdo->prepare("DELETE FROM criteria WHERE id=?")->execute([$id]);
      emit_event('teacher_criterion_deleted',['target_id'=>$id,'set_id'=>$set_id]);
    }
    header('Location: '.$bp.'/teacher/criteria.php?set='.$set_id);
    exit;
  }

  if($a==='restore'){
    $id=(int)($_POST['id']??0);
    $set_id=(int)($_POST['set_id']??0);
    $pdo->prepare("UPDATE criteria SET archived=0, active=1 WHERE id=?")->execute([$id]);
    emit_event('teacher_criterion_restored',['target_id'=>$id,'set_id'=>$set_id]);
    header('Location: '.$bp.'/teacher/criteria.php?set='.$set_id.'&show_archived=1');
    exit;
  }

  if($a==='seed'){
    $set_id=(int)($_POST['set_id']??0);
    $st=$pdo->prepare("SELECT cs.id, s.code, cs.subject_id FROM criteria_sets cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.id=? AND cs.scope='teacher' AND cs.teacher_id=?");
    $st->execute([$set_id,(int)$u['id']]);
    $row=$st->fetch();
    if(!$row){
      $err='Set nicht gefunden oder keine Berechtigung.';
    } else {

      // Determine relevant school types for this teacher+subject (FSB/HLS)
      $types=[];
      try{
        $stt=$pdo->prepare("SELECT DISTINCT c.school_type FROM teacher_assignments ta JOIN classes c ON c.id=ta.class_id WHERE ta.teacher_id=? AND ta.subject_id=?");
        $stt->execute([(int)$u['id'], (int)$row['subject_id']]);
        $types=$stt->fetchAll(PDO::FETCH_COLUMN);
      }catch(Exception $e){ $types=[]; }
      $types=array_values(array_unique(array_filter($types)));
      if(!$types) $types=['FSB','HLS']; // fallback: show both

      // Fetch suggestions for this subject (and generic ALL) and for relevant school types + BOTH
      $in = implode(',', array_fill(0, count($types), '?'));
      $sql="SELECT subject_code, category, label
            FROM criteria_suggestions
            WHERE archived=0 AND active=1
              AND (subject_code=? OR subject_code='ALL')
              AND (school_type='BOTH' OR school_type IN ($in))
            ORDER BY subject_code DESC, category, sort, label";
      $params=array_merge([$row['code']], $types);
      $sts=$pdo->prepare($sql);
      $sts->execute($params);
      $tpl=$sts->fetchAll();

      // Insert into set, skip duplicates
      $chk=$pdo->prepare("SELECT COUNT(*) c FROM criteria WHERE criteria_set_id=? AND label=? AND IFNULL(category,'')=?");
      $ins=$pdo->prepare("INSERT INTO criteria (criteria_set_id,label,category,active,archived) VALUES (?,?,?,?,0)");
      $added=0;
      foreach($tpl as $t){
        $cat=(string)($t['category']??'');
        $lab=(string)($t['label']??'');
        $chk->execute([$set_id,$lab,$cat]);
        $exists=(int)$chk->fetchColumn();
        if($exists>0) continue;
        $ins->execute([$set_id,$lab,$cat,1]);
        $added++;
      }

      emit_event('teacher_criteria_seeded',['set_id'=>$set_id,'subject_code'=>$row['code'],'count'=>$added]);
      header('Location: '.$bp.'/teacher/criteria.php?set='.$set_id);
      exit;
    }
  }


  if($a==='rename_set'){
    $set_id=(int)($_POST['set_id']??0);
    $name=trim($_POST['name']??'');
    if($name===''){
      $err='Bitte neuen Namen eingeben.';
    } else {
      $st=$pdo->prepare("UPDATE criteria_sets SET name=? WHERE id=? AND scope='teacher' AND teacher_id=?");
      $st->execute([$name,$set_id,(int)$u['id']]);
      emit_event('teacher_criteria_set_renamed',['target_id'=>$set_id,'target_name'=>$name]);
      header('Location: '.$bp.'/teacher/criteria.php?set='.$set_id);
      exit;
    }
  }

  if($a==='delete_set'){
    $set_id=(int)($_POST['set_id']??0);

    $st=$pdo->prepare("SELECT id,name FROM criteria_sets WHERE id=? AND scope='teacher' AND teacher_id=?");
    $st->execute([$set_id,(int)$u['id']]);
    $row=$st->fetch();
    if(!$row){
      $err='Set nicht gefunden oder keine Berechtigung.';
    } else {
      $st=$pdo->prepare("SELECT COUNT(*) AS c
                         FROM participation_event_criteria pec
                         JOIN criteria c ON c.id=pec.criteria_id
                         WHERE c.criteria_set_id=?");
      $st->execute([$set_id]);
      $used=(int)($st->fetch()['c']??0);

      if($used>0){
        $err='Dieses Set kann nicht gelöscht werden, weil bereits Mitarbeit-Einträge mit Kriterien aus diesem Set gespeichert wurden.';
      } else {
        // Deleting the set will cascade-delete its criteria; because there are no linked entries this is safe.
        $pdo->prepare("DELETE FROM criteria_sets WHERE id=? AND scope='teacher' AND teacher_id=?")->execute([$set_id,(int)$u['id']]);
        emit_event('teacher_criteria_set_deleted',['target_id'=>$set_id,'target_name'=>$row['name']]);
        header('Location: '.$bp.'/teacher/criteria.php');
        exit;
      }
    }
  }

}

$setsSt=$pdo->prepare("SELECT cs.*, s.code AS subject_code, s.name AS subject_name
                       FROM criteria_sets cs
                       LEFT JOIN subjects s ON s.id=cs.subject_id
                       WHERE cs.scope='teacher' AND cs.teacher_id=?
                       ORDER BY cs.id DESC");
$setsSt->execute([(int)$u['id']]);
$sets=$setsSt->fetchAll();

$setId=!empty($_GET['set'])?(int)$_GET['set']:(count($sets)?(int)$sets[0]['id']:0);
$show_archived = isset($_GET['show_archived']) && (string)$_GET['show_archived']==='1';

$current=null;
$criteria=[];
$set_used_count=0;
if($setId){
  $st=$pdo->prepare("SELECT cs.*, s.code AS subject_code, s.name AS subject_name
                     FROM criteria_sets cs
                     LEFT JOIN subjects s ON s.id=cs.subject_id
                     WHERE cs.id=? AND cs.scope='teacher' AND cs.teacher_id=?");
  $st->execute([$setId,(int)$u['id']]);
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

    // used entries count on set level (for safe delete)
    $st=$pdo->prepare("SELECT COUNT(*) FROM participation_event_criteria pec JOIN criteria c ON c.id=pec.criteria_id WHERE c.criteria_set_id=?");
    $st->execute([$setId]);
    $set_used_count=(int)$st->fetchColumn();
  }
}

render_header('Kriterien',$u);
?>
<div class="grid">
  <div class="col-12"><div class="card">
    <h1>Meine Kriterien-Sets</h1>
    <p class="muted">Sets werden bei der Mitarbeit-Erfassung als Auswahl angeboten.</p>

    <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

    <div class="card-grid" style="margin-top:10px">
      <?php foreach($sets as $s): ?>
        <?php
          $title = ($s['subject_code']??'').': '.$s['name'];
          $meta  = ($s['subject_name']??'');
        ?>
        <a class="set-card <?php echo ($setId===(int)$s['id'])?'active':''; ?>" href="<?php echo h($bp); ?>/teacher/criteria.php?set=<?php echo (int)$s['id']; ?>">
          <div style="font-weight:900"><?php echo h($title); ?></div>
          <div class="meta"><?php echo h($meta); ?></div>
        </a>
      <?php endforeach; ?>

      <div class="set-card" style="border-style:dashed">
        <div style="font-weight:900">Neues Set</div>
        <div class="meta">z.B. pro Fach ein Set</div>
        <div style="height:10px"></div>
        <form method="post" <?php echo dirty_form_attrs(); ?>>
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="create_set">
          <label class="muted">Name</label>
          <input class="input" name="name" required placeholder="z.B. RWCO Mitarbeit">
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
    <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück</a>
  </div></div>

  <div class="col-12"><div class="card">
    <h1><?php echo h($current ? ($current['subject_code'].' – '.$current['name']) : 'Kein Set'); ?></h1>

    <?php if($current): ?>
      <div class="card" style="margin-bottom:10px">
        <div class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap">
          <form method="post" class="inline-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="rename_set">
            <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
            <div>
              <label class="muted">Set umbenennen</label>
              <input class="input" name="name" value="<?php echo h($current['name']); ?>" style="min-width:260px" required>
            </div>
            <button class="btn small" type="submit">Speichern</button>
          </form>

          <div style="flex:1"></div>

          <?php if($set_used_count===0): ?>
            <form method="post" class="inline-form" onsubmit="return confirm('Set wirklich löschen? Es werden alle Kriterien dieses Sets entfernt.');">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="delete_set">
              <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
              <button class="btn small danger" type="submit">Set löschen</button>
            </form>
          <?php else: ?>
            <span class="muted">Set kann nicht gelöscht werden (<?php echo (int)$set_used_count; ?> gespeicherte Einträge).</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="card" style="background:rgba(71,142,79,.06);border-style:dashed">
        <form method="post" <?php echo dirty_form_attrs(); ?>>
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
          <div class="row">
            <div style="flex:1"><label class="muted">Kriterium</label><input class="input" name="label" required></div>
            <div style="flex:1"><label class="muted">Kategorie (optional)</label><input class="input" name="category"></div>
          </div>
          <div style="height:10px"></div>
          <button class="btn small" type="submit">Hinzufügen</button>
        </form>

        <div style="height:10px"></div>
        <div class="row" style="align-items:center;gap:8px;flex-wrap:wrap">
          <form method="post" class="inline-form" onsubmit="return confirm('Wollen Sie wirklich automatisch-generierte Vorschläge einfügen?')">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="seed">
            <input type="hidden" name="set_id" value="<?php echo (int)$setId; ?>">
            <button class="btn small secondary" type="submit" title="Vorlage für dieses Fach einfügen">Vorschläge einfügen</button>
          </form>

          <a class="btn small secondary" href="<?php echo h($bp); ?>/teacher/criteria.php?set=<?php echo (int)$setId; ?>&show_archived=<?php echo $show_archived?0:1; ?>">
            <?php echo $show_archived?'Archiv ausblenden':'Archiv anzeigen'; ?>
          </a>
        </div>
      </div>

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
        <p class="muted">Noch keine Kriterien. Klicke auf „Vorschläge einfügen“ oder füge eigene Kriterien hinzu.</p>
      <?php endif; ?>

    <?php else: ?>
      <p class="muted">Lege oben ein Set an, um Kriterien zu verwalten.</p>
    <?php endif; ?>
  </div></div>
</div>
<?php render_footer(); ?>
