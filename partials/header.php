<?php
// Variables expected: $title (string), $u (array|null), $bp (string)
?>
<?php
  $theme = 'light';
  if (!empty($u) && (($u['pref_theme'] ?? '') === 'dark')) $theme = 'dark';
  $entryContrast = entry_contrast_mode($u);
  $brandPalette = app_brand_palette();
  $brandCssVars = app_brand_css_vars();
  $timeoutMin = 0;
  if (!empty($u)) {
    // Inactivity timeout in minutes (0 = disabled)
    $timeoutMin = (int)app_setting_get('session_timeout_minutes', 30);
  }
?>
<!doctype html>
<html lang="de" data-theme="<?php echo h($theme); ?>" data-entry-contrast="<?php echo h($entryContrast); ?>" style="<?php echo h($brandCssVars); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
  <meta name="theme-color" content="<?php echo h($brandPalette['primary']); ?>">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="COOL-Grades">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="format-detection" content="telephone=no">
  <title><?php echo h($title); ?></title>
  <link rel="manifest" href="<?php echo h($bp); ?>/manifest.php">
  <link rel="icon" type="image/png" sizes="192x192" href="<?php echo h($bp); ?>/assets/icons/icon-192.png">
  <link rel="icon" type="image/png" sizes="512x512" href="<?php echo h($bp); ?>/assets/icons/icon-512.png">
  <link rel="apple-touch-icon" sizes="180x180" href="<?php echo h($bp); ?>/assets/icons/apple-touch-icon.png">
  <link rel="stylesheet" href="<?php echo h($bp); ?>/assets/styles.css?v=<?php echo h(_asset_v('assets/styles.css')); ?>">
  <link rel="stylesheet" href="<?php echo h($bp); ?>/assets/app.css?v=<?php echo h(_asset_v('assets/app.css')); ?>">
  <link rel="stylesheet" href="<?php echo h($bp); ?>/assets/dark.css?v=<?php echo h(_asset_v('assets/dark.css')); ?>">
</head>
<body data-timeout-min="<?php echo h((string)$timeoutMin); ?>" data-base-path="<?php echo h($bp); ?>">

<div class="topbar"><div class="wrap">

  <a class="brandlink" href="<?php echo h($bp); ?>/dashboard.php">
    <img class="brandlogo" src="<?php echo h($bp); ?>/assets/icons/pwa-icon.svg?v=<?php echo h(_asset_v('assets/icons/pwa-icon.svg')); ?>" alt="COOL-Grades Logo">
    <div class="brandtext">
      <div class="brandtitle">COOL-Grades</div>
      <div class="brandsub">Mitarbeit und Noten nach LBV erfassen</div>
    </div>
  </a>

  <button class="burger" type="button" aria-label="Menü" aria-controls="mainNav" aria-expanded="false" id="burgerBtn">
    <span></span><span></span><span></span>
  </button>

  <nav class="nav" id="mainNav">
    <?php if($u): ?>
      <a href="<?php echo h($bp); ?>/dashboard.php">Dashboard</a>
      <?php if(($u['role'] ?? '')==='admin'): ?><a href="<?php echo h($bp); ?>/admin/manage.php">Verwaltung</a><?php endif; ?>
      <?php if(($u['role'] ?? '')==='admin'): ?><a href="<?php echo h($bp); ?>/admin/settings_index.php">Einstellungen</a><?php endif; ?>
      <?php if(($u['role'] ?? '')==='teacher'): ?><a href="<?php echo h($bp); ?>/teacher/index.php">Lehrerbereich</a><?php endif; ?>
      <?php if(($u['role'] ?? '')==='teacher'): ?><a href="<?php echo h($bp); ?>/teacher/manage.php">Verwaltung</a><?php endif; ?>
      <a href="<?php echo h($bp); ?>/account.php">Konto</a>
      <form method="post" action="<?php echo h($bp); ?>/logout.php" class="logout-stack">
        <?php echo csrf_input(); ?>
        <button type="submit" class="navbtn">Logout</button>
        <?php if($timeoutMin > 0): ?>
          <span class="timeout-pill" id="sessionTimeoutCountdown" title="Automatischer Logout bei Inaktivität"><?php echo (int)$timeoutMin; ?> min</span>
        <?php endif; ?>
      </form>
    <?php else: ?>
      <a href="<?php echo h($bp); ?>/login.php">Login</a>
    <?php endif; ?>
  </nav>

  <?php if($u):
    $name = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''));
  ?>
    <div class="userpill">
      <span class="badge"><?php echo h(($u['role'] ?? '')); ?></span>
      <span><?php echo h($name ?: ($u['username'] ?? '')); ?></span>
    </div>
  <?php endif; ?>

</div></div>

<?php if($u && (($u['role'] ?? '') === 'teacher')):
  $teacherSchoolLabel = teacher_school_context_label($u);
?>
  <?php if($teacherSchoolLabel !== ''): ?>
    <div class="teacher-school-strip">
      <div class="wrap">
        <span class="teacher-school-label">Schule: <?php echo h($teacherSchoolLabel); ?></span>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="wrap">
