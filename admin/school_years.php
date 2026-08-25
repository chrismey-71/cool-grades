<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/settings.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/schools.php';

$u = require_role('admin');
$bp = cfg()['base_path'] ?? '';
$pdo=db();
$isSuperAdmin=admin_is_superadmin($pdo,$u);
$schools=admin_schools_load($pdo,$u,true);
$schoolNames=[];
foreach($schools as $school) $schoolNames[(int)$school['id']]=(string)$school['name'];
$schoolFilter=admin_filter_school_id($pdo,$u,(int)($_REQUEST['school_id'] ?? 0),$isSuperAdmin);

$msg = '';
$err = '';

$defaultRanges = school_period_default_ranges();
$newPeriodLabel = school_period_year_label($defaultRanges['semester1']['from'], $defaultRanges['semester2']['to']);
$semester1From = (string)$defaultRanges['semester1']['from'];
$semester1To = (string)$defaultRanges['semester1']['to'];
$semester2From = (string)$defaultRanges['semester2']['from'];
$semester2To = (string)$defaultRanges['semester2']['to'];
$newPeriodSchoolId = -1;
if(!$isSuperAdmin){
  $newPeriodSchoolId = $schoolFilter;
} elseif($schoolFilter > 0){
  $newPeriodSchoolId = $schoolFilter;
} elseif(count($schools) === 1){
  $newPeriodSchoolId = (int)$schools[0]['id'];
} elseif(!$schools){
  $newPeriodSchoolId = 0;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $action = trim((string)($_POST['action'] ?? ''));

  if($action === 'create_school_period'){
    $schoolId=(int)($_POST['school_id'] ?? -1);
    $newPeriodSchoolId = $schoolId;
    $newPeriodLabel = trim((string)($_POST['period_label'] ?? $newPeriodLabel));
    $semester1From = trim((string)($_POST['semester1_from'] ?? $semester1From));
    $semester1To = trim((string)($_POST['semester1_to'] ?? $semester1To));
    $semester2From = trim((string)($_POST['semester2_from'] ?? $semester2From));
    $semester2To = trim((string)($_POST['semester2_to'] ?? $semester2To));

    if($schoolId < 0){
      $err='Bitte wählen Sie aus, für welche Schule das Schuljahr angelegt werden soll.';
    } elseif(!$isSuperAdmin && $schoolId<=0){
      $err='Bitte eine Schule aus Ihrer Schulzuordnung auswählen.';
    } elseif($schoolId>0 && !admin_can_access_school($pdo,$u,$schoolId)){
      $err='Keine Berechtigung für diese Schule.';
    } elseif(
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
      foreach(app_school_period_sets(true,$schoolId,false) as $periodRow){
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
        app_school_period_create($newPeriodLabel, $semester1From, $semester1To, $semester2From, $semester2To,$schoolId);
        $msg = 'Schuljahr gespeichert.';
        $defaultRanges = school_period_default_ranges();
        $newPeriodLabel = school_period_year_label($defaultRanges['semester1']['from'], $defaultRanges['semester2']['to']);
        $semester1From = (string)$defaultRanges['semester1']['from'];
        $semester1To = (string)$defaultRanges['semester1']['to'];
        $semester2From = (string)$defaultRanges['semester2']['from'];
        $semester2To = (string)$defaultRanges['semester2']['to'];
        if($isSuperAdmin && count($schools) > 1){
          $newPeriodSchoolId = $schoolFilter > 0 ? $schoolFilter : -1;
        }
      }
    }
  } elseif($action === 'archive_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    if($periodId > 0){
      if(admin_can_access_school_period($pdo,$u,$periodId,false)){
        app_school_period_archive($periodId);
        $msg = 'Schuljahr aus der Auswahl entfernt.';
      } else {
        $err='Keine Berechtigung für dieses Schuljahr.';
      }
    }
  } elseif($action === 'assign_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    $targetSchoolId = (int)($_POST['target_school_id'] ?? 0);
    if($periodId <= 0 || $targetSchoolId <= 0){
      $err = 'Bitte ein globales Schuljahr und eine Zielschule auswählen.';
    } elseif(!admin_can_access_school($pdo,$u,$targetSchoolId)){
      $err = 'Keine Berechtigung für diese Zielschule.';
    } else {
      $periodRow = app_school_period_find($periodId,true);
      if(!$periodRow){
        $err = 'Schuljahr nicht gefunden.';
      } elseif((int)($periodRow['school_id'] ?? 0) !== 0){
        $err = 'Dieses Schuljahr ist bereits einer Schule zugeordnet.';
      } else {
        try{
          app_school_period_assign_school($periodId,$targetSchoolId);
          $schoolFilter = $targetSchoolId;
          $msg = 'Das globale Schuljahr wurde der Schule „'.($schoolNames[$targetSchoolId] ?? 'ausgewählte Schule').'“ zugeordnet.';
        }catch(Throwable $e){
          $err = $e->getMessage();
        }
      }
    }
  } elseif($action === 'restore_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    if($periodId > 0){
      if(admin_can_access_school_period($pdo,$u,$periodId,false)){
        app_school_period_restore($periodId);
        $msg = 'Schuljahr wieder in die Auswahl aufgenommen.';
      } else {
        $err='Keine Berechtigung für dieses Schuljahr.';
      }
    }
  } elseif($action === 'set_current_school_period'){
    $periodId = (int)($_POST['period_id'] ?? 0);
    if($periodId > 0){
      if(admin_can_access_school_period($pdo,$u,$periodId,false)){
        school_year_set_current(db(), $periodId);
        $msg = 'Aktuelles Schuljahr wurde gesetzt.';
      } else {
        $err='Keine Berechtigung für dieses Schuljahr.';
      }
    }
  }
}

