<?php
require_once __DIR__.'/../lib/layout.php';

$u = require_role('admin');
$bp = cfg()['base_path'];

render_header('Verwaltung', $u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Verwaltung</h1>
      <p class="muted">Hier pflegst du die zentralen Kriterien, Kriterien-Vorschläge und Picklisten für die Anwendung.</p>

      <div class="grid" style="margin-top:14px">
        <div class="col-12 col-md-4">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Kriterien</h2>
            <div class="muted" style="font-size:13px">Fachbezogene globale Kriteriensets für alle Lehrkräfte verwalten.</div>
            <div style="height:10px"></div>
            <a class="btn" href="<?php echo h($bp); ?>/admin/criteria.php">Kriterien verwalten</a>
          </div>
        </div>

        <div class="col-12 col-md-4">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Kriterien-Vorschläge</h2>
            <div class="muted" style="font-size:13px">Vorschläge für Lehrkräfte pflegen und nach Fach bzw. Schultyp strukturieren.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/admin/suggestions.php">Vorschläge öffnen</a>
          </div>
        </div>

        <div class="col-12 col-md-4">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Picklisten</h2>
            <div class="muted" style="font-size:13px">Globale und fachbezogene Bezeichnungen für Mitarbeit und Leistungsfeststellungen pflegen.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/admin/options.php">Picklisten verwalten</a>
          </div>
        </div>
      </div>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/admin/index.php">Zurück zum Adminbereich</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
