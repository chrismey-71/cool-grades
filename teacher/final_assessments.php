<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/final_assessments.php';
require_once __DIR__.'/../lib/school_years.php';
require_once __DIR__.'/../lib/schools.php';

$u = require_role('teacher');
$pdo = db();
$bp = cfg()['base_path'] ?? '';

$selectedSchoolId=teacher_school_context_id($pdo,(int)$u['id']);
$periodSets = app_school_period_sets(true,$selectedSchoolId,true);
$school_period_set_id = array_key_exists('school_period_set_id', $_REQUEST)
  ? (int)$_REQUEST['school_period_set_id']
  : final_assessment_default_period_set_id($periodSets);
$classes = load_teacher_classes($pdo,(int)$u['id'],$school_period_set_id,true,true,true,$selectedSchoolId);

$subjectSql = "SELECT DISTINCT s.id,s.code,s.name
               FROM teacher_assignments ta
               JOIN classes c ON c.id=ta.class_id
               JOIN school_forms sf ON sf.id=c.school_form_id
               JOIN subjects s ON s.id=ta.subject_id
               WHERE ta.teacher_id=? AND c.school_period_set_id=?";
$subjectParams = [(int)$u['id'], $school_period_set_id];
if($selectedSchoolId>0){ $subjectSql .= " AND sf.school_id=?"; $subjectParams[]=$selectedSchoolId; }
$subjectSql .= " ORDER BY s.code";
$st = $pdo->prepare($subjectSql);
$st->execute($subjectParams);
$subjects = $st->fetchAll();

$class_id = (int)($_REQUEST['class_id'] ?? 0);
$subject_id = (int)($_REQUEST['subject_id'] ?? 0);
$selectedAssessmentSystem = null;
foreach($classes as $classOption){
  if((int)($classOption['id'] ?? 0) === $class_id){
    $candidateSystem = (string)($classOption['assessment_system'] ?? '');
    $selectedAssessmentSystem = class_assessment_system_is_valid($candidateSystem) ? $candidateSystem : null;
    break;
  }
}
$periodSetForScopeDefault = $school_period_set_id > 0 ? app_school_period_find($school_period_set_id, true) : null;
$scope = array_key_exists('scope', $_REQUEST)
  ? (string)$_REQUEST['scope']
  : final_assessment_default_scope($periodSetForScopeDefault ?: null, null, $selectedAssessmentSystem);
$selected_student_id = (int)($_REQUEST['student_id'] ?? 0);
$scope = final_assessment_scope_normalize($scope, $selectedAssessmentSystem);

$msg = '';
$error = '';
$fieldErrors = [];
$postedValues = null;
$bulkValues = [];
$bulkFieldErrors = [];
$rowsData = null;
$assignmentReadOnly = false;

function _fa_query(array $base, array $overrides = []): string {
  return '?'.http_build_query(array_merge($base, $overrides));
}

function _fa_grade_tone(?int $grade): string {
  if($grade === null || $grade <= 0) return 'neutral';
  if($grade <= 2) return 'positive';
  if($grade >= 4) return 'critical';
  return 'neutral';
}

function _fa_avg_grade_tone($avg): string {
  if($avg === null) return 'neutral';
  $avg = (float)$avg;
  if($avg <= 2.5) return 'positive';
  if($avg >= 3.8) return 'critical';
  return 'neutral';
}

function _fa_oral_assessment_display(array $oralRows): array {
  $byType = [];
  $legacyParts = [];
  foreach($oralRows as $row){
    $grade = (int)($row['grade'] ?? 0);
    if($grade >= 1 && $grade <= 5){
      $type = oral_assessment_normalize_type((string)($row['assessment_type'] ?? 'ORAL_EXAM'));
      if(!isset($byType[$type])){
        $byType[$type] = [
          'label' => oral_assessment_type_label($type),
          'count' => 0,
          'grades' => [],
          'details' => [],
        ];
      }
      $symbol = oral_assessment_grade_symbol($grade, (string)($row['tendency'] ?? ''));
      $weight = assessment_weight_multiplier_normalize($row['weight_multiplier'] ?? 1);
      $weightSuffix = abs($weight - 1.0) > 0.001 ? ' · '.number_format($weight, 1, ',', '.').'x' : '';
      $byType[$type]['count']++;
      $byType[$type]['grades'][] = $symbol.$weightSuffix;
      $detail = trim((string)($row['assessment_date'] ?? ''));
      $extra = oral_assessment_detail($row);
      if($extra !== '—' && $extra !== '') $detail .= ($detail !== '' ? ' · ' : '').$extra;
      $detail .= ($detail !== '' ? ': ' : '').$symbol.$weightSuffix;
      $byType[$type]['details'][] = $detail;
    } else {
      $label = trim((string)($row['impact_label'] ?? ''));
      if($label !== '') $legacyParts[] = $label;
    }
  }

  $legacyNote = $legacyParts
    ? count($legacyParts).' ältere Eintrag/Einträge nur mit Eindruck/Relevanz (zählt nicht in die Notenberechnung): '.implode(', ', array_slice($legacyParts, 0, 3)).(count($legacyParts) > 3 ? ', …' : '')
    : '';

  if(!$byType){
    return [
      'types' => 'Keine benoteten besonderen mündlichen Leistungen erfasst.',
      'grades' => '–',
      'details' => '–',
      'legacy_note' => $legacyNote,
    ];
  }

  $typeParts = [];
  $gradeParts = [];
  $detailParts = [];
  foreach($byType as $data){
    $typeParts[] = $data['label'].' ('.(int)$data['count'].')';
    $gradeParts[] = $data['label'].': '.implode(', ', array_slice($data['grades'], 0, 4)).(count($data['grades']) > 4 ? ', …' : '');
    foreach(array_slice($data['details'], 0, 3) as $detail){
      if($detail !== '') $detailParts[] = $detail;
    }
  }

  return [
    'types' => implode(' · ', $typeParts),
    'grades' => implode(' · ', $gradeParts),
    'details' => implode(' | ', array_slice($detailParts, 0, 4)).(count($detailParts) > 4 ? ' | …' : ''),
    'legacy_note' => $legacyNote,
  ];
}

function _fa_written_assessment_display(array $writtenRows): array {
  $byType = [];
  foreach($writtenRows as $row){
    $grade = (int)($row['grade'] ?? 0);
    if($grade <= 0) continue;
    $type = written_assessment_normalize_type((string)($row['exam_type'] ?? 'SA'));
    if(!isset($byType[$type])){
      $byType[$type] = [
        'label' => written_assessment_type_label($type),
        'short' => written_assessment_type_short_label($type),
        'count' => 0,
        'grades' => [],
        'details' => [],
      ];
    }
    $symbol = report_eval_grade_symbol($grade, (string)($row['tendency'] ?? ''));
    $weight = assessment_weight_multiplier_normalize($row['weight_multiplier'] ?? 1);
    $weightSuffix = abs($weight - 1.0) > 0.001 ? ' · '.number_format($weight, 1, ',', '.').'x' : '';
    $byType[$type]['count']++;
    $byType[$type]['grades'][] = $symbol.$weightSuffix;
    $detail = trim((string)($row['exam_date'] ?? ''));
    $title = trim((string)($row['title'] ?? ''));
    if($title !== '') $detail .= ($detail !== '' ? ' · ' : '').$title;
    $detail .= ($detail !== '' ? ': ' : '').$symbol.$weightSuffix;
    $byType[$type]['details'][] = $detail;
  }

  if(!$byType){
    return [
      'types' => 'Keine besonderen schriftlichen Leistungen erfasst.',
      'grades' => '–',
      'details' => '–',
    ];
  }

  $typeParts = [];
  $gradeParts = [];
  $detailParts = [];
  foreach($byType as $data){
    $typeParts[] = $data['label'].' ('.(int)$data['count'].')';
    $gradeParts[] = $data['label'].': '.implode(', ', array_slice($data['grades'], 0, 4)).(count($data['grades']) > 4 ? ', …' : '');
    foreach(array_slice($data['details'], 0, 3) as $detail){
      if($detail !== '') $detailParts[] = $detail;
    }
  }

  return [
    'types' => implode(' · ', $typeParts),
    'grades' => implode(' · ', $gradeParts),
    'details' => implode(' | ', array_slice($detailParts, 0, 4)).(count($detailParts) > 4 ? ' | …' : ''),
  ];
}

