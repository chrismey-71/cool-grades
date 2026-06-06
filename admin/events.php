<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/settings.php';
$u=require_role('admin'); 
$pdo=db();

/**
 * admin/events.php
 * - Typ deutsch (für Admin verständlich)
 * - Payload aufgeschlüsselt (IDs -> Namen, wo möglich)
 * - Filter: Typ, Akteur, Zeitraum, Suche, Limit
 */

function _json_payload(string $s): array {
  $j = json_decode($s, true);
  if (is_array($j)) return $j;
  return ['raw' => $s];
}

function _event_type_de(string $type): string {
  $map = [
    'login' => 'Anmeldung',
    'password_change' => 'Passwort geändert',

    'participation_recorded' => 'Mitarbeit gespeichert',
    'participation_lbvo_updated' => 'LBV-Tags manuell geändert',
    'participation_lbvo_recalc' => 'LBV-Tags neu berechnet',

    'exam_created' => 'Schularbeit/Test angelegt',
    'exam_updated' => 'Schularbeit/Test geändert',
    'exam_deleted' => 'Schularbeit/Test gelöscht',
    'oral_assessment_created' => 'Mündliche Leistungsfeststellung angelegt',
    'oral_assessment_updated' => 'Mündliche Leistungsfeststellung geändert',
    'oral_assessment_deleted' => 'Mündliche Leistungsfeststellung gelöscht',

    'lesson_updated' => 'Stunde geändert',
    'lesson_deleted' => 'Stunde gelöscht',

    'teacher_criteria_set_created' => 'Kriterienset erstellt',
    'teacher_criteria_set_deleted' => 'Kriterienset gelöscht',
    'teacher_criteria_seeded' => 'Kriterien-Vorschläge eingefügt',
    'teacher_criterion_created' => 'Kriterium erstellt',
    'teacher_criterion_deleted' => 'Kriterium gelöscht',
    'teacher_criterion_toggled' => 'Kriterium aktiviert/deaktiviert',

    'teacher_option_copied' => 'Picklisten-Option kopiert',
    'teacher_option_deleted' => 'Picklisten-Option gelöscht',
    'teacher_student_group_saved' => 'Schüler:innengruppe gespeichert',
    'teacher_student_group_deleted' => 'Schüler:innengruppe gelöscht',
    'teacher_student_groups_random_created' => 'Schüler:innengruppen zufällig erstellt',

    'admin_class_created' => 'Klasse angelegt',
    'admin_class_updated' => 'Klasse geändert',
    'admin_subject_created' => 'Fach angelegt',
    'admin_students_import' => 'Schüler:innen importiert',
    'admin_teacher_created' => 'Lehrkraft angelegt',
    'admin_teacher_assignment_added' => 'Lehrkraft-Zuordnung hinzugefügt',

    'admin_criteria_set_created' => 'Kriterienset (Admin) erstellt',
    'admin_criterion_created' => 'Kriterium (Admin) erstellt',
    'admin_criterion_deleted' => 'Kriterium (Admin) gelöscht',
    'admin_criterion_toggled' => 'Kriterium (Admin) aktiviert/deaktiviert',

    'admin_option_created' => 'Picklisten-Option (Admin) erstellt',
    'admin_option_updated' => 'Picklisten-Option (Admin) geändert',
    'admin_options_reordered' => 'Picklisten-Reihenfolge (Admin) geändert',

    'admin_suggestions_imported' => 'Vorschlagskatalog importiert',
    'admin_database_backup_downloaded' => 'Datenbanksicherung heruntergeladen',
  ];
  return $map[$type] ?? $type;
}

