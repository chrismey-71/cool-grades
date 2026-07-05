<?php
require_once __DIR__.'/lib/layout.php';
$u=require_login();
$bp=cfg()['base_path'];
if(($u['role'] ?? '')==='teacher'){
  header('Location: '.$bp.'/teacher/index.php');
  exit;
}
render_header('Dashboard',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Dashboard</h1>
<div class="muted">Angemeldet als <b><?php echo h($u['first_name'].' '.$u['last_name']); ?></b> (<?php echo h($u['role']); ?>)</div>
<?php if((int)$u['must_change_password']===1): ?><div class="card" style="margin-top:10px;border-color:#ffe3a3;background:#fff7e6"><b>Hinweis:</b> Passwort ändern: <a href="<?php echo h($bp); ?>/account.php">Konto</a></div><?php endif; ?>
<?php if($u['role']==='admin'): ?>
<div style="height:14px"></div>
<h2>Adminbereich</h2>
<p class="muted">Hier verwaltest du die Stammdaten der Anwendung. Auswertungen und Beurteilungen sind für Administrator:innen bewusst nicht zugänglich.</p>

<div class="grid" style="margin-top:14px">
  <div class="col-12 col-md-4">
    <div class="card" style="padding:14px">
      <h2 style="margin:0 0 8px 0">Stammdaten</h2>
      <div class="muted" style="font-size:13px">Zuerst Schuljahre und Semester anlegen, danach Klassen, Fächer, Zuweisungen sowie Personen verwalten.</div>
      <details class="accordion" style="margin-top:10px">
        <summary><span class="acc-title">Hinweis empfohlene Reihenfolge</span></summary>
        <div class="acc-body">
          <ol class="muted" style="margin-top:0;padding-left:20px">
            <li>Neues Schuljahr mit beiden Semestern unter <b>Schuljahre/Semester</b> anlegen, aber erst später als aktuell setzen.</li>
            <li>Unter <b>Klassen</b> nur echte neue Einstiegsklassen anlegen, also Klassen ohne Vorgängerklasse.</li>
            <li>Fortgeführte Klassen immer über <b>Schuljahreswechsel</b> erzeugen, damit Vorjahresdaten erhalten bleiben.</li>
            <li>Abschlussklassen im Schuljahreswechsel als <b>Abschlussklasse ohne Zielklasse</b> abschließen.</li>
            <li>Lehrer:innen-/Fachzuordnungen und Schüler:innenlisten prüfen.</li>
            <li>Erst danach das neue Schuljahr unter <b>Schuljahre/Semester</b> als aktuell setzen.</li>
          </ol>
        </div>
      </details>
      <div style="height:10px"></div>
      <div class="row">
        <a class="btn" href="<?php echo h($bp); ?>/admin/school_years.php">Schuljahre/Semester</a>
        <a class="btn" href="<?php echo h($bp); ?>/admin/classes.php">Klassen</a>
        <a class="btn" href="<?php echo h($bp); ?>/admin/school_year_transition.php">Schuljahreswechsel</a>
        <a class="btn" href="<?php echo h($bp); ?>/admin/subjects.php">Fächer</a>
        <a class="btn" href="<?php echo h($bp); ?>/admin/assignments.php">Zuweisungen</a>
        <a class="btn" href="<?php echo h($bp); ?>/admin/students.php">Schüler:innen</a>
        <a class="btn" href="<?php echo h($bp); ?>/admin/teachers.php">Lehrer:innen</a>
      </div>
    </div>
  </div>

  <div class="col-12 col-md-4">
    <div class="card" style="padding:14px">
      <h2 style="margin:0 0 8px 0">Verwaltung</h2>
      <div class="muted" style="font-size:13px">Kriterien, Kriterien-Vorschläge und Picklisten zentral pflegen.</div>
      <div style="height:10px"></div>
      <div class="row">
        <a class="btn secondary" href="<?php echo h($bp); ?>/admin/manage.php">Zur Verwaltung</a>
      </div>
    </div>
  </div>

  <div class="col-12 col-md-4">
    <div class="card" style="padding:14px">
      <h2 style="margin:0 0 8px 0">Einstellungen</h2>
      <div class="muted" style="font-size:13px">Globale App-Einstellungen und Eventauswertungen gebündelt öffnen.</div>
      <div style="height:10px"></div>
      <div class="row">
        <a class="btn secondary" href="<?php echo h($bp); ?>/admin/settings_index.php">Zu Einstellungen</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
</div></div></div>
<?php render_footer(); ?>
