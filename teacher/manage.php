<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/assessment_weights.php';
require_once __DIR__.'/../lib/schools.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

$selectedSchoolId=teacher_school_context_id($pdo,(int)$u['id']);
$schoolYears=load_school_years($pdo,true,$selectedSchoolId,$selectedSchoolId<=0);
$schoolYearId=(int)($_REQUEST['school_period_set_id'] ?? school_year_current_id($pdo,$selectedSchoolId));
$classId=(int)($_REQUEST['class_id'] ?? 0);
$subjectId=(int)($_REQUEST['subject_id'] ?? 0);
$assignmentRequest=(string)($_REQUEST['assignment'] ?? '');
if(preg_match('/^(\d+):(\d+)$/',$assignmentRequest,$assignmentParts)){
  $classId=(int)$assignmentParts[1];
  $subjectId=(int)$assignmentParts[2];
}
$weightMsg='';
$weightError='';
$weightFieldErrors=[];
$selectedSchoolYear=null;
foreach($schoolYears as $schoolYear){
  if((int)$schoolYear['id']===$schoolYearId){ $selectedSchoolYear=$schoolYear; break; }
}

$assignmentSql="SELECT c.id AS class_id,c.name AS class_name,c.assessment_system,c.is_archived,c.is_departed,
                       s.id AS subject_id,s.code AS subject_code,s.name AS subject_name,s.is_schularbeit_subject,
                       sp.label AS school_year_label
	                FROM teacher_assignments ta
	                JOIN classes c ON c.id=ta.class_id
	                JOIN school_forms sf ON sf.id=c.school_form_id
	                JOIN subjects s ON s.id=ta.subject_id
	                LEFT JOIN school_period_sets sp ON sp.id=c.school_period_set_id
	                WHERE ta.teacher_id=? AND c.school_period_set_id=? AND c.is_departed=0 AND ta.status='active'
	                ".($selectedSchoolId>0 ? "AND sf.school_id=? " : '')."
	                ORDER BY c.name,s.code,s.name";
$assignmentParams=[(int)$u['id'],$schoolYearId];
if($selectedSchoolId>0) $assignmentParams[]=$selectedSchoolId;
$st=$pdo->prepare($assignmentSql);
$st->execute($assignmentParams);
$weightAssignments=$st->fetchAll();

if(($classId<=0 || $subjectId<=0) && $weightAssignments){
  $classId=(int)$weightAssignments[0]['class_id'];
  $subjectId=(int)$weightAssignments[0]['subject_id'];
}
$selectedAssignment=null;
foreach($weightAssignments as $assignment){
  if((int)$assignment['class_id']===$classId && (int)$assignment['subject_id']===$subjectId){
    $selectedAssignment=$assignment;
    break;
  }
}
if(!$selectedAssignment && $_SERVER['REQUEST_METHOD']!=='POST' && $weightAssignments){
  $selectedAssignment=$weightAssignments[0];
  $classId=(int)$selectedAssignment['class_id'];
  $subjectId=(int)$selectedAssignment['subject_id'];
}
$weightContextReadonly = $selectedAssignment && (
  (int)($selectedAssignment['is_archived'] ?? 0) === 1
  || (int)($selectedSchoolYear['archived'] ?? 0) === 1
);

if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action'] ?? '')==='save_assessment_weights'){
  verify_csrf();
  if(!$selectedAssignment){
    $weightError='Die gewählte Klasse-Fach-Zuordnung ist für dieses Schuljahr nicht verfügbar.';
  } else {
    require_teacher_active_assignment($u,$classId,$subjectId);
    $assessmentModel=(string)$selectedAssignment['assessment_system'];
    if($weightContextReadonly){
      $weightError='Gewichtungen archivierter Klassen oder Schuljahre können nicht verändert werden.';
    } elseif(!class_assessment_system_is_valid($assessmentModel)){
      $weightError='Für diese Klasse ist kein gültiges Beurteilungsmodell hinterlegt. Bitte lassen Sie die Klasseneinstellung prüfen.';
    } else {
      $isYearly=$assessmentModel==='yearly';
      $validated=assessment_weight_settings_validate($_POST,$isYearly);
      $weightFieldErrors=$validated['errors'];
      if($weightFieldErrors){
        $weightError='Bitte korrigieren Sie die Gewichtungen.';
      } else {
        assessment_weight_settings_store(
          $pdo,(int)$u['id'],$classId,$subjectId,$schoolYearId,$assessmentModel,$validated['values']
        );
        header('Location: '.$bp.'/teacher/manage.php?'.http_build_query([
          'school_period_set_id'=>$schoolYearId,
          'class_id'=>$classId,
          'subject_id'=>$subjectId,
          'open_weights'=>1,
          'msg'=>'weights_saved',
        ]));
        exit;
      }
    }
  }
}
if((string)($_GET['msg'] ?? '')==='weights_saved') $weightMsg='Orientierungsgewichtung für Notenvorschläge gespeichert.';

