<?php
// Review/correction page for WebUntis events whose SUMMARY code doesn't
// match any subjects.code exactly (GitHub issue #2, deferred "Vorschau-
// und Korrekturseite"). A teacher can either alias the code onto one of
// their own subjects, or mark it as not a real teaching lesson (e.g.
// "SPRE" = Sprechstunde) so it never becomes a lesson_sessions row but
// stays recorded for a future timetable overview.
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/webuntis_ical.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];
$msg=(string)($_GET['msg'] ?? '');
$err=(string)($_GET['err'] ?? '');

function webuntis_review_redirect(string $bp, string $msg='', string $err=''): void {
  $params=[];
  if($msg!=='') $params['msg']=$msg;
  if($err!=='') $params['err']=$err;
  header('Location: '.$bp.'/teacher/webuntis_review.php'.($params ? ('?'.http_build_query($params)) : ''));
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');
  $code=trim((string)($_POST['webuntis_code'] ?? ''));

  try{
    if($action==='map_subject'){
      $subjectId=(int)($_POST['subject_id'] ?? 0);
      webuntis_save_subject_mapping($pdo,(int)$u['id'],$code,'subject',$subjectId,null);

      $note='';
      try{
        $summary=webuntis_import_for_teacher($pdo,current_user());
        $note=' '.(int)$summary['imported'].' neue, '.(int)$summary['updated'].' aktualisierte Stunde(n) direkt übernommen.';
      }catch(Exception $e){
        $note=' Die Zuordnung ist gespeichert, der sofortige erneute Import ist aber fehlgeschlagen ('.$e->getMessage().') – sie wird beim nächsten Import angewendet.';
      }
      webuntis_review_redirect($bp,'Fachkürzel „'.$code.'" zugeordnet.'.$note);
    } elseif($action==='mark_ignore'){
      $note=trim((string)($_POST['note'] ?? ''));
      webuntis_save_subject_mapping($pdo,(int)$u['id'],$code,'ignore',null,$note!==''?$note:null);

      $resultNote='';
      try{
        webuntis_import_for_teacher($pdo,current_user());
      }catch(Exception $e){
        $resultNote=' (Erneuter Import fehlgeschlagen: '.$e->getMessage().' – die Markierung ist aber gespeichert.)';
      }
      webuntis_review_redirect($bp,'Fachkürzel „'.$code.'" als „keine Unterrichtsstunde" markiert.'.$resultNote);
    } elseif($action==='undo_mapping'){
      webuntis_delete_subject_mapping($pdo,(int)$u['id'],$code);
      webuntis_review_redirect($bp,'Zuordnung für „'.$code.'" aufgehoben.');
    } else {
      throw new Exception('Unbekannte Aktion.');
    }
  } catch(Exception $e){
    webuntis_review_redirect($bp,'',$e->getMessage());
  }
}

$unmappedCodes = webuntis_unmapped_subject_codes($pdo,(int)$u['id']);
$existingMappings = webuntis_subject_mappings_for_teacher($pdo,(int)$u['id']);