function _fa_proposal_area_context_label(string $scope, bool $isYearlyModel): string {
  if($scope === 'year' && $isYearlyModel) return 'Bereichswert aktueller Leistungsstand';
  if($scope === 'semester1' && $isYearlyModel) return 'Bereichswert Schulnachricht';
  if($scope === 'semester1') return 'Bereichswert 1. Semester';
  if($scope === 'semester2') return 'Bereichswert 2. Semester';
  return 'Bereichswert Beurteilungszeitraum';
}

function _fa_proposal_basis_intro(string $scope, bool $isYearlyModel, ?string $assessmentSystem): string {
  if($scope === 'year' && $isYearlyModel){
    return 'Für die Jahresbeurteilung wird der Notenvorschlag aus der Schulnachricht / dem 1. Semester und dem aktuellen Leistungsstand des restlichen Schuljahres gebildet. Die Bereichskarten zeigen den aktuellen Leistungsstand, nicht nochmals den Gesamtjahresdurchschnitt.';
  }
  if($scope === 'semester1' && $isYearlyModel){
    return 'Für die Schulnachricht wird ein Vorschlag aus den im 1. Semester dokumentierten Bereichen gebildet.';
  }
  if($scope === 'semester2'){
    return 'Für diese Semesterbeurteilung wird ein Vorschlag aus den im 2. Semester dokumentierten Bereichen gebildet. Das 1. Semester dient nur als Orientierung.';
  }
  if($scope === 'year' && in_array($assessmentSystem, ['sost','nost'], true)){
    return 'Für SOST/NOST wird keine automatische Jahresnote aus zwei Semestern berechnet. Die App zeigt hier nur eine Orientierung zur pädagogischen Entscheidung.';
  }
  return 'Für diese Beurteilung wird ein Vorschlag aus den dokumentierten Bereichen des ausgewählten Zeitraums gebildet.';
}

function _fa_proposal_part_display(array $proposalPart): string {
  if(isset($proposalPart['calculated_value']) && $proposalPart['calculated_value'] !== null){
    return assessment_weight_grade_format((float)$proposalPart['calculated_value']);
  }
  if(isset($proposalPart['value']) && $proposalPart['value'] !== null){
    return assessment_weight_grade_format((float)$proposalPart['value']);
  }
  return '–';
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
  verify_csrf();
  if($class_id && $subject_id){
    require_teacher_active_assignment($u, $class_id, $subject_id);
  }
  $postAction = (string)($_POST['action'] ?? 'single_save');

  $periodSet = app_school_period_find($school_period_set_id, true);
  if(!$class_id || !$subject_id || !$periodSet){
    $error = 'Bitte Klasse, Fach und Schuljahr korrekt auswählen.';
  } elseif($postAction === 'bulk_final') {
    $bulkGradesRaw = (array)($_POST['bulk_final_grade'] ?? []);
    $bulkCommentsRaw = (array)($_POST['bulk_teacher_comment'] ?? []);
    $bulkChangeNote = trim((string)($_POST['bulk_change_note'] ?? ''));
    $bulkValues = [
      'grades' => $bulkGradesRaw,
      'comments' => $bulkCommentsRaw,
      'change_note' => $bulkChangeNote,
    ];
    $rowsData = final_assessment_build_rows($pdo, $class_id, $subject_id, $periodSet, $scope, (int)$u['id']);
    $rowsByStudent = [];
    foreach(($rowsData['rows'] ?? []) as $row){
      $rowsByStudent[(int)$row['student_id']] = $row;
    }
    if(!$rowsByStudent){
      $error = 'Für die gewählte Kombination liegen keine Schüler:innen für die Schnelleingabe vor.';
    } else {
      foreach($rowsByStudent as $sid => $row){
        $grade = (int)($bulkGradesRaw[$sid] ?? 0);
        if($grade < 1 || $grade > 5){
          $bulkFieldErrors[$sid] = 'Bitte Note auswählen.';
        }
      }
      if($bulkFieldErrors){
        $error = 'Bitte für alle Schüler:innen eine finale Note auswählen.';
      } else {
        $payloads = [];
        foreach($rowsByStudent as $sid => $row){
          $grade = (int)$bulkGradesRaw[$sid];
          $comment = trim((string)($bulkCommentsRaw[$sid] ?? ''));
          $payload = final_assessment_build_payload($u, $class_id, $subject_id, $row, $grade, 'final', $comment, $bulkChangeNote);
          $existing = $row['existing'] ?? null;
          if(final_assessment_requires_change_note($existing, $payload) && $bulkChangeNote === ''){
            $bulkFieldErrors[$sid] = 'Änderungsvermerk erforderlich.';
          }
          $payloads[] = ['existing'=>$existing, 'payload'=>$payload, 'student_id'=>$sid];
        }
        if($bulkFieldErrors){
          $error = 'Mindestens eine bereits final gespeicherte Beurteilung würde geändert. Bitte einen Änderungsvermerk für die Sammelspeicherung angeben.';
        } else {
          $pdo->beginTransaction();
          try{
            foreach($payloads as $entry){
              final_assessment_store($pdo, $entry['existing'], $entry['payload'], $u);
            }
            $pdo->commit();
            $firstStudentId = (int)array_key_first($rowsByStudent);
            $redirect = $_SERVER['PHP_SELF']._fa_query([
              'class_id' => $class_id,
              'subject_id' => $subject_id,
              'school_period_set_id' => $school_period_set_id,
              'scope' => $scope,
              'student_id' => $selected_student_id > 0 ? $selected_student_id : $firstStudentId,
              'msg' => 'bulk_final_saved',
            ]);
            header('Location: '.$redirect);
            exit;
          }catch(Throwable $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            app_log('error', 'final assessment bulk save failed', [
              'error' => $e->getMessage(),
              'class_id' => $class_id,
              'subject_id' => $subject_id,
              'school_period_set_id' => $school_period_set_id,
              'scope' => $scope,
            ]);
            $error = 'Die Sammelspeicherung konnte nicht abgeschlossen werden.';
          }
        }
      }
    }
  } else {
    $saveStudentId = (int)($_POST['student_id'] ?? 0);
    $selected_student_id = $saveStudentId > 0 ? $saveStudentId : $selected_student_id;
    $saveMode = (string)($_POST['save_mode'] ?? 'draft');
    $saveMode = $saveMode === 'final' ? 'final' : 'draft';
    $finalGrade = (int)($_POST['final_grade'] ?? 0);
    $finalGrade = ($finalGrade >= 1 && $finalGrade <= 5) ? $finalGrade : null;
    $teacherComment = trim((string)($_POST['teacher_comment'] ?? ''));
    $changeNote = trim((string)($_POST['change_note'] ?? ''));
    $postedValues = [
      'student_id' => $saveStudentId,
      'final_grade' => $finalGrade,
      'teacher_comment' => $teacherComment,
      'change_note' => $changeNote,
    ];

    if($saveStudentId <= 0){
      $error = 'Bitte eine Schüler:in korrekt auswählen.';
    } else {
      $rowsData = final_assessment_build_rows($pdo, $class_id, $subject_id, $periodSet, $scope, (int)$u['id']);
      $targetRow = null;
      foreach(($rowsData['rows'] ?? []) as $row){
        if((int)$row['student_id'] === $saveStudentId){
          $targetRow = $row;
          break;
        }
      }
      if(!$targetRow){
        $error = 'Die gewählte Schüler:in konnte für die aktuelle Abschlussbeurteilung nicht gefunden werden.';
      } else {
        if($saveMode === 'final' && $finalGrade === null){
          $error = 'Für eine finale Abschlussbeurteilung bitte eine Note auswählen.';
          $fieldErrors['final_grade'] = 'Bitte wählen Sie für die finale Speicherung eine Note aus.';
        } else {
          $payload = final_assessment_build_payload($u, $class_id, $subject_id, $targetRow, $finalGrade, $saveMode, $teacherComment, $changeNote);
          $existing = $targetRow['existing'] ?? null;
          if(final_assessment_requires_change_note($existing, $payload) && $changeNote === ''){
            $error = 'Eine bereits final gespeicherte Abschlussbeurteilung kann nur mit Änderungsvermerk geändert werden.';
            $fieldErrors['change_note'] = 'Bitte begründen Sie die Änderung einer bereits final gespeicherten Abschlussbeurteilung.';
          } else {
            $pdo->beginTransaction();
            try{
              final_assessment_store($pdo, $existing, $payload, $u);
              $pdo->commit();
              $nextStudentId = final_assessment_next_student_id($rowsData['rows'] ?? [], $saveStudentId);
              $redirect = $_SERVER['PHP_SELF']._fa_query([
                'class_id' => $class_id,
                'subject_id' => $subject_id,
                'school_period_set_id' => $school_period_set_id,
                'scope' => $scope,
                'student_id' => $nextStudentId ?? $saveStudentId,
                'msg' => $nextStudentId ? 'saved_next' : 'saved_end',
              ]);
              header('Location: '.$redirect);
              exit;
            }catch(Throwable $e){
              if($pdo->inTransaction()) $pdo->rollBack();
              app_log('error', 'final assessment save failed', [
                'error' => $e->getMessage(),
                'class_id' => $class_id,
                'subject_id' => $subject_id,
                'student_id' => $saveStudentId,
                'school_period_set_id' => $school_period_set_id,
                'scope' => $scope,
              ]);
              $error = 'Die Abschlussbeurteilung konnte nicht gespeichert werden.';
            }
          }
        }
      }
    }
  }
}

