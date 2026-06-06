<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/_crud.php';

$u=require_role('admin');
$pdo=db();
$bp=cfg()['base_path'];

$subjects=$pdo->query("SELECT code,name FROM subjects ORDER BY code")->fetchAll();

$show_archived = !empty($_GET['show_archived']) ? 1 : 0;
$filter_subject = strtoupper(trim($_GET['subject'] ?? ''));
$filter_school = strtoupper(trim($_GET['school_type'] ?? ''));
$q = trim($_GET['q'] ?? '');

$msg=''; $err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $a=$_POST['action']??'';

  if($a==='save'){
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $school_type = strtoupper(trim($_POST['school_type'] ?? 'BOTH'));
    if(!in_array($school_type,['FSB','HLS','BOTH'],true)) $school_type='BOTH';

    $subject_code = strtoupper(trim($_POST['subject_code'] ?? 'ALL'));
    if($subject_code==='') $subject_code='ALL';

    $category = trim($_POST['category'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $sort = (int)($_POST['sort'] ?? 0);
    $active = !empty($_POST['active']) ? 1 : 0;

    if($category==='' || $label===''){
      $err='Bitte Kategorie und Kriterium (Label) ausfüllen.';
    }else{
      $data=[
        'school_type'=>$school_type,
        'subject_code'=>$subject_code,
        'category'=>$category,
        'label'=>$label,
        'description'=>null,
        'active'=>$active,
        'archived'=>0,
        'sort'=>$sort,
        'created_at'=>date('Y-m-d H:i:s'),
        'updated_at'=>null,
      ];
      if($id!==null){
        unset($data['created_at']);
        $data['updated_at']=date('Y-m-d H:i:s');
        upsert('criteria_suggestions',$data,$id);
        emit_event('admin_suggestion_updated',['target_id'=>$id,'subject_code'=>$subject_code]);
      }else{
        $nid=upsert('criteria_suggestions',$data,null);
        emit_event('admin_suggestion_created',['target_id'=>$nid,'subject_code'=>$subject_code]);
      }
      header('Location: '.$bp.'/admin/suggestions.php'); exit;
    }
  }


  if($a==='import_defaults'){
    $file=__DIR__.'/../data/criteria_suggestions_defaults.json';
    if(!file_exists($file)){
      $err='Default-Datei fehlt.';
    }else{
      $raw=file_get_contents($file);
      $arr=json_decode($raw,true);
      if(!is_array($arr)){ $err='Default-Datei ist ungültig.'; }
      else{
        $ins=$pdo->prepare("INSERT IGNORE INTO criteria_suggestions (school_type,subject_code,category,label,description,active,archived,sort,created_at,updated_at)
          VALUES (?,?,?,?,NULL,?,0,?, ?, NULL)");
        $now=date('Y-m-d H:i:s');
        $n=0;
        foreach($arr as $it){
          $school=strtoupper(trim((string)($it['school_type']??'BOTH')));
          if(!in_array($school,['FSB','HLS','BOTH'],true)) $school='BOTH';
          $sub=strtoupper(trim((string)($it['subject_code']??'ALL'))); if($sub==='') $sub='ALL';
          $cat=trim((string)($it['category']??'')); $lab=trim((string)($it['label']??''));
          if($cat===''||$lab==='') continue;
          $active=!empty($it['active'])?1:0;
          $sort=(int)($it['sort']??0);
          $ins->execute([$school,$sub,$cat,$lab,$active,$sort,$now]);
          $n++;
        }
        emit_event('admin_suggestions_imported',['count'=>$n]);
        $msg='Standard-Vorschläge importiert (fehlende Einträge ergänzt).';
      }
    }
  }
  if($a==='archive' || $a==='restore'){
    $id=(int)$_POST['id'];
    $arch = ($a==='archive') ? 1 : 0;
    $st=$pdo->prepare("UPDATE criteria_suggestions SET archived=?, updated_at=? WHERE id=?");
    $st->execute([$arch,date('Y-m-d H:i:s'),$id]);
    emit_event($arch?'admin_suggestion_archived':'admin_suggestion_restored',['target_id'=>$id]);
    header('Location: '.$bp.'/admin/suggestions.php'); exit;
  }

  if($a==='toggle_active'){
    $id=(int)$_POST['id'];
    $st=$pdo->prepare("UPDATE criteria_suggestions SET active=1-active, updated_at=? WHERE id=?");
    $st->execute([date('Y-m-d H:i:s'),$id]);
    emit_event('admin_suggestion_toggled',['target_id'=>$id]);
    header('Location: '.$bp.'/admin/suggestions.php'); exit;
  }

  if($a==='delete'){
    $id=(int)$_POST['id'];
    emit_event('admin_suggestion_deleted',['target_id'=>$id]);
    del('criteria_suggestions',$id);
    header('Location: '.$bp.'/admin/suggestions.php'); exit;
  }
}

$edit=null;
if(!empty($_GET['edit'])){
  $st=$pdo->prepare("SELECT * FROM criteria_suggestions WHERE id=?");
  $st->execute([(int)$_GET['edit']]);
  $edit=$st->fetch();
}

$where=[]; $params=[];
if(!$show_archived){ $where[]="archived=0"; }
if($filter_subject!==''){
  $where[]="subject_code=?";
  $params[]=$filter_subject;
}
if($filter_school!=='' && in_array($filter_school,['FSB','HLS','BOTH'],true)){
  $where[]="school_type=?";
  $params[]=$filter_school;
}
if($q!==''){
  $where[]="(label LIKE ? OR category LIKE ? OR subject_code LIKE ?)";
  $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%";
}

$sql="SELECT * FROM criteria_suggestions";
if($where) $sql.=" WHERE ".implode(" AND ",$where);
$sql.=" ORDER BY subject_code, school_type, category, sort, label";

$st=$pdo->prepare($sql); $st->execute($params);
$items=$st->fetchAll();

render_header('Kriterien-Vorschläge',$u);
?>
<div class="grid">
  <div class="col-12 col-5">
    <div class="card">
      <h1>Vorschlag anlegen/bearbeiten</h1>
      <?php if($err): ?><div class="alert error"><?php echo h($err); ?></div><?php endif; ?>
      <form method="post" class="form" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id']??0); ?>">

        <label>Schultyp</label>
        <select name="school_type">
          <?php $stp=$edit['school_type']??'BOTH'; ?>
          <?php foreach(['BOTH'=>'FSB+HLS','FSB'=>'Fachschule (FSB)','HLS'=>'HLS'] as $k=>$v): ?>
            <option value="<?php echo h($k); ?>" <?php echo $stp===$k?'selected':''; ?>><?php echo h($v); ?></option>
          <?php endforeach; ?>
        </select>

        <label>Fach (Code)</label>
        <select name="subject_code">
          <?php $sc=$edit['subject_code']??'ALL'; ?>
          <option value="ALL" <?php echo $sc==='ALL'?'selected':''; ?>>ALL (für alle Fächer)</option>
          <?php foreach($subjects as $s): ?>
            <option value="<?php echo h($s['code']); ?>" <?php echo $sc===$s['code']?'selected':''; ?>>
              <?php echo h($s['code'].' – '.$s['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label>Kategorie</label>
        <input name="category" value="<?php echo h($edit['category']??''); ?>" required>

        <label>Kriterium (Label)</label>
        <input name="label" value="<?php echo h($edit['label']??''); ?>" required>

        <div class="row">
          <div style="flex:1">
            <label>Sort</label>
            <input name="sort" type="number" value="<?php echo (int)($edit['sort']??0); ?>">
          </div>
          <div style="width:160px; align-self:end">
            <label style="display:flex; gap:8px; align-items:center">
              <input type="checkbox" name="active" value="1" <?php echo !isset($edit) || ($edit && (int)($edit['active']??1)===1) ? 'checked' : ''; ?>>
              Aktiv
            </label>
          </div>
        </div>

        <div style="height:10px"></div>
        <button class="btn"><?php echo $edit?'Speichern':'Anlegen'; ?></button>
        <?php if($edit): ?>
          <a class="btn secondary" href="<?php echo h($bp); ?>/admin/suggestions.php">Abbrechen</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="col-12 col-7">
    <div class="card">
      <h1>Vorschläge</h1>
      <form method="post" style="margin:10px 0">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="import_defaults">
        <button class="btn small secondary" title="Ergänzt die Standard-Vorschläge aus der Datei">Standard‑Vorschläge importieren</button>
      </form>
      <form method="get" class="form">
        <div class="row" style="gap:10px; align-items:end">
          <div style="flex:1">
            <label>Suche</label>
            <input name="q" value="<?php echo h($q); ?>" placeholder="Label/Kategorie/Code…">
          </div>
          <div style="width:220px">
            <label>Fach</label>
            <select name="subject">
              <option value="">Alle</option>
              <option value="ALL" <?php echo $filter_subject==='ALL'?'selected':''; ?>>ALL</option>
              <?php foreach($subjects as $s): ?>
                <option value="<?php echo h($s['code']); ?>" <?php echo $filter_subject===$s['code']?'selected':''; ?>>
                  <?php echo h($s['code']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="width:180px">
            <label>Schultyp</label>
            <select name="school_type">
              <option value="">Alle</option>
              <?php foreach(['BOTH'=>'FSB+HLS','FSB'=>'FSB','HLS'=>'HLS'] as $k=>$v): ?>
                <option value="<?php echo h($k); ?>" <?php echo $filter_school===$k?'selected':''; ?>><?php echo h($v); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="width:180px">
            <label>&nbsp;</label>
            <button class="btn small">Filtern</button>
          </div>
        </div>
        <div style="margin-top:8px">
          <label style="display:flex; gap:8px; align-items:center">
            <input type="checkbox" name="show_archived" value="1" <?php echo $show_archived?'checked':''; ?>>
            Archiv anzeigen
          </label>
        </div>
      </form>

      <div style="height:10px"></div>

      <table class="table">
        <thead>
          <tr>
            <th>Fach</th><th>Typ</th><th>Kategorie</th><th>Kriterium</th><th>Status</th><th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $it): $arch=(int)($it['archived']??0)===1; ?>
          <tr class="<?php echo $arch?'muted':''; ?>">
            <td data-label="Fach"><?php echo h($it['subject_code']); ?></td>
            <td data-label="Typ"><?php echo h($it['school_type']); ?></td>
            <td data-label="Kategorie"><?php echo h($it['category']); ?></td>
            <td data-label="Kriterium"><?php echo h($it['label']); ?></td>
            <td data-label="Status">
              <?php echo (int)$it['active']===1?'aktiv':'inaktiv'; ?>
              <?php echo $arch?' · archiviert':''; ?>
            </td>
            <td data-label="Aktion" style="white-space:nowrap">
              <a class="btn small secondary" href="<?php echo h($bp); ?>/admin/suggestions.php?edit=<?php echo (int)$it['id']; ?>">Bearbeiten</a>
              <form method="post" style="display:inline">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                <button class="btn small secondary" name="action" value="toggle_active" title="Aktiv/Inaktiv"><?php echo (int)$it['active']===1?'Deaktivieren':'Aktivieren'; ?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Wirklich?');">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                <?php if(!$arch): ?>
                  <button class="btn small secondary" name="action" value="archive">Archivieren</button>
                <?php else: ?>
                  <button class="btn small secondary" name="action" value="restore">Wiederherstellen</button>
                <?php endif; ?>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Wirklich endgültig löschen?');">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="id" value="<?php echo (int)$it['id']; ?>">
                <button class="btn small danger" name="action" value="delete">Löschen</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="muted" style="margin-top:10px">
        Hinweis: Lehrkräfte übernehmen Vorschläge in eigene Kriteriensets. Das Löschen eines Vorschlags beeinflusst bereits übernommene Kriterien nicht.
      </p>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/admin/manage.php">Zurück</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