$assessmentModel=$selectedAssignment ? (string)$selectedAssignment['assessment_system'] : null;
$subjectContext=$selectedAssignment
  ? report_eval_subject_context_from_row([
      'id' => (int)$selectedAssignment['subject_id'],
      'code' => (string)$selectedAssignment['subject_code'],
      'name' => (string)$selectedAssignment['subject_name'],
      'is_schularbeit_subject' => $selectedAssignment['is_schularbeit_subject'] ?? null,
    ])
  : [];
$weightSettings=$selectedAssignment
  ? assessment_weight_settings_load($pdo,(int)$u['id'],$classId,$subjectId,$schoolYearId,$assessmentModel,$subjectContext)
  : assessment_weight_settings_resolve(null,null,[]);
$weightValues=$weightSettings['configured'];
$weightPresets=assessment_weight_preset_definitions((string)($subjectContext['status'] ?? ''));
$weightActivity=$selectedAssignment && $selectedSchoolYear
  ? assessment_weight_settings_activity($pdo,(int)$u['id'],$classId,$subjectId,$selectedSchoolYear)
  : ['participation_count'=>0,'oral_count'=>0,'written_count'=>0,'written_type_counts'=>[]];
$weightPlausibility=assessment_weight_plausibility_warnings($weightSettings,$subjectContext,$weightActivity);
if($_SERVER['REQUEST_METHOD']==='POST' && $weightError!==''){
  $postMap=[
    'participation'=>'participation_weight',
    'oral'=>'special_oral_weight',
    'written'=>'special_written_weight',
    'first_semester'=>'first_semester_to_annual_weight',
    'current_year'=>'current_year_to_annual_weight',
  ];
  foreach($postMap as $key=>$field){
    if(isset($_POST[$field]) && is_numeric(str_replace(',','.',(string)$_POST[$field]))){
      $weightValues[$key]=(float)str_replace(',','.',(string)$_POST[$field]);
    }
  }
}
$displayWeight=static fn($value): string => number_format((float)$value,abs((float)$value-round((float)$value))<0.001?0:1,'.','');
$weightAccordionOpen = $weightMsg !== '' || $weightError !== '' || !empty($_GET['open_weights']) || $_SERVER['REQUEST_METHOD']==='POST';

