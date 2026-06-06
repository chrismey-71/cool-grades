<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/participation_presets.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

function fmt_preset_timestamp(?string $value): string {
  if(!$value) return '–';
  $ts=strtotime($value);
  if($ts===false) return (string)$value;
  return date('d.m.Y H:i',$ts);
}

$msg='';
$err='';
$notice=(string)($_GET['msg'] ?? '');
if($notice==='saved') $msg='Preset gespeichert.';
if($notice==='deleted') $msg='Preset gelöscht.';

$presets=load_participation_presets($pdo,(int)$u['id']);
$selected_preset_id=(int)($_GET['preset'] ?? $_POST['preset_id'] ?? ($presets ? (int)$presets[0]['id'] : 0));
$current=$selected_preset_id ? find_participation_preset($pdo,(int)$u['id'],$selected_preset_id) : null;
if(!$current && $presets){
  $selected_preset_id=(int)$presets[0]['id'];
  $current=find_participation_preset($pdo,(int)$u['id'],$selected_preset_id);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=(string)($_POST['action'] ?? '');
  $selected_preset_id=(int)($_POST['preset_id'] ?? 0);
  $current=$selected_preset_id ? find_participation_preset($pdo,(int)$u['id'],$selected_preset_id) : null;

  if(!$current){
    $err='Preset nicht gefunden.';
  } elseif($action==='save'){
    $name=participation_preset_name((string)($_POST['name'] ?? ''));
    if($name===''){
      $err='Bitte einen Namen für das Preset eingeben.';
    } else {
      $payload=participation_preset_payload_from_request($_POST);
      try{
        save_participation_preset(
          $pdo,
          (int)$u['id'],
          (int)$current['subject_id'],
          $name,
          $payload,
          (int)$current['id']
        );
        emit_event('teacher_preset_updated',[
          'preset_id'=>(int)$current['id'],
          'preset_name'=>$name,
          'subject_id'=>(int)$current['subject_id'],
        ]);
        header('Location: '.$bp.'/teacher/presets.php?preset='.(int)$current['id'].'&msg=saved');
        exit;
      }catch(PDOException $e){
        if((string)$e->getCode()==='23000'){
          $err='Für dieses Fach existiert bereits ein Preset mit diesem Namen.';
        } else {
          throw $e;
        }
      }
    }
  } elseif($action==='delete'){
    if(delete_participation_preset($pdo,(int)$u['id'],(int)$current['id'])){
      emit_event('teacher_preset_deleted',[
        'preset_id'=>(int)$current['id'],
        'preset_name'=>(string)$current['name'],
        'subject_id'=>(int)$current['subject_id'],
      ]);
      header('Location: '.$bp.'/teacher/presets.php?msg=deleted');
      exit;
    }
    $err='Preset konnte nicht gelöscht werden.';
  }

  $presets=load_participation_presets($pdo,(int)$u['id']);
  $current=$selected_preset_id ? find_participation_preset($pdo,(int)$u['id'],$selected_preset_id) : null;
}

$payload=[];
$form_name='';
$reasons=[];
$impacts=[];
$perfs=[];
$socials=[];
$phases=[];
$homeworks=[];
$criteria=[];
$checked_perf=[];
$checked_criteria=[];

if($current){
  $payload=$current['payload'] ?? [];
  $form_name=(string)$current['name'];

  if($_SERVER['REQUEST_METHOD']==='POST' && (int)($_POST['preset_id'] ?? 0)===(int)$current['id'] && $err!==''){
    $payload=participation_preset_payload_from_request($_POST);
    $form_name=trim((string)($_POST['name'] ?? $form_name));
  }

  $subject_id=(int)$current['subject_id'];
  $teacher_id=(int)$u['id'];
  $reasons=load_participation_options($pdo,$teacher_id,$subject_id,'reason');
  $impacts=load_participation_options($pdo,$teacher_id,$subject_id,'impact');
  $perfs=load_participation_options($pdo,$teacher_id,$subject_id,'performance');
  $socials=load_participation_options($pdo,$teacher_id,$subject_id,'social_form');
  $phases=load_participation_options($pdo,$teacher_id,$subject_id,'phase');
  $homeworks=load_participation_options($pdo,$teacher_id,$subject_id,'homework');
  $criteria=load_participation_criteria($pdo,$teacher_id,$subject_id);
  $checked_perf=array_map('intval',(array)($payload['performance_option_ids'] ?? []));
  $checked_criteria=array_map('intval',(array)($payload['criteria_ids'] ?? []));
}

$preset_form_dirty_initial = ($_SERVER['REQUEST_METHOD']==='POST' && $current && $err!=='');