$allPeriods = app_school_period_sets(true,$schoolFilter,$schoolFilter>0);
$activePeriods = [];
$archivedPeriods = [];
foreach($allPeriods as $periodRow){
  if((int)$periodRow['archived'] === 1) $archivedPeriods[] = $periodRow;
  else $activePeriods[] = $periodRow;
}

$periodSchoolUsage = [];
if($activePeriods){
  $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)$row['id'], $activePeriods)));
  if($ids){
    $in = implode(',', array_fill(0, count($ids), '?'));
    $usageStmt = $pdo->prepare("SELECT c.school_period_set_id,
                                       sf.school_id,
                                       COALESCE(sc.name, CONCAT('Schule ', sf.school_id)) AS school_name,
                                       GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS class_names
                                FROM classes c
                                JOIN school_forms sf ON sf.id=c.school_form_id
                                LEFT JOIN schools sc ON sc.id=sf.school_id
                                WHERE c.school_period_set_id IN ($in) AND sf.school_id IS NOT NULL
                                GROUP BY c.school_period_set_id, sf.school_id, sc.name");
    $usageStmt->execute($ids);
    foreach($usageStmt->fetchAll() as $usageRow){
      $periodSchoolUsage[(int)$usageRow['school_period_set_id']][] = $usageRow;
    }
  }
}

render_header('Schuljahre und Semester', $u);
?>