if($msg === '' && isset($_GET['msg'])){
  $msgCode = (string)$_GET['msg'];
  if($msgCode === 'saved_next') $msg = 'Abschlussbeurteilung gespeichert. Nächste:r Schüler:in wurde geöffnet.';
  elseif($msgCode === 'saved_end') $msg = 'Abschlussbeurteilung gespeichert. Sie haben das Ende der Liste erreicht.';
  elseif($msgCode === 'bulk_final_saved') $msg = 'Finale Abschlussbeurteilungen wurden für die Klasse gespeichert.';
  elseif($msgCode === 'final_saved') $msg = 'Finale Abschlussbeurteilung wurde gespeichert.';
  elseif($msgCode === 'draft_saved') $msg = 'Abschlussbeurteilung wurde als Entwurf gespeichert.';
}

if($class_id && $subject_id && $school_period_set_id > 0){
  require_teacher_assignment($u, $class_id, $subject_id);
  $assignmentReadOnly = !teacher_can_edit_assignment((int)$u['id'], $class_id, $subject_id)
    || class_is_readonly((array)(class_context($pdo,$class_id) ?? []));
  $periodSet = app_school_period_find($school_period_set_id, true);
  if($periodSet){
    $rowsData = final_assessment_build_rows($pdo, $class_id, $subject_id, $periodSet, $scope, (int)$u['id']);
  }
}

$selectedRow = null;
$selectedIndex = null;
$stateQuery = [
  'class_id' => $class_id,
  'subject_id' => $subject_id,
  'school_period_set_id' => $school_period_set_id,
  'scope' => $scope,
];

if($rowsData && !empty($rowsData['rows'])){
  $studentIds = array_map(static fn(array $row): int => (int)$row['student_id'], $rowsData['rows']);
  if($selected_student_id <= 0 || !in_array($selected_student_id, $studentIds, true)){
    $selected_student_id = $studentIds[0];
  }
  $selectedIndex = array_search($selected_student_id, $studentIds, true);
  if($selectedIndex !== false){
    $selectedRow = $rowsData['rows'][$selectedIndex];
  }
}

$studentCount = ($rowsData && !empty($rowsData['rows'])) ? count($rowsData['rows']) : 0;
$previousRow = null;
$nextRow = null;
if($selectedRow && $selectedIndex !== null && $selectedIndex !== false && $studentCount > 0){
  $idx = (int)$selectedIndex;
  $previousRow = $idx > 0 ? $rowsData['rows'][$idx - 1] : null;
  $nextRow = $idx < ($studentCount - 1) ? $rowsData['rows'][$idx + 1] : null;
}

