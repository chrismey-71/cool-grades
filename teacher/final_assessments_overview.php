<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/final_assessments.php';
require_once __DIR__.'/../lib/school_years.php';

$u = require_role('teacher');
$pdo = db();
$bp = cfg()['base_path'] ?? '';

$periodSets = app_school_period_sets(true);
$schoolPeriodSetId = array_key_exists('school_period_set_id', $_GET)
  ? (int)$_GET['school_period_set_id']
  : final_assessment_default_period_set_id($periodSets);
$periodSet = $schoolPeriodSetId > 0 ? app_school_period_find($schoolPeriodSetId, true) : null;
$period = final_assessment_overview_period_normalize((string)($_GET['period'] ?? 'current'));
$classId = (int)($_GET['class_id'] ?? 0);
$subjectId = (int)($_GET['subject_id'] ?? 0);
$statusFilter = final_assessment_overview_status_normalize((string)($_GET['status'] ?? 'all'));

$classes = $schoolPeriodSetId > 0
  ? load_teacher_classes($pdo, (int)$u['id'], $schoolPeriodSetId, true, true)
  : [];
$subjectSql = "SELECT DISTINCT s.id,s.code,s.name
               FROM teacher_assignments ta
               JOIN classes c ON c.id=ta.class_id
               JOIN subjects s ON s.id=ta.subject_id
               WHERE ta.teacher_id=? AND c.school_period_set_id=?
               ORDER BY s.code,s.name";
$st = $pdo->prepare($subjectSql);
$st->execute([(int)$u['id'], $schoolPeriodSetId]);
$subjects = $st->fetchAll();

$overview = $periodSet
  ? final_assessment_teacher_overview(
      $pdo,
      (int)$u['id'],
      $periodSet,
      $period,
      $classId,
      $subjectId,
      $statusFilter
    )
  : ['rows'=>[],'groups'=>[],'stats'=>['total'=>0,'open'=>0,'draft'=>0,'final'=>0,'saved_grades'=>0,'grade_counts'=>[1=>0,2=>0,3=>0,4=>0,5=>0]],'skipped_combinations'=>0];
$stats = $overview['stats'];
$completion = ((int)$stats['total'] > 0) ? (int)round(((int)$stats['final'] / (int)$stats['total']) * 100) : 0;
$gradeTone = static function(?int $grade): string {
  if($grade === null || $grade <= 0) return 'neutral';
  if($grade <= 2) return 'positive';
  if($grade >= 4) return 'critical';
  return 'neutral';
};
$query = [
  'school_period_set_id' => $schoolPeriodSetId,
  'period' => $period,
  'class_id' => $classId,
  'subject_id' => $subjectId,
  'status' => $statusFilter,
];
$statusFilterUrl = static function(string $status) use ($bp, $query): string {
  return $bp.'/teacher/final_assessments_overview.php?'.http_build_query(array_replace($query, ['status'=>$status]));
};

