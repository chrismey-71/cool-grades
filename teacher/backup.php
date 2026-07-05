<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/backup.php';

$u = require_role('teacher');
$pdo = db();
$bp = cfg()['base_path'] ?? '';

$err = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $password = (string)($_POST['backup_password'] ?? '');
  $passwordConfirm = (string)($_POST['backup_password_confirm'] ?? '');

  if($password !== $passwordConfirm){
    $err = 'Die Kennwörter stimmen nicht überein.';
  } else {
    try{
      $stamp = date('Y-m-d_H-i-s');
      $filename = 'cool-grades-lehrkraft-sicherung-' . $stamp . '.zip';
      $export = backup_teacher_export($pdo, $u);
      $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if($json === false) throw new RuntimeException('JSON-Export konnte nicht erzeugt werden.');

      $readme = "COOL-Grades Lehrkraft-Sicherung\n"
        ."Erstellt am: ".date('Y-m-d H:i:s')."\n"
        ."Lehrkraft: ".trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''))."\n"
        ."Inhalt: Strukturierter JSON-Export der zugewiesenen Klassen, Fächer, Schuljahre und dazugehörigen Leistungsdaten dieser Lehrkraft.\n"
        ."Kennwortschutz: ".($password !== '' ? 'aktiviert' : 'nicht aktiviert')."\n\n"
        ."Hinweis: Diese Sicherung enthält personenbezogene und leistungsbezogene Daten. Bitte sicher speichern und nur berechtigt weitergeben.\n"
        ."Dieser Export ist eine Datensicherung/Dokumentation, kein automatischer Wiederherstellungsimport.\n";

      emit_event('teacher_backup_downloaded', [
        'filename' => $filename,
        'format' => 'zip_json',
        'encrypted' => $password !== '',
      ]);

      backup_send_zip($filename, [
        'teacher-backup.json' => $json,
        'README.txt' => $readme,
      ], $password);
      exit;
    }catch(Throwable $e){
      app_log('error', 'teacher backup failed', ['error'=>$e->getMessage(), 'teacher_id'=>(int)$u['id']]);
      $err = 'Die Sicherung konnte nicht erstellt werden: '.$e->getMessage();
    }
  }
}

render_header('Datensicherung', $u);
?>
<div class="grid">
  <div class="col-12 col-8">
    <div class="card">
      <h1>Datensicherung</h1>
      <p class="muted">Hier können Sie eine gepackte Sicherung Ihrer zugewiesenen Klassen, Fächer, Schuljahre und dazugehörigen Leistungsdaten herunterladen. Optional kann die ZIP-Datei mit einem Kennwort verschlüsselt werden.</p>

      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <div class="card" style="padding:14px;background:rgba(71,142,79,.06);border-style:dashed">
        <div><b>Inhalt der Sicherung</b></div>
        <div class="muted" style="margin-top:6px">
          Enthalten sind Ihre Klasse-Fach-Zuordnungen, zugehörige Schuljahre, Klassen, Schüler:innen dieser Klassen, eigene Mitarbeitseinträge, besondere mündliche und schriftliche Leistungsfeststellungen, Gruppen, Presets, Gewichtungen und eigene Abschlussbeurteilungen.
        </div>
        <div class="muted" style="margin-top:6px">
          Nicht enthalten sind globale Admin-Daten oder Daten anderer Lehrkräfte außerhalb Ihrer Zuordnungen.
        </div>
      </div>

      <form method="post" style="margin-top:14px">
        <?php echo csrf_input(); ?>
        <div class="settings-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
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
        <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/manage.php">Zurück zur Verwaltung</a>
      </form>
    </div>
  </div>
</div>
<?php render_footer(); ?>