$subjectsStmt=$pdo->prepare("SELECT DISTINCT s.id, s.code, s.name
                             FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id
                             WHERE ta.teacher_id=? ORDER BY s.code");
$subjectsStmt->execute([(int)$u['id']]);
$teacherSubjects=$subjectsStmt->fetchAll();

render_header('WebUntis: Fachkürzel prüfen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>WebUntis: Fachkürzel prüfen</h1>
<div class="muted">
  Hier siehst du WebUntis-Stunden, deren Fachkürzel (z. B. aus dem Kalenderfeed) keinem Fach in COOL-Grades entspricht. Du kannst jedes Kürzel entweder einem bestehenden Fach zuordnen oder als „keine Unterrichtsstunde" markieren (z. B. Sprechstunde, Pause, Konferenz) – solche Termine erzeugen dann keine Mitarbeits-relevante Stunde, bleiben aber für eine spätere Stundenplanübersicht gespeichert.
</div>
<?php if($msg): ?><div class="card" style="border-color:#bfe5cd;background:#e8f5ee;margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="border-color:#ffc6c0;background:#ffeceb;margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

<h2 style="margin-top:18px">Noch unklare Fachkürzel</h2>
<?php if(!$unmappedCodes): ?>
  <div class="muted" style="margin-top:8px">Aktuell gibt es keine unklaren Fachkürzel – entweder wurde noch nicht importiert, oder alle Kürzel sind bereits zugeordnet.</div>
<?php else: ?>
  <?php foreach($unmappedCodes as $codeRow): ?>
    <?php
      $code=(string)$codeRow['webuntis_code'];
      $examples=webuntis_unmapped_event_examples($pdo,(int)$u['id'],$code);
    ?>
    <div class="settings-panel" style="margin-top:12px;border-color:#ffe3b0;background:#fffaf0">
      <div class="settings-panel-title">„<?php echo h($code); ?>" <span class="setting-impact"><?php echo (int)$codeRow['cnt']; ?>× im Feed</span></div>
      <div class="small muted" style="margin-top:4px">
        <?php echo h((string)$codeRow['date_min']); ?> bis <?php echo h((string)$codeRow['date_max']); ?>
        <?php if($examples): ?>
          · Beispiele:
          <?php
            $exampleTexts=[];
            foreach($examples as $ex){
              $t=(string)$ex['lesson_date'];
              if(!empty($ex['start_time'])) $t.=' '.substr((string)$ex['start_time'],0,5);
              if(!empty($ex['webuntis_description'])) $t.=' ('.$ex['webuntis_description'].')';
              $exampleTexts[]=$t;
            }
            echo h(implode(' · ',$exampleTexts));
          ?>
        <?php endif; ?>
      </div>

      <div class="row" style="margin-top:10px;gap:20px;flex-wrap:wrap">
        <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="map_subject">
          <input type="hidden" name="webuntis_code" value="<?php echo h($code); ?>">
          <div>
            <label class="muted">Diesem Fach zuordnen</label>
            <select class="input" name="subject_id" required>
              <option value="">– Fach wählen –</option>
              <?php foreach($teacherSubjects as $subjectRow): ?>
                <option value="<?php echo (int)$subjectRow['id']; ?>"><?php echo h($subjectRow['code']); ?> – <?php echo h($subjectRow['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn secondary">Zuordnen</button>
        </form>

        <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="mark_ignore">
          <input type="hidden" name="webuntis_code" value="<?php echo h($code); ?>">
          <div>
            <label class="muted">Als „keine Unterrichtsstunde" markieren (optional: Bezeichnung)</label>
            <input class="input" name="note" placeholder="z. B. Sprechstunde" maxlength="255">
          </div>
          <button class="btn secondary">Markieren</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h2 style="margin-top:22px">Bereits entschiedene Fachkürzel</h2>
<?php if(!$existingMappings): ?>
  <div class="muted" style="margin-top:8px">Noch keine Entscheidungen getroffen.</div>
<?php else: ?>
  <?php foreach($existingMappings as $mappingRow): ?>
    <div class="settings-panel" style="margin-top:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
          <b>„<?php echo h($mappingRow['webuntis_code']); ?>"</b>
          <?php if($mappingRow['action']==='subject'): ?>
            → Fach <b><?php echo h($mappingRow['subject_code'] ?? ''); ?><?php echo $mappingRow['subject_name'] ? ' – '.h($mappingRow['subject_name']) : ''; ?></b>
          <?php else: ?>
            → keine Unterrichtsstunde<?php echo $mappingRow['note'] ? ' ('.h($mappingRow['note']).')' : ''; ?>
            <?php if((int)($mappingRow['ignored_count'] ?? 0) > 0): ?>
              <span class="small muted">– <?php echo (int)$mappingRow['ignored_count']; ?> gespeicherte Termine</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <form method="post">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" value="undo_mapping">
          <input type="hidden" name="webuntis_code" value="<?php echo h($mappingRow['webuntis_code']); ?>">
          <button class="btn secondary small">Zuordnung aufheben</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div style="height:12px"></div>
<a class="btn secondary" href="<?php echo h($bp); ?>/account.php#account-webuntis">Zurück zu WebUntis-Einstellungen</a>
</div></div></div>
<?php render_footer(); ?>
