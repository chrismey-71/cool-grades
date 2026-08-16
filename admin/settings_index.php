<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/demo_installation.php';

$u = require_role('admin');
$pdo = db();
$bp = cfg()['base_path'];
$msg = '';
$err = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $action = (string)($_POST['action'] ?? '');
  try{
    if($action === 'demo_reset'){
      if((string)($_POST['confirm_demo_reset'] ?? '') !== '1'){
        throw new RuntimeException('Bitte bestätigen, dass die Demodaten zurückgesetzt werden sollen.');
      }
      $result = demo_installation_reset($pdo);
      if(!empty($result['admin_id'])) $_SESSION['uid'] = (int)$result['admin_id'];
      $msg = 'Demoinstallation wurde zurückgesetzt. Demo-Zugang: demoadmin / demolehrer mit Passwort '.$result['password'].'.';
    } elseif($action === 'demo_remove'){
      if(trim((string)($_POST['confirm_phrase'] ?? '')) !== 'DEMO LOESCHEN'){
        throw new RuntimeException('Bitte zur Bestätigung exakt DEMO LOESCHEN eingeben.');
      }
      $password = (string)($_POST['new_admin_password'] ?? '');
      if($password !== (string)($_POST['new_admin_password_confirm'] ?? '')){
        throw new RuntimeException('Die beiden Passwörter für das neue Administrationskonto stimmen nicht überein.');
      }
      demo_installation_remove($pdo, [
        'username' => (string)($_POST['new_admin_username'] ?? ''),
        'first_name' => (string)($_POST['new_admin_first_name'] ?? ''),
        'last_name' => (string)($_POST['new_admin_last_name'] ?? ''),
        'password' => $password,
      ]);
      session_destroy();
      header('Location: '.$bp.'/login.php');
      exit;
    }
  }catch(Throwable $e){
    $err = $e instanceof RuntimeException ? $e->getMessage() : 'Demofunktion konnte nicht ausgeführt werden.';
  }
}

$demoActive = demo_installation_is_active($pdo);
$demoYear = demo_installation_setting_get($pdo, 'demo_installation_school_year', '');
$demoLastReset = demo_installation_setting_get($pdo, 'demo_installation_last_reset_at', '');

render_header('Einstellungen', $u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Einstellungen</h1>
      <p class="muted">Hier bündelst du die globalen App-Einstellungen und die Eventauswertungen der Administration.</p>
      <?php if($msg): ?><div class="alert ok"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="alert err"><?php echo h($err); ?></div><?php endif; ?>

      <?php if($demoActive): ?>
        <div class="card" style="padding:16px;margin-top:14px;border-color:#d8b65a;background:#fffaf0">
          <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">
            <div>
              <h2 style="margin:0 0 6px 0">Demoinstallation</h2>
              <div class="muted" style="font-size:13px">Diese Installation ist als Demo markiert. Sie enthält Musterdaten und ist nicht für echte Schüler:innendaten vorgesehen.</div>
              <div style="margin-top:8px;font-size:13px">
                <strong>Demoschuljahr:</strong> <?php echo h($demoYear ?: 'nicht gesetzt'); ?>
                <?php if($demoLastReset !== ''): ?> · <strong>letzter Reset:</strong> <?php echo h($demoLastReset); ?><?php endif; ?>
              </div>
              <div class="muted" style="font-size:13px;margin-top:6px">Standardzugänge nach Reset: <code>demoadmin</code> und <code>demolehrer</code>, Passwort <code>DemoZugang47!</code>.</div>
            </div>
            <form method="post" style="margin:0">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="demo_reset">
              <label class="small" style="display:block;margin-bottom:8px"><input type="checkbox" name="confirm_demo_reset" value="1"> Demodaten neu erzeugen</label>
              <button class="btn secondary" type="submit">Demo jetzt zurücksetzen</button>
            </form>
          </div>
          <details style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:700">Demoinstallation löschen und echten Admin anlegen</summary>
            <p class="muted" style="font-size:13px">Diese Aktion entfernt Demo-Schule, Demo-Benutzer und Demodaten. Vorher wird ein neues Administrationskonto angelegt, mit dem die Installation danach produktiv weitergeführt werden kann.</p>
            <form method="post" class="grid" style="margin-top:10px">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="demo_remove">
              <div class="col-12 col-md-4">
                <label>Benutzername neuer Admin<br><input class="input" name="new_admin_username" required autocomplete="off"></label>
              </div>
              <div class="col-12 col-md-4">
                <label>Vorname<br><input class="input" name="new_admin_first_name" required></label>
              </div>
              <div class="col-12 col-md-4">
                <label>Nachname<br><input class="input" name="new_admin_last_name" required></label>
              </div>
              <div class="col-12 col-md-6">
                <label>Passwort<br><input class="input" type="password" name="new_admin_password" required autocomplete="new-password"></label>
              </div>
              <div class="col-12 col-md-6">
                <label>Passwort wiederholen<br><input class="input" type="password" name="new_admin_password_confirm" required autocomplete="new-password"></label>
              </div>
              <div class="col-12">
                <label>Bestätigung<br><input class="input" name="confirm_phrase" placeholder="DEMO LOESCHEN" required></label>
                <div class="small muted" style="margin-top:4px">Bitte exakt <code>DEMO LOESCHEN</code> eingeben.</div>
              </div>
              <div class="col-12">
                <button class="btn danger" type="submit">Demoinstallation löschen</button>
              </div>
            </form>
          </details>
          <div class="small muted" style="margin-top:12px">
            Automatischer täglicher Reset per Cron, ohne Anzeige des Serverpfads:
            <code>0 3 * * * php /pfad/zur-app/tools/reset_demo_installation.php</code>
            <br>Den tatsächlichen absoluten Pfad bitte nur in der Hosting- bzw. Serververwaltung eintragen.
          </div>
        </div>
      <?php endif; ?>

      <div class="grid" style="margin-top:14px">
        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Schulen und Schulformen</h2>
            <div class="muted" style="font-size:13px">Schulen mit Adresse und auswählbaren Schulformen für Klassen verwalten.</div>
            <div style="height:10px"></div>
            <a class="btn" href="<?php echo h($bp); ?>/admin/schools.php">Schulen öffnen</a>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Anwendung</h2>
            <div class="muted" style="font-size:13px">Globale Einstellungen wie das automatische Logout der Anwendung verwalten.</div>
            <div style="height:10px"></div>
            <a class="btn" href="<?php echo h($bp); ?>/admin/settings.php">Einstellungen öffnen</a>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Impressum und Datenschutz</h2>
            <div class="muted" style="font-size:13px">Öffentliche Rechtstexte für die Fußzeile mit einem einfachen HTML-Editor pflegen.</div>
            <div style="height:10px"></div>
            <a class="btn" href="<?php echo h($bp); ?>/admin/legal_pages.php">Rechtstexte öffnen</a>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Datenbanksicherung</h2>
            <div class="muted" style="font-size:13px">Eine vollständige gepackte SQL-Sicherung der App herunterladen, optional mit Kennwortschutz.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/admin/backup.php">Sicherung öffnen</a>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Eventauswertungen</h2>
            <div class="muted" style="font-size:13px">Systemereignisse, Änderungen und Audit-Daten der Anwendung einsehen.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/admin/events.php">Eventauswertungen öffnen</a>
          </div>
        </div>
      </div>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/admin/index.php">Zurück zum Adminbereich</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
