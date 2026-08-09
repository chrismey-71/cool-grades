<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/backup.php';
require_once __DIR__.'/../lib/schools.php';

$u = require_role('admin');
$pdo = db();
$bp = cfg()['base_path'] ?? '';
$schools=schools_load($pdo,true);

$err = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $password = (string)($_POST['backup_password'] ?? '');
  $passwordConfirm = (string)($_POST['backup_password_confirm'] ?? '');
  $backupScope=(string)($_POST['backup_scope'] ?? 'all');
  $schoolId=(int)($_POST['school_id'] ?? 0);

  if($password !== $passwordConfirm){
    $err = 'Die Kennwörter stimmen nicht überein.';
  } else {
    try{
      $stamp = date('Y-m-d_H-i-s');
      if($backupScope==='school'){
        if($schoolId<=0) throw new RuntimeException('Bitte eine Schule für die schulbezogene Sicherung auswählen.');
        $export=backup_school_export($pdo,$schoolId);
        $schoolName=(string)($export['metadata']['school_name'] ?? 'schule');
        $safeSchool=preg_replace('/[^a-z0-9]+/i','-',strtolower($schoolName)) ?: 'schule';
        $filename='cool-grades-schulsicherung-'.$safeSchool.'-'.$stamp.'.zip';
        $dataFilename='cool-grades-schulsicherung-'.$stamp.'.json';
        $readme="COOL-Grades Schulsicherung\nErstellt am: ".date('Y-m-d H:i:s')."\nSchule: ".$schoolName."\nInhalt: Daten der ausgewählten Schule im JSON-Format.\nKennwortschutz: ".($password!==''?'aktiviert':'nicht aktiviert')."\n\nHinweis: Diese Sicherung enthält personenbezogene und leistungsbezogene Daten. Bitte sicher speichern und nur berechtigt weitergeben.\n";
        emit_event('admin_school_backup_downloaded',['school_id'=>$schoolId,'filename'=>$filename,'format'=>'zip_json','encrypted'=>$password!=='']);
        backup_send_zip($filename,[$dataFilename=>json_encode($export,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'README.txt'=>$readme],$password);
        exit;
      }
      $filename = 'cool-grades-gesamtsicherung-' . $stamp . '.zip';
      $sqlFilename = 'cool-grades-backup-' . $stamp . '.sql';
      $readme = "COOL-Grades Gesamtsicherung\n"
        ."Erstellt am: ".date('Y-m-d H:i:s')."\n"
        ."Inhalt: Vollständiger SQL-Dump der Datenbank mit Tabellenstruktur und Daten.\n"
        ."Kennwortschutz: ".($password !== '' ? 'aktiviert' : 'nicht aktiviert')."\n\n"
        ."Hinweis: Diese Sicherung enthält personenbezogene und leistungsbezogene Daten. Bitte sicher speichern und nur berechtigt weitergeben.\n";

      emit_event('admin_database_backup_downloaded', [
        'filename' => $filename,
        'format' => 'zip_sql',
        'encrypted' => $password !== '',
      ]);

      backup_send_zip($filename, [
        $sqlFilename => backup_sql_dump($pdo),
        'README.txt' => $readme,
      ], $password);
      exit;
    }catch(Throwable $e){
      app_log('error', 'admin backup failed', ['error'=>$e->getMessage()]);
      $err = 'Die Sicherung konnte nicht erstellt werden: '.$e->getMessage();
    }
  }
}

render_header('Datenbanksicherung', $u);
?>
<div class="grid">
  <div class="col-12 col-8">
    <div class="card">
      <h1>Datenbanksicherung</h1>
      <p class="muted">Hier können Sie eine vollständige oder schulbezogene, gepackte Sicherung herunterladen. Optional kann die ZIP-Datei mit einem Kennwort verschlüsselt werden.</p>

      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <div class="card" style="padding:14px;background:rgba(71,142,79,.06);border-style:dashed">
        <div><b>Inhalt der Sicherung</b></div>
        <div class="muted" style="margin-top:6px">Die Sicherung enthält Tabellenstruktur und Daten der Anwendung im SQL-Format, verpackt in einer ZIP-Datei.</div>
      </div>

      <form method="post" style="margin-top:14px">
        <?php echo csrf_input(); ?>
        <div class="settings-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
          <div class="settings-panel">
            <div class="settings-panel-title">Umfang der Sicherung</div>
            <label class="muted">Sicherungstyp</label>
            <select class="input" name="backup_scope" id="backup-scope">
              <option value="all">Gesamtsicherung aller Schulen</option>
              <option value="school">Sicherung einer Schule</option>
            </select>
            <div style="height:10px"></div>
            <label class="muted">Schule</label>
            <select class="input" name="school_id" id="backup-school-id">
              <option value="0">Bitte wählen…</option>
              <?php foreach($schools as $school): ?><option value="<?php echo (int)$school['id']; ?>"><?php echo h($school['name']); ?></option><?php endforeach; ?>
            </select>
            <div class="muted" style="margin-top:8px;font-size:13px">Die Schulsicherung enthält nur Klassen, Zuordnungen und Leistungsdaten der gewählten Schule. Die Gesamtsicherung bleibt für eine vollständige Systemwiederherstellung vorgesehen.</div>
          </div>
          <div class="settings-panel">
            <div class="settings-panel-title">Optionaler Kennwortschutz</div>
            <label class="muted">Kennwort</label>
            <input class="input" type="password" name="backup_password" autocomplete="new-password">
            <div style="height:10px"></div>
            <label class="muted">Kennwort wiederholen</label>
            <input class="input" type="password" name="backup_password_confirm" autocomplete="new-password">
            <div class="muted" style="margin-top:8px;font-size:13px">Ohne Kennwort wird nur eine normale ZIP-Datei erstellt. Mit Kennwort wird der Inhalt der ZIP-Datei verschlüsselt.</div>
          </div>
        </div>
        <div style="height:14px"></div>
        <button class="btn">Gepackte Sicherung herunterladen</button>
        <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück</a>
      </form>
      <script>
        (() => { const scope=document.getElementById('backup-scope'), school=document.getElementById('backup-school-id'); if(!scope||!school)return; const sync=()=>{school.required=scope.value==='school'; school.disabled=false;}; scope.addEventListener('change',sync); sync(); })();
      </script>
    </div>
  </div>
</div>
<?php render_footer(); ?>
