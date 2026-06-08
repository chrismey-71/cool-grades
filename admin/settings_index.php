<?php
require_once __DIR__.'/../lib/layout.php';

$u = require_role('admin');
$bp = cfg()['base_path'];

render_header('Einstellungen', $u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Einstellungen</h1>
      <p class="muted">Hier bündelst du die globalen App-Einstellungen und die Eventauswertungen der Administration.</p>

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
            <div class="muted" style="font-size:13px">Eine vollständige SQL-Sicherung der App herunterladen.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/admin/backup.php">Sicherung herunterladen</a>
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
