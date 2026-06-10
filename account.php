<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/events.php';
$u=require_login(); $msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action = (string)($_POST['action'] ?? '');
  try{
    if($action==='prefs'){
      $theme = (string)($_POST['pref_theme'] ?? 'light');
      if(!in_array($theme,['light','dark'],true)) $theme='light';

      $quick = (string)($_POST['pref_quick_entry_ui'] ?? '');
      if(!in_array($quick,['dropdown','buttons'],true)) $quick=null;

      $quickPickEnabled = (int)($u['pref_participation_quick_pick_enabled'] ?? 1);
      $quickPickLimit = (int)($u['pref_participation_quick_pick_limit'] ?? 10);
      $legalHintsEnabled = (int)($u['pref_legal_hints_enabled'] ?? 1);
      $compactFormsEnabled = (int)($u['pref_compact_forms_enabled'] ?? 0);
      $visualContrast = (string)($u['pref_visual_contrast'] ?? 'normal');
      $simpleParticipationEntry = (int)($u['pref_simple_participation_entry'] ?? 0);
      if(!in_array($visualContrast,['normal','high'],true)) $visualContrast='normal';
      if($quickPickLimit < 1 || $quickPickLimit > 30) $quickPickLimit = 10;

      $legalHintsEnabledRaw = (string)($_POST['pref_legal_hints_enabled'] ?? '1');
      if(!in_array($legalHintsEnabledRaw,['0','1'],true)) $legalHintsEnabledRaw='1';
      $legalHintsEnabled = (int)$legalHintsEnabledRaw;

      if(($u['role'] ?? '')==='teacher'){
        $compactFormsEnabledRaw = (string)($_POST['pref_compact_forms_enabled'] ?? '0');
        if(!in_array($compactFormsEnabledRaw,['0','1'],true)) $compactFormsEnabledRaw='0';
        $compactFormsEnabled = (int)$compactFormsEnabledRaw;

        $visualContrastRaw = (string)($_POST['pref_visual_contrast'] ?? 'normal');
        if(!in_array($visualContrastRaw,['normal','high'],true)) $visualContrastRaw='normal';
        $visualContrast = $visualContrastRaw;

        $simpleParticipationEntryRaw = (string)($_POST['pref_simple_participation_entry'] ?? '0');
        if(!in_array($simpleParticipationEntryRaw,['0','1'],true)) $simpleParticipationEntryRaw='0';
        $simpleParticipationEntry = (int)$simpleParticipationEntryRaw;

        $quickPickEnabledRaw = (string)($_POST['pref_participation_quick_pick_enabled'] ?? '1');
        if(!in_array($quickPickEnabledRaw,['0','1'],true)) $quickPickEnabledRaw='1';
        $quickPickEnabled = (int)$quickPickEnabledRaw;

        $quickPickLimitRaw = trim((string)($_POST['pref_participation_quick_pick_limit'] ?? '10'));
        if($quickPickLimitRaw==='') $quickPickLimitRaw='10';
        if(!preg_match('/^\d+$/',$quickPickLimitRaw)){
          throw new Exception('Bitte für den Quick-Pick eine ganze Zahl eingeben.');
        }
        $quickPickLimit = (int)$quickPickLimitRaw;
        if($quickPickLimit < 1 || $quickPickLimit > 30){
          throw new Exception('Bitte für den Quick-Pick eine Zahl zwischen 1 und 30 wählen.');
        }
      }

      $st=db()->prepare("UPDATE users
                         SET pref_theme=?, pref_quick_entry_ui=?,
                             pref_participation_quick_pick_enabled=?, pref_participation_quick_pick_limit=?,
                             pref_legal_hints_enabled=?, pref_compact_forms_enabled=?, pref_visual_contrast=?, pref_simple_participation_entry=?
                         WHERE id=?");
      $st->execute([$theme,$quick,$quickPickEnabled,$quickPickLimit,$legalHintsEnabled,$compactFormsEnabled,$visualContrast,$simpleParticipationEntry,(int)$u['id']]);
      $msg='Einstellungen gespeichert.';
      $u=current_user();
    } else {
      // default: password change
      change_password((int)$u['id'], (string)($_POST['new_password']??''));
      emit_event('password_change',[]);
      $msg='Passwort geändert.';
      $u=current_user();
    }
  }
  catch(Exception $e){ $err=$e->getMessage(); }
}
render_header('Konto',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Konto</h1>
<div class="muted">Username: <b><?php echo h($u['username']); ?></b></div>
<?php if($msg): ?><div class="card" style="border-color:#bfe5cd;background:#e8f5ee;margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="border-color:#ffc6c0;background:#ffeceb;margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>
<h2 style="margin-top:14px">Passwort ändern</h2>
<div class="settings-panel" style="margin-top:12px">
  <form method="post" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="password">
    <div class="settings-panel-title">Neues Passwort</div>
    <label class="muted">Neues Passwort</label>
    <input class="input" type="password" name="new_password" required>
    <div class="small muted settings-panel-note">Erforderlich: <?php echo h(password_policy_summary()); ?>.</div>
    <div style="height:12px"></div><button class="btn">Speichern</button>
  </form>
</div>

<h2 style="margin-top:18px">Darstellung und Arbeitsweise</h2>
<form method="post" <?php echo dirty_form_attrs(); ?>>
  <?php echo csrf_input(); ?>
  <input type="hidden" name="action" value="prefs">

  <div class="settings-grid">
    <div class="col-12"><div class="settings-section-heading">Ansicht</div></div>
    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Theme <span class="setting-impact">nur Ansicht</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_theme" value="light" style="width:auto" <?php echo (($u['pref_theme'] ?? '')!=='dark')?'checked':''; ?>>
            <span>Hell</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_theme" value="dark" style="width:auto" <?php echo (($u['pref_theme'] ?? '')==='dark')?'checked':''; ?>>
            <span>Darkmode</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Der Darkmode wirkt auf alle Bereiche der Oberfläche. Gespeicherte Daten, Auswertungen und PDF-Dateien werden dadurch nicht verändert.</div>
      </div>
    </div>

    <div class="col-12"><div class="settings-section-heading">Auswertung und Hinweise</div></div>
    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Gesetzeshinweise <span class="setting-impact">Hinweise</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_legal_hints_enabled" value="1" style="width:auto" <?php echo ((string)($u['pref_legal_hints_enabled'] ?? '1')!=='0')?'checked':''; ?>>
            <span>Anzeigen</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_legal_hints_enabled" value="0" style="width:auto" <?php echo ((string)($u['pref_legal_hints_enabled'] ?? '1')==='0')?'checked':''; ?>>
            <span>Ausblenden</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Blendet die eingebauten Gesetzeshinweise in Erfassungs- und Auswertungsseiten ein oder aus. Die gespeicherten Leistungsdaten werden dadurch nicht verändert.</div>
      </div>
    </div>

    <?php if(($u['role'] ?? '')==='teacher'): ?>
    <div class="col-12"><div class="settings-section-heading">Erfassung</div></div>
    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Anzeige in Eingabefenstern <span class="setting-impact">Erfassung / Ansicht</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_compact_forms_enabled" value="0" style="width:auto" <?php echo ((string)($u['pref_compact_forms_enabled'] ?? '0')!=='1')?'checked':''; ?>>
            <span>Normal</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_compact_forms_enabled" value="1" style="width:auto" <?php echo ((string)($u['pref_compact_forms_enabled'] ?? '0')==='1')?'checked':''; ?>>
            <span>Kompakt</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Kompakt bündelt Eingabebereiche in Accordions. Normal belässt die Formulare wie bisher. Die gespeicherten Daten bleiben gleich.</div>
      </div>
    </div>

    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Farbliche Abhebung in Eingabebereichen und Menüs <span class="setting-impact">nur Ansicht</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_visual_contrast" value="normal" style="width:auto" <?php echo (($u['pref_visual_contrast'] ?? 'normal')!=='high')?'checked':''; ?>>
            <span>Normal</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_visual_contrast" value="high" style="width:auto" <?php echo (($u['pref_visual_contrast'] ?? 'normal')==='high')?'checked':''; ?>>
            <span>Kontrastreich</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Hebt Eingabebereiche und Menükarten farblich deutlicher voneinander ab. Die Auswahl verändert keine gespeicherten Daten.</div>
      </div>
    </div>

    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Vereinfachte Eingabe bei Mitarbeit <span class="setting-impact">Erfassung</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_simple_participation_entry" value="0" style="width:auto" <?php echo ((string)($u['pref_simple_participation_entry'] ?? '0')!=='1')?'checked':''; ?>>
            <span>Aus</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_simple_participation_entry" value="1" style="width:auto" <?php echo ((string)($u['pref_simple_participation_entry'] ?? '0')==='1')?'checked':''; ?>>
            <span>Vereinfachte Eingabe</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Empfohlen für die schnelle Alltagserfassung: Datum, Grund/Anlass, Eindruck/Relevanz, Beobachtungsbereich, Leistungsart, kurze Beobachtung und Schüler:innen. Die fachliche Tiefe mit Unterrichtskontext und Detailkriterien bleibt in der normalen Ansicht verfügbar.</div>
      </div>
    </div>

    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Schnellerfassung: Auswahlmodus <span class="setting-impact">nur Ansicht</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_quick_entry_ui" value="dropdown" style="width:auto" <?php echo (($u['pref_quick_entry_ui'] ?? '')==='dropdown')?'checked':''; ?>>
            <span>Dropdown</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_quick_entry_ui" value="buttons" style="width:auto" <?php echo (($u['pref_quick_entry_ui'] ?? '')==='buttons')?'checked':''; ?>>
            <span>Buttons</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Steuert nur, ob Klasse/Fach im Lehrerbereich als Buttons oder Dropdown angezeigt werden. Bewertungen und Auswertungen bleiben unverändert.</div>
      </div>
    </div>

    <div class="col-12 col-6">
      <div class="settings-panel">
        <div class="settings-panel-title">Quick-Pick in Mitarbeit erfassen <span class="setting-impact">Erfassung</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_participation_quick_pick_enabled" value="1" style="width:auto" <?php echo ((string)($u['pref_participation_quick_pick_enabled'] ?? '1')!=='0')?'checked':''; ?>>
            <span>Anzeigen</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_participation_quick_pick_enabled" value="0" style="width:auto" <?php echo ((string)($u['pref_participation_quick_pick_enabled'] ?? '1')==='0')?'checked':''; ?>>
            <span>Ausblenden</span>
          </label>
        </div>
        <div style="height:10px"></div>
        <label class="muted">Anzahl angezeigter Schüler:innen</label>
        <input class="input" name="pref_participation_quick_pick_limit" type="number" min="1" max="30" value="<?php echo h((string)((int)($u['pref_participation_quick_pick_limit'] ?? 10) ?: 10)); ?>">
        <div class="small muted settings-panel-note">Zeigt in der Mitarbeit-Erfassung Schüler:innen mit den wenigsten oder keinen bisherigen Bewertungen in diesem Fach und dieser Klasse. Der Quick-Pick ist nur ein Vorschlag und speichert noch keine Auswahl.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div style="height:12px"></div>
  <button class="btn">Einstellungen speichern</button>
</form>
</div></div></div>
<?php render_footer(); ?>
