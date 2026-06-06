<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/settings.php';
require_once __DIR__.'/../lib/school_years.php';

$u = require_role('admin');
$bp = cfg()['base_path'] ?? '';

$msg = '';
$err = '';

$defaultRanges = school_period_default_ranges();
$newPeriodLabel = school_period_year_label($defaultRanges['semester1']['from'], $defaultRanges['semester2']['to']);
$semester1From = (string)$defaultRanges['semester1']['from'];
$semester1To = (string)$defaultRanges['semester1']['to'];
$semester2From = (string)$defaultRanges['semester2']['from'];
$semester2To = (string)$defaultRanges['semester2']['to'];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $action = trim((string)($_POST['action'] ?? ''));

  if($action === 'create_school_period'){
    $newPeriodLabel = trim((string)($_POST['period_label'] ?? $newPeriodLabel));
    $semester1From = trim((string)($_POST['semester1_from'] ?? $semester1From));
    $semester1To = trim((string)($_POST['semester1_to'] ?? $semester1To));
    $semester2From = trim((string)($_POST['semester2_from'] ?? $semester2From));
    $semester2To = trim((string)($_POST['semester2_to'] ?? $semester2To));

    if(
      !preg_match('/^\d{4}-\d{2}-\d{2}$/', $semester1From) ||
      !preg_match('/^\d{4}-\d{2}-\d{2}$/', $semester1To) ||
      !preg_match('/^\d{4}-\d{2}-\d{2}$/', $semester2From) ||
      !preg_match('/^\d{4}-\d{2}-\d{2}$/', $semester2To)
    ){
      $err = 'Bitte gültige Datumswerte für beide Semester eingeben.';
    } elseif(!($semester1From <= $semester1To && $semester2From <= $semester2To && $semester1To < $semester2From)) {
      $err = 'Bitte die Semesterdaten in logischer Reihenfolge eingeben.';
    } else {
      $duplicate = false;
      foreach(app_school_period_sets(true) as $periodRow){
        if(
          (string)$periodRow['semester1_from'] === $semester1From &&
          (string)$periodRow['semester1_to'] === $semester1To &&
          (string)$periodRow['semester2_from'] === $semester2From &&
          (string)$periodRow['semester2_to'] === $semester2To &&
          (int)$periodRow['archived'] === 0
        ){
          $duplicate = true;
          break;
        }
      }
      if($duplicate){
        $err = 'Für diese Datumswerte existiert bereits ein aktives Schuljahr.';
      } else {
        app_school_period_create($newPeriodLabel, $semester1From, $semester1To, $semester2From, $semester2To);
        $msg = 'Schuljahr gespeichert.';
        $defaultRanges = school_period_default_ranges();
        $newPeriodLabel = school_period_year_label($defaultRanges['semester1']['from'], $defaultRanges['semester2']['to']);
        $semester1From = (string)$defaultRanges['semester1']['from'];
        $semester1To = (string)$defaultRanges['semester1']['to'];
        $semester2From = (string)$defaultRanges['semester2']['from'];
        $semester2To = (string)$defaultRanges['semester2']['to'];
      }
    }
  } elseif($action === 'archive_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    if($periodId > 0){
      app_school_period_archive($periodId);
      $msg = 'Schuljahr aus der Auswahl entfernt.';
    }
  } elseif($action === 'restore_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    if($periodId > 0){
      app_school_period_restore($periodId);
      $msg = 'Schuljahr wieder in die Auswahl aufgenommen.';
    }
  } elseif($action === 'set_current_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    if($periodId > 0){
      school_year_set_current(db(), $periodId);
      $msg = 'Aktuelles Schuljahr wurde gesetzt.';
    }
  }
}

$allPeriods = app_school_period_sets(true);
$activePeriods = [];
$archivedPeriods = [];
foreach($allPeriods as $periodRow){
  if((int)$periodRow['archived'] === 1) $archivedPeriods[] = $periodRow;
  else $activePeriods[] = $periodRow;
}

render_header('Schuljahre und Semester', $u);
?>

