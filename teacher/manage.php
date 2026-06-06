<?php
require_once __DIR__.'/../lib/layout.php';

$u=require_role('teacher');
$bp=cfg()['base_path'];

render_header('Verwaltung',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Verwaltung</h1>
      <p class="muted">Hier verwaltest du deine Mitarbeit-Kriterien, Picklisten und Presets getrennt von der eigentlichen Eingabe.</p>

      <div class="grid" style="margin-top:14px">
        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Mitarbeit-Kriterien</h2>
            <div class="muted" style="font-size:13px">Kriteriensets und einzelne Kriterien für deine Dokumentation pflegen.</div>
            <div style="height:10px"></div>
            <a class="btn" href="<?php echo h($bp); ?>/teacher/criteria.php">Mitarbeit-Kriterien verwalten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Bezeichnungen / Picklisten</h2>
            <div class="muted" style="font-size:13px">Eigene Optionen für Eindruck, Leistungsart, Sozialform und weitere Picklisten verwalten.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/options.php">Bezeichnungen / Picklisten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Mitarbeit-Presets</h2>
            <div class="muted" style="font-size:13px">Vorlagen für häufige Unterrichtssituationen je Fach erstellen, ändern und löschen.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/presets.php">Mitarbeit-Presets verwalten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Schüler:innengruppen</h2>
            <div class="muted" style="font-size:13px">Gruppen pro Klasse und Fach bilden, zufällig verteilen, bearbeiten und später für die Mitarbeitsauswahl verwenden.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/student_groups.php">Gruppen verwalten</a>
          </div>
        </div>
      </div>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück zum Lehrerbereich</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