function _key_label(string $k): string {
  $map = [
    'actor_id'=>'Akteur-ID',
    'actor_username'=>'Akteur-User',
    'actor_name'=>'Akteur-Name',
    'actor_role'=>'Akteur-Rolle',

    'target_id'=>'Ziel-ID',
    'target_name'=>'Ziel-Name',
    'target_username'=>'Ziel-Username',

    'class_id'=>'Klasse',
    'subject_id'=>'Fach',
    'subject_code'=>'Fachcode',

    'lesson_id'=>'Stunde-ID',
    'lesson_date'=>'Datum',
    'lesson_unit'=>'UE',
    'topic'=>'Thema',

    'count'=>'Anzahl',
    'reason'=>'Grund/Anlass',
    'impact'=>'Eindruck/Relevanz',

    'event_id'=>'Mitarbeit-ID',
    'tags'=>'LBV-Tags',

    'exam_id'=>'Leistungsfeststellung-ID',
    'oral_assessment_id'=>'Mündliche Leistungsfeststellung-ID',
    'oral_type'=>'Art',
    'student_id'=>'Schüler:in',
    'title'=>'Titel',
    'topic_area'=>'Themengebiet',
    'questions'=>'Fragen',
    'category'=>'Kategorie',

    'set_id'=>'Kriterienset-ID',
    'group_id'=>'Gruppen-ID',
    'group_name'=>'Gruppenname',
    'group_prefix'=>'Gruppenpräfix',
    'group_size'=>'Gruppengröße',
    'group_count'=>'Gruppenanzahl',
    'student_ids'=>'Schüler:innen',
    'type'=>'Option-Typ',
    'scope'=>'Gültigkeit',
    'label'=>'Bezeichnung',
    'id'=>'ID',
  ];
  return $map[$k] ?? $k;
}

function _tags_pretty($tags): string {
  $t = (string)$tags;
  $t = preg_replace('/[^a-e]/', '', $t);
  return $t ? implode(', ', str_split($t)) : '—';
}

function _option_type_de(?string $t): string {
  $map = [
    'reason' => 'Grund/Anlass',
    'impact' => 'Eindruck/Relevanz',
    'performance_types' => 'Leistungsart',
    'social_form' => 'Sozialform',
    'phase' => 'Unterrichtsphase',
    'homework_status' => 'Hausübung-Status',
  ];
  return $t && isset($map[$t]) ? $map[$t] : (string)$t;
}

function _scope_de(?string $s): string {
  $map = ['global'=>'global', 'subject'=>'fachbezogen', 'teacher'=>'eigene'];
  return $s && isset($map[$s]) ? $map[$s] : (string)$s;
}