render_header('Preset-Verwaltung',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Mitarbeit-Presets verwalten</h1>
      <p class="muted">Hier kannst du alle eigenen Presets bearbeiten oder löschen. Presets gelten pro Fach, nicht pro Klasse. Neue Presets legst du weiterhin direkt in der Mitarbeit-Erfassung an.</p>

      <?php if($msg): ?><div class="flash success"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($err): ?><div class="flash error"><?php echo h($err); ?></div><?php endif; ?>

      <?php if(!$presets): ?>
        <div class="flash info">Noch keine Presets vorhanden. Lege ein Preset zuerst in der Mitarbeit-Erfassung an.</div>
        <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück zum Lehrerbereich</a>
      <?php else: ?>
        <h2>Alle Presets</h2>
        <table class="table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Fach</th>
              <th>Aktualisiert</th>
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($presets as $preset): ?>
              <tr>
                <td data-label="Name"><b><?php echo h($preset['name']); ?></b></td>
                <td data-label="Fach"><?php echo h($preset['subject_code'].' - '.$preset['subject_name']); ?></td>
                <td data-label="Aktualisiert"><?php echo h(fmt_preset_timestamp((string)($preset['updated_at'] ?? ''))); ?></td>
                <td data-label="Aktion" class="actions">
                  <a class="btn small <?php echo $selected_preset_id===(int)$preset['id']?'':'secondary'; ?>" href="<?php echo h($bp); ?>/teacher/presets.php?preset=<?php echo (int)$preset['id']; ?>">Bearbeiten</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if($current): ?>
          <div style="height:14px"></div>
          <h2>Preset bearbeiten</h2>
          <div class="muted">Fach: <b><?php echo h($current['subject_code'].' - '.$current['subject_name']); ?></b> · Zuletzt aktualisiert: <b><?php echo h(fmt_preset_timestamp((string)($current['updated_at'] ?? ''))); ?></b></div>

          <form method="post" style="margin-top:10px" <?php echo dirty_form_attrs($preset_form_dirty_initial); ?>>
            <?php echo csrf_input(); ?>
            <input type="hidden" name="preset_id" value="<?php echo (int)$current['id']; ?>">

            <label class="muted">Preset-Name</label>
            <input class="input" name="name" maxlength="120" required value="<?php echo h($form_name); ?>" placeholder="z.B. Reflexion mit Begriffen">

            <div style="height:12px"></div>
            <div class="row">
              <div>
                <label class="muted">Grund/Anlass</label>
                <select class="input" name="reason_option_id" required>
                  <option value="0">Bitte wählen…</option>
                  <?php foreach($reasons as $o): ?>
                    <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)($payload['reason_option_id'] ?? 0)===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="muted">Eindruck/Relevanz</label>
                <select class="input" name="impact_option_id" required>
                  <option value="0">Bitte wählen…</option>
                  <?php foreach($impacts as $o): ?>
                    <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)($payload['impact_option_id'] ?? 0)===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div style="height:12px"></div>
            <fieldset class="multi-field">
              <legend>Leistungsart (Mehrfach)</legend>
              <div class="multi-grid">
                <?php foreach($perfs as $o): ?>
                  <label class="multi-item">
                    <input type="checkbox" name="performance_option_ids[]" value="<?php echo (int)$o['id']; ?>" <?php echo in_array((int)$o['id'],$checked_perf,true)?'checked':''; ?>>
                    <span><?php echo h($o['label']); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>

            <div style="height:12px"></div>
            <div class="row">
              <div>
                <label class="muted">Sozialform</label>
                <select class="input" name="social_form_option_id">
                  <option value="0">–</option>
                  <?php foreach($socials as $o): ?>
                    <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)($payload['social_form_option_id'] ?? 0)===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="muted">Unterrichtsphase</label>
                <select class="input" name="phase_option_id">
                  <option value="0">–</option>
                  <?php foreach($phases as $o): ?>
                    <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)($payload['phase_option_id'] ?? 0)===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="muted">Hausübung-Status</label>
                <select class="input" name="homework_option_id">
                  <option value="0">–</option>
                  <?php foreach($homeworks as $o): ?>
                    <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)($payload['homework_option_id'] ?? 0)===(int)$o['id'])?'selected':''; ?>><?php echo h($o['label']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div style="height:12px"></div>
            <label class="muted">Kurzbeschreibung (optional)</label>
            <input class="input" name="reason_text" value="<?php echo h((string)($payload['reason_text'] ?? '')); ?>" placeholder="z.B. Falllösung sauber erklärt.">

            <div style="height:12px"></div>
            <label class="muted">Notiz (optional)</label>
            <textarea class="input" name="note" rows="3" placeholder="1–2 Sätze als Beleg/Beobachtung."><?php echo h((string)($payload['note'] ?? '')); ?></textarea>

            <div style="height:14px"></div>
            <h2>Kriterien</h2>
            <fieldset class="multi-field">
              <legend>Kategorien</legend>
              <?php if(!$criteria): ?>
                <div class="muted">Für dieses Fach sind derzeit keine aktiven Kriterien vorhanden.</div>
              <?php else: ?>
                <?php
                  $criteria_by_cat=[];
                  foreach($criteria as $c){
                    $cat=trim((string)($c['category'] ?? ''));
                    if($cat==='') $cat='Allgemein';
                    if(!isset($criteria_by_cat[$cat])) $criteria_by_cat[$cat]=[];
                    $criteria_by_cat[$cat][]=$c;
                  }
                ?>
                <div class="criteria-accordion">
                  <?php foreach($criteria_by_cat as $cat=>$list): ?>
                    <?php
                      $sel=0;
                      foreach($list as $cc){ if(in_array((int)$cc['id'],$checked_criteria,true)) $sel++; }
                    ?>
                    <details class="accordion" <?php echo $sel>0?'open':''; ?>>
                      <summary>
                        <span class="acc-title"><?php echo h($cat); ?></span>
                        <span class="acc-meta"><span class="badge"><?php echo (int)$sel; ?>/<?php echo (int)count($list); ?></span></span>
                      </summary>
                      <div class="acc-body">
                        <div class="multi-grid">
                          <?php foreach($list as $c): ?>
                            <label class="multi-item">
                              <input type="checkbox" name="criteria_ids[]" value="<?php echo (int)$c['id']; ?>" <?php echo in_array((int)$c['id'],$checked_criteria,true)?'checked':''; ?>>
                              <span><?php echo h($c['label']); ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </details>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </fieldset>

            <div style="height:14px"></div>
            <div class="row" style="gap:10px;flex-wrap:wrap">
              <button class="btn" name="action" value="save">Änderungen speichern</button>
              <button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('Dieses Preset wirklich löschen?');">Preset löschen</button>
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück zum Lehrerbereich</a>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php render_footer(); ?>