render_header('Notenübersicht', $u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card final-overview-page">
      <div class="row final-overview-title-row" style="justify-content:space-between;align-items:flex-start;gap:14px">
        <div>
          <h1 style="margin-bottom:6px">Notenübersicht</h1>
          <p class="muted" style="margin:0;max-width:900px">
            Diese Übersicht bündelt die gespeicherten Entwürfe und finalen Abschlussbeurteilungen aller Ihrer Klassen und Fächer. Offene Zeilen zeigen, wo noch keine Abschlussbeurteilung gespeichert wurde.
          </p>
        </div>
        <div class="row" style="gap:8px;flex-wrap:wrap">
          <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/final_assessments.php">Beurteilungen bearbeiten</a>
          <?php if($periodSet): ?>
            <a class="btn" href="<?php echo h($bp); ?>/teacher/final_assessments_overview_pdf.php?<?php echo h(http_build_query($query)); ?>">PDF herunterladen</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="report-focus-block final-overview-help" style="margin-top:14px">
        <strong>Einordnung</strong>
        <div class="muted" style="margin-top:6px">
          „Aktueller Beurteilungszeitraum“ verwendet bei SOST/NOST das laufende Semester. Im Jahresmodell wird im 1. Semester die Schulnachricht und danach die Jahresbeurteilung angezeigt. Die Übersicht verändert keine Noten.
        </div>
      </div>

      <form method="get" class="final-overview-filters" style="margin-top:14px">
        <div>
          <label class="muted">Schuljahr</label>
          <select class="input" name="school_period_set_id" required onchange="this.form.submit()">
            <option value="0">-</option>
            <?php foreach($periodSets as $set): ?>
              <option value="<?php echo (int)$set['id']; ?>" <?php echo $schoolPeriodSetId === (int)$set['id'] ? 'selected' : ''; ?>><?php echo h((string)$set['label'].(((int)($set['archived'] ?? 0) === 1) ? ' · Archiv' : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="muted">Zeitraum</label>
          <select class="input" name="period">
            <?php foreach(final_assessment_overview_period_options() as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php echo $period === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="muted">Klasse</label>
          <select class="input" name="class_id">
            <option value="0">Alle Klassen</option>
            <?php foreach($classes as $class): ?>
              <option value="<?php echo (int)$class['id']; ?>" <?php echo $classId === (int)$class['id'] ? 'selected' : ''; ?>><?php echo h((string)$class['name'].(class_is_readonly($class) ? ' · Archiv' : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="muted">Fach</label>
          <select class="input" name="subject_id">
            <option value="0">Alle Fächer</option>
            <?php foreach($subjects as $subject): ?>
              <option value="<?php echo (int)$subject['id']; ?>" <?php echo $subjectId === (int)$subject['id'] ? 'selected' : ''; ?>><?php echo h((string)$subject['code'].' – '.(string)$subject['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="muted">Bearbeitungsstand</label>
          <select class="input" name="status">
            <?php foreach(final_assessment_overview_status_options() as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="final-overview-filter-action">
          <button class="btn">Übersicht anzeigen</button>
        </div>
      </form>

      <?php if(!$periodSet): ?>
        <div class="flash error" style="margin-top:14px">Es ist kein gültiges Schuljahr ausgewählt.</div>
      <?php else: ?>
        <?php if((int)($periodSet['archived'] ?? 0) === 1): ?>
          <div class="flash" style="margin-top:14px"><strong>Archiviertes Schuljahr:</strong> Die Übersicht und der PDF-Export bleiben verfügbar; die Anzeige verändert keine historischen Daten.</div>
        <?php endif; ?>
        <?php if((int)$overview['skipped_combinations'] > 0): ?>
          <div class="flash" style="margin-top:14px">
            <?php echo (int)$overview['skipped_combinations']; ?> Klasse-Fach-Kombination(en) im Jahresmodell wurden ausgeblendet, weil dort keine eigenständige 2. Semesterbeurteilung geführt wird.
          </div>
        <?php endif; ?>

        <div class="final-overview-stats" style="margin-top:16px">
          <a class="final-overview-stat final-overview-stat-link total <?php echo $statusFilter==='all'?'is-active':''; ?>" href="<?php echo h($statusFilterUrl('all')); ?>" <?php echo $statusFilter==='all'?'aria-current="page"':''; ?>><span>Beurteilungen gesamt</span><strong><?php echo (int)$stats['total']; ?></strong><small>Alle anzeigen</small></a>
          <a class="final-overview-stat final-overview-stat-link final <?php echo $statusFilter==='final'?'is-active':''; ?>" href="<?php echo h($statusFilterUrl('final')); ?>" <?php echo $statusFilter==='final'?'aria-current="page"':''; ?>><span>Final gespeichert</span><strong><?php echo (int)$stats['final']; ?></strong><small>Danach filtern</small></a>
          <a class="final-overview-stat final-overview-stat-link draft <?php echo $statusFilter==='draft'?'is-active':''; ?>" href="<?php echo h($statusFilterUrl('draft')); ?>" <?php echo $statusFilter==='draft'?'aria-current="page"':''; ?>><span>Entwürfe</span><strong><?php echo (int)$stats['draft']; ?></strong><small>Danach filtern</small></a>
          <a class="final-overview-stat final-overview-stat-link open <?php echo $statusFilter==='open'?'is-active':''; ?>" href="<?php echo h($statusFilterUrl('open')); ?>" <?php echo $statusFilter==='open'?'aria-current="page"':''; ?>><span>Noch offen</span><strong><?php echo (int)$stats['open']; ?></strong><small>Danach filtern</small></a>
        </div>
        <div class="final-overview-progress" aria-label="<?php echo $completion; ?> Prozent final gespeichert" style="--completion:<?php echo $completion; ?>%">
          <div></div>
          <span><?php echo $completion; ?> % final gespeichert</span>
        </div>

        <?php if(empty($overview['rows'])): ?>
          <div class="report-focus-block" style="margin-top:16px">
            <strong>Keine passenden Einträge</strong>
            <div class="muted" style="margin-top:6px">Für die gewählten Filter wurden keine Schüler:innen bzw. Beurteilungsstände gefunden.</div>
          </div>
        <?php else: ?>
          <div class="final-overview-table-wrap" style="margin-top:16px">
            <table class="table final-overview-table">
              <thead>
                <tr>
                  <th>Klasse</th>
                  <th>Fach</th>
                  <th>Zeitraum</th>
                  <th>Schüler:in</th>
                  <th>Gespeicherte Note</th>
                  <th>Notenvorschlag</th>
                  <th>Status</th>
                  <th>Aktualisiert</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($overview['rows'] as $row): ?>
                  <?php
                    $assessment = $row['assessment'];
                    $grade = ($assessment && $assessment['final_grade'] !== null) ? (int)$assessment['final_grade'] : null;
                    $proposalData = (array)($row['proposal'] ?? []);
                    $proposal = trim((string)($proposalData['label'] ?? '')) ?: '–';
                    $proposalExplanation = trim((string)($proposalData['explanation'] ?? ''));
                    $proposalTone = (string)($proposalData['tone'] ?? 'neutral');
                    $updatedAt = $assessment ? trim((string)($assessment['updated_at'] ?? '')) : '';
                    $editQuery = http_build_query([
                      'school_period_set_id' => $schoolPeriodSetId,
                      'class_id' => (int)$row['class_id'],
                      'subject_id' => (int)$row['subject_id'],
                      'scope' => (string)$row['scope'],
                      'student_id' => (int)$row['student_id'],
                    ]);
                  ?>
                  <tr class="final-overview-row-<?php echo h((string)$row['row_status']); ?>">
                    <td><strong><?php echo h((string)$row['class_name']); ?></strong></td>
                    <td><strong><?php echo h((string)$row['subject_code']); ?></strong><div class="muted small"><?php echo h((string)$row['subject_name']); ?></div></td>
                    <td><?php echo h((string)$row['scope_label']); ?><div class="muted small"><?php echo h((string)$row['assessment_system_label']); ?></div></td>
                    <td><a class="final-overview-student-link" href="<?php echo h($bp); ?>/teacher/final_assessments.php?<?php echo h($editQuery); ?>"><strong><?php echo h((string)$row['student_name']); ?></strong></a></td>
                    <td>
                      <?php if($grade !== null): ?>
                        <span class="final-overview-grade <?php echo h($gradeTone($grade)); ?>" title="<?php echo h(final_assessment_grade_label($grade)); ?>" aria-label="Gespeicherte Note: <?php echo h(final_assessment_grade_label($grade)); ?>"><?php echo $grade; ?></span>
                      <?php else: ?>
                        <span class="muted">noch nicht festgelegt</span>
                      <?php endif; ?>
                    </td>
                    <td title="<?php echo h($proposalExplanation); ?>"><span class="final-overview-proposal <?php echo h($proposalTone); ?>"><?php echo h($proposal); ?></span></td>
                    <td><span class="final-overview-status <?php echo h((string)$row['row_status']); ?>"><?php echo h((string)$row['status_label']); ?></span></td>
                    <td><?php echo $updatedAt !== '' ? h(date('d.m.Y H:i', strtotime($updatedAt))) : '<span class="muted">–</span>'; ?></td>
                    <td><a class="btn secondary small" href="<?php echo h($bp); ?>/teacher/final_assessments.php?<?php echo h($editQuery); ?>">Öffnen</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="muted small" style="margin-top:14px">
        Die Übersicht ist eine Arbeits- und Kontrollansicht. Notenvorschläge bleiben pädagogische Entscheidungshilfen; die finale Beurteilung wird ausschließlich in der Abschlussbeurteilung durch die Lehrkraft festgelegt.
      </div>
    </div>
  </div>
</div>
<?php render_footer(); ?>
