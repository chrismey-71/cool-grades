<?php
require_once __DIR__.'/lib/layout.php';
require_once __DIR__.'/lib/assessment_summaries.php';
require_once __DIR__.'/lib/oral_assessments.php';
require_once __DIR__.'/lib/report_evaluation.php';
require_once __DIR__.'/lib/final_assessments.php';
require_once __DIR__.'/lib/school_years.php';
$u=require_role('teacher');
$pdo=db();

$class_id=(int)($_GET['class_id']??0);
$subject_id=(int)($_GET['subject_id']??0);
$date_from=$_GET['from'] ?? '';
$date_to=$_GET['to'] ?? '';
$student_id=(int)($_GET['student_id']??0);
$period=(string)($_GET['period'] ?? 'current');
$resolvedPeriod=app_school_period_resolve($period,$date_from,$date_to);
$periodOptions=app_school_period_options();
if($period !== 'custom'){
  $date_from=(string)$resolvedPeriod['from'];
  $date_to=(string)$resolvedPeriod['to'];
}
$periodSchoolYearId=(int)($resolvedPeriod['id'] ?? 0);
if($periodSchoolYearId<=0 && preg_match('/^period_(\d+)_/', $period, $m)) $periodSchoolYearId=(int)$m[1];
if($periodSchoolYearId<=0) $periodSchoolYearId=school_year_current_id($pdo);
$reportAssessmentContext=report_eval_assessment_context_from_period($resolvedPeriod,$period);

$classes=load_teacher_classes($pdo,(int)$u['id'],$periodSchoolYearId,true,false);
$subjects=load_teacher_subjects($pdo,(int)$u['id'],$class_id);

// Print-friendly view (users can "Print to PDF" in the browser)
$view = (string)($_GET['view'] ?? '');
$is_print = ($view === 'print');

function _reports_qs_keep(array $add=[]): string {
  $q=$_GET;
  foreach($add as $k=>$v){ $q[$k]=$v; }
  return '?'.http_build_query($q);
}

function _reports_print_header(string $title): void {
  $bp = cfg()['base_path'] ?? '';
  echo '<!doctype html><html lang="de"><head>';
  echo '<meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>'.h($title).'</title>';
  echo '<link rel="stylesheet" href="'.h($bp).'/assets/styles.css?v='._asset_v('assets/styles.css').'">';
  echo '<link rel="stylesheet" href="'.h($bp).'/assets/app.css?v='._asset_v('assets/app.css').'">';
  echo '<style>';
  echo '@page{size:A4 portrait;margin:12mm;}';
  echo 'body{background:#fff;font-size:12px;line-height:1.45;color:#111827;}';
  echo '.topbar,.footer,.nav,.burger,.userpill{display:none !important;}';
  echo '.wrap{max-width:100% !important;}';
  echo '.card{box-shadow:none !important;border:0 !important;padding:0 !important;background:transparent !important;}';
  echo 'form,.btn,.flash{display:none !important;}';
  echo '.table th,.table td{font-size:10.5px !important;vertical-align:top;padding:6px 7px !important;}';
  echo '.muted{color:#444 !important;}';
  echo '.report-focus-block,.report-judgement,.report-kv .item{break-inside:avoid-page;border-color:#cfd6df !important;background:#fff !important;}';
  echo '.report-print-section{margin-top:16px;break-inside:avoid-page;}';
  echo '.report-print-short th,.report-print-short td{font-size:10px !important;}';
  echo '.report-print-short .short-note{color:#374151;font-size:10px;}';
  echo '.report-chip{border-color:#cfd6df !important;background:#f8fafc !important;color:#111827 !important;}';
  echo '.report-count-chip{display:inline-flex;padding:2px 5px;border-radius:999px;border:1px solid currentColor;font-size:9px;font-weight:800;margin:1px 2px 1px 0;}';
  echo '.report-count-positive{color:#0f5d35 !important;background:#e8f6ed !important;border-color:#0f5d35 !important;}';
  echo '.report-count-neutral{color:#4f5665 !important;background:#f2f4f7 !important;border-color:#9aa3b2 !important;}';
  echo '.report-count-negative{color:#a22f2f !important;background:#fdecec !important;border-color:#c75b5b !important;}';
  echo '</style>';
  echo '</head><body><div class="wrap">';
}

function _reports_print_footer(): void {
  echo '<script>window.addEventListener("load",function(){setTimeout(function(){try{window.print();}catch(e){}},150);});</script>';
  echo '</div></body></html>';
}

function _reports_period_label(array $resolvedPeriod, string $customFrom, string $customTo): string {
  if(($resolvedPeriod['period'] ?? '') === 'custom'){
    if($customFrom !== '' || $customTo !== ''){
      return 'Benutzerdefiniert: '.($customFrom !== '' ? $customFrom : '–').' bis '.($customTo !== '' ? $customTo : '–');
    }
    return 'Benutzerdefiniert';
  }
  return (string)($resolvedPeriod['label'] ?? 'Zeitraum');
}

function _reports_final_grade_label(?array $final): string {
  if(!$final) return 'noch nicht gespeichert';
  return final_assessment_grade_label($final['final_grade'] !== null ? (int)$final['final_grade'] : null);
}

function _reports_final_suggestion_label(?array $final): string {
  if(!$final) return '–';
  $label = trim((string)($final['suggestion_label'] ?? ''));
  return $label !== '' ? $label : '–';
}

