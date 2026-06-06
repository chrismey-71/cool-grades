<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/settings.php';

$u = require_role('admin');
$bp = cfg()['base_path'] ?? '';

$msg = '';
$err = '';

$current = (int)app_setting_get('session_timeout_minutes', 30);
$brandColor = app_brand_color();
$eventRetentionDays = (int)app_setting_get('event_retention_days', 30);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $action = trim((string)($_POST['action'] ?? ''));

  if($action === 'save_general'){
    $v = trim((string)($_POST['session_timeout_minutes'] ?? ''));
    $retention = trim((string)($_POST['event_retention_days'] ?? ''));
    $color = sanitize_hex_color((string)($_POST['brand_primary_color'] ?? $brandColor), $brandColor);
    if($v === '') $v = '30';
    if($retention === '') $retention = '30';

    if(!preg_match('/^\d+$/', $v) || !preg_match('/^\d+$/', $retention)){
      $err = 'Bitte eine ganze Zahl eingeben.';
    } else {
      $n = (int)$v;
      $retentionDays = (int)$retention;
      if($n !== 0 && ($n < 5 || $n > 240)){
        $err = 'Bitte zwischen 5 und 240 Minuten wählen (oder 0 zum Deaktivieren).';
      } elseif($retentionDays !== 0 && ($retentionDays < 7 || $retentionDays > 3650)) {
        $err = 'Bitte zwischen 7 und 3650 Tagen wählen (oder 0 zum Deaktivieren).';
      } else {
        app_setting_set('session_timeout_minutes', (string)$n);
        app_setting_set('brand_primary_color', $color);
        app_setting_set('event_retention_days', (string)$retentionDays);
        $current = $n;
        $brandColor = $color;
        $eventRetentionDays = $retentionDays;
        $msg = 'Einstellungen gespeichert.';
      }
    }
  }
}

render_header('Einstellungen', $u);
?>

<div class="grid">
  <div class="col-12 col-8">
    <div class="card">
      <h1>Einstellungen</h1>
      <p class="muted">Globale Einstellungen für die Anwendung.</p>

      <?php if($msg): ?>
        <div class="flash success"><?php echo h($msg); ?></div>
      <?php endif; ?>
      <?php if($err): ?>
        <div class="flash error"><?php echo h($err); ?></div>
      <?php endif; ?>

      <h2>Allgemein</h2>
      <p class="muted">
        Diese Werte wirken global auf die Anwendung.
      </p>
      <form method="post" class="row" style="gap:12px;align-items:flex-end" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="save_general">
        <div style="min-width:260px">
          <label class="muted">Inaktivitäts‑Timeout (Minuten)</label>
          <input class="input" name="session_timeout_minutes" value="<?php echo h((string)$current); ?>" inputmode="numeric" pattern="[0-9]*" required>
          <div class="muted" style="margin-top:6px">0 = deaktivieren • 5–240 Minuten möglich</div>
        </div>
        <div style="min-width:260px">
          <label class="muted">Grundfarbe des Designs</label>
          <div class="row" style="gap:10px;align-items:center">
            <input class="input" type="color" name="brand_primary_color" value="<?php echo h($brandColor); ?>" style="max-width:84px;padding:6px 8px">
            <div>
              <div class="badge" style="background:<?php echo h(app_brand_palette()['soft']); ?>;color:<?php echo h(app_brand_palette()['dark']); ?>;border-color:<?php echo h(app_brand_palette()['ring']); ?>">Aktuell: <?php echo h($brandColor); ?></div>
              <div class="muted" style="margin-top:6px">Wirkt auf Header, Buttons, Links und die iPhone-/PWA-Farbe.</div>
            </div>
          </div>
        </div>
        <div style="min-width:260px">
          <label class="muted">Event-Aufbewahrung (Tage)</label>
          <input class="input" name="event_retention_days" value="<?php echo h((string)$eventRetentionDays); ?>" inputmode="numeric" pattern="[0-9]*" required>
          <div class="muted" style="margin-top:6px">Automatische Löschung alter Eventdaten. 0 = deaktivieren • Empfehlung: 30 Tage</div>
        </div>
        <div>
          <button class="btn">Speichern</button>
          <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zurück</a>
        </div>
      </form>

    </div>
  </div>
</div>

<?php render_footer(); ?>
