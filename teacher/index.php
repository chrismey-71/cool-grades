<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/assessment_summaries.php';
require_once __DIR__.'/../lib/school_years.php';
$u=require_role('teacher'); $pdo=db(); $bp=cfg()['base_path'];

$currentSchoolYearId=school_year_current_id($pdo);
$classes=load_teacher_classes($pdo,(int)$u['id'],$currentSchoolYearId,false,false);

$st=$pdo->prepare("SELECT DISTINCT s.id,s.code,s.name FROM teacher_assignments ta JOIN subjects s ON s.id=ta.subject_id WHERE ta.teacher_id=? ORDER BY s.code");
$st->execute([(int)$u['id']]); $subjects=$st->fetchAll();

// Class+Subject combinations for quick-entry buttons
$st=$pdo->prepare("SELECT c.id AS class_id,c.name AS class_name,s.id AS subject_id,s.code AS subject_code,s.name AS subject_name
  FROM teacher_assignments ta
  JOIN classes c ON c.id=ta.class_id
  JOIN subjects s ON s.id=ta.subject_id
  WHERE ta.teacher_id=? AND c.school_period_set_id=? AND c.is_archived=0 AND c.is_departed=0
  ORDER BY c.name,s.code");
$st->execute([(int)$u['id'],$currentSchoolYearId]);
$combos=$st->fetchAll();
$comboCount=count($combos);
$written_type_options = written_assessment_types();

$prefMode=(string)($u['pref_quick_entry_ui'] ?? '');
if($prefMode==='buttons' || $prefMode==='dropdown') $quickMode=$prefMode;
else $quickMode = ($comboCount<=12 ? 'buttons' : 'dropdown');

render_header('Lehrerbereich',$u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <h1>Lehrerbereich</h1>
      <p class="muted">Wähle Klasse und Fach – danach kannst du Mitarbeit, Stundenerfassung sowie mündliche und schriftliche Leistungsfeststellungen schnell öffnen.</p>

      <div class="grid" style="margin-top:14px">
        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Mitarbeit schnell erfassen</h2>
            <?php if($quickMode==='buttons'): ?>
              <div class="small" style="margin-bottom:8px">Tippe eine Kombination an (Klasse + Fach). Einstellung: <a href="<?php echo h($bp); ?>/account.php">Konto</a>.</div>
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
              <div class="small" style="margin-top:8px">Du kannst hier auch Buttons verwenden: <a href="<?php echo h($bp); ?>/account.php">Konto → Darstellung</a>.</div>
            <?php endif; ?>

            <div class="muted" style="margin-top:10px;font-size:13px">
              Tipp: Für typische Unterrichtssituationen nutze die <b>Stundenerfassung</b> (Schnell + Detail) und erfasse mehrere Schüler:innen gemeinsam.
            </div>

            <div style="height:10px"></div>
            <div class="row" style="gap:10px;flex-wrap:wrap">
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/lesson.php">Stundenerfassung (Schnell + Detail)</a>
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/participation_list.php">Einträge bearbeiten</a>
              <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/student_groups.php">Gruppen verwalten</a>
            </div>
          </div>

          <div class="card" style="padding:14px;margin-top:14px">
            <h2 style="margin:0 0 8px 0">Abschlussbeurteilung</h2>
            <div class="muted" style="font-size:13px">
              Mitarbeit, besondere mündliche und besondere schriftliche Leistungsfeststellungen zu einer Semester- oder Jahresbeurteilung zusammenführen.
            </div>
            <div style="height:10px"></div>
            <div class="muted" style="font-size:13px">
              Die App zeigt einen Notenvorschlag und dokumentiert die Entscheidungsgrundlage. Die finale Note wird bewusst durch die Lehrkraft festgelegt und gespeichert.
            </div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/final_assessments.php">Semester- und Jahresbeurteilung festlegen</a>
          </div>
        </div>

        <div class="col-12 col-md-6">
          <div class="card" style="padding:14px">
            <h2 style="margin:0 0 8px 0">Bes. mündl. Leistungsfeststellung</h2>
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

            <div style="height:10px"></div>
            <div class="muted" style="font-size:13px">Mündliche Prüfungen und mündliche Übungen werden getrennt erfasst und mit Eindruck/Relevanz dokumentiert.</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/orals.php">Mündliche Leistungsfeststellungen bearbeiten</a>
          </div>

          <div class="card" style="padding:14px;margin-top:14px">
            <h2 style="margin:0 0 8px 0">Bes. schriftl. Leistungsfeststellung</h2>
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
              <div style="flex:0 0 190px">
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

            <div style="height:10px"></div>
            <div class="muted" style="font-size:13px">Schriftliche Leistungsfeststellungen bleiben klassische Noten (1–5). Mitarbeit bleibt dokumentationsorientiert (LBV).</div>
            <div style="height:10px"></div>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/exams.php">Schriftliche Leistungsfeststellungen bearbeiten</a>
          </div>
        </div>

      </div>

    </div>
  </div>
</div>
<?php render_footer(); ?>