render_header('Abschlussbeurteilung', $u);
?>
<div class="grid">
  <div class="col-12">
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:flex-start;gap:12px">
        <div style="flex:1 1 520px">
          <h1>Abschlussbeurteilung</h1>
          <p class="muted">
            Hier führen Sie dokumentierte Mitarbeit, besondere mündliche und besondere schriftliche Leistungsfeststellungen zu einer pädagogischen Entscheidung zusammen.
            Der Notenvorschlag ist nicht verbindlich, die finale Semester- oder Jahresbeurteilung wird bewusst von Ihnen festgelegt.
          </p>
        </div>
        <div style="flex:0 0 auto">
          <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/final_assessments_overview.php?school_period_set_id=<?php echo (int)$school_period_set_id; ?>">Notenübersicht öffnen</a>
        </div>
      </div>

      <?php if($msg): ?><div class="flash success" style="margin-top:10px"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($error): ?><div class="flash error" style="margin-top:10px"><?php echo h($error); ?></div><?php endif; ?>

      <div class="report-focus-block" style="margin-top:14px">
        <strong>Hinweis zum Ablauf</strong>
        <div class="muted" style="margin-top:8px">
          Wählen Sie zuerst, welche Abschlussbeurteilung Sie bearbeiten möchten. Die App unterstützt die pädagogische Entscheidung, legt aber keine Note automatisch fest.
        </div>
      </div>

      <form method="get" class="row" style="align-items:end;margin-top:14px" <?php echo teacher_assignment_guard_attrs($u); ?>>
        <div>
          <label class="muted">Schuljahr</label>
          <select class="input" name="school_period_set_id" required onchange="this.form.submit()">
            <option value="0">-</option>
            <?php foreach($periodSets as $set): ?>
              <option value="<?php echo (int)$set['id']; ?>" <?php echo $school_period_set_id === (int)$set['id'] ? 'selected' : ''; ?>><?php echo h((string)$set['label'].(((int)($set['archived'] ?? 0)===1)?' · Archiv':'')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="muted">Klasse</label>
          <select class="input" name="class_id" required>
            <option value="0">-</option>
            <?php foreach($classes as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo h($c['name'].(class_is_readonly($c)?' · Archiv':'')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="muted">Fach</label>
          <select class="input" name="subject_id" required>
            <option value="0">-</option>
            <?php foreach($subjects as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo $subject_id === (int)$s['id'] ? 'selected' : ''; ?>><?php echo h($s['code'].' - '.$s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:300px">
          <label class="muted">Beurteilungszeitraum</label>
          <select class="input" name="scope" required>
            <?php foreach(final_assessment_scope_options($selectedAssessmentSystem) as $scopeValue => $scopeLabel): ?>
              <option value="<?php echo h($scopeValue); ?>" <?php echo $scope === $scopeValue ? 'selected' : ''; ?>><?php echo h($scopeLabel); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted" style="margin-top:6px;font-size:13px"><?php echo h(final_assessment_scope_help($scope, $selectedAssessmentSystem)); ?></div>
        </div>
        <div style="flex:0 0 auto">
          <label class="muted">&nbsp;</label>
          <button class="btn">Beurteilung öffnen</button>
        </div>
        <?php if($class_id && $subject_id && $school_period_set_id > 0): ?>
          <div style="flex:0 0 auto">
            <label class="muted">&nbsp;</label>
            <a class="btn secondary" href="<?php echo h($bp); ?>/teacher/final_assessments_pdf.php<?php echo h(_fa_query($stateQuery)); ?>">PDF-Bericht öffnen</a>
          </div>
        <?php endif; ?>
      </form>

      <?php if(!$periodSets): ?>
        <div class="flash error" style="margin-top:14px">Es ist aktuell kein Schuljahr für die Abschlussbeurteilung hinterlegt. Bitte den Admin kontaktieren.</div>
      <?php endif; ?>

      <?php if($rowsData && empty($rowsData['rows'])): ?>
        <div class="flash error" style="margin-top:14px">Für die gewählte Kombination liegen aktuell keine auswertbaren Schüler:innendaten vor.</div>
      <?php endif; ?>

      <?php if($rowsData && !empty($rowsData['rows']) && !$assignmentReadOnly): ?>
        <details class="accordion final-assessment-bulk-entry" style="margin-top:14px">
          <summary><span class="acc-title">Schnelleingabe: finale Noten für die ganze Klasse speichern</span></summary>
          <div class="acc-body">
            <div class="final-bulk-intro" style="margin-bottom:12px">
              <strong>Sammelaktion für feststehende Noten</strong>
              <div>
              Diese Eingabe ist für Fälle gedacht, in denen die Abschlussnoten bereits feststehen. Mit dem Speichern werden alle ausgewählten Noten als <strong>finale Abschlussbeurteilung</strong> für die gewählte Klasse, das Fach und den Zeitraum gespeichert.
              </div>
            </div>
            <form method="post" <?php echo dirty_form_attrs(); ?>>
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="bulk_final">
              <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
              <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
              <input type="hidden" name="school_period_set_id" value="<?php echo (int)$school_period_set_id; ?>">
              <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
              <div style="overflow-x:auto">
                <table class="table" style="min-width:980px">
                  <thead>
                    <tr>
                      <th>Schüler:in</th>
                      <th>Datenlage</th>
                      <th>Notenvorschlag</th>
                      <th>bisher gespeichert</th>
                      <th>finale Note</th>
                      <th>Kommentar</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($rowsData['rows'] as $row): ?>
                      <?php
                        $sid=(int)$row['student_id'];
                        $existing=$row['existing'] ?? null;
                        $bulkGradeRaw=$bulkValues['grades'][$sid] ?? null;
                        $bulkCommentRaw=$bulkValues['comments'][$sid] ?? null;
                        $bulkGradeValue=$bulkGradeRaw !== null ? (int)$bulkGradeRaw : ($existing && $existing['final_grade'] !== null ? (int)$existing['final_grade'] : 0);
                        $bulkCommentValue=$bulkCommentRaw !== null ? (string)$bulkCommentRaw : (string)($existing['teacher_comment'] ?? '');
                        $rowSummary=$row['summary'];
                        $rowProposal=$row['proposal'];
                      ?>
                      <tr>
                        <td><strong><?php echo h((string)$row['student_name']); ?></strong></td>
                        <td>
                          <span class="report-chip <?php echo h((string)$rowSummary['data_basis']['tone']); ?>"><?php echo h(report_eval_data_basis_display($rowSummary['data_basis'])); ?></span>
                        </td>
                        <td>
                          <span class="report-chip <?php echo h((string)$rowProposal['tone']); ?>"><?php echo h((string)$rowProposal['label']); ?></span>
                        </td>
                        <td>
                          <?php if($existing): ?>
                            <?php echo h(final_assessment_status_label((string)$existing['status'])); ?>
                            <?php if($existing['final_grade'] !== null): ?> · <?php echo h(final_assessment_grade_label((int)$existing['final_grade'])); ?><?php endif; ?>
                          <?php else: ?>
                            <span class="muted">noch nicht gespeichert</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <select class="input" name="bulk_final_grade[<?php echo $sid; ?>]" required>
                            <option value="0">bitte wählen</option>
                            <?php foreach(final_assessment_grade_options() as $gradeOption => $gradeLabel): ?>
                              <option value="<?php echo (int)$gradeOption; ?>" <?php echo $bulkGradeValue === (int)$gradeOption ? 'selected' : ''; ?>><?php echo h($gradeLabel); ?></option>
                            <?php endforeach; ?>
                          </select>
                          <?php if(!empty($bulkFieldErrors[$sid])): ?><div class="field-error"><?php echo h($bulkFieldErrors[$sid]); ?></div><?php endif; ?>
                        </td>
                        <td>
                          <input class="input" name="bulk_teacher_comment[<?php echo $sid; ?>]" value="<?php echo h($bulkCommentValue); ?>" placeholder="optional">
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div style="margin-top:12px">
                <label class="muted">Änderungsvermerk für Sammelspeicherung</label>
                <input class="input" name="bulk_change_note" value="<?php echo h((string)($bulkValues['change_note'] ?? '')); ?>" placeholder="erforderlich, wenn bereits finale Beurteilungen geändert werden">
                <div class="muted" style="margin-top:6px;font-size:13px">Wird bei allen gespeicherten Datensätzen als Änderungsvermerk protokolliert, sofern angegeben.</div>
              </div>
              <div class="row" style="gap:10px;margin-top:14px">
                <button class="btn" onclick="return confirm('Alle ausgewählten Noten final für diese Klasse speichern?');">Alle final speichern</button>
              </div>
            </form>
          </div>
        </details>
      <?php endif; ?>

      <?php if($selectedRow): ?>
        <?php
          $periodMeta = $rowsData['period_meta'];
          $scope = (string)$periodMeta['scope'];
          $subjectContext = $rowsData['subject_context'];
          $classContext = $rowsData['class_context'];
          $isYearlyModel = (($classContext['value'] ?? null) === 'yearly');
          $subjectDisplay = '';
          foreach($subjects as $subjectRow){
            if((int)$subjectRow['id'] === $subject_id){
              $subjectDisplay = (string)$subjectRow['code'].' - '.(string)$subjectRow['name'];
              break;
            }
          }
          $classDisplay = '';
          foreach($classes as $classRow){
            if((int)$classRow['id'] === $class_id){
              $classDisplay = (string)$classRow['name'];
              break;
            }
          }
          $classArchiveContext = class_context($pdo,$class_id);
          $summary = $selectedRow['summary'];
          $proposal = $selectedRow['proposal'];
          $proposalWeighting = (array)($proposal['weighting'] ?? []);
          $proposalYearWeighting = (array)($proposal['year_weighting'] ?? []);
          $proposalAreas = (array)($proposal['areas'] ?? ($proposal['current_year_proposal']['areas'] ?? []));
          $firstSemesterProposal = (array)($proposal['first_semester_proposal'] ?? []);
          $currentYearProposal = (array)($proposal['current_year_proposal'] ?? []);
          $proposalAreaContextLabel = _fa_proposal_area_context_label($scope, $isYearlyModel);
          $proposalBasisIntro = _fa_proposal_basis_intro($scope, $isYearlyModel, $selectedAssessmentSystem);
          $proposalWarnings = array_values(array_unique(array_merge(
            (array)($proposalWeighting['warnings'] ?? []),
            (array)($proposalYearWeighting['warnings'] ?? [])
          )));
          $proposalPriorityWarnings = array_values(array_filter($proposalWarnings, static function(string $warning): bool {
            return str_contains($warning, 'dürfen nicht alleinige Grundlage')
              || str_contains($warning, 'Schulnachricht / 1. Semester konnte nicht berücksichtigt')
              || str_contains($warning, 'aktuelle Leistungsstand konnte nicht berücksichtigt')
              || str_contains($warning, '0 % Gewicht');
          }));
          $weightManageQuery = http_build_query([
            'school_period_set_id' => $school_period_set_id,
            'class_id' => $class_id,
            'subject_id' => $subject_id,
            'open_weights' => 1,
          ]);
          $existing = $selectedRow['existing'];
          $semesterContext = $selectedRow['semester_context'] ?? [];
          $yearTrend = $selectedRow['year_trend'] ?? [];
          $formId = 'fa-single-form-'.(int)$selectedRow['student_id'];
          $gradeValue = $existing['final_grade'] ?? null;
          $statusTone = $existing ? ((string)$existing['status'] === 'final' ? 'positive' : 'neutral') : 'neutral';
          $showPostedValues = $postedValues && (int)$postedValues['student_id'] === (int)$selectedRow['student_id'];
          $formGradeValue = $showPostedValues ? $postedValues['final_grade'] : $gradeValue;
          $formTeacherComment = $showPostedValues ? (string)$postedValues['teacher_comment'] : (string)($existing['teacher_comment'] ?? '');
          $formChangeNote = $showPostedValues ? (string)$postedValues['change_note'] : '';
          $lastChangeNote = trim((string)($existing['last_change_note'] ?? ''));
          $schularbeitCompactNote = 'Bitte Facheinstellung prüfen.';
          if(($subjectContext['status'] ?? 'unset') === 'yes') $schularbeitCompactNote = 'Schularbeitsleistungen gesondert berücksichtigen.';
          elseif(($subjectContext['status'] ?? 'unset') === 'no') $schularbeitCompactNote = 'Kein Schularbeitsfach.';
          $writtenAssessmentDisplay = _fa_written_assessment_display((array)($summary['written_rows'] ?? []));
          $oralAssessmentDisplay = _fa_oral_assessment_display((array)($summary['oral_rows'] ?? []));
          $writtenGradeTone = _fa_avg_grade_tone($summary['written_avg'] ?? null);
          $oralGradeTone = _fa_avg_grade_tone($summary['oral_avg'] ?? null);
          $participationCount = (int)($summary['participation_count'] ?? 0);
          $lowParticipationCount = $participationCount < 3;
          $sem1ForCurrent = null;
          $sem2ForCurrent = null;
          if($scope === 'semester1'){
            $sem1ForCurrent = $existing;
          } else {
            $sem1ForCurrent = $semesterContext['semester1_saved'] ?? null;
          }
          if($scope === 'year'){
            $sem2ForCurrent = $isYearlyModel ? null : ($semesterContext['semester2_saved'] ?? null);
          }
          $sem1GradeDisplay = ($sem1ForCurrent && $sem1ForCurrent['final_grade'] !== null) ? (int)$sem1ForCurrent['final_grade'] : null;
          $participationProposalArea = (array)($proposalAreas['participation'] ?? []);
          $oralProposalArea = (array)($proposalAreas['oral'] ?? []);
          $writtenProposalArea = (array)($proposalAreas['written'] ?? []);
          $proposalAreaDisplay = static function(array $area): string {
            return !empty($area['available']) ? assessment_weight_grade_format((float)$area['value']) : '–';
          };
          $proposalAreaBasisDisplay = static function(array $area): string {
            return trim((string)($area['basis'] ?? '')) !== '' ? (string)$area['basis'] : 'keine verwertbaren Daten';
          };
        ?>

        <div class="report-focus-block final-context-compact" style="margin-top:14px">
          <div class="final-context-strip">
            <span><strong><?php echo h($classDisplay ?: ('#'.$class_id)); ?></strong></span>
            <span><?php echo h($subjectDisplay ?: ('#'.$subject_id)); ?></span>
            <span><?php echo h((string)$periodMeta['assessment_label']); ?></span>
            <span><?php echo h((string)$classContext['label']); ?></span>
            <?php if($classArchiveContext && class_is_readonly($classArchiveContext)): ?><span>Vorjahresdaten / Archiv</span><?php endif; ?>
            <span>Schularbeitsfach: <?php echo h((string)$subjectContext['status_label']); ?></span>
          </div>
          <details class="accordion final-context-details" style="margin-top:10px">
            <summary><span class="acc-title">Beurteilungssystem und Fachstatus erklären</span></summary>
            <div class="acc-body">
              <div class="muted"><?php echo h((string)$classContext['note']); ?></div>
              <div class="muted" style="margin-top:8px"><?php echo h($schularbeitCompactNote); ?></div>
            </div>
          </details>
        </div>

        <div class="report-focus-block final-student-context-card" style="margin-top:16px">
          <div class="final-assessment-student-nav">
            <div class="final-assessment-student-picker">
              <?php if($previousRow): ?>
                <a class="btn secondary final-assessment-arrow" href="<?php echo h($bp); ?>/teacher/final_assessments.php<?php echo h(_fa_query($stateQuery, ['student_id' => (int)$previousRow['student_id']])); ?>" title="Vorherige Schüler:in">←</a>
              <?php else: ?>
                <span class="btn secondary final-assessment-arrow is-disabled" aria-disabled="true" title="Keine vorherige Schüler:in">←</span>
              <?php endif; ?>

              <form method="get" class="final-assessment-jump" <?php echo teacher_assignment_guard_attrs($u); ?>>
                <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
                <input type="hidden" name="school_period_set_id" value="<?php echo (int)$school_period_set_id; ?>">
                <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
                <label class="muted">Schüler:in</label>
                <select class="input" name="student_id" onchange="this.form.submit()">
                  <?php foreach($rowsData['rows'] as $row): ?>
                    <option value="<?php echo (int)$row['student_id']; ?>" <?php echo (int)$row['student_id'] === $selected_student_id ? 'selected' : ''; ?>><?php echo h($row['student_name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <noscript><button class="btn secondary">Öffnen</button></noscript>
              </form>

              <?php if($nextRow): ?>
                <a class="btn secondary final-assessment-arrow" href="<?php echo h($bp); ?>/teacher/final_assessments.php<?php echo h(_fa_query($stateQuery, ['student_id' => (int)$nextRow['student_id']])); ?>" title="Nächste Schüler:in">→</a>
              <?php else: ?>
                <span class="btn secondary final-assessment-arrow is-disabled" aria-disabled="true" title="Keine nächste Schüler:in">→</span>
              <?php endif; ?>
            </div>
            <div class="muted"><?php echo ((int)$selectedIndex + 1); ?> von <?php echo $studentCount; ?> Schüler:innen</div>
          </div>
          <div class="final-student-status-row" style="margin-top:12px">
            <span class="report-chip <?php echo h($summary['data_basis']['tone']); ?>"><?php echo h(report_eval_data_basis_display($summary['data_basis'])); ?></span>
            <span class="report-chip <?php echo h($statusTone); ?>"><?php echo h($existing ? final_assessment_status_label((string)$existing['status']) : 'noch nicht gespeichert'); ?></span>
            <span class="report-chip <?php echo h($subjectContext['tone']); ?>">Schularbeitsfach: <?php echo h((string)$subjectContext['status_label']); ?></span>
            <?php if($existing && $existing['final_grade'] !== null): ?>
              <span class="report-chip <?php echo h(_fa_grade_tone((int)$existing['final_grade'])); ?>"><?php echo h(final_assessment_grade_label((int)$existing['final_grade'])); ?></span>
            <?php endif; ?>
            <span class="muted">Einzelbeurteilung · <?php echo h($schularbeitCompactNote); ?></span>
          </div>
          <?php if($assignmentReadOnly): ?><div class="notice" style="margin-top:12px">Historische Zuweisung: Die vorhandenen Daten und Beurteilungen sind nur lesbar. Neue oder geänderte Abschlussbeurteilungen sind nicht möglich.</div><?php endif; ?>
        </div>

        <div class="grid final-assessment-box-grid" style="margin-top:14px">
          <div class="col-12">
            <div class="report-focus-block final-assessment-section final-section-semester">
              <div class="final-section-topline">
                <strong>1. <?php echo $isYearlyModel ? 'Überblick Schulnachricht' : ($scope === 'year' ? 'Semesterüberblick' : 'Überblick 1. Semester'); ?></strong>
                <div class="final-section-result final-grade-card">
                  <span class="label">gespeicherte Note</span>
                  <strong class="final-signal-value <?php echo h(_fa_grade_tone($sem1GradeDisplay)); ?>"><?php echo $sem1GradeDisplay !== null ? h(final_assessment_grade_label($sem1GradeDisplay)) : '–'; ?></strong>
                </div>
              </div>
              <?php if($sem1ForCurrent): ?>
                <div class="report-kv" style="margin-top:10px">
                  <div class="item"><span class="label">Status</span><strong><?php echo h(final_assessment_status_label((string)$sem1ForCurrent['status'])); ?></strong></div>
                  <div class="item"><span class="label">Notenvorschlag zum Speicherzeitpunkt</span><strong><?php echo h((string)($sem1ForCurrent['suggestion_label'] ?? '')); ?></strong></div>
                  <div class="item"><span class="label">gespeichert am</span><strong><?php echo h((string)($sem1ForCurrent['updated_at'] ?? $sem1ForCurrent['created_at'] ?? '')); ?></strong></div>
                </div>
                <?php if(trim((string)($sem1ForCurrent['teacher_comment'] ?? '')) !== ''): ?>
                  <div class="muted" style="margin-top:10px"><strong>Kommentar:</strong> <?php echo h((string)$sem1ForCurrent['teacher_comment']); ?></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="muted" style="margin-top:10px"><?php echo h($isYearlyModel ? 'Keine gespeicherte Schulnachricht vorhanden.' : 'Keine gespeicherte 1.-Semesterbeurteilung vorhanden.'); ?></div>
              <?php endif; ?>

              <?php if($scope === 'year' && !$isYearlyModel): ?>
                <div style="height:8px"></div>
                <?php if($sem2ForCurrent): ?>
                  <?php $sem2GradeDisplay = $sem2ForCurrent['final_grade'] !== null ? (int)$sem2ForCurrent['final_grade'] : null; ?>
                  <div class="muted"><strong>2. Semester:</strong> <span class="final-inline-grade <?php echo h(_fa_grade_tone($sem2GradeDisplay)); ?>"><?php echo h(final_assessment_grade_label($sem2GradeDisplay)); ?></span> (<?php echo h(final_assessment_status_label((string)$sem2ForCurrent['status'])); ?>)</div>
                <?php else: ?>
                  <div class="muted">Keine gespeicherte 2.-Semesterbeurteilung vorhanden.</div>
                <?php endif; ?>
                <?php if(!empty($yearTrend['label'])): ?>
                  <div class="muted" style="margin-top:8px"><strong>Entwicklung:</strong> <?php echo h((string)$yearTrend['label']); ?> - <?php echo h((string)$yearTrend['explanation']); ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="col-12">
            <section class="report-focus-block final-assessment-section final-performance-group">
              <h2 class="final-section-heading final-section-performance-heading">2. Besondere Leistungsfeststellungen</h2>
              <div class="final-performance-cards">
                <div class="final-performance-card report-oral">
                  <div class="final-performance-card-head">
                    <div>
                      <strong>Besondere mündliche Leistungen</strong>
                      <div class="muted small"><?php echo (int)$summary['oral_graded_count']; ?> benotete Eintrag/Einträge</div>
                    </div>
                    <div class="final-performance-result final-grade-card">
                      <span class="label">Noten</span>
                      <strong class="final-signal-value <?php echo h($oralGradeTone); ?>"><?php echo h($oralAssessmentDisplay['grades']); ?></strong>
                    </div>
                  </div>
                  <div class="final-performance-meta">
                    <span><strong>Ø:</strong> <?php echo $summary['oral_avg'] !== null ? h(number_format((float)$summary['oral_avg'], 2, ',', '.')) : '-'; ?></span>
                    <span><strong>Leistungsarten:</strong> <?php echo h($oralAssessmentDisplay['types']); ?></span>
                  </div>
                  <div class="muted final-performance-details"><strong>Details:</strong> <?php echo h($summary['oral_graded_count'] > 0 ? $oralAssessmentDisplay['details'] : 'Keine benoteten besonderen mündlichen Leistungen erfasst.'); ?></div>
                  <?php if($oralAssessmentDisplay['legacy_note'] !== ''): ?>
                    <div class="muted small final-performance-legacy-note"><?php echo h($oralAssessmentDisplay['legacy_note']); ?></div>
                  <?php endif; ?>
                </div>

                <div class="final-performance-card report-written">
                  <div class="final-performance-card-head">
                    <div>
                      <strong>Besondere schriftliche Leistungen</strong>
                      <div class="muted small"><?php echo (int)$summary['written_count']; ?> Eintrag/Einträge</div>
                    </div>
                    <div class="final-performance-result final-grade-card">
                      <span class="label">Noten</span>
                      <strong class="final-signal-value <?php echo h($writtenGradeTone); ?>"><?php echo h($writtenAssessmentDisplay['grades']); ?></strong>
                    </div>
                  </div>
                  <div class="final-performance-meta">
                    <span><strong>Ø:</strong> <?php echo $summary['written_avg'] !== null ? h(number_format((float)$summary['written_avg'], 2, ',', '.')) : '-'; ?></span>
                    <span><strong>Leistungsarten:</strong> <?php echo h($writtenAssessmentDisplay['types']); ?></span>
                  </div>
                  <div class="muted final-performance-details"><strong>Details:</strong> <?php echo h($summary['written_count'] > 0 ? $writtenAssessmentDisplay['details'] : 'Keine besonderen schriftlichen Leistungen erfasst.'); ?></div>
                </div>
              </div>
            </section>
          </div>

          <div class="col-12">
            <div class="report-focus-block final-assessment-section final-section-participation">
              <div class="final-section-topline">
                <strong>3. Mitarbeit</strong>
                <div class="final-section-result final-trend-card">
                  <span class="label">Tendenz</span>
                  <strong class="final-signal-value <?php echo h($summary['quality']['tone']); ?>"><?php echo h($summary['quality']['label']); ?></strong>
                  <?php if($lowParticipationCount): ?><span class="final-low-data-badge">zu wenige Einträge</span><?php endif; ?>
                </div>
              </div>
              <div class="report-kv" style="margin-top:10px">
                <div class="item"><span class="label">Mitarbeitseinträge</span><strong><?php echo $participationCount; ?></strong></div>
                <div class="item"><span class="label">dokumentierte Tage</span><strong><?php echo (int)$summary['documented_day_count']; ?></strong></div>
                <div class="item final-count-item final-count-positive"><span class="label">positiv</span><strong><?php echo (int)$summary['positive_count']; ?></strong></div>
                <div class="item final-count-item final-count-neutral"><span class="label">neutral</span><strong><?php echo (int)$summary['neutral_count']; ?></strong></div>
                <div class="item final-count-item final-count-negative"><span class="label">negativ</span><strong><?php echo (int)$summary['negative_count']; ?></strong></div>
              </div>
              <div class="muted" style="margin-top:10px"><strong>Datenbasis:</strong> <?php echo h(report_eval_data_basis_level_label($summary['data_basis'])); ?> - <?php echo h((string)$summary['data_basis']['explanation']); ?></div>
              <div class="muted" style="margin-top:8px"><strong>Wichtige Kriterien:</strong> <?php echo h($summary['top_criteria']); ?></div>
              <div class="muted" style="margin-top:8px"><strong>Kommentare / Auffälligkeiten:</strong> <?php echo h($summary['comments_text']); ?></div>
            </div>
          </div>
        </div>

        <div class="report-focus-block report-recommendation final-assessment-decision final-assessment-section final-section-decision" style="margin-top:16px">
          <div class="final-section-topline final-decision-topline">
            <strong>4. Finale Abschlussbeurteilung festlegen</strong>
            <div class="final-section-result final-proposal-result <?php echo h((string)$proposal['tone']); ?>">
              <span class="label">Notenvorschlag der App</span>
              <strong class="final-signal-value"><?php echo h((string)$proposal['label']); ?></strong>
            </div>
          </div>
          <div class="final-decision-context" style="margin-top:10px">
            <span><strong><?php echo h($selectedRow['student_name']); ?></strong></span>
            <span><?php echo h($subjectDisplay ?: ('Fach #'.$subject_id)); ?></span>
            <span><?php echo h((string)$periodMeta['assessment_label']); ?></span>
          </div>
          <div class="muted" style="margin-top:8px">
            Die drei Leistungsbereiche werden als Entscheidungsgrundlage zusammengeführt. Die finale Beurteilung legen Sie als Lehrkraft fest.
          </div>
          <div class="final-proposal-logic-note" style="margin-top:10px">
            <strong>Wie der Vorschlag zu lesen ist:</strong> <?php echo h($proposalBasisIntro); ?>
            <?php if($scope === 'year' && $isYearlyModel): ?>
              <div class="final-proposal-part-row">
                <span>Schulnachricht / 1. Semester: <strong><?php echo h($sem1GradeDisplay !== null ? final_assessment_grade_label($sem1GradeDisplay) : _fa_proposal_part_display($firstSemesterProposal)); ?></strong></span>
                <span>aktueller Leistungsstand: <strong><?php echo h(_fa_proposal_part_display($currentYearProposal)); ?></strong></span>
                <?php if(!empty($proposalYearWeighting['effective_label'])): ?><span>Jahresgewichtung: <strong><?php echo h((string)$proposalYearWeighting['effective_label']); ?></strong></span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if(!empty($proposalWeighting['effective_label']) || isset($proposal['calculated_value'])): ?>
              <div class="final-proposal-part-row">
                <?php if(!empty($proposalWeighting['effective_label'])): ?>
                  <span>Orientierungsgewichtung: <strong><?php echo h((string)$proposalWeighting['effective_label']); ?></strong></span>
                <?php endif; ?>
                <?php if(isset($proposal['calculated_value']) && $proposal['calculated_value'] !== null): ?>
                  <span>Rechenwert: <strong><?php echo h(assessment_weight_grade_format((float)$proposal['calculated_value'])); ?></strong></span>
                <?php endif; ?>
                <span><a href="<?php echo h($bp); ?>/teacher/manage.php?<?php echo h($weightManageQuery); ?>">Gewichtung bearbeiten</a></span>
              </div>
            <?php endif; ?>
            <?php if(!empty($proposalWeighting['excluded'])): ?>
              <div class="small muted" style="margin-top:8px">
                Nicht berücksichtigt: <?php echo h(implode(', ', (array)$proposalWeighting['excluded'])); ?>, da dafür keine verwertbaren Daten vorhanden sind.
              </div>
            <?php endif; ?>
          </div>

          <div class="final-decision-evidence-grid" style="margin-top:12px">
            <div class="final-decision-evidence participation">
              <div class="final-decision-evidence-head"><span>Mitarbeit</span><strong><?php echo h($proposalAreaDisplay($participationProposalArea)); ?></strong></div>
              <div class="small"><strong><?php echo h($proposalAreaContextLabel); ?></strong></div>
              <div class="muted small"><?php echo h((string)$summary['quality']['label']); ?> · positiv <?php echo (int)$summary['positive_count']; ?> / neutral <?php echo (int)$summary['neutral_count']; ?> / negativ <?php echo (int)$summary['negative_count']; ?></div>
              <div class="muted small final-evidence-basis"><?php echo h($proposalAreaBasisDisplay($participationProposalArea)); ?></div>
            </div>
            <div class="final-decision-evidence oral">
              <div class="final-decision-evidence-head"><span>Bes. mündl. Leistungsfeststellung</span><strong><?php echo h($proposalAreaDisplay($oralProposalArea)); ?></strong></div>
              <div class="small"><strong><?php echo h($proposalAreaContextLabel); ?></strong></div>
              <div class="muted small">Noten im Überblick: <?php echo h($oralAssessmentDisplay['grades']); ?></div>
              <div class="muted small"><?php echo (int)$summary['oral_graded_count']; ?> Eintrag/Einträge · Ø <?php echo $summary['oral_avg'] !== null ? h(number_format((float)$summary['oral_avg'], 2, ',', '.')) : '–'; ?></div>
              <?php if($oralAssessmentDisplay['legacy_note'] !== ''): ?>
                <div class="muted small"><?php echo h($oralAssessmentDisplay['legacy_note']); ?></div>
              <?php endif; ?>
              <div class="muted small final-evidence-basis"><?php echo h($proposalAreaBasisDisplay($oralProposalArea)); ?></div>
            </div>
            <div class="final-decision-evidence written">
              <div class="final-decision-evidence-head"><span>Bes. schriftl. Leistungsfeststellung</span><strong><?php echo h($proposalAreaDisplay($writtenProposalArea)); ?></strong></div>
              <div class="small"><strong><?php echo h($proposalAreaContextLabel); ?></strong></div>
              <div class="muted small">Noten im Überblick: <?php echo h($writtenAssessmentDisplay['grades']); ?></div>
              <div class="muted small"><?php echo (int)$summary['written_count']; ?> Eintrag/Einträge · Ø <?php echo $summary['written_avg'] !== null ? h(number_format((float)$summary['written_avg'], 2, ',', '.')) : '–'; ?></div>
              <div class="muted small final-evidence-basis"><?php echo h($proposalAreaBasisDisplay($writtenProposalArea)); ?></div>
            </div>
          </div>

          <?php foreach($proposalPriorityWarnings as $proposalWarning): ?>
            <div class="final-weight-warning"><?php echo h((string)$proposalWarning); ?></div>
          <?php endforeach; ?>

          <?php if(legal_hints_enabled($u)): ?>
            <details class="accordion final-legal-hints" style="margin-top:12px">
              <summary><span class="acc-title">Gesetzeshinweise</span></summary>
              <div class="acc-body muted">
                <strong>LBV-Hinweis (§ 11 und § 14 LBV):</strong> Die abschließende Leistungsbeurteilung ist aus den vorgesehenen Formen der Leistungsfeststellung, unter Bedachtnahme auf Lehrplan und Unterrichtsstand, sachlich und gerecht zu gewinnen. Die Beurteilungsstufe wird von der Lehrkraft festgelegt.
              </div>
            </details>
          <?php endif; ?>
          <?php if($existing && (string)$existing['status'] === 'final'): ?>
            <div class="flash info" style="margin-top:12px">
              Diese Abschlussbeurteilung ist bereits final gespeichert. Änderungen sind möglich, werden aber mit Änderungsvermerk protokolliert.
            </div>
          <?php endif; ?>

          <?php if(!$assignmentReadOnly): ?><form method="post" id="<?php echo h($formId); ?>" class="final-assessment-decision-form" style="margin-top:14px" data-existing-final="<?php echo ($existing && (string)$existing['status'] === 'final') ? '1' : '0'; ?>">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
            <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
            <input type="hidden" name="school_period_set_id" value="<?php echo (int)$school_period_set_id; ?>">
            <input type="hidden" name="scope" value="<?php echo h($scope); ?>">
            <input type="hidden" name="student_id" value="<?php echo (int)$selectedRow['student_id']; ?>">

            <div class="grid">
              <div class="col-12 col-md-4">
                <label class="muted">finale Note der Lehrkraft</label>
                <select class="input" name="final_grade">
                  <option value="0">noch nicht festgelegt</option>
                  <?php foreach(final_assessment_grade_options() as $gradeOption => $gradeLabel): ?>
                    <option value="<?php echo (int)$gradeOption; ?>" <?php echo $formGradeValue === (int)$gradeOption ? 'selected' : ''; ?>><?php echo h($gradeLabel); ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if(!empty($fieldErrors['final_grade'])): ?>
                  <div class="flash error" style="margin-top:8px"><?php echo h($fieldErrors['final_grade']); ?></div>
                <?php endif; ?>
                <div class="small muted" style="margin-top:6px">Für einen Entwurf optional, für die finale Speicherung verpflichtend.</div>
              </div>
              <div class="col-12 col-md-8">
                <label class="muted">Begründung / Kommentar</label>
                <textarea class="input" name="teacher_comment" rows="4" placeholder="z. B. kurze Begründung der Gesamtentscheidung, besondere Entwicklung oder ergänzende fachliche Einordnung"><?php echo h($formTeacherComment); ?></textarea>
                <div class="small muted" style="margin-top:6px">Dient der pädagogischen Begründung der aktuellen Semester- oder Jahresbeurteilung und bleibt Teil dieser gespeicherten Beurteilung.</div>
              </div>
              <div class="col-12">
                <label class="muted"><?php echo ($existing && (string)$existing['status'] === 'final') ? 'Änderungsvermerk bei späterer Anpassung' : 'optional: Änderungsvermerk / Zusatzhinweis'; ?></label>
                <input class="input" type="text" name="change_note" value="<?php echo h($formChangeNote); ?>" placeholder="<?php echo ($existing && (string)$existing['status'] === 'final') ? 'bei Änderungen einer final gespeicherten Beurteilung bitte kurz begründen' : 'z. B. externe Schularbeitsleistung nachgetragen, Klassenkonferenz, ergänzende Rücksprache'; ?>">
                <div class="small muted" style="margin-top:6px">Dokumentiert, warum eine gespeicherte finale Beurteilung nachträglich geändert wurde oder welcher Zusatzhinweis zur Speicherung gehört. Bei Änderung einer finalen Note ist dieser Vermerk verpflichtend.</div>
                <?php if($lastChangeNote !== ''): ?>
                  <div class="muted" style="margin-top:6px"><strong>Letzter gespeicherter Änderungsvermerk:</strong> <?php echo h($lastChangeNote); ?></div>
                <?php endif; ?>
                <?php if(!empty($fieldErrors['change_note'])): ?>
                  <div class="flash error" style="margin-top:8px"><?php echo h($fieldErrors['change_note']); ?></div>
                <?php endif; ?>
              </div>
            </div>

            <div class="row" style="gap:10px;flex-wrap:wrap;margin-top:14px">
              <button class="btn secondary" name="save_mode" value="draft">Entwurf speichern und nächste:n öffnen</button>
              <button class="btn" name="save_mode" value="final">Finale Note speichern und nächste:n öffnen</button>
            </div>
          </form><?php endif; ?>
        </div>

        <?php if($existing): ?>
          <?php $historyRows = final_assessment_history($pdo, (int)$existing['id'], 5); ?>
          <?php if($historyRows): ?>
            <details class="accordion" style="margin-top:14px">
              <summary><span class="acc-title">Änderungsverlauf anzeigen</span></summary>
              <div class="acc-body">
                <?php foreach($historyRows as $history): ?>
                  <div class="report-focus-block" style="margin-bottom:10px">
                    <div><strong><?php echo h((string)$history['created_at']); ?></strong></div>
                    <div class="muted" style="margin-top:6px">
                      <?php echo h((string)$history['change_type']); ?>
                      <?php if(!empty($history['changed_by_username'])): ?> · <?php echo h((string)$history['changed_by_username']); ?><?php endif; ?>
                      <?php if(trim((string)($history['change_note'] ?? '')) !== ''): ?> · <?php echo h((string)$history['change_note']); ?><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function(){
  document.querySelectorAll('.final-assessment-decision-form[data-existing-final="1"]').forEach(function(form){
    form.addEventListener('submit', function(event){
      var submitter = event.submitter || document.activeElement;
      if(!submitter || submitter.getAttribute('name') !== 'save_mode' || submitter.value !== 'final') return;
      var ok = window.confirm('Diese Abschlussbeurteilung ist bereits final gespeichert. Änderung wirklich final speichern? Ein Änderungsvermerk ist erforderlich.');
      if(!ok) event.preventDefault();
    });
  });
})();
</script>
<?php render_footer(); ?>