<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Schuljahre und Semester</h1>
      <p class="muted">
        Hier legen Sie die Schuljahre mit 1. und 2. Semester an. Dieser Schritt sollte vor dem Anlegen neuer Einstiegsklassen und vor dem Schuljahreswechsel erfolgen.
      </p>

      <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <div class="report-focus-block" style="margin-top:12px">
        <strong>Empfohlener Ablauf</strong>
        <div class="muted" style="margin-top:8px">
          1. Neues Schuljahr mit beiden Semestern anlegen.
          2. Danach unter „Klassen“ nur neue 1. Klassen erfassen.
          3. Bestehende Klassen über den Schuljahreswechsel-Assistenten fortführen.
        </div>
      </div>

      <?php if($activePeriods): ?>
        <table class="table" style="margin-top:14px">
          <thead>
            <tr>
              <th>Schuljahr</th>
              <th>1. Semester</th>
              <th>2. Semester</th>
              <th>Gesamtes Schuljahr</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($activePeriods as $periodRow): ?>
              <tr>
                <td><strong><?php echo h($periodRow['label']); ?></strong></td>
                <td><?php echo h($periodRow['semester1_from']); ?> bis <?php echo h($periodRow['semester1_to']); ?></td>
                <td><?php echo h($periodRow['semester2_from']); ?> bis <?php echo h($periodRow['semester2_to']); ?></td>
                <td><?php echo h($periodRow['semester1_from']); ?> bis <?php echo h($periodRow['semester2_to']); ?></td>
                <td><?php echo ((int)($periodRow['is_current'] ?? 0)===1)?'<span class="badge ok">aktuell</span>':'<span class="badge">Archiv/Planung</span>'; ?></td>
                <td style="white-space:nowrap">
                  <?php if((int)($periodRow['is_current'] ?? 0)!==1): ?>
                  <form method="post" style="display:inline" data-dirty-ignore="1">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="set_current_school_period">
                    <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                    <button class="btn secondary small">Als aktuell setzen</button>
                  </form>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Schuljahr aus der Auswahl entfernen?');" style="display:inline" data-dirty-ignore="1">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="archive_school_period">
                    <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                    <button class="btn danger small">Löschen</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="muted" style="margin-top:10px">Noch keine aktiven Schuljahre vorhanden.</div>
      <?php endif; ?>

      <div style="height:18px"></div>

      <details class="accordion" open>
        <summary><span class="acc-title">Neues Schuljahr anlegen</span></summary>
        <div class="acc-body">
          <form method="post" <?php echo dirty_form_attrs(); ?>>
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="create_school_period">
            <div class="settings-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
              <div class="settings-panel">
                <div class="settings-panel-title">Bezeichnung</div>
                <label class="muted">Schuljahr</label>
                <input class="input" name="period_label" value="<?php echo h($newPeriodLabel); ?>" placeholder="z. B. 2025/26">
                <div class="muted" style="margin-top:6px">Wird in Auswertung, Abschlussbeurteilung und PDF-Berichten angezeigt.</div>
              </div>
              <div class="settings-panel">
                <div class="settings-panel-title">1. Semester</div>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
                  <div>
                    <label class="muted">von</label>
                    <input class="input" type="date" name="semester1_from" value="<?php echo h($semester1From); ?>" required>
                  </div>
                  <div>
                    <label class="muted">bis</label>
                    <input class="input" type="date" name="semester1_to" value="<?php echo h($semester1To); ?>" required>
                  </div>
                </div>
              </div>
              <div class="settings-panel">
                <div class="settings-panel-title">2. Semester</div>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
                  <div>
                    <label class="muted">von</label>
                    <input class="input" type="date" name="semester2_from" value="<?php echo h($semester2From); ?>" required>
                  </div>
                  <div>
                    <label class="muted">bis</label>
                    <input class="input" type="date" name="semester2_to" value="<?php echo h($semester2To); ?>" required>
                  </div>
                </div>
              </div>
            </div>
            <div style="margin-top:12px">
              <button class="btn">Schuljahr speichern</button>
              <a class="btn secondary" href="<?php echo h($bp); ?>/admin/classes.php">Weiter zu Klassen</a>
              <a class="btn secondary" href="<?php echo h($bp); ?>/admin/school_year_transition.php">Zum Schuljahreswechsel</a>
            </div>
          </form>
        </div>
      </details>

      <?php if($archivedPeriods): ?>
        <div style="height:18px"></div>
        <details class="accordion">
          <summary><span class="acc-title">Ausgeblendete Schuljahre</span></summary>
          <div class="acc-body">
            <table class="table">
              <thead>
                <tr>
                  <th>Schuljahr</th>
                  <th>1. Semester</th>
                  <th>2. Semester</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($archivedPeriods as $periodRow): ?>
                  <tr>
                    <td><strong><?php echo h($periodRow['label']); ?></strong></td>
                    <td><?php echo h($periodRow['semester1_from']); ?> bis <?php echo h($periodRow['semester1_to']); ?></td>
                    <td><?php echo h($periodRow['semester2_from']); ?> bis <?php echo h($periodRow['semester2_to']); ?></td>
                    <td style="white-space:nowrap">
                      <form method="post" style="display:inline" data-dirty-ignore="1">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="restore_school_period">
                        <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                        <button class="btn secondary small">Wiederherstellen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/dashboard.php">Zurück zum Dashboard</a>
    </div>
  </div>
</div>

<?php render_footer(); ?>