render_header('Verwaltung',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Verwaltung</h1>
      <p class="muted">Hier verwalten Sie Ihre Kriterien, Picklisten, Presets, Gruppen und die Berechnungshilfe für Notenvorschläge getrennt von der eigentlichen Eingabe.</p>

      <details class="accordion assessment-weight-accordion" style="margin-top:14px" <?php echo $weightAccordionOpen?'open':''; ?>>
        <summary>
          <span class="assessment-weight-summary-copy">
            <span class="acc-title">Orientierungsgewichtung für Notenvorschläge</span>
            <span class="small muted">Optionale Berechnungshilfe je Klasse und Fach</span>
          </span>
          <span class="assessment-weight-summary-action">Einstellungen</span>
        </summary>
        <div class="acc-body assessment-weight-panel">
          <div class="assessment-weight-lead">
            <div>
              <strong>Orientierung für den Notenvorschlag</strong>
              <div class="muted">Wählen Sie zuerst den Unterrichtskontext. Danach passen Sie die drei Leistungsbereiche an.</div>
            </div>
            <span class="report-chip neutral">unverbindlich</span>
          </div>

          <details class="accordion assessment-weight-legal" style="margin-top:12px">
            <summary><span class="acc-title">Rechtlicher Hinweis zur Orientierung</span></summary>
            <div class="acc-body">
              <div class="muted">
                Die LBVO schreibt keine festen Prozentwerte für die einzelnen Leistungsbereiche vor. Die hier gewählte Gewichtung dient ausschließlich als Orientierung für den Notenvorschlag. Anzahl, Umfang und Schwierigkeitsgrad der Leistungsfeststellungen sind zusätzlich zu berücksichtigen.
              </div>
              <div class="small" style="margin-top:7px"><a href="https://www.ris.bka.gv.at/GeltendeFassung.wxe?Abfrage=Bundesnormen&Gesetzesnummer=10009375" target="_blank" rel="noopener">Aktuelle LBVO im RIS öffnen</a></div>
            </div>
          </details>

        <?php if($weightMsg): ?><div class="flash success" style="margin-top:12px"><?php echo h($weightMsg); ?></div><?php endif; ?>
        <?php if($weightError): ?><div class="flash error" style="margin-top:12px"><?php echo h($weightError); ?></div><?php endif; ?>

        <section class="assessment-weight-step">
          <div class="assessment-weight-step-head">
            <span>1</span>
            <div><strong>Unterrichtskontext wählen</strong><small>Diese Einstellung gilt nur für die gewählte Klasse-Fach-Kombination.</small></div>
          </div>
          <form method="get" class="assessment-weight-context">
            <input type="hidden" name="open_weights" value="1">
            <div>
              <label class="muted">Schuljahr</label>
              <select class="input" name="school_period_set_id" onchange="this.form.submit()">
                <?php foreach($schoolYears as $schoolYear): ?>
                  <option value="<?php echo (int)$schoolYear['id']; ?>" <?php echo $schoolYearId===(int)$schoolYear['id']?'selected':''; ?>><?php echo h((string)$schoolYear['label'].(((int)$schoolYear['is_current']===1)?' · aktuell':'').(((int)$schoolYear['archived']===1)?' · Archiv':'')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="muted">Klasse und Fach</label>
              <select class="input" name="assignment" onchange="var p=this.value.split(':');this.form.class_id.value=p[0]||0;this.form.subject_id.value=p[1]||0;this.form.submit()">
                <?php if(!$weightAssignments): ?><option value="">Keine Zuordnung vorhanden</option><?php endif; ?>
                <?php foreach($weightAssignments as $assignment): ?>
                  <?php $assignmentValue=(int)$assignment['class_id'].':'.(int)$assignment['subject_id']; ?>
                  <option value="<?php echo h($assignmentValue); ?>" <?php echo ((int)$assignment['class_id']===$classId && (int)$assignment['subject_id']===$subjectId)?'selected':''; ?>><?php echo h((string)$assignment['class_name'].' · '.(string)$assignment['subject_code'].' – '.(string)$assignment['subject_name']); ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="class_id" value="<?php echo (int)$classId; ?>">
              <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
            </div>
            <div class="assessment-weight-context-action"><button class="btn secondary">Auswahl laden</button></div>
          </form>
        </section>

        <?php if($selectedAssignment): ?>
          <div class="assessment-weight-model-row" style="margin-top:12px">
            <span><strong>Klasse:</strong> <?php echo h((string)$selectedAssignment['class_name']); ?></span>
            <span><strong>Fach:</strong> <?php echo h((string)$selectedAssignment['subject_code']); ?></span>
            <span><strong>Schularbeitsfach:</strong> <?php echo h((string)($subjectContext['status_label'] ?? 'Nicht festgelegt')); ?></span>
            <span><strong>Beurteilungsmodell:</strong> <?php echo h(class_assessment_system_label($assessmentModel)); ?></span>
            <span><strong>Einstellung:</strong> <?php echo $weightSettings['source']==='saved'?'gespeichert':'Standardwerte'; ?></span>
          </div>

          <form method="post" class="assessment-weight-form" style="margin-top:12px" <?php echo dirty_form_attrs($_SERVER['REQUEST_METHOD']==='POST' && $weightError!==''); ?>>
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save_assessment_weights">
            <input type="hidden" name="school_period_set_id" value="<?php echo (int)$schoolYearId; ?>">
            <input type="hidden" name="class_id" value="<?php echo (int)$classId; ?>">
            <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">

            <div class="assessment-weight-step-head">
              <span>2</span>
              <div><strong>Prozentwerte festlegen</strong><small>Die Summe der drei Leistungsbereiche muss 100 % ergeben.</small></div>
            </div>

            <div class="assessment-weight-preset-row">
              <div>
                <label class="muted">Vorlage auswählen</label>
                <select class="input" id="assessmentWeightPreset" onchange="applyAssessmentWeightPreset(this)">
                  <option value="">Individuell / bestehende Werte beibehalten</option>
                  <?php foreach($weightPresets as $presetKey => $preset): ?>
                    <option value="<?php echo h($presetKey); ?>"
                            data-participation="<?php echo h($displayWeight($preset['weights']['participation'])); ?>"
                            data-oral="<?php echo h($displayWeight($preset['weights']['oral'])); ?>"
                            data-written="<?php echo h($displayWeight($preset['weights']['written'])); ?>"
                            data-description="<?php echo h((string)$preset['description']); ?>">
                      <?php echo h((string)$preset['label']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="small muted" id="assessmentWeightPresetHint" style="margin-top:6px">
                  Presets ändern nur die Eingabefelder. Gespeichert wird erst mit „Orientierungsgewichtung speichern“.
                </div>
              </div>
              <div class="assessment-weight-sum-display">
                <span class="muted">Summe</span>
                <strong id="assessmentWeightSum">100 %</strong>
              </div>
            </div>

            <div class="assessment-weight-grid">
              <div class="assessment-weight-field participation">
                <label for="participationWeight">Mitarbeit</label>
                <div class="assessment-weight-input"><input class="input" id="participationWeight" type="number" min="0" max="100" step="1" name="participation_weight" value="<?php echo h($displayWeight($weightValues['participation'])); ?>" required><span>%</span></div>
                <div class="small muted">Bereichswert aus Eindruck/Relevanz, nicht aus Einzelnoten.</div>
                <?php if(!empty($weightFieldErrors['participation_weight'])): ?><div class="field-error"><?php echo h($weightFieldErrors['participation_weight']); ?></div><?php endif; ?>
              </div>
              <div class="assessment-weight-field oral">
                <label for="oralWeight">Bes. mündl. Leistungsfeststellung</label>
                <div class="assessment-weight-input"><input class="input" id="oralWeight" type="number" min="0" max="100" step="1" name="special_oral_weight" value="<?php echo h($displayWeight($weightValues['oral'])); ?>" required><span>%</span></div>
                <div class="small muted">Bereichswert aus Eindruck/Relevanz.</div>
                <?php if(!empty($weightFieldErrors['special_oral_weight'])): ?><div class="field-error"><?php echo h($weightFieldErrors['special_oral_weight']); ?></div><?php endif; ?>
              </div>
              <div class="assessment-weight-field written">
                <label for="writtenWeight">Bes. schriftl. Leistungsfeststellung</label>
                <div class="assessment-weight-input"><input class="input" id="writtenWeight" type="number" min="0" max="100" step="1" name="special_written_weight" value="<?php echo h($displayWeight($weightValues['written'])); ?>" required><span>%</span></div>
                <div class="small muted">Bereichswert aus Noten; optionale Einzelgewichte werden berücksichtigt.</div>
                <?php if(!empty($weightFieldErrors['special_written_weight'])): ?><div class="field-error"><?php echo h($weightFieldErrors['special_written_weight']); ?></div><?php endif; ?>
              </div>
            </div>
            <?php if(!empty($weightFieldErrors['area_sum'])): ?><div class="field-error assessment-weight-sum-error"><?php echo h($weightFieldErrors['area_sum']); ?></div><?php endif; ?>
            <div class="small muted" style="margin-top:8px">Die Standardwerte und Vorlagen sind pädagogische Orientierungsvorschläge und keine gesetzliche Vorgabe.</div>

            <section class="assessment-weight-support">
              <div class="assessment-weight-step-head compact">
                <span>3</span>
                <div><strong>Orientierung prüfen</strong><small>Diese Zahlen verändern die Gewichtung nicht automatisch.</small></div>
              </div>
              <div class="assessment-weight-existing">
                <div class="assessment-weight-existing-grid">
                  <span>Mitarbeit <strong><?php echo (int)($weightActivity['participation_count'] ?? 0); ?></strong></span>
                  <span>Bes. mündl. <strong><?php echo (int)($weightActivity['oral_count'] ?? 0); ?></strong></span>
                  <span>Schularbeiten <strong><?php echo (int)($weightActivity['written_type_counts']['SA'] ?? 0); ?></strong></span>
                  <span>Tests <strong><?php echo (int)($weightActivity['written_type_counts']['TEST'] ?? 0); ?></strong></span>
                  <span>Diktate <strong><?php echo (int)($weightActivity['written_type_counts']['DICTATION'] ?? 0); ?></strong></span>
                  <span>Schriftlich gesamt <strong><?php echo (int)($weightActivity['written_count'] ?? 0); ?></strong></span>
                </div>
              </div>

              <?php if($weightPlausibility): ?>
                <div class="assessment-weight-hints">
                  <?php foreach($weightPlausibility as $hint): ?>
                    <div class="flash info assessment-weight-hint"><?php echo h($hint); ?></div>
                  <?php endforeach; ?>
                  <div class="assessment-weight-hint-actions">
                    <button class="btn small secondary" type="button" onclick="this.closest('.assessment-weight-hints').style.display='none'">Gewichtung beibehalten</button>
                    <a class="btn small secondary" href="#writtenWeight">Gewichtung bearbeiten</a>
                  </div>
                </div>
              <?php endif; ?>
            </section>

            <?php if($assessmentModel==='yearly'): ?>
              <div class="assessment-year-weighting" style="margin-top:14px">
                <h3 style="margin:0 0 8px">Zusätzliche Gewichtung für den Jahresvorschlag</h3>
                <div class="assessment-year-weight-grid">
                  <div>
                    <label for="firstSemesterWeight">Schulnachricht / 1. Semester</label>
                    <div class="assessment-weight-input"><input class="input" id="firstSemesterWeight" type="number" min="0" max="100" step="1" name="first_semester_to_annual_weight" value="<?php echo h($displayWeight($weightValues['first_semester'])); ?>" required><span>%</span></div>
                  </div>
                  <div>
                    <label for="currentYearWeight">Restliches Schuljahr / aktueller Leistungsstand</label>
                    <div class="assessment-weight-input"><input class="input" id="currentYearWeight" type="number" min="0" max="100" step="1" name="current_year_to_annual_weight" value="<?php echo h($displayWeight($weightValues['current_year'])); ?>" required><span>%</span></div>
                  </div>
                </div>
                <?php if(!empty($weightFieldErrors['year_sum'])): ?><div class="field-error assessment-weight-sum-error"><?php echo h($weightFieldErrors['year_sum']); ?></div><?php endif; ?>
                <div class="small muted" style="margin-top:8px">Diese Gewichtung wird ausschließlich für den Jahresvorschlag im Jahresmodell verwendet. Sie verändert keine Schulnachricht und keine finale Jahresbeurteilung.</div>
              </div>
            <?php else: ?>
              <div class="assessment-semester-model-note" style="margin-top:14px">
                <strong>Semesterbezogenes Modell:</strong> Bei <?php echo h(strtoupper((string)$assessmentModel)); ?> werden Winter- und Sommersemester nicht zu einer Jahresnote verrechnet. Deshalb werden hier keine Jahresgewichtungen angeboten.
              </div>
            <?php endif; ?>

            <div class="assessment-weight-technical" style="margin-top:12px">
              Fehlende Bereiche werden nicht mit 0 bewertet. Die App passt die Gewichtung automatisch auf die vorhandenen verwertbaren Bereiche an. Ist die Datenlage rechtlich oder fachlich nicht ausreichend, wird ein Hinweis angezeigt.
            </div>
            <?php if($weightContextReadonly): ?>
              <div class="flash info" style="margin-top:12px">Archivierte Klasse bzw. archiviertes Schuljahr: Die gespeicherte Gewichtung bleibt sichtbar, kann hier aber nicht verändert werden.</div>
            <?php elseif(!class_assessment_system_is_valid($assessmentModel)): ?>
              <div class="flash" style="margin-top:12px">Bitte lassen Sie zuerst ein gültiges Beurteilungsmodell für diese Klasse hinterlegen.</div>
            <?php else: ?>
              <div style="margin-top:12px"><button class="btn">Orientierungsgewichtung speichern</button></div>
            <?php endif; ?>
          </form>
        <?php else: ?>
          <div class="flash" style="margin-top:12px">Für das gewählte Schuljahr ist keine Klasse-Fach-Zuordnung vorhanden.</div>
        <?php endif; ?>
        </div>
      </details>

      <div class="grid" style="margin-top:14px">
        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Mitarbeit-Kriterien</h2>
            <div class="muted" style="font-size:13px">Kriteriensets und einzelne Kriterien für deine Dokumentation pflegen.</div>
            <div style="height:10px"></div>
            <a class="btn" href="<?php echo h($bp); ?>/teacher/criteria.php">Mitarbeit-Kriterien verwalten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Bezeichnungen / Picklisten</h2>
            <div class="muted" style="font-size:13px">Eigene Optionen für Eindruck, Leistungsart, Sozialform und weitere Picklisten verwalten.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/options.php">Bezeichnungen / Picklisten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Mitarbeit-Presets</h2>
            <div class="muted" style="font-size:13px">Vorlagen für häufige Unterrichtssituationen je Fach erstellen, ändern und löschen.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/presets.php">Mitarbeit-Presets verwalten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Schüler:innengruppen</h2>
            <div class="muted" style="font-size:13px">Gruppen pro Klasse und Fach bilden, zufällig verteilen, bearbeiten und später für die Mitarbeitsauswahl verwenden.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/student_groups.php">Gruppen verwalten</a>
          </div>
        </div>

        <div class="col-12 col-md-3">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Datensicherung</h2>
            <div class="muted" style="font-size:13px">Eigene zugewiesene Klassen, Fächer, Schuljahre und Leistungsdaten als gepackte Datei sichern.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/backup.php">Datensicherung öffnen</a>
          </div>
        </div>
      </div>

      <div style="height:12px"></div>
      <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/index.php">Zurück zum Dashboard</a>
    </div>
  </div>
</div>
<script>
function applyAssessmentWeightPreset(select){
  var option=select && select.options ? select.options[select.selectedIndex] : null;
  var hint=document.getElementById('assessmentWeightPresetHint');
  if(!option || !option.value){
    if(hint) hint.textContent='Presets ändern nur die Eingabefelder. Gespeichert wird erst mit „Orientierungsgewichtung speichern“.';
    updateAssessmentWeightSum();
    return;
  }
  [
    ['participationWeight','participation'],
    ['oralWeight','oral'],
    ['writtenWeight','written']
  ].forEach(function(pair){
    var input=document.getElementById(pair[0]);
    var value=option.getAttribute('data-'+pair[1]);
    if(input && value !== null) input.value=value;
  });
  if(hint) hint.textContent=option.getAttribute('data-description') || 'Vorlage übernommen. Bitte prüfen und speichern.';
  updateAssessmentWeightSum();
}
function updateAssessmentWeightSum(){
  var sum=0;
  ['participationWeight','oralWeight','writtenWeight'].forEach(function(id){
    var input=document.getElementById(id);
    var value=input ? parseFloat(String(input.value || '0').replace(',','.')) : 0;
    if(!isNaN(value)) sum+=value;
  });
  var el=document.getElementById('assessmentWeightSum');
  if(el){
    el.textContent=(Math.round(sum*10)/10).toLocaleString('de-DE')+' %';
    el.className=Math.abs(sum-100)<=0.01 ? 'ok' : 'warn';
  }
}
document.addEventListener('DOMContentLoaded', function(){
  ['participationWeight','oralWeight','writtenWeight'].forEach(function(id){
    var input=document.getElementById(id);
    if(input) input.addEventListener('input', updateAssessmentWeightSum);
  });
  updateAssessmentWeightSum();
});
</script>
<?php render_footer(); ?>
