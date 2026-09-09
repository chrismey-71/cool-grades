<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/../lib/lesson_unit_times.php';

$u = require_role('admin');
$pdo = db();
$bp = cfg()['base_path'] ?? '';

$msg = '';
$err = '';

$schools = admin_schools_load($pdo, $u, true);
if(!$schools){
  render_header('UE-Zeiten', $u);
  ?>
  <div class="grid"><div class="col-12 col-8"><div class="card">
    <h1>UE-Zeiten</h1>
    <p class="muted">Es ist noch keine Schule angelegt. Bitte zuerst unter „Schulen und Schulformen" eine Schule anlegen.</p>
    <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück</a>
  </div></div></div>
  <?php
  render_footer();
  exit;
}

$school_id = (int)($_GET['school_id'] ?? $_POST['school_id'] ?? 0);
if(!$school_id || !admin_can_access_school($pdo, $u, $school_id)){
  $school_id = (int)$schools[0]['id'];
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $action = trim((string)($_POST['action'] ?? ''));

  if($action === 'save'){
    require_admin_school_access($pdo, $u, $school_id);
    $rows = [];
    for($n = LESSON_UNIT_TIME_MIN; $n <= LESSON_UNIT_TIME_MAX; $n++){
      $rows[$n] = [
        'start' => (string)($_POST['rows'][$n]['start'] ?? ''),
        'end'   => (string)($_POST['rows'][$n]['end'] ?? ''),
      ];
    }
    $err = lesson_unit_times_save($pdo, $school_id, $rows);
    if(!$err) $msg = 'UE-Zeiten gespeichert.';
  }
}

$ueRows = lesson_unit_time_rows($pdo, $school_id);
$school_name = '';
foreach($schools as $s){ if((int)$s['id']===$school_id){ $school_name=(string)$s['name']; break; } }

render_header('UE-Zeiten', $u);
?>

<div class="grid">
  <div class="col-12 col-8">
    <div class="card">
      <h1>UE-Zeiten</h1>
      <p class="muted">
        Trage hier je Schule die Uhrzeiten der Unterrichtseinheiten (UE) ein (z.B. UE 1: 07:40–08:30) – verschiedene
        Schulen können unterschiedliche Zeiten haben. Damit können importierte WebUntis-Stunden, die nur eine Uhrzeit
        mitbringen, automatisch der passenden UE dieser Schule zugeordnet werden – Lehrkräfte müssen die UE dann nicht
        mehr von Hand nachtragen. Eine zusammenhängende Doppelstunde wird dabei automatisch als z.B. „1,2" erkannt.
        Bereits vorhandene UE-Angaben an Stunden werden dadurch nie überschrieben. Nicht benötigte UE können leer bleiben.
      </p>

      <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <?php if(count($schools) > 1): ?>
        <form method="get" class="row" style="align-items:end;margin-bottom:14px">
          <div>
            <label class="muted">Schule</label>
            <select class="input" name="school_id">
              <?php foreach($schools as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo $school_id===(int)$s['id']?'selected':''; ?>><?php echo h($s['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="flex:0 0 auto">
            <label class="muted">&nbsp;</label>
            <button class="btn secondary">Anzeigen</button>
          </div>
        </form>
      <?php endif; ?>

      <form method="post" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="school_id" value="<?php echo (int)$school_id; ?>">
        <div class="settings-grid">
          <div class="settings-panel col-12">
            <div class="settings-panel-title">Stundenraster<?php echo $school_name?' – '.h($school_name):''; ?></div>
            <table class="table">
              <thead>
                <tr>
                  <th>UE</th>
                  <th>Beginn</th>
                  <th>Ende</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($ueRows as $unit => $t): ?>
                  <tr>
                    <td data-label="UE"><b>UE <?php echo (int)$unit; ?></b></td>
                    <td data-label="Beginn">
                      <input class="input" type="time" name="rows[<?php echo (int)$unit; ?>][start]" value="<?php echo h((string)($t['start'] ?? '')); ?>">
                    </td>
                    <td data-label="Ende">
                      <input class="input" type="time" name="rows[<?php echo (int)$unit; ?>][end]" value="<?php echo h((string)($t['end'] ?? '')); ?>">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="col-12">
            <button class="btn">Speichern</button>
            <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück</a>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<?php render_footer(); ?>
