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
$loginRateLimit = login_rate_limit_config();
$passwordPolicy = password_policy();

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  $action = trim((string)($_POST['action'] ?? ''));

  if($action === 'save_general'){
    $v = trim((string)($_POST['session_timeout_minutes'] ?? ''));
    $retention = trim((string)($_POST['event_retention_days'] ?? ''));
    $loginMaxAttemptsRaw = trim((string)($_POST['login_rate_limit_max_attempts'] ?? '5'));
    $loginDelayRaw = trim((string)($_POST['login_rate_limit_delay_seconds'] ?? '2'));
    $loginLockoutRaw = trim((string)($_POST['login_rate_limit_lockout_minutes'] ?? '15'));
    $passwordMinLengthRaw = trim((string)($_POST['password_min_length'] ?? '12'));
    $color = sanitize_hex_color((string)($_POST['brand_primary_color'] ?? $brandColor), $brandColor);
    if($v === '') $v = '30';
    if($retention === '') $retention = '30';
    if($loginMaxAttemptsRaw === '') $loginMaxAttemptsRaw = '5';
    if($loginDelayRaw === '') $loginDelayRaw = '2';
    if($loginLockoutRaw === '') $loginLockoutRaw = '15';
    if($passwordMinLengthRaw === '') $passwordMinLengthRaw = '12';

    if(!preg_match('/^\d+$/', $v)
       || !preg_match('/^\d+$/', $retention)
       || !preg_match('/^\d+$/', $loginMaxAttemptsRaw)
       || !preg_match('/^\d+$/', $loginDelayRaw)
       || !preg_match('/^\d+$/', $loginLockoutRaw)
       || !preg_match('/^\d+$/', $passwordMinLengthRaw)){
      $err = 'Bitte eine ganze Zahl eingeben.';
    } else {
      $n = (int)$v;
      $retentionDays = (int)$retention;
      $loginMaxAttempts = (int)$loginMaxAttemptsRaw;
      $loginDelay = (int)$loginDelayRaw;
      $loginLockout = (int)$loginLockoutRaw;
      $passwordMinLength = (int)$passwordMinLengthRaw;
      $passwordRequireUpper = (string)($_POST['password_require_upper'] ?? '0') === '1' ? '1' : '0';
      $passwordRequireLower = (string)($_POST['password_require_lower'] ?? '0') === '1' ? '1' : '0';
      $passwordRequireDigit = (string)($_POST['password_require_digit'] ?? '0') === '1' ? '1' : '0';
      $passwordRequireSpecial = (string)($_POST['password_require_special'] ?? '0') === '1' ? '1' : '0';
      if($n !== 0 && ($n < 5 || $n > 240)){
        $err = 'Bitte zwischen 5 und 240 Minuten wählen (oder 0 zum Deaktivieren).';
      } elseif($retentionDays !== 0 && ($retentionDays < 7 || $retentionDays > 3650)) {
        $err = 'Bitte zwischen 7 und 3650 Tagen wählen (oder 0 zum Deaktivieren).';
      } elseif($loginMaxAttempts < 1 || $loginMaxAttempts > 50) {
        $err = 'Bitte für die Login-Ratenbegrenzung 1 bis 50 Fehlversuche wählen.';
      } elseif($loginDelay < 0 || $loginDelay > 30) {
        $err = 'Bitte für die Login-Verzögerung 0 bis 30 Sekunden wählen.';
      } elseif($loginLockout < 1 || $loginLockout > 1440) {
        $err = 'Bitte für die Login-Sperre 1 bis 1440 Minuten wählen.';
      } elseif($passwordMinLength < 8 || $passwordMinLength > 128) {
        $err = 'Bitte für die Passwortlänge 8 bis 128 Zeichen wählen.';
      } else {
        app_setting_set('session_timeout_minutes', (string)$n);
        app_setting_set('brand_primary_color', $color);
        app_setting_set('event_retention_days', (string)$retentionDays);
        app_setting_set('login_rate_limit_max_attempts', (string)$loginMaxAttempts);
        app_setting_set('login_rate_limit_delay_seconds', (string)$loginDelay);
        app_setting_set('login_rate_limit_lockout_minutes', (string)$loginLockout);
        app_setting_set('password_min_length', (string)$passwordMinLength);
        app_setting_set('password_require_upper', $passwordRequireUpper);
        app_setting_set('password_require_lower', $passwordRequireLower);
        app_setting_set('password_require_digit', $passwordRequireDigit);
        app_setting_set('password_require_special', $passwordRequireSpecial);
        $current = $n;
        $brandColor = $color;
        $eventRetentionDays = $retentionDays;
        $loginRateLimit = login_rate_limit_config();
        $passwordPolicy = password_policy();
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
      <form method="post" <?php echo dirty_form_attrs(); ?>>
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="save_general">
        <div class="settings-grid">
          <div class="settings-panel col-12">
            <div class="settings-panel-title">Allgemeine Anwendung</div>
            <div class="row" style="gap:12px;align-items:flex-end">
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
            </div>
          </div>

          <div class="settings-panel col-12">
            <div class="settings-panel-title">Login-Schutz</div>
            <div class="small muted settings-panel-note">Begrenzt wiederholte Fehlversuche pro Username und IP-Hash. Nach Überschreiten der Anzahl wird die Anmeldung temporär gesperrt.</div>
            <div class="row" style="gap:12px">
              <div>
                <label class="muted">Maximale Fehlversuche</label>
                <input class="input" name="login_rate_limit_max_attempts" value="<?php echo h((string)$loginRateLimit['max_attempts']); ?>" inputmode="numeric" pattern="[0-9]*" required>
                <div class="muted" style="margin-top:6px">1–50 Fehlversuche</div>
              </div>
              <div>
                <label class="muted">Verzögerung nach Fehlversuch (Sekunden)</label>
                <input class="input" name="login_rate_limit_delay_seconds" value="<?php echo h((string)$loginRateLimit['delay_seconds']); ?>" inputmode="numeric" pattern="[0-9]*" required>
                <div class="muted" style="margin-top:6px">0–30 Sekunden</div>
              </div>
              <div>
                <label class="muted">Temporäre Sperre (Minuten)</label>
                <input class="input" name="login_rate_limit_lockout_minutes" value="<?php echo h((string)$loginRateLimit['lockout_minutes']); ?>" inputmode="numeric" pattern="[0-9]*" required>
                <div class="muted" style="margin-top:6px">1–1440 Minuten</div>
              </div>
            </div>
          </div>

          <div class="settings-panel col-12">
            <div class="settings-panel-title">Passwortregeln</div>
            <div class="small muted settings-panel-note">Diese Regeln gelten für neue Passwörter und temporäre Passwörter. Bestehende Passwörter werden nicht rückwirkend geändert.</div>
            <div class="row" style="gap:12px">
              <div>
                <label class="muted">Mindestlänge</label>
                <input class="input" name="password_min_length" value="<?php echo h((string)$passwordPolicy['min_length']); ?>" inputmode="numeric" pattern="[0-9]*" required>
                <div class="muted" style="margin-top:6px">8–128 Zeichen</div>
              </div>
              <label style="display:flex;gap:8px;align-items:center;min-width:auto">
                <input type="checkbox" name="password_require_upper" value="1" style="width:auto" <?php echo $passwordPolicy['require_upper'] ? 'checked' : ''; ?>>
                <span>Großbuchstaben verlangen</span>
              </label>
              <label style="display:flex;gap:8px;align-items:center;min-width:auto">
                <input type="checkbox" name="password_require_lower" value="1" style="width:auto" <?php echo $passwordPolicy['require_lower'] ? 'checked' : ''; ?>>
                <span>Kleinbuchstaben verlangen</span>
              </label>
              <label style="display:flex;gap:8px;align-items:center;min-width:auto">
                <input type="checkbox" name="password_require_digit" value="1" style="width:auto" <?php echo $passwordPolicy['require_digit'] ? 'checked' : ''; ?>>
                <span>Zahl verlangen</span>
              </label>
              <label style="display:flex;gap:8px;align-items:center;min-width:auto">
                <input type="checkbox" name="password_require_special" value="1" style="width:auto" <?php echo $passwordPolicy['require_special'] ? 'checked' : ''; ?>>
                <span>Sonderzeichen verlangen</span>
              </label>
            </div>
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