<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Schuljahre und Semester</h1>
      <p class="muted">
        Hier legen Sie die Schuljahre mit 1. und 2. Semester an. Dieser Schritt sollte vor dem Anlegen neuer Einstiegsklassen und vor dem Schuljahreswechsel erfolgen.
        Bereits global angelegte Schuljahre können hier nachträglich einer Schule zugeordnet werden; bestehende Klassen und Leistungsdaten bleiben dabei unverändert beim selben Schuljahr.
      </p>

      <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <form method="get" class="row" style="align-items:end;margin-top:12px">
        <div class="school-selection" data-school-selection style="max-width:420px"><label class="school-selection-label">Schule</label><select class="input school-select" name="school_id" onchange="this.form.submit()"><?php if($isSuperAdmin): ?><option value="0" <?php echo $schoolFilter===0?'selected':''; ?>>Alle Schulen und globale Schuljahre</option><?php endif; ?><?php foreach($schools as $school): ?><option value="<?php echo (int)$school['id']; ?>" data-school-tone="<?php echo h(school_tone_class((int)$school['id'])); ?>" <?php echo $schoolFilter===(int)$school['id']?'selected':''; ?>><?php echo h($school['name']); ?></option><?php endforeach; ?></select><div class="school-selection-note">Filtert die angezeigten Schuljahre und Semestertermine.</div></div>
      </form>

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
              <th>Schule</th>
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
                <td><?php echo h($schoolNames[(int)($periodRow['school_id'] ?? 0)] ?? 'global / alle Schulen'); ?></td>
                <td><strong><?php echo h($periodRow['label']); ?></strong></td>
                <td><?php echo h($periodRow['semester1_from']); ?> bis <?php echo h($periodRow['semester1_to']); ?></td>
                <td><?php echo h($periodRow['semester2_from']); ?> bis <?php echo h($periodRow['semester2_to']); ?></td>
                <td><?php echo h($periodRow['semester1_from']); ?> bis <?php echo h($periodRow['semester2_to']); ?></td>
                <td><?php echo ((int)($periodRow['is_current'] ?? 0)===1)?'<span class="badge ok">aktuell</span>':'<span class="badge">Archiv/Planung</span>'; ?></td>
                <td style="min-width:260px">
                  <?php if((int)($periodRow['is_current'] ?? 0)!==1): ?>
                  <form method="post" style="display:inline" data-dirty-ignore="1">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="set_current_school_period">
                    <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                    <input type="hidden" name="school_id" value="<?php echo (int)$schoolFilter; ?>">
                    <button class="btn secondary small">Als aktuell setzen</button>
                  </form>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Schuljahr aus der Auswahl entfernen?');" style="display:inline" data-dirty-ignore="1">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="archive_school_period">
                    <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                    <input type="hidden" name="school_id" value="<?php echo (int)$schoolFilter; ?>">
                    <button class="btn danger small">Löschen</button>
                  </form>
                  <?php if((int)($periodRow['school_id'] ?? 0)===0 && $schools): ?>
                    <?php
                      $usageRows = $periodSchoolUsage[(int)$periodRow['id']] ?? [];
                      $usedSchoolIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['school_id'], $usageRows)));
                      $suggestedTargetSchoolId = count($usedSchoolIds) === 1 ? (int)$usedSchoolIds[0] : (int)$schoolFilter;
                      if($suggestedTargetSchoolId <= 0 && count($schools) === 1) $suggestedTargetSchoolId = (int)$schools[0]['id'];
                      $usageLabels = array_map(static function(array $row): string {
                        $classes = trim((string)($row['class_names'] ?? ''));
                        return (string)$row['school_name'].($classes !== '' ? ' ('.$classes.')' : '');
                      }, $usageRows);
                      $hasMultipleUsedSchools = count($usedSchoolIds) > 1;
                    ?>
                    <?php if($usageLabels): ?>
                      <div class="small muted" style="margin-top:8px;max-width:360px">
                        Enthält Klassen aus: <?php echo h(implode('; ', $usageLabels)); ?>
                      </div>
                    <?php endif; ?>
                    <form method="post" data-dirty-ignore="1" onsubmit="return confirm('Dieses globale Schuljahr der ausgewählten Schule zuordnen? Bestehende Leistungsdaten bleiben unverändert.');" style="display:flex;gap:6px;align-items:center;margin-top:8px;max-width:360px">
                      <?php echo csrf_input(); ?>
                      <input type="hidden" name="action" value="assign_school_period">
                      <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                      <input type="hidden" name="school_id" value="<?php echo (int)$schoolFilter; ?>">
                      <select class="input" name="target_school_id" aria-label="Zielschule für globales Schuljahr" <?php echo $hasMultipleUsedSchools ? 'disabled' : ''; ?>>
                        <?php foreach($schools as $school): ?>
                          <option value="<?php echo (int)$school['id']; ?>" <?php echo $suggestedTargetSchoolId===(int)$school['id']?'selected':''; ?>><?php echo h($school['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn secondary small" style="white-space:nowrap" <?php echo $hasMultipleUsedSchools ? 'disabled' : ''; ?>>Schule zuordnen</button>
                    </form>
                    <?php if($hasMultipleUsedSchools): ?>
                      <div class="small muted" style="margin-top:6px;max-width:360px">Automatische Zuordnung ist gesperrt, weil Klassen mehrerer Schulen enthalten sind.</div>
                    <?php elseif($usageRows): ?>
                      <div class="small muted" style="margin-top:6px;max-width:360px">Vorausgewählt ist die Schule, zu der die vorhandenen Klassen bereits gehören.</div>
                    <?php endif; ?>
                  <?php endif; ?>
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
                <div class="settings-panel-title">Schule und Bezeichnung</div>
                <div class="school-selection" data-school-selection>
                <label class="school-selection-label">Schule</label>
                <select class="input school-select" name="school_id">
                  <?php if($isSuperAdmin && count($schools)>1): ?>
                  <option value="-1" <?php echo $newPeriodSchoolId<0?'selected':''; ?>>Bitte Schule auswählen</option>
                  <?php endif; ?>
                  <?php if($isSuperAdmin): ?>
                  <option value="0" <?php echo $newPeriodSchoolId===0?'selected':''; ?>>global / alle Schulen (Sonderfall)</option>
                  <?php endif; ?>
                  <?php foreach($schools as $school): ?><option value="<?php echo (int)$school['id']; ?>" data-school-tone="<?php echo h(school_tone_class((int)$school['id'])); ?>" <?php echo $newPeriodSchoolId===(int)$school['id']?'selected':''; ?>><?php echo h($school['name']); ?></option><?php endforeach; ?>
                </select>
                <div class="school-selection-note">
                  Empfehlung bei mehreren Schulen: Legen Sie Schuljahre schulbezogen an. „global / alle Schulen“ ist nur sinnvoll, wenn wirklich alle Schulen dieselben Semestertermine nutzen und keine schulbezogene Trennung benötigt wird.
                </div>
                </div>
                <div style="height:10px"></div>
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
                  <th>Schule</th>
                  <th>Schuljahr</th>
                  <th>1. Semester</th>
                  <th>2. Semester</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($archivedPeriods as $periodRow): ?>
                  <tr>
                    <td><?php echo h($schoolNames[(int)($periodRow['school_id'] ?? 0)] ?? 'global / alle Schulen'); ?></td>
                    <td><strong><?php echo h($periodRow['label']); ?></strong></td>
                    <td><?php echo h($periodRow['semester1_from']); ?> bis <?php echo h($periodRow['semester1_to']); ?></td>
                    <td><?php echo h($periodRow['semester2_from']); ?> bis <?php echo h($periodRow['semester2_to']); ?></td>
                    <td style="white-space:nowrap">
                      <form method="post" style="display:inline" data-dirty-ignore="1">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="restore_school_period">
                        <input type="hidden" name="period_id" value="<?php echo (int)$periodRow['id']; ?>">
                        <input type="hidden" name="school_id" value="<?php echo (int)$schoolFilter; ?>">
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
