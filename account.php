<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/events.php';
require_once __DIR__.'/lib/security.php';
require_once __DIR__.'/lib/webuntis_ical.php';
$u=require_login(); $msg=''; $err='';
$webuntisImportSummary=null;
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

      $navStyle = (string)($_POST['pref_nav_style'] ?? 'text');
      if(!in_array($navStyle,['text','icon','icon_text'],true)) $navStyle='text';

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
                             pref_legal_hints_enabled=?, pref_compact_forms_enabled=?, pref_visual_contrast=?, pref_simple_participation_entry=?,
                             pref_nav_style=?
                         WHERE id=?");
      $st->execute([$theme,$quick,$quickPickEnabled,$quickPickLimit,$legalHintsEnabled,$compactFormsEnabled,$visualContrast,$simpleParticipationEntry,$navStyle,(int)$u['id']]);
      $msg='Einstellungen gespeichert.';
      $u=current_user();
    } elseif($action==='webuntis_save'){
      if(($u['role'] ?? '')!=='teacher'){
        throw new Exception('Nur für Lehrkräfte verfügbar.');
      }
      $icalUrl=trim((string)($_POST['webuntis_ical_url'] ?? ''));
      if($icalUrl===''){
        $st=db()->prepare("UPDATE users SET webuntis_ical_url_enc=NULL, webuntis_ical_saved_at=NULL WHERE id=?");
        $st->execute([(int)$u['id']]);
        $msg='WebUntis-Link entfernt.';
      } else {
        if(!preg_match('#^https?://#i',$icalUrl)){
          throw new Exception('Bitte einen gültigen Link (http/https) eingeben.');
        }
        $enc=security_encrypt_secret($icalUrl);
        $st=db()->prepare("UPDATE users SET webuntis_ical_url_enc=?, webuntis_ical_saved_at=? WHERE id=?");
        $st->execute([$enc,now_iso(),(int)$u['id']]);
        $msg='WebUntis-Link gespeichert. Der Link wird verschlüsselt abgelegt und nirgends im Klartext angezeigt.';
      }
      $u=current_user();
    } elseif($action==='webuntis_import'){
      if(($u['role'] ?? '')!=='teacher'){
        throw new Exception('Nur für Lehrkräfte verfügbar.');
      }
      $webuntisImportSummary=webuntis_import_for_teacher(db(),$u);
      $u=current_user();
      $msg='WebUntis-Import abgeschlossen: '.(int)$webuntisImportSummary['imported'].' neue, '.(int)$webuntisImportSummary['updated'].' aktualisierte Stunde(n) übernommen.';
    } elseif($action==='webuntis_create_groups'){
      if(($u['role'] ?? '')!=='teacher'){
        throw new Exception('Nur für Lehrkräfte verfügbar.');
      }
      $groupClassId=(int)($_POST['class_id'] ?? 0);
      $groupSubjectId=(int)($_POST['subject_id'] ?? 0);
      if($groupClassId<=0 || $groupSubjectId<=0){
        throw new Exception('Ungültige Klasse/Fach-Auswahl.');
      }
      require_teacher_assignment($u,$groupClassId,$groupSubjectId);
      webuntis_create_ab_groups(db(),(int)$u['id'],$groupClassId,$groupSubjectId);
      header('Location: '.cfg()['base_path'].'/teacher/student_groups.php?class_id='.$groupClassId.'&subject_id='.$groupSubjectId.'&msg='.rawurlencode('Gruppen „a" und „b" angelegt – bitte jetzt die Mitglieder zuweisen.'));
      exit;
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
if(($u['role'] ?? '')==='teacher' && $webuntisImportSummary===null){
  $storedSummaryRaw=(string)($u['webuntis_ical_last_import_summary'] ?? '');
  if($storedSummaryRaw!==''){
    $decoded=json_decode($storedSummaryRaw,true);
    if(is_array($decoded)) $webuntisImportSummary=$decoded;
  }
}
render_header('Konto',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Konto</h1>
<div class="muted">Username: <b><?php echo h($u['username']); ?></b></div>
<?php if($msg): ?><div class="card" style="border-color:#bfe5cd;background:#e8f5ee;margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="border-color:#ffc6c0;background:#ffeceb;margin-top:10px"><?php echo h($err); ?></div><?php endif; ?>

<h2 id="account-display-workflow" style="margin-top:18px">Persönliche Einstellungen</h2>
<div class="muted" style="margin-top:4px">
  Diese Einstellungen verändern Darstellung, Hinweise und Arbeitsweise der App. Sie ändern keine gespeicherten Leistungsdaten.
</div>
<form method="post" <?php echo dirty_form_attrs(); ?>>
  <?php echo csrf_input(); ?>
  <input type="hidden" name="action" value="prefs">
  <div class="settings-panel" style="margin-top:12px;border-color:#cfe5ff;background:#f8fbff">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div>
        <div class="settings-panel-title">Einstellungen speichern</div>
        <div class="small muted">Diese Taste speichert nur die unten gewählten Konto-Einstellungen. Das Passwort wird in einem eigenen Sicherheitsbereich geändert.</div>
      </div>
      <button class="btn">Persönliche Einstellungen speichern</button>
    </div>
  </div>

  <div class="settings-grid">
    <?php if(($u['role'] ?? '')==='teacher'): ?>
    <div class="col-12"><div class="settings-section-heading">Schnelle Mitarbeitserfassung</div></div>
    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-simple-participation">
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
        <div class="small muted settings-panel-note">Empfohlen für die schnelle Alltagserfassung: Datum, Grund/Anlass, Eindruck/Relevanz, Beobachtungsbereich, Leistungsart, Unterrichtskontext, kurze Beobachtung und Schüler:innen. Die fachliche Tiefe mit Detailkriterien bleibt in der normalen Ansicht verfügbar.</div>
      </div>
    </div>

    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-quick-entry-ui">
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
        <div class="small muted settings-panel-note">Steuert nur, ob Klasse/Fach im Dashboard als Buttons oder Dropdown angezeigt werden. Bewertungen und Auswertungen bleiben unverändert.</div>
      </div>
    </div>

    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-quick-pick">
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

    <div class="col-12"><div class="settings-section-heading">Formulare und Darstellung</div></div>
    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-compact-forms">
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
      <div class="settings-panel" id="account-pref-visual-contrast">
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
      <div class="settings-panel" id="account-pref-theme">
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

    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-nav-style">
        <div class="settings-panel-title">Hauptnavigation <span class="setting-impact">nur Ansicht</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_nav_style" value="text" style="width:auto" <?php echo (($u['pref_nav_style'] ?? 'text')==='text')?'checked':''; ?>>
            <span>Text</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_nav_style" value="icon" style="width:auto" <?php echo (($u['pref_nav_style'] ?? 'text')==='icon')?'checked':''; ?>>
            <span>Nur Symbol</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_nav_style" value="icon_text" style="width:auto" <?php echo (($u['pref_nav_style'] ?? 'text')==='icon_text')?'checked':''; ?>>
            <span>Symbol + Text</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Bestimmt, ob die Menüpunkte oben als Text, als Symbol oder als Symbol mit Text angezeigt werden. Wirkt nur auf die Darstellung, nicht auf verfügbare Funktionen.</div>
      </div>
    </div>
    <?php else: ?>
    <div class="col-12"><div class="settings-section-heading">Ansicht</div></div>

    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-theme">
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

    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-nav-style">
        <div class="settings-panel-title">Hauptnavigation <span class="setting-impact">nur Ansicht</span></div>
        <div class="row" style="gap:10px;align-items:center">
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_nav_style" value="text" style="width:auto" <?php echo (($u['pref_nav_style'] ?? 'text')==='text')?'checked':''; ?>>
            <span>Text</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_nav_style" value="icon" style="width:auto" <?php echo (($u['pref_nav_style'] ?? 'text')==='icon')?'checked':''; ?>>
            <span>Nur Symbol</span>
          </label>
          <label style="display:flex;gap:8px;align-items:center;min-width:auto;flex:0 0 auto">
            <input type="radio" name="pref_nav_style" value="icon_text" style="width:auto" <?php echo (($u['pref_nav_style'] ?? 'text')==='icon_text')?'checked':''; ?>>
            <span>Symbol + Text</span>
          </label>
        </div>
        <div class="small muted settings-panel-note">Bestimmt, ob die Menüpunkte oben als Text, als Symbol oder als Symbol mit Text angezeigt werden. Wirkt nur auf die Darstellung, nicht auf verfügbare Funktionen.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-12"><div class="settings-section-heading">Hinweise und Auswertung</div></div>
    <div class="col-12 col-6">
      <div class="settings-panel" id="account-pref-legal-hints">
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
  </div>

  <div style="height:12px"></div>
  <button class="btn">Persönliche Einstellungen speichern</button>
</form>

<?php if(($u['role'] ?? '')==='teacher'): ?>
<h2 id="account-webuntis" style="margin-top:22px">WebUntis-Stundenplan (iCal)</h2>
<div class="settings-panel" style="margin-top:12px;border-color:#cfe5ff;background:#f8fbff">
  <div class="settings-panel-title">Privater iCal-Link <span class="setting-impact">Stundenkontext</span></div>
  <div class="small muted settings-panel-note">
    Der Link aus WebUntis (Stundenplan exportieren/abonnieren, „iCal“) enthält ein persönliches Zugriffstoken. Er wird verschlüsselt gespeichert und nirgends im Klartext angezeigt – auch dir selbst wird hier nur Anbieter und Host angezeigt, nicht der vollständige Link. Ein Import liest ausschließlich Fächer und Klassen, denen du aktuell zugewiesen bist.
  </div>
  <div style="margin-top:8px">
    <?php if(!empty($u['webuntis_ical_url_enc'])): ?>
      <div>Gespeicherter Link: <b><?php
        $storedUrl=security_decrypt_secret((string)$u['webuntis_ical_url_enc']);
        echo h($storedUrl!==null ? security_mask_url($storedUrl) : '(nicht lesbar – bitte neu speichern)');
      ?></b></div>
      <div class="small muted" style="margin-top:4px">Gespeichert am <?php echo h((string)($u['webuntis_ical_saved_at'] ?? '')); ?><?php if(!empty($u['webuntis_ical_last_import_at'])): ?> · zuletzt importiert am <?php echo h((string)$u['webuntis_ical_last_import_at']); ?><?php endif; ?></div>
    <?php else: ?>
      <div class="muted">Es ist noch kein WebUntis-Link hinterlegt.</div>
    <?php endif; ?>
  </div>

  <form method="post" <?php echo dirty_form_attrs(); ?> style="margin-top:12px">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="webuntis_save">
    <label class="muted">iCal-Link (leer lassen, um den gespeicherten Link zu entfernen)</label>
    <input class="input" type="url" name="webuntis_ical_url" placeholder="https://webuntis.../ical?token=..." autocomplete="off">
    <div style="height:10px"></div>
    <button class="btn secondary">Link speichern</button>
  </form>

  <?php if(!empty($u['webuntis_ical_url_enc'])): ?>
    <form method="post" <?php echo dirty_form_attrs(); ?> style="margin-top:12px">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="webuntis_import">
      <button class="btn">Jetzt importieren</button>
    </form>
  <?php endif; ?>

  <?php if(is_array($webuntisImportSummary)): ?>
    <div class="settings-panel" style="margin-top:14px;border-color:#bfe5cd;background:#eefaf2">
      <div class="settings-panel-title">Letztes Import-Ergebnis</div>
      <div class="small" style="margin-top:6px">
        <?php echo (int)($webuntisImportSummary['total_events'] ?? 0); ?> Termine im Feed ·
        <b><?php echo (int)($webuntisImportSummary['imported'] ?? 0); ?></b> neu übernommen ·
        <?php echo (int)($webuntisImportSummary['updated'] ?? 0); ?> aktualisiert ·
        <?php echo (int)($webuntisImportSummary['skipped_cancelled'] ?? 0); ?> abgesagt/übersprungen ·
        <?php if((int)($webuntisImportSummary['skipped_unmapped_subject'] ?? 0) > 0): ?>
          <a href="<?php echo h(cfg()['base_path']); ?>/teacher/webuntis_review.php"><?php echo (int)$webuntisImportSummary['skipped_unmapped_subject']; ?> mit unbekanntem Fachkürzel</a> ·
        <?php else: ?>
          <?php echo (int)($webuntisImportSummary['skipped_unmapped_subject'] ?? 0); ?> mit unbekanntem Fachkürzel ·
        <?php endif; ?>
        <?php echo (int)($webuntisImportSummary['skipped_unmapped_class'] ?? 0); ?> ohne erkennbare Klasse ·
        <?php echo (int)($webuntisImportSummary['skipped_not_assigned'] ?? 0); ?> außerhalb deiner Zuweisungen.
      </div>
      <?php if(!empty($webuntisImportSummary['unmapped_subjects'])): ?>
        <div class="small muted" style="margin-top:6px">
          Unbekannte Fachkürzel (kein passendes Fachkürzel in COOL-Grades gefunden): <?php echo h(implode(', ',$webuntisImportSummary['unmapped_subjects'])); ?>
          – <a href="<?php echo h(cfg()['base_path']); ?>/teacher/webuntis_review.php">jetzt zuordnen oder als „keine Unterrichtsstunde" markieren</a>.
        </div>
      <?php endif; ?>
      <?php if(!empty($webuntisImportSummary['unmapped_classes'])): ?>
        <div class="small muted" style="margin-top:6px">Unbekannte Klassen (keine passende Klasse in COOL-Grades gefunden): <?php echo h(implode(', ',$webuntisImportSummary['unmapped_classes'])); ?></div>
      <?php endif; ?>
      <div class="small muted" style="margin-top:6px">Diese Termine wurden bewusst nicht übernommen, statt sie zu erraten. Unbekannte Fachkürzel kannst du auf der <a href="<?php echo h(cfg()['base_path']); ?>/teacher/webuntis_review.php">Übersichts- und Korrekturseite</a> zuordnen oder als „keine Unterrichtsstunde" markieren.</div>

      <?php
        $webuntisMissingGroupCombos = webuntis_missing_subgroup_combos(db(),(int)$u['id'],$webuntisImportSummary);
      ?>
      <?php if($webuntisMissingGroupCombos): ?>
        <div class="settings-panel" style="margin-top:12px;border-color:#cfe5ff;background:#f8fbff">
          <div class="settings-panel-title">Gruppen a/b anlegen?</div>
          <div class="small muted" style="margin-top:4px">Laut WebUntis gibt es für folgende Klassen/Fächer eigene Stunden für Gruppe a bzw. b, aber noch keine passende Gruppe in COOL-Grades. Das Anlegen erzeugt nur die leeren Gruppen „a" und „b" – wer dazugehört, trägst du danach einmalig selbst ein.</div>
          <?php foreach($webuntisMissingGroupCombos as $combo): ?>
            <form method="post" style="margin-top:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="webuntis_create_groups">
              <input type="hidden" name="class_id" value="<?php echo (int)$combo['class_id']; ?>">
              <input type="hidden" name="subject_id" value="<?php echo (int)$combo['subject_id']; ?>">
              <span><b><?php echo h($combo['class_name']); ?></b> · <?php echo h($combo['subject_code']); ?></span>
              <button class="btn secondary">Gruppen a/b anlegen</button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2 id="account-password" style="margin-top:22px">Sicherheit</h2>
<div class="settings-panel" style="margin-top:12px;border-color:#ffe3b0;background:#fffaf0">
  <form method="post" <?php echo dirty_form_attrs(); ?>>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="password">
    <div class="settings-panel-title">Passwort ändern <span class="setting-impact">Sicherheit</span></div>
    <div class="small muted settings-panel-note">Dieser Bereich ändert ausschließlich dein Passwort. Persönliche Einstellungen werden oben separat gespeichert.</div>
    <label class="muted">Neues Passwort</label>
    <input class="input" type="password" name="new_password" required autocomplete="new-password">
    <div class="small muted settings-panel-note">Erforderlich: <?php echo h(password_policy_summary()); ?>.</div>
    <div style="height:12px"></div><button class="btn secondary">Passwort ändern</button>
  </form>
</div>
</div></div></div>
<?php render_footer(); ?>