if($is_print) _reports_print_header('Auswertungen – Druck');
else render_header('Auswertungen',$u);
?>
<div class="grid"><div class="col-12"><div class="card">
<h1>Auswertungen</h1>
<form method="get" class="row" style="align-items:end" <?php echo teacher_assignment_guard_attrs($u); ?>>
  <div><label class="muted">Klasse</label>
    <select class="input" name="class_id"><option value="0">–</option>
      <?php foreach($classes as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id===(int)$c['id']?'selected':''; ?>><?php echo h($c['name'].(class_is_readonly($c)?' · Archiv':'')); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div><label class="muted">Fach</label>
    <select class="input" name="subject_id"><option value="0">–</option>
      <?php foreach($subjects as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $subject_id===(int)$s['id']?'selected':''; ?>><?php echo h($s['code']); ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="settings-panel" style="min-width:440px;flex:1 1 440px;padding:12px">
    <div class="settings-panel-title">Zeitraum / von / bis</div>
    <div style="display:grid;grid-template-columns:minmax(180px,1.25fr) minmax(130px,1fr) minmax(130px,1fr);gap:12px;align-items:end">
      <div>
        <label class="muted">Zeitraum</label>
        <select class="input" name="period" id="periodSelect">
          <option value="current" <?php echo $period==='current'?'selected':''; ?>>Aktueller Zeitraum</option>
          <?php foreach($periodOptions as $periodKey => $periodOption): ?>
            <option
              value="<?php echo h($periodKey); ?>"
              data-from="<?php echo h((string)$periodOption['from']); ?>"
              data-to="<?php echo h((string)$periodOption['to']); ?>"
              <?php echo $period===$periodKey?'selected':''; ?>
            ><?php echo h((string)$periodOption['label']); ?></option>
          <?php endforeach; ?>
          <option value="custom" <?php echo $period==='custom'?'selected':''; ?>>Benutzerdefiniert</option>
        </select>
      </div>
      <div>
        <label class="muted">von</label>
        <input class="input" type="date" name="from" value="<?php echo h($date_from); ?>">
      </div>
      <div>
        <label class="muted">bis</label>
        <input class="input" type="date" name="to" value="<?php echo h($date_to); ?>">
      </div>
    </div>
  </div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Anzeigen</button></div>
  <?php if($class_id && $subject_id): ?>
    <?php
      $currentAssessmentSetId = (int)($reportAssessmentContext['school_period_set_id'] ?? 0);
      $currentAssessmentScope = (string)($reportAssessmentContext['scope'] ?? 'semester1');
    ?>
    <?php if($currentAssessmentSetId > 0): ?>
      <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><a class="btn secondary" href="<?php echo h((cfg()['base_path'] ?? '').'/teacher/final_assessments.php?'.http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'school_period_set_id'=>$currentAssessmentSetId,'scope'=>$currentAssessmentScope])); ?>">Zur Abschlussbeurteilung</a></div>
    <?php endif; ?>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><a class="btn" href="<?php echo h(_reports_qs_keep(['view'=>'print'])); ?>" target="_blank" rel="noopener">Druckansicht öffnen</a></div>
    <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><a class="btn secondary" href="<?php echo h((cfg()['base_path'] ?? '').'/reports_pdf.php'._reports_qs_keep()); ?>">PDF-Datei herunterladen</a></div>
  <?php endif; ?>
</form>

<?php if($class_id && $subject_id): ?>
<?php
  require_teacher_assignment($u,$class_id,$subject_id);
  $students=load_class_students($pdo,$class_id,false);
?>

<form method="get" class="row" style="align-items:end;margin-top:10px">
  <input type="hidden" name="class_id" value="<?php echo (int)$class_id; ?>">
  <input type="hidden" name="subject_id" value="<?php echo (int)$subject_id; ?>">
  <input type="hidden" name="period" value="<?php echo h($period); ?>">
  <input type="hidden" name="from" value="<?php echo h($date_from); ?>">
  <input type="hidden" name="to" value="<?php echo h($date_to); ?>">
  <div><label class="muted">Schüler:in</label>
    <select class="input" name="student_id">
      <option value="0">– alle –</option>
      <?php foreach($students as $s): ?>
        <option value="<?php echo (int)$s['id']; ?>" <?php echo $student_id===(int)$s['id']?'selected':''; ?>>
          <?php echo h($s['last_name'].', '.$s['first_name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="flex:0 0 auto"><label class="muted">&nbsp;</label><button class="btn secondary">Filter anwenden</button></div>
</form>

<?php
  $className=''; foreach($classes as $c){ if((int)$c['id']===$class_id){ $className=$c['name']; break; } }
  $subjCode=''; foreach($subjects as $s){ if((int)$s['id']===$subject_id){ $subjCode=$s['code']; break; } }
  $studName='';
  if($student_id){
    foreach($students as $s){ if((int)$s['id']===$student_id){ $studName=$s['last_name'].', '.$s['first_name']; break; } }
  }
  $subjectContext = report_eval_subject_context($pdo, $subject_id);
  $subjectDisplay = trim((string)$subjectContext['code'].(((string)$subjectContext['name']) !== '' ? ' – '.(string)$subjectContext['name'] : ''));
?>
<div class="muted" style="margin-top:10px">
  <b>Filter:</b>
  <?php echo h($className ?: ('#'.$class_id)); ?> · <?php echo h($subjCode ?: ('#'.$subject_id)); ?>
  <?php if($date_from || $date_to): ?> · Zeitraum: <?php echo h(_reports_period_label($resolvedPeriod,$date_from,$date_to)); ?> (<?php echo h($date_from ?: '–'); ?> bis <?php echo h($date_to ?: '–'); ?>)<?php endif; ?>
  <?php if($studName): ?> · Schüler:in: <?php echo h($studName); ?><?php endif; ?>
</div>

<?php
  $studentSummaries = report_build_student_summaries($pdo, $class_id, $subject_id, (string)$date_from, (string)$date_to);
  $finalAssessments = [];
  if((int)($reportAssessmentContext['school_period_set_id'] ?? 0) > 0){
    $finalAssessments = final_assessment_existing_map(
      $pdo,
      $class_id,
      $subject_id,
      (int)$reportAssessmentContext['school_period_set_id'],
      (string)($reportAssessmentContext['scope'] ?? 'semester1')
    );
  }
  foreach($studentSummaries as &$summaryRow){
    $summaryRow['final_assessment'] = $finalAssessments[(int)$summaryRow['student_id']] ?? null;
  }
  unset($summaryRow);
  if($student_id){
    $studentSummaries = array_values(array_filter($studentSummaries, static fn(array $row): bool => (int)$row['student_id'] === $student_id));
  }
  $selectedStudentSummary = $studentSummaries[0] ?? null;
  $hasSpecialAssessments = false;
  foreach($studentSummaries as $summaryRow){
    if(((int)$summaryRow['written_count']) > 0 || ((int)$summaryRow['oral_count']) > 0){
      $hasSpecialAssessments = true;
      break;
    }
  }
  $lbvLegalNote = report_eval_legal_note($hasSpecialAssessments);
?>

<div class="report-focus-block" style="margin-top:12px">
  <div>
    <strong>Fachstatus</strong>
    <div class="muted" style="margin-top:6px">
      Fach: <b><?php echo h($subjectDisplay !== '' ? $subjectDisplay : ('#'.$subject_id)); ?></b> ·
      Schularbeitsfach:
      <span class="report-chip <?php echo h($subjectContext['tone']); ?>"><?php echo h($subjectContext['status_label']); ?></span>
    </div>
    <div class="muted" style="margin-top:8px"><?php echo h($subjectContext['note']); ?></div>
  </div>
</div>

<?php if(!$is_print && legal_hints_enabled($u)): ?>
  <div class="report-focus-block" style="margin-top:12px">
    <div class="row" style="justify-content:space-between;align-items:flex-start;gap:12px">
      <div>
        <strong>LBV-orientierte Entscheidungshilfe</strong>
        <div class="muted" style="margin-top:6px">
          Die Auswertung unterstützt die Beurteilung auf Basis der erfassten Mitarbeit und besonderer Leistungsfeststellungen. Die endgültige Note wird nicht automatisch festgelegt, sondern bleibt eine pädagogische Entscheidung der Lehrkraft gemäß LBV.
        </div>
      </div>
      <div><a class="btn secondary small" href="https://www.jusline.at/gesetz/lbv" target="_blank" rel="noopener">LBV öffnen</a></div>
    </div>
  </div>
<?php endif; ?>

<?php if(!$is_print): ?>
  <details class="accordion" style="margin-top:12px">
    <summary><span class="acc-title">So entsteht der Notenvorschlag Mitarbeit</span></summary>
    <div class="acc-body">
      <div class="muted">
        Der Vorschlag berücksichtigt nur die dokumentierte Mitarbeit im gewählten Zeitraum: Anzahl der Einträge, Verteilung positiv/neutral/negativ, zeitliche Streuung sowie die zusammengefasste Eindruckstendenz.
        <br>Ab 3 dokumentierten Tagen oder 3 verwertbaren Einträgen ist eine erste Einschätzung möglich; ab 6 dokumentierten Tagen gilt die Datenbasis in der Regel als gut.
        <br>Schwellen für den Mitarbeitsvorschlag: sehr stark positiv = eher 1, überwiegend positiv = eher 2, gemischt = eher 3, kritisch = eher 4, deutlich kritisch = eher 5.
        <br>Besondere mündliche und schriftliche Leistungen werden getrennt ausgewiesen und nur als Entscheidungshilfe für das Gesamtbild herangezogen.
      </div>
    </div>
  </details>
<?php endif; ?>

<?php if($is_print): ?>
  <div class="report-focus-block" style="margin-top:12px">
    <div class="row" style="justify-content:space-between;align-items:flex-start;gap:12px">
      <div>
        <h2 style="margin:0 0 6px 0">Auswertung als Druck/PDF</h2>
        <div class="muted">Zusammenfassende Entscheidungshilfe pro Schüler:in mit Mitarbeit, besonderen Leistungsfeststellungen und transparentem Vorschlag.</div>
      </div>
      <div class="report-chip neutral">A4 · Druckansicht</div>
    </div>
    <div class="report-kv" style="margin-top:12px">
      <div class="item"><span class="label">Klasse</span><strong><?php echo h($className ?: ('#'.$class_id)); ?></strong></div>
      <div class="item"><span class="label">Fach</span><strong><?php echo h($subjectDisplay !== '' ? $subjectDisplay : ('#'.$subject_id)); ?></strong></div>
      <div class="item"><span class="label">Zeitraum</span><strong><?php echo h(($date_from ?: '–').' bis '.($date_to ?: '–')); ?></strong></div>
      <div class="item"><span class="label">Schüler:in</span><strong><?php echo h($studName ?: 'gesamte Klasse'); ?></strong></div>
      <div class="item"><span class="label">Schularbeitsfach</span><strong><?php echo h($subjectContext['status_label']); ?></strong></div>
    </div>
    <div class="muted" style="margin-top:10px"><?php echo h($subjectContext['short_note']); ?></div>
  </div>
<?php endif; ?>

<div style="height:14px"></div>
<h2>Zusammenfassende Auswertung pro Schüler:in</h2>
<div class="muted">
  Die Haupttabelle bündelt Mitarbeit, besondere mündliche und besondere schriftliche Leistungsfeststellungen zu einer kompakten Entscheidungsgrundlage. Der Notenvorschlag ist bewusst nur ein transparenter Hinweis und ersetzt keine pädagogische Endentscheidung.
</div>

<?php if(!$studentSummaries): ?>
  <div class="report-focus-block" style="margin-top:12px">
    <p class="muted">Für den gewählten Zeitraum liegen keine auswertbaren Schüler:innendaten vor.</p>
  </div>
<?php else: ?>
  <?php if($is_print): ?>
    <table class="table report-summary-table" style="margin-top:12px">
      <thead>
        <tr>
          <th>Schüler:in</th>
          <th>Mitarbeit</th>
          <th>Bes. mündlich</th>
          <th>Bes. schriftlich</th>
          <th>Vorschläge</th>
          <th>Endnote</th>
          <th>Hinweis</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($studentSummaries as $summary): ?>
          <?php
            $dataBasisLevel = (string)($summary['data_basis']['level'] ?? 'none');
            $rowClass = '';
            if($dataBasisLevel === 'none' || $dataBasisLevel === 'thin'){
              $rowClass = 'report-row-warning';
            } elseif(($summary['note_proposal']['tone'] ?? 'neutral') === 'critical'){
              $rowClass = 'report-row-critical';
            }
            $final = $summary['final_assessment'] ?? null;
            $finalGradeText = _reports_final_grade_label($final);
            $finalSuggestionText = _reports_final_suggestion_label($final);
            $finalStatusText = $final ? final_assessment_status_label((string)$final['status']) : 'offen';
          ?>
          <tr class="<?php echo h($rowClass); ?>">
            <td style="min-width:150px"><strong><?php echo h($summary['student_name']); ?></strong></td>
            <td>
              <?php echo (int)$summary['participation_count']; ?> Einträge · <?php echo (int)($summary['documented_day_count'] ?? count($summary['distinct_dates'])); ?> Tage<br>
              <span class="report-count-chip report-count-positive">pos <?php echo (int)$summary['positive_count']; ?></span>
              <span class="report-count-chip report-count-neutral">neutral <?php echo (int)$summary['neutral_count']; ?></span>
              <span class="report-count-chip report-count-negative">neg <?php echo (int)$summary['negative_count']; ?></span><br>
              <?php echo h($summary['quality']['short']); ?> · <?php echo h(report_eval_data_basis_level_label($summary['data_basis'])); ?>
              <?php if($summary['quality']['avg'] !== null): ?>
                <br><span class="muted">Ø <?php echo h(number_format((float)$summary['quality']['avg'], 2, ',', '.')); ?></span>
              <?php endif; ?>
            </td>
            <td><?php echo h($summary['oral_text']); ?></td>
            <td><?php echo h($summary['written_text']); ?></td>
            <td>
              Mitarbeit: <?php echo h($summary['note_proposal']['label']); ?><br>
              Abschluss: <?php echo h($finalSuggestionText); ?>
            </td>
            <td><?php echo h($finalGradeText); ?><br><span class="muted"><?php echo h($finalStatusText); ?></span></td>
            <td><?php echo h($summary['semester_hint']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="report-student-list" style="margin-top:12px">
      <?php foreach($studentSummaries as $summary): ?>
        <?php
          $dataBasisLevel = (string)($summary['data_basis']['level'] ?? 'none');
          $cardClass = '';
          if($dataBasisLevel === 'none' || $dataBasisLevel === 'thin') $cardClass = 'is-warning';
          elseif(($summary['note_proposal']['tone'] ?? 'neutral') === 'critical') $cardClass = 'is-critical';
          $final = $summary['final_assessment'] ?? null;
          $finalGradeText = _reports_final_grade_label($final);
          $finalSuggestionText = _reports_final_suggestion_label($final);
          $finalStatusText = $final ? final_assessment_status_label((string)$final['status']) : 'offen';
          $finalTone = ($final && (string)$final['status'] === 'final') ? 'positive' : 'neutral';
        ?>
        <details class="report-student-card report-student-row-card <?php echo h($cardClass); ?>" <?php echo $student_id && (int)$summary['student_id']===$student_id ? 'open' : ''; ?>>
          <summary class="report-student-row-summary">
            <span class="report-row-cell report-row-student">
              <strong><?php echo h($summary['student_name']); ?></strong>
              <span class="muted"><?php echo (int)$summary['participation_count']; ?> Einträge · <?php echo (int)($summary['documented_day_count'] ?? count($summary['distinct_dates'])); ?> Tage</span>
            </span>
            <span class="report-row-cell report-row-participation">
              <span class="report-row-label">Mitarbeit</span>
              <span>
                <span class="report-count-chip report-count-positive">pos <?php echo (int)$summary['positive_count']; ?></span>
                <span class="report-count-chip report-count-neutral">neutral <?php echo (int)$summary['neutral_count']; ?></span>
                <span class="report-count-chip report-count-negative">neg <?php echo (int)$summary['negative_count']; ?></span>
              </span>
              <span class="report-mini-note"><?php echo h($summary['quality']['short']); ?> · <?php echo h(report_eval_data_basis_level_label($summary['data_basis'])); ?></span>
            </span>
            <span class="report-row-cell">
              <span class="report-row-label">bes. mündlich</span>
              <strong><?php echo h($summary['oral_text']); ?></strong>
            </span>
            <span class="report-row-cell">
              <span class="report-row-label">bes. schriftlich</span>
              <strong><?php echo h($summary['written_text']); ?></strong>
            </span>
            <span class="report-row-cell">
              <span class="report-row-label">Vorschläge</span>
              <span class="report-chip <?php echo h($summary['note_proposal']['tone']); ?>">Mitarbeit: <?php echo h($summary['note_proposal']['label']); ?></span>
              <span class="report-mini-note">Abschluss: <?php echo h($finalSuggestionText); ?></span>
            </span>
            <span class="report-row-cell">
              <span class="report-row-label">Endnote</span>
              <span class="report-chip <?php echo h($finalTone); ?>"><?php echo h($finalGradeText); ?></span>
              <span class="report-mini-note"><?php echo h($finalStatusText); ?></span>
            </span>
          </summary>
          <div class="report-student-body">
            <div class="report-kv">
              <div class="item"><span class="label">Mitarbeit positiv / neutral / negativ</span><strong><?php echo h($summary['positive_neutral_negative']); ?></strong></div>
              <div class="item"><span class="label">Qualität</span><strong><?php echo h($summary['quality']['label']); ?></strong><?php if($summary['quality']['avg'] !== null): ?><div class="muted">Ø <?php echo h(number_format((float)$summary['quality']['avg'], 2, ',', '.')); ?></div><?php endif; ?></div>
              <div class="item"><span class="label">Wichtige Kriterien</span><strong><?php echo h($summary['top_criteria']); ?></strong></div>
              <div class="item"><span class="label">Besondere mündliche Leistungen</span><strong><?php echo h($summary['oral_text']); ?></strong></div>
              <div class="item"><span class="label">Besondere schriftliche Leistungen</span><strong><?php echo h($summary['written_text']); ?></strong></div>
              <div class="item"><span class="label">Notenvorschlag Mitarbeit</span><strong><?php echo h($summary['note_proposal']['label']); ?></strong><div class="muted"><?php echo h($summary['note_proposal']['explanation']); ?></div></div>
              <div class="item"><span class="label">Abschlussvorschlag / Endnote</span><strong><?php echo h($finalSuggestionText); ?> · <?php echo h($finalGradeText); ?></strong><div class="muted"><?php echo h($finalStatusText); ?></div></div>
              <div class="item"><span class="label">Kommentare / Auffälligkeiten</span><strong><?php echo h($summary['comments_text']); ?></strong></div>
            </div>
            <div class="muted" style="margin-top:10px"><strong>Entscheidungshilfe:</strong> <?php echo h($summary['semester_hint']); ?> · <?php echo h($summary['note_proposal']['explanation']); ?></div>
            <div class="report-inline-events">
              <strong>Mitarbeitseinträge – Kurzüberblick</strong>
              <?php if($summary['participation_details']): ?>
                <ul>
                  <?php foreach($summary['participation_details'] as $detail): ?>
                    <li><?php echo h($detail); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="muted" style="margin-top:6px">Keine Mitarbeitseinträge im gewählten Zeitraum.</div>
              <?php endif; ?>
            </div>
            <div style="margin-top:10px">
              <a class="btn secondary small" href="<?php echo h(_reports_qs_keep(['student_id'=>(int)$summary['student_id']])); ?>">Detailansicht öffnen</a>
            </div>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if($is_print): ?>
  <div class="report-focus-block" style="margin-top:12px">
    <div class="muted"><?php echo h($lbvLegalNote); ?></div>
  </div>
<?php endif; ?>

<?php if($student_id): ?>
<div style="height:12px"></div>
<h2>Mitarbeit – Dokumentation</h2>
<div class="muted">
  Diese Liste dient als nachvollziehbare Dokumentation von Beobachtungen. Sie legt keine Note fest.
  Für ein schüler:innenfreundliches Blatt bitte oben eine/n Schüler:in wählen und dann „Druckansicht öffnen“.
</div>

<div class="card" style="margin-top:12px">
  <b>LBV-Tags (a–e) – Legende</b>
  <div class="muted" style="margin-top:6px">
    <b>a</b> Eingebundene Leistung · <b>b</b> Sicherung/Hausuebung · <b>c</b> Erarbeitung neuer Lehrstoffe · <b>d</b> Erfassen/Verstehen · <b>e</b> Einordnen/Anwenden
  </div>
</div>

<?php
  $where="pe.class_id=? AND pe.subject_id=?";
  $params=[$class_id,$subject_id];

  if($student_id){ $where.=" AND pe.student_id=?"; $params[]=$student_id; }
  if($date_from){ $where.=" AND pe.event_date >= ?"; $params[]=$date_from; }
  if($date_to){ $where.=" AND pe.event_date <= ?"; $params[]=$date_to; }

  $sql="SELECT pe.*, st.last_name, st.first_name,
        ls.lesson_unit, ls.topic,
        so.label AS social_label, ph.label AS phase_label, hw.label AS hw_label
        FROM participation_events pe
        JOIN students st ON st.id=pe.student_id
        LEFT JOIN lesson_sessions ls ON ls.id=pe.lesson_id
        LEFT JOIN participation_options so ON so.id=pe.social_form_option_id
        LEFT JOIN participation_options ph ON ph.id=pe.phase_option_id
        LEFT JOIN participation_options hw ON hw.id=pe.homework_option_id
        WHERE $where
        ORDER BY pe.event_date DESC, pe.id DESC
        LIMIT 600";
  $st=$pdo->prepare($sql); $st->execute($params); $events=$st->fetchAll();

  // prefetch criteria + performance types
  $critMap=[]; $perfMap=[]; $lbvoManual=[]; $lbvoAuto=[];
  if($events){
    $ids=array_map(fn($e)=>(int)$e['id'],$events);
    $in="(".implode(",",array_fill(0,count($ids),"?")).")";
    $st=$pdo->prepare("SELECT pec.event_id, c.label, c.category
                       FROM participation_event_criteria pec
                       JOIN criteria c ON c.id=pec.criteria_id
                       WHERE pec.event_id IN $in
                       ORDER BY c.category,c.label");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){
      $critMap[(int)$r['event_id']][] = ($r['category']?($r['category'].': '):'').$r['label'];
    }
    $st=$pdo->prepare("SELECT peo.event_id, po.label
                       FROM participation_event_options peo
                       JOIN participation_options po ON po.id=peo.option_id
                       WHERE peo.event_id IN $in AND po.opt_type='observation_group'
                       ORDER BY po.sort, po.label");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){
      $eid=(int)$r['event_id'];
      if(!isset($critMap[$eid])) $critMap[$eid]=[];
      array_unshift($critMap[$eid], $r['label']);
    }
    // performance labels
    $st=$pdo->prepare("SELECT peo.event_id, po.label
                       FROM participation_event_options peo
                       JOIN participation_options po ON po.id=peo.option_id
                       WHERE peo.event_id IN $in AND po.opt_type='performance'
                       ORDER BY po.sort, po.label");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){
      $perfMap[(int)$r['event_id']][] = $r['label'];
    }

    // LBV tags (manual preferred)
    $st=$pdo->prepare("SELECT event_id, GROUP_CONCAT(tag ORDER BY tag SEPARATOR '') AS tags
                       FROM participation_event_lbvo
                       WHERE event_id IN $in AND source='manual'
                       GROUP BY event_id");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){ $lbvoManual[(int)$r['event_id']]=$r['tags']; }

    $st=$pdo->prepare("SELECT event_id, GROUP_CONCAT(tag ORDER BY tag SEPARATOR '') AS tags
                       FROM participation_event_lbvo
                       WHERE event_id IN $in AND source='auto'
                       GROUP BY event_id");
    $st->execute($ids);
    foreach($st->fetchAll() as $r){ $lbvoAuto[(int)$r['event_id']]=$r['tags']; }
  }
?>

<?php if($is_print): ?>
  <div class="report-focus-block report-print-section">
    <div class="muted" style="margin-bottom:8px">Kurzfassung der Mitarbeitseinträge mit Anlass, Eindruck, LBV-Zuordnung und kurzer Beobachtung.</div>
    <table class="table report-print-short">
      <thead><tr>
        <th>Datum</th>
        <?php if(!$student_id): ?><th>Schüler:in</th><?php endif; ?>
        <th>Anlass / Beobachtung</th>
        <th>Eindruck</th>
        <th>LBV</th>
      </tr></thead>
      <tbody>
      <?php foreach($events as $e): ?>
      <tr>
        <td><?php echo h($e['event_date']); ?></td>
        <?php if(!$student_id): ?><td><?php echo h($e['last_name'].', '.$e['first_name']); ?></td><?php endif; ?>
        <td>
          <strong><?php echo h($e['reason_label']); ?></strong>
          <?php
            $shortBits=[];
            if($e['reason_text']) $shortBits[] = h($e['reason_text']);
            elseif($e['note']) $shortBits[] = h((string)$e['note']);
            if($e['lesson_unit']) $shortBits[] = 'UE '.h($e['lesson_unit']);
            if($e['topic']) $shortBits[] = h((string)$e['topic']);
          ?>
          <?php if($shortBits): ?><div class="short-note"><?php echo implode(' · ', $shortBits); ?></div><?php endif; ?>
        </td>
        <td><?php echo h($e['rating']); ?></td>
        <td>
          <?php
            $eid=(int)$e['id'];
            $tags = $lbvoManual[$eid] ?? ($lbvoAuto[$eid] ?? '');
            if($tags==='') echo '<span class="muted">–</span>';
            else echo '<span style="font-family:ui-monospace,Menlo,monospace;letter-spacing:1px">'.h($tags).'</span>';
          ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <table class="table">
  <thead><tr>
    <th>Datum</th><th>Schüler:in</th><th>UE / Thema</th><th>Grund</th><th>Eindruck</th><th>LBV</th><th>Kontext</th><th>Kriterien</th><th>Notiz</th>
  </tr></thead>
  <tbody>
  <?php foreach($events as $e): ?>
  <tr>
    <td><?php echo h($e['event_date']); ?></td>
    <td><?php echo h($e['last_name'].', '.$e['first_name']); ?></td>
    <td class="muted" style="font-size:12px">
      <?php
        $lx=[];
        if($e['lesson_unit']) $lx[]='UE '.h($e['lesson_unit']);
        if($e['topic']) $lx[] = h($e['topic']);
        echo $lx ? implode('<br>', $lx) : '–';
      ?>
    </td>
    <td>
      <?php echo h($e['reason_label']); ?>
      <?php if($e['reason_text']): ?><div class="muted" style="font-size:12px"><?php echo h($e['reason_text']); ?></div><?php endif; ?>
    </td>
    <td><?php echo h($e['rating']); ?></td>
    <td>
      <?php
        $eid=(int)$e['id'];
        $tags = $lbvoManual[$eid] ?? ($lbvoAuto[$eid] ?? '');
        if($tags==='') echo '<span class="muted">–</span>';
        else echo '<span style="font-family:ui-monospace,Menlo,monospace;letter-spacing:1px">'.h($tags).'</span>';
      ?>
    </td>
    <td class="muted" style="font-size:12px">
      <?php
        $ctx=[];
        if(!empty($perfMap[(int)$e['id']])) $ctx[]='<b>Leistungsart:</b> '.h(implode(', ',$perfMap[(int)$e['id']]));
        if($e['social_label']) $ctx[]='<b>Sozialform:</b> '.h($e['social_label']);
        if($e['phase_label']) $ctx[]='<b>Phase:</b> '.h($e['phase_label']);
        if($e['hw_label']) $ctx[]='<b>Hausübung:</b> '.h($e['hw_label']);
        echo $ctx ? implode('<br>', $ctx) : '–';
      ?>
    </td>
    <td class="muted" style="font-size:12px">
      <?php echo !empty($critMap[(int)$e['id']]) ? h(implode('; ',$critMap[(int)$e['id']])) : '–'; ?>
    </td>
    <td><?php echo $e['note']?nl2br(h($e['note'])):'<span class="muted">–</span>'; ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
<?php endif; ?>

<div style="height:18px"></div>

<h2>Besondere schriftliche Leistungsfeststellungen</h2>
<div class="muted">Schriftliche Leistungsfeststellungen werden hier als Noten (1–5) dokumentiert und nach Art getrennt ausgewiesen.</div>

<?php
  // Exams (schriftliche Leistungsfeststellungen)
  $whereEx = "e.class_id=? AND e.subject_id=?";
  $paramsEx = [$class_id,$subject_id];
  if($date_from){ $whereEx .= " AND e.exam_date >= ?"; $paramsEx[] = $date_from; }
  if($date_to){ $whereEx .= " AND e.exam_date <= ?"; $paramsEx[] = $date_to; }

  $st=$pdo->prepare("SELECT e.* FROM exams e WHERE $whereEx ORDER BY e.exam_date DESC, e.id DESC");
  $st->execute($paramsEx);
  $exams=$st->fetchAll();

  $examIds=[];
  foreach($exams as $ex){ $examIds[]=(int)$ex['id']; }

  $gradesByStudent = []; // [student_id][exam_id]=grade
  if($examIds){
    $in='('.implode(',', array_fill(0,count($examIds),'?')).')';
    $st=$pdo->prepare("SELECT eg.exam_id, eg.student_id, eg.grade, eg.tendency, eg.remark FROM exam_grades eg WHERE eg.exam_id IN $in");
    $st->execute($examIds);
    foreach($st->fetchAll() as $r){
      $gradesByStudent[(int)$r['student_id']][(int)$r['exam_id']] = [
        'grade'=>(int)$r['grade'],
        'tendency'=>(string)($r['tendency'] ?? ''),
        'remark'=>(string)($r['remark'] ?? ''),
      ];
    }
  }

  $writtenTypeCounts=[];
  foreach($exams as $ex){
    $t=written_assessment_normalize_type((string)($ex['exam_type'] ?? 'SA'));
    $writtenTypeCounts[$t] = ($writtenTypeCounts[$t] ?? 0) + 1;
  }
?>

<?php if(!$exams): ?>
  <div class="report-focus-block report-written report-print-section" style="margin-top:10px">
    <p class="muted">Keine schriftlichen Leistungsfeststellungen im gewählten Zeitraum.</p>
  </div>
<?php else: ?>
  <div class="report-focus-block report-written report-print-section" style="margin-top:10px">
    <div class="card" style="margin-top:0">
      <b>Übersicht</b>
      <div class="muted" style="margin-top:6px">
        <?php
          $typeParts=[];
          foreach($writtenTypeCounts as $typeCode => $typeCount){
            $typeParts[] = written_assessment_type_label((string)$typeCode).': <b>'.(int)$typeCount.'</b>';
          }
          echo $typeParts ? implode(' · ', $typeParts) : '–';
        ?>
      </div>
    </div>

  <?php if($student_id): ?>
    <?php
      $studRow=null;
      foreach($students as $sx){ if((int)$sx['id']===$student_id){ $studRow=$sx; break; } }
    ?>
    <table class="table" style="margin-top:10px">
      <thead><tr><th>Datum</th><th>Art</th><th>Titel</th><th>Note</th><th>Tendenz</th><th>Bemerkung</th></tr></thead>
      <tbody>
        <?php foreach($exams as $ex):
          $eid=(int)$ex['id'];
          $t=written_assessment_normalize_type((string)($ex['exam_type'] ?? 'SA'));
          $tlabel = written_assessment_type_label($t);
          $g = $gradesByStudent[$student_id][$eid] ?? null;
        ?>
          <tr>
            <td><?php echo h($ex['exam_date']); ?></td>
            <td><?php echo h($tlabel); ?></td>
            <td><?php echo h($ex['title']); ?></td>
            <td><?php echo $g ? (int)($g['grade'] ?? 0) : '<span class="muted">–</span>'; ?></td>
            <td><?php $gt = normalize_exam_grade_tendency((string)($g['tendency'] ?? '')); echo ($g && $gt!=='') ? h($gt) : '<span class="muted">–</span>'; ?></td>
            <td><?php echo ($g && trim((string)($g['remark'] ?? ''))!=='') ? nl2br(h((string)$g['remark'])) : '<span class="muted">–</span>'; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <?php if($is_print): ?>
      <div class="muted" style="margin-top:10px">Kurzfassung: pro Leistungsfeststellung der bewertete Umfang und der durchschnittliche Notenwert.</div>
      <table class="table report-print-short" style="margin-top:10px">
        <thead><tr><th>Datum</th><th>Art</th><th>Titel</th><th>Bewertungen</th><th>Durchschnitt</th></tr></thead>
        <tbody>
        <?php foreach($exams as $ex):
          $eid=(int)$ex['id'];
          $t=written_assessment_normalize_type((string)($ex['exam_type'] ?? 'SA'));
          $tlabel = written_assessment_type_label($t);
          $gradeValues=[];
          foreach($gradesByStudent as $studentGrades){
            if(isset($studentGrades[$eid]['grade'])) $gradeValues[]=(int)$studentGrades[$eid]['grade'];
            }
            $gradeCount=count($gradeValues);
            $gradeAvg=$gradeCount ? number_format(array_sum($gradeValues)/$gradeCount,2,',','.') : '–';
          ?>
            <tr>
              <td><?php echo h($ex['exam_date']); ?></td>
              <td><?php echo h($tlabel); ?></td>
              <td><?php echo h($ex['title']); ?></td>
              <td><?php echo (int)$gradeCount; ?></td>
              <td><?php echo h((string)$gradeAvg); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="muted" style="margin-top:10px">Matrix: pro Schüler:in die Noten je schriftlicher Leistungsfeststellung.</div>
      <table class="table" style="margin-top:10px">
        <thead>
          <tr>
            <th>Schüler:in</th>
            <?php foreach($exams as $ex):
              $t=written_assessment_normalize_type((string)($ex['exam_type'] ?? 'SA'));
              $tlabel = written_assessment_type_short_label($t);
            ?>
              <th style="white-space:nowrap">
                <?php echo h($ex['exam_date']); ?><br>
                <span class="muted" style="font-size:11px"><?php echo h($tlabel); ?></span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($students as $stRow): $sid=(int)$stRow['id']; ?>
            <tr>
              <td><?php echo h($stRow['last_name'].', '.$stRow['first_name']); ?></td>
              <?php foreach($exams as $ex): $eid=(int)$ex['id']; $g=$gradesByStudent[$sid][$eid] ?? null; ?>
                <td style="text-align:center"><?php echo $g ? (int)($g['grade'] ?? 0) : '<span class="muted">–</span>'; ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>
  </div>
<?php endif; ?>

<div style="height:18px"></div>
<h2>Besondere mündliche Leistungsfeststellungen</h2>
<div class="muted">Mündliche Prüfungen und mündliche Übungen werden hier mit Eindruck/Relevanz sowie den erfassten Details ausgegeben und in der Druckansicht mit berücksichtigt.</div>

<?php
  $whereOral = "oa.class_id=? AND oa.subject_id=?";
  $paramsOral = [$class_id,$subject_id];
  if($student_id){ $whereOral .= " AND oa.student_id=?"; $paramsOral[] = $student_id; }
  if($date_from){ $whereOral .= " AND oa.assessment_date >= ?"; $paramsOral[] = $date_from; }
  if($date_to){ $whereOral .= " AND oa.assessment_date <= ?"; $paramsOral[] = $date_to; }

  $st=$pdo->prepare("SELECT oa.*, st.last_name, st.first_name
                     FROM oral_assessments oa
                     JOIN students st ON st.id=oa.student_id
                     WHERE $whereOral
                     ORDER BY oa.assessment_date DESC, oa.id DESC");
  $st->execute($paramsOral);
  $oralRows=$st->fetchAll();

  $cntOralExam=0; $cntOralExercise=0;
  foreach($oralRows as $row){
    $type=oral_assessment_normalize_type((string)($row['assessment_type'] ?? ''));
    if($type==='ORAL_EXERCISE') $cntOralExercise++;
    else $cntOralExam++;
  }
?>

<?php if(!$oralRows): ?>
  <div class="report-focus-block report-oral report-print-section" style="margin-top:10px">
    <p class="muted">Keine besonderen mündlichen Leistungsfeststellungen im gewählten Zeitraum.</p>
  </div>
<?php else: ?>
  <div class="report-focus-block report-oral report-print-section" style="margin-top:10px">
    <div class="card" style="margin-top:0">
      <b>Übersicht</b>
      <div class="muted" style="margin-top:6px">
        mündliche Prüfungen: <b><?php echo (int)$cntOralExam; ?></b> · mündliche Übungen: <b><?php echo (int)$cntOralExercise; ?></b>
      </div>
    </div>

  <table class="table" style="margin-top:10px">
    <thead><tr>
      <th>Datum</th>
      <th>Art</th>
      <?php if(!$student_id): ?><th>Schüler:in</th><?php endif; ?>
      <th>Eindruck/Relevanz</th>
      <th><?php echo $student_id ? 'Themengebiet / Kategorie' : 'Details'; ?></th>
      <th><?php echo $student_id ? 'Fragen / Thema' : 'Zusatz'; ?></th>
    </tr></thead>
    <tbody>
      <?php foreach($oralRows as $row):
        $type=oral_assessment_normalize_type((string)($row['assessment_type'] ?? ''));
        $isExam=$type==='ORAL_EXAM';
        $detailPrimary = $isExam ? trim((string)($row['topic_area'] ?? '')) : trim((string)($row['category'] ?? ''));
        $detailSecondary = $isExam ? trim((string)($row['questions'] ?? '')) : trim((string)($row['title'] ?? ''));
      ?>
        <tr>
          <td><?php echo h($row['assessment_date']); ?></td>
          <td><?php echo h(oral_assessment_type_label($type)); ?></td>
          <?php if(!$student_id): ?>
            <td><?php echo h($row['last_name'].', '.$row['first_name']); ?></td>
          <?php endif; ?>
          <td><?php echo h((string)($row['impact_label'] ?? '–')); ?></td>
          <td><?php echo $detailPrimary!=='' ? h($detailPrimary) : '<span class="muted">–</span>'; ?></td>
          <td><?php echo $detailSecondary!=='' ? nl2br(h($detailSecondary)) : '<span class="muted">–</span>'; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if($student_id): ?>
  <?php
    $tagCnt=['a'=>0,'b'=>0,'c'=>0,'d'=>0,'e'=>0];
    $impactLabels=[];
    $criteriaFreq=[];
    foreach($events as $e){
      $eid=(int)$e['id'];
      $tags = $lbvoManual[$eid] ?? ($lbvoAuto[$eid] ?? '');
      foreach(str_split((string)$tags) as $t){ if(isset($tagCnt[$t])) $tagCnt[$t]++; }
      $rating = trim((string)($e['rating'] ?? ''));
      if($rating !== '') $impactLabels[$rating] = ($impactLabels[$rating]??0)+1;
      if(!empty($critMap[$eid])){
        foreach($critMap[$eid] as $cl){ $criteriaFreq[$cl]=($criteriaFreq[$cl]??0)+1; }
      }
    }
    arsort($criteriaFreq);
  ?>

  <div class="report-focus-block report-recommendation report-print-section" style="margin-top:14px">
    <h2 style="margin-top:0">Kurz-Auswertung &amp; Empfehlung</h2>
    <div class="report-judgement" style="margin-top:6px">
      <span class="report-chip <?php echo h($selectedStudentSummary['quality']['tone']); ?>">
        Zusammengefasster Eindruck: <?php echo h($selectedStudentSummary['quality']['short']); ?>
      </span>
      <strong><?php echo h($selectedStudentSummary['quality']['label']); ?></strong>
      <span class="report-chip <?php echo h($selectedStudentSummary['note_proposal']['tone']); ?>">
        <?php echo h($selectedStudentSummary['note_proposal']['label']); ?>
      </span>
      <span class="report-chip <?php echo h($selectedStudentSummary['data_basis']['tone']); ?>">
        <?php echo h(report_eval_data_basis_display($selectedStudentSummary['data_basis'])); ?>
      </span>
    </div>
    <div class="row" style="gap:18px;align-items:flex-start">
      <div style="min-width:320px">
        <b>LBV-Verteilung (Anzahl)</b>
        <div class="muted" style="margin-top:6px">
          <?php echo 'a: '.(int)$tagCnt['a'].' · b: '.(int)$tagCnt['b'].' · c: '.(int)$tagCnt['c'].' · d: '.(int)$tagCnt['d'].' · e: '.(int)$tagCnt['e']; ?>
        </div>
        <div style="height:10px"></div>
        <b>Eindruck (Häufigkeit)</b>
        <div class="muted" style="margin-top:6px">
          <?php
            arsort($impactLabels);
            $tmp=[]; foreach($impactLabels as $lab=>$cnt){ $tmp[] = h($lab).': '.(int)$cnt; }
            echo $tmp ? implode(' · ', $tmp) : '–';
          ?>
        </div>
        <div style="height:10px"></div>
        <b>Leistungsfeststellungen</b>
        <div class="muted" style="margin-top:6px">
          <?php
            $writtenCount=0;
            foreach(($gradesByStudent[$student_id] ?? []) as $grade){
              if($grade !== null) $writtenCount++;
            }
            echo 'schriftlich: '.(int)$writtenCount.' · mündlich: '.(int)count($oralRows);
            if($oralRows){
              echo ' (Prüfungen: '.(int)$cntOralExam.' · Übungen: '.(int)$cntOralExercise.')';
            }
          ?>
        </div>
      </div>
      <div style="flex:1">
        <b>Empfehlung (aus den dokumentierten Beobachtungen)</b>
        <div class="muted" style="margin-top:8px;line-height:1.5">
          <?php
            echo 'Datenbasis: <b>'.h(report_eval_data_basis_level_label($selectedStudentSummary['data_basis'])).'</b>. ';
            echo h($selectedStudentSummary['note_proposal']['explanation']).'. ';
            echo 'Notenvorschlag Mitarbeit: <b>'.h($selectedStudentSummary['note_proposal']['label']).'</b>. ';
            echo 'Wichtige Kriterien: <b>'.h($selectedStudentSummary['top_criteria']).'</b>. ';
            echo 'Hinweis für die Semesterbeurteilung: <b>'.h($selectedStudentSummary['semester_hint']).'</b>.';
          ?>
        </div>
        <div class="muted" style="margin-top:8px;font-size:12px">
          Hinweis: Das ist eine <b>transparente Entscheidungshilfe</b> aus den erfassten Daten. Die endgültige Beurteilung trifft weiterhin die Lehrkraft.
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php else: ?>
<?php if(!$is_print): ?>
  <details class="accordion" style="margin-top:14px">
    <summary><span class="acc-title">Optional: Detailhinweise pro Schüler:in</span></summary>
    <div class="acc-body">
      <div class="muted" style="margin-bottom:10px">
        Für die schnelle Semesterentscheidung ist die Haupttabelle gedacht. Wenn du tiefer einsteigen willst, wähle oben gezielt eine Schüler:in aus oder nutze die Kurzdetails hier.
      </div>
      <?php foreach($studentSummaries as $summary): ?>
        <div class="card" style="margin-top:10px">
          <div class="row" style="justify-content:space-between;align-items:flex-start;gap:12px">
            <div>
              <strong><?php echo h($summary['student_name']); ?></strong>
              <div class="muted" style="margin-top:6px">
                <?php echo h(report_eval_data_basis_display($summary['data_basis'])); ?> · <?php echo h($summary['note_proposal']['explanation']); ?>
              </div>
            </div>
            <div>
              <a class="btn secondary small" href="<?php echo h(_reports_qs_keep(['student_id'=>(int)$summary['student_id']])); ?>">Detailansicht öffnen</a>
            </div>
          </div>
          <div class="muted" style="margin-top:10px">
            <b>Letzte Beobachtungen:</b>
            <?php echo $summary['participation_details'] ? h(implode(' | ', $summary['participation_details'])) : '–'; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
<?php endif; ?>

<?php endif; ?>

<?php else: ?><div style="height:12px" class="muted">Bitte Klasse und Fach wählen.</div><?php endif; ?>

</div></div></div>
<?php
if($is_print) _reports_print_footer();
else{
  ?>
  <script>
  (function(){
    const periodSelect=document.getElementById('periodSelect');
    if(!periodSelect) return;
    const fromField=document.querySelector('input[name="from"]');
    const toField=document.querySelector('input[name="to"]');
    function syncPeriodInputs(){
      const custom=periodSelect.value === 'custom';
      const selectedOption=periodSelect.options[periodSelect.selectedIndex];
      if(!custom && selectedOption){
        if(fromField && selectedOption.dataset.from){
          fromField.value=selectedOption.dataset.from;
        }
        if(toField && selectedOption.dataset.to){
          toField.value=selectedOption.dataset.to;
        }
      }
      if(fromField){
        fromField.readOnly=!custom;
        fromField.style.opacity=custom ? '1' : '.75';
      }
      if(toField){
        toField.readOnly=!custom;
        toField.style.opacity=custom ? '1' : '.75';
      }
    }
    periodSelect.addEventListener('change', syncPeriodInputs);
    syncPeriodInputs();
  })();
  </script>
  <?php
  render_footer();
}
?>
