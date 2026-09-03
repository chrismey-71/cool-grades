<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/assessment_summaries.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/schools.php';
$u=require_role('teacher'); $pdo=db(); $bp=cfg()['base_path'];

$teacherId=(int)$u['id'];
$assignedSchoolIds=teacher_school_ids($pdo,$teacherId);
if(!$assignedSchoolIds){
  // Safe fallback for legacy installations before teacher_schools was populated.
  $st=$pdo->prepare("SELECT DISTINCT sf.school_id
                     FROM teacher_assignments ta
                     JOIN classes c ON c.id=ta.class_id
                     JOIN school_forms sf ON sf.id=c.school_form_id
                     WHERE ta.teacher_id=? AND sf.school_id IS NOT NULL
                     ORDER BY sf.school_id");
  $st->execute([$teacherId]);
  $assignedSchoolIds=array_map('intval',array_column($st->fetchAll(),'school_id'));
}

$teacherSchools=[];
if($assignedSchoolIds){
  $in=implode(',',array_fill(0,count($assignedSchoolIds),'?'));
  $st=$pdo->prepare("SELECT id,name FROM schools WHERE active=1 AND id IN ($in) ORDER BY name");
  $st->execute($assignedSchoolIds);
  $teacherSchools=$st->fetchAll();
}

$allowedSchoolIds=array_map('intval',array_column($teacherSchools,'id'));
$requestedSchoolId=(int)($_GET['school_id'] ?? 0);
$storedSchoolId=(int)($_SESSION['teacher_school_context_id'] ?? 0);
if($requestedSchoolId>0 && in_array($requestedSchoolId,$allowedSchoolIds,true)){
  // A direct choice in the dashboard deliberately changes the working context.
  $selectedSchoolId=$requestedSchoolId;
} elseif(in_array($storedSchoolId,$allowedSchoolIds,true)){
  // Navigation back to the dashboard must keep the teacher's last choice.
  $selectedSchoolId=$storedSchoolId;
} else {
  $selectedSchoolId=count($teacherSchools) ? (int)$teacherSchools[0]['id'] : 0;
}
$selectedSchoolName='';
foreach($teacherSchools as $school){
  if((int)$school['id']===$selectedSchoolId){
    $selectedSchoolName=(string)$school['name'];
    break;
  }
}

// The selected school is a personal, temporary working context for the header
// and subsequent teacher pages. It is always validated again before display.
if($selectedSchoolId>0){
  $_SESSION['teacher_school_context_id']=$selectedSchoolId;
} else {
  unset($_SESSION['teacher_school_context_id']);
}

$currentSchoolYearId=school_year_current_id($pdo,$selectedSchoolId);
$classes=load_teacher_classes($pdo,$teacherId,$currentSchoolYearId,false,false,false,$selectedSchoolId);

$subjectSql="SELECT DISTINCT s.id,s.code,s.name
             FROM teacher_assignments ta
             JOIN classes c ON c.id=ta.class_id
             JOIN school_forms sf ON sf.id=c.school_form_id
             JOIN subjects s ON s.id=ta.subject_id
             WHERE ta.teacher_id=? AND ta.status='active' AND c.school_period_set_id=? AND c.is_archived=0 AND c.is_departed=0";
$subjectParams=[$teacherId,$currentSchoolYearId];
if($selectedSchoolId>0){ $subjectSql.=" AND sf.school_id=?"; $subjectParams[]=$selectedSchoolId; }
$subjectSql.=" ORDER BY s.code";
$st=$pdo->prepare($subjectSql);
$st->execute($subjectParams);
$subjects=$st->fetchAll();

// Class+Subject combinations for quick-entry buttons
$st=$pdo->prepare("SELECT c.id AS class_id,c.name AS class_name,s.id AS subject_id,s.code AS subject_code,s.name AS subject_name
  FROM teacher_assignments ta
  JOIN classes c ON c.id=ta.class_id
  JOIN school_forms sf ON sf.id=c.school_form_id
  JOIN subjects s ON s.id=ta.subject_id
  WHERE ta.teacher_id=? AND ta.status='active' AND c.school_period_set_id=? AND c.is_archived=0 AND c.is_departed=0".
  ($selectedSchoolId>0 ? " AND sf.school_id=?" : '')."
  ORDER BY c.name,s.code");
$comboParams=[$teacherId,$currentSchoolYearId];
if($selectedSchoolId>0) $comboParams[]=$selectedSchoolId;
$st->execute($comboParams);
$combos=$st->fetchAll();
$comboCount=count($combos);
$written_type_options = written_assessment_types();

$prefMode=(string)($u['pref_quick_entry_ui'] ?? '');
if($prefMode==='buttons' || $prefMode==='dropdown') $quickMode=$prefMode;
else $quickMode = ($comboCount<=12 ? 'buttons' : 'dropdown');

render_header('Dashboard',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <div class="dashboard-hero">
        <div>
          <h1>Dashboard</h1>
          <p class="muted">Schneller Einstieg in die drei Erfassungsbereiche. Abschlussbeurteilung sowie Berichte &amp; Auswertungen sind oben als eigene Menüpunkte erreichbar.</p>
        </div>
      </div>

      <?php if(count($teacherSchools)>1): ?>
        <form method="get" class="dashboard-school-selection" data-school-selection style="margin-top:14px">
          <div>
            <label class="school-selection-label" for="dashboard-school-id">Arbeitsbereich Schule</label>
            <select class="input school-select" id="dashboard-school-id" name="school_id" onchange="this.form.submit()">
              <?php foreach($teacherSchools as $school): ?>
                <option value="<?php echo (int)$school['id']; ?>" data-school-tone="<?php echo h(school_tone_class((int)$school['id'])); ?>" <?php echo $selectedSchoolId===(int)$school['id']?'selected':''; ?>><?php echo h($school['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="school-selection-note">Klassen, Fächer und Schnellzugriffe dieses Dashboards werden auf die gewählte Schule beschränkt.</div>
        </form>
      <?php elseif($selectedSchoolName!==''): ?>
        <div class="dashboard-school-single" style="margin-top:14px"><span>Arbeitsbereich Schule</span><b><?php echo h($selectedSchoolName); ?></b></div>
      <?php endif; ?>

      <div class="dashboard-entry-grid" style="margin-top:16px">
          <div class="card dashboard-entry-card dashboard-entry-participation">
            <div class="dashboard-entry-kicker">laufende Unterrichtsbeobachtung</div>
            <h2>Mitarbeit erfassen</h2>
            <p class="muted">Für kurze, laufende Beobachtungen im Unterricht. Diese Einträge bilden die Grundlage für Mitarbeitsauswertungen und spätere Entscheidungshilfen.</p>
            <?php if($quickMode==='buttons'): ?>
              <div class="small dashboard-entry-note">Tippe eine Kombination an (Klasse + Fach). Einstellung: <a href="<?php echo h($bp); ?>/account.php#account-pref-quick-entry-ui">Konto → Schnellerfassung: Auswahlmodus</a>.</div>
              <?php if(!$combos): ?>
                <div class="flash error">Keine Zuordnungen gefunden. Bitte im Admin unter „Lehrerzuordnung“ Klasse/Fach zuweisen.</div>
              <?php else: ?>
                <div class="quick-combos">
                  <?php foreach($combos as $cs):
                    $label = trim($cs['class_name'].' '.$cs['subject_code']);
                    $title = trim($cs['subject_code'].' – '.$cs['subject_name']);
                  ?>
                    <a class="btn secondary quick-combo" title="<?php echo h($title); ?>" href="<?php echo h($bp); ?>/teacher/participation_new.php?class_id=<?php echo (int)$cs['class_id']; ?>&subject_id=<?php echo (int)$cs['subject_id']; ?>"><?php echo h($label); ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <form class="row" method="get" action="<?php echo h($bp); ?>/teacher/participation_new.php" style="gap:10px;align-items:end" <?php echo teacher_assignment_guard_attrs($u); ?>>
                <div style="flex:1 1 220px">
                  <label class="muted">Klasse</label>
                  <select class="input" name="class_id" required>
                    <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div style="flex:1 1 260px">
                  <label class="muted">Fach</label>
                  <select class="input" name="subject_id" required>
                    <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['code'].' – '.$s['name']); ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div style="flex:0 0 auto">
                  <label class="muted">&nbsp;</label>
                  <button class="btn" style="min-width:190px">Mitarbeit erfassen</button>
                </div>
              </form>
              <div class="small dashboard-entry-note">Du kannst hier auch Buttons verwenden: <a href="<?php echo h($bp); ?>/account.php#account-pref-quick-entry-ui">Konto → Schnellerfassung: Auswahlmodus</a>.</div>
            <?php endif; ?>

            <div class="dashboard-entry-actions">
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/lesson.php">Stundenerfassung (Schnell + Detail)</a>
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/participation_list.php">Einträge bearbeiten</a>
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/student_groups.php">Gruppen verwalten</a>
            </div>
          </div>

          <div class="card dashboard-entry-card dashboard-entry-oral">
            <div class="dashboard-entry-kicker">besondere Leistungsfeststellung</div>
            <h2>Bes. mündl. Leistung erfassen</h2>
            <p class="muted">Für mündliche Prüfungen oder mündliche Übungen, die getrennt von der laufenden Mitarbeit dokumentiert werden.</p>
            <form class="row" method="get" action="<?php echo h($bp); ?>/teacher/oral_new.php" style="gap:10px;align-items:end" <?php echo teacher_assignment_guard_attrs($u); ?>>
              <div style="flex:1 1 220px">
                <label class="muted">Klasse</label>
                <select class="input" name="class_id" required>
                  <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div style="flex:1 1 260px">
                <label class="muted">Fach</label>
                <select class="input" name="subject_id" required>
                  <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['code'].' – '.$s['name']); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div style="flex:0 0 220px">
                <label class="muted">Art</label>
                <select class="input" name="oral_type" required>
                  <option value="ORAL_EXAM">mündliche Prüfung</option>
                  <option value="ORAL_EXERCISE">mündliche Übung</option>
                </select>
              </div>
              <div style="flex:0 0 auto">
                <label class="muted">&nbsp;</label>
                <button class="btn" style="min-width:190px">Anlegen</button>
              </div>
            </form>

            <div class="dashboard-entry-actions">
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/orals.php">Mündliche Leistungen bearbeiten</a>
            </div>
          </div>

          <div class="card dashboard-entry-card dashboard-entry-written">
            <div class="dashboard-entry-kicker">besondere Leistungsfeststellung</div>
            <h2>Bes. schriftl. Leistung erfassen</h2>
            <p class="muted">Für Schularbeiten, Tests, Diktate oder andere schriftliche Leistungsfeststellungen mit klassischer Note.</p>
            <form class="row" method="get" action="<?php echo h($bp); ?>/teacher/exam_new.php" style="gap:10px;align-items:end" <?php echo teacher_assignment_guard_attrs($u); ?>>
              <div style="flex:1 1 220px">
                <label class="muted">Klasse</label>
                <select class="input" name="class_id" required>
                  <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div style="flex:1 1 260px">
                <label class="muted">Fach</label>
                <select class="input" name="subject_id" required>
                  <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['code'].' – '.$s['name']); ?></option><?php endforeach; ?>
                </select>
              </div>
              <div style="flex:0 0 220px">
                <label class="muted">Art</label>
                <select class="input" name="exam_type" required>
                  <?php foreach($written_type_options as $typeValue => $typeLabel): ?>
                    <option value="<?php echo h($typeValue); ?>"><?php echo h($typeLabel); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="flex:0 0 auto">
                <label class="muted">&nbsp;</label>
                <button class="btn" style="min-width:190px">Anlegen</button>
              </div>
            </form>

            <div class="dashboard-entry-actions">
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/exams.php">Schriftliche Leistungen bearbeiten</a>
            </div>
          </div>
      </div>

    </div>
  </div>
</div>
<?php render_footer(); ?>