function _pretty_value(string $k, $v, array $classes, array $subjects, array $users, array $students, array $sets, array $criteria): string {
  if ($v === null) return '—';
  if (is_bool($v)) return $v ? 'ja' : 'nein';

  // ID-Auflösung
  if ($k === 'class_id')   { $id=(int)$v; return $classes[$id] ?? ('ID '.$id); }
  if ($k === 'subject_id') { $id=(int)$v; return $subjects[$id] ?? ('ID '.$id); }
  if ($k === 'teacher_id') { $id=(int)$v; return $users[$id] ?? ('ID '.$id); }
  if ($k === 'student_id') { $id=(int)$v; return $students[$id] ?? ('ID '.$id); }
  if ($k === 'set_id')     { $id=(int)$v; return $sets[$id] ?? ('ID '.$id); }

  if ($k === 'type')  return _option_type_de((string)$v);
  if ($k === 'scope') return _scope_de((string)$v);
  if ($k === 'tags')  return _tags_pretty($v);
  if ($k === 'oral_type') return ((string)$v)==='ORAL_EXERCISE' ? 'mündliche Übung' : 'mündliche Prüfung';

  if (is_array($v)) return implode(', ', array_map(fn($x)=>is_scalar($x)?(string)$x:json_encode($x,JSON_UNESCAPED_UNICODE), $v));
  if (is_scalar($v)) return (string)$v;

  return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$eventRetentionDays=(int)app_setting_get('event_retention_days', 30);
if($eventRetentionDays>0){
  try{
    $cutoff=(new DateTimeImmutable('now'))->modify('-'.$eventRetentionDays.' days')->format('Y-m-d H:i:s');
    $cleanup=$pdo->prepare("DELETE FROM events WHERE created_at < ?");
    $cleanup->execute([$cutoff]);
  }catch(Throwable $e){
    // silently ignore to keep the audit view available even if cleanup fails
  }
}

// --- Lookup Maps (IDs -> Namen) ---
$classes = []; $subjects = []; $users = []; $students = []; $sets = []; $criteria = [];
try { foreach($pdo->query("SELECT id, name FROM classes") as $r)  $classes[(int)$r['id']] = $r['name']; } catch(Throwable $e) {}
try { foreach($pdo->query("SELECT id, CONCAT(code,' ',name) AS n FROM subjects") as $r) $subjects[(int)$r['id']] = trim($r['n']); } catch(Throwable $e) {}
try { foreach($pdo->query("SELECT id, CONCAT(last_name,', ',first_name,' (',role,')') AS n FROM users") as $r) $users[(int)$r['id']] = $r['n']; } catch(Throwable $e) {}
try { foreach($pdo->query("SELECT id, CONCAT(last_name,', ',first_name) AS n FROM students") as $r) $students[(int)$r['id']] = $r['n']; } catch(Throwable $e) {}
try { foreach($pdo->query("SELECT id, name FROM criteria_sets") as $r) $sets[(int)$r['id']] = $r['name']; } catch(Throwable $e) {}
try { foreach($pdo->query("SELECT id, label FROM criteria") as $r) $criteria[(int)$r['id']] = $r['label']; } catch(Throwable $e) {}

// --- Filter ---
$type = trim($_GET['type'] ?? '');
$actor = trim($_GET['actor'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$q = trim($_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 300);
if ($limit < 50) $limit = 50;
if ($limit > 1000) $limit = 1000;

$where = [];
$params = [];

if ($type !== '') { $where[] = "e.type=?"; $params[] = $type; }
if ($actor !== '') { $where[] = "e.actor_user_id=?"; $params[] = (int)$actor; }
if ($from !== '') { $where[] = "e.created_at>=?"; $params[] = $from.' 00:00:00'; }
if ($to !== '') { $where[] = "e.created_at<=?"; $params[] = $to.' 23:59:59'; }
if ($q !== '') {
  $where[] = "(e.type LIKE ? OR e.payload_json LIKE ? OR u.username LIKE ?)";
  $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}

$sql = "SELECT e.*, u.username AS actor_username
        FROM events e
        LEFT JOIN users u ON u.id=e.actor_user_id";
if ($where) $sql .= " WHERE ".implode(" AND ", $where);
$sql .= " ORDER BY e.id DESC LIMIT ".$limit;

$types = $pdo->query("SELECT type, COUNT(*) c FROM events GROUP BY type ORDER BY c DESC")->fetchAll();
$actors = $pdo->query("SELECT u.id, u.username, CONCAT(u.last_name,', ',u.first_name) AS n, COUNT(*) c
                       FROM events e JOIN users u ON u.id=e.actor_user_id
                       GROUP BY u.id,u.username,u.first_name,u.last_name
                       ORDER BY c DESC")->fetchAll();

$st = $pdo->prepare($sql);
$st->execute($params);
$events = $st->fetchAll();

render_header('Eventauswertungen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
  <h1>Eventauswertungen</h1>
  <div class="muted">Systemereignisse und Änderungen zur Nachvollziehbarkeit der Administration.</div>

  <form method="get" class="row" style="align-items:end; gap:10px; flex-wrap:wrap">
    <div>
      <label class="muted">Typ</label>
      <select class="input" name="type" onchange="this.form.submit()">
        <option value="">Alle</option>
        <?php foreach($types as $t): $v=$t['type']; ?>
          <option value="<?php echo h($v); ?>" <?php echo $type===$v?'selected':''; ?>>
            <?php echo h(_event_type_de($v)); ?> (<?php echo (int)$t['c']; ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="muted">Akteur</label>
      <select class="input" name="actor" onchange="this.form.submit()">
        <option value="">Alle</option>
        <?php foreach($actors as $a): $id=(int)$a['id']; ?>
          <option value="<?php echo $id; ?>" <?php echo $actor!=='' && (int)$actor===$id?'selected':''; ?>>
            <?php echo h(($a['n'] ?: $a['username']).' ('.$a['c'].')'); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="muted">Von</label>
      <input class="input" type="date" name="from" value="<?php echo h($from); ?>">
    </div>
    <div>
      <label class="muted">Bis</label>
      <input class="input" type="date" name="to" value="<?php echo h($to); ?>">
    </div>

    <div style="min-width:240px; flex:1">
      <label class="muted">Suche</label>
      <input class="input" type="text" name="q" placeholder="Typ, Username, Text im Payload…" value="<?php echo h($q); ?>">
    </div>

    <div>
      <label class="muted">Limit</label>
      <input class="input" type="number" name="limit" min="50" max="1000" value="<?php echo (int)$limit; ?>" style="width:110px">
    </div>

    <div>
      <button class="btn">Filtern</button>
      <a class="btn btn-ghost" href="events.php">Reset</a>
    </div>
  </form>

  <div style="height:10px"></div>

  <table class="table">
    <thead>
      <tr>
        <th style="white-space:nowrap;">Zeit</th>
        <th>Typ</th>
        <th>Akteur</th>
        <th>Übersicht</th>
        <th>Details</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($events as $e):
      $payload = _json_payload($e['payload_json'] ?? '{}');
      $typeDe = _event_type_de($e['type']);
      $actorTxt = $payload['actor_name'] ?? ($e['actor_username'] ?? '');
      if ($actorTxt === '' && !empty($e['actor_user_id'])) $actorTxt = 'User #'.(int)$e['actor_user_id'];

      // Kurzzusammenfassung
      $sum = [];
      if (isset($payload['class_id']))   $sum[] = "Klasse: "._pretty_value('class_id',$payload['class_id'],$classes,$subjects,$users,$students,$sets,$criteria);
      if (isset($payload['subject_id'])) $sum[] = "Fach: "._pretty_value('subject_id',$payload['subject_id'],$classes,$subjects,$users,$students,$sets,$criteria);
      if (isset($payload['lesson_unit'])) $sum[] = "UE: ".$payload['lesson_unit'];
      if (isset($payload['lesson_date'])) $sum[] = "Datum: ".$payload['lesson_date'];
      if (isset($payload['lesson_id'])) $sum[] = "Stunde-ID: ".$payload['lesson_id'];
      if (isset($payload['count'])) $sum[] = "Anzahl: ".$payload['count'];

      if ($e['type']==='participation_recorded') {
        if (!empty($payload['reason'])) $sum[] = "Grund: ".$payload['reason'];
        if (!empty($payload['impact'])) $sum[] = "Eindruck: ".$payload['impact'];
      }
      if ($e['type']==='participation_lbvo_updated') {
        $sum[] = "Tags: "._tags_pretty($payload['tags'] ?? '');
      }
      if ($e['type']==='exam_created' || $e['type']==='exam_updated') {
        if (!empty($payload['title'])) $sum[] = "Titel: ".$payload['title'];
      }
      if (str_starts_with($e['type'],'oral_assessment_')) {
        if (!empty($payload['student_id'])) $sum[] = "Schüler:in: "._pretty_value('student_id',$payload['student_id'],$classes,$subjects,$users,$students,$sets,$criteria);
        if (!empty($payload['oral_type'])) $sum[] = "Art: "._pretty_value('oral_type',$payload['oral_type'],$classes,$subjects,$users,$students,$sets,$criteria);
        if (!empty($payload['impact'])) $sum[] = "Eindruck: ".$payload['impact'];
        if (!empty($payload['topic_area'])) $sum[] = "Themengebiet: ".$payload['topic_area'];
        if (!empty($payload['category'])) $sum[] = "Kategorie: ".$payload['category'];
        if (!empty($payload['title'])) $sum[] = "Titel: ".$payload['title'];
      }
      if (str_starts_with($e['type'],'admin_option_') || str_starts_with($e['type'],'teacher_option_')) {
        if (isset($payload['type']))  $sum[] = "Typ: "._option_type_de($payload['type']);
        if (isset($payload['scope'])) $sum[] = "Scope: "._scope_de($payload['scope']);
        if (!empty($payload['label'])) $sum[] = "Label: ".$payload['label'];
      }

      $summary = $sum ? implode(' • ', $sum) : '—';
    ?>
      <tr>
        <td style="white-space:nowrap;"><?php echo h($e['created_at']); ?></td>
        <td>
          <span class="badge"><?php echo h($typeDe); ?></span><br>
          <span class="muted" style="font-size:12px;"><?php echo h($e['type']); ?></span>
        </td>
        <td><?php echo h($actorTxt); ?></td>
        <td><?php echo h($summary); ?></td>
        <td style="min-width:280px;">
          <details>
            <summary>Payload anzeigen</summary>
            <div style="margin-top:8px;">
              <table class="table" style="font-size:13px;">
                <tbody>
                  <?php foreach($payload as $k=>$v): ?>
                    <tr>
                      <td class="muted" style="width:38%;"><?php echo h(_key_label((string)$k)); ?></td>
                      <td><?php echo h(_pretty_value((string)$k,$v,$classes,$subjects,$users,$students,$sets,$criteria)); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <details style="margin-top:8px;">
                <summary>Raw JSON</summary>
                <pre class="muted" style="white-space:pre-wrap;font-family:ui-monospace,Menlo,Monaco,Consolas,monospace;font-size:12px;"><?php
                  echo h(json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                ?></pre>
              </details>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="height:12px"></div>
  <a class="btn secondary" href="<?php echo h(cfg()['base_path'] ?? ''); ?>/admin/settings_index.php">Zurück</a>
</div></div></div>
<?php render_footer(); ?>
