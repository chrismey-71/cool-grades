<?php
require_once __DIR__.'/report_evaluation.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/assessment_summaries.php';
require_once __DIR__.'/assessment_systems.php';

function final_assessment_scope_options(): array {
  $options = [];
  foreach(final_assessment_scope_meta() as $value => $meta){
    $options[$value] = (string)$meta['label'];
  }
  return $options;
}

function final_assessment_scope_label(string $scope): string {
  return final_assessment_scope_options()[$scope] ?? 'Abschlussbeurteilung';
}

function final_assessment_scope_help(string $scope): string {
  $meta = final_assessment_scope_meta();
  if(isset($meta[$scope])) return (string)$meta[$scope]['help'];
  return 'Wählen Sie zuerst, welche Abschlussbeurteilung Sie bearbeiten möchten. Die App unterstützt die pädagogische Entscheidung, legt aber keine Note automatisch fest.';
}

function final_assessment_default_scope(?array $periodSet = null, ?string $date = null): string {
  $date = $date ?: date('Y-m-d');
  if($periodSet){
    $semester1From = (string)($periodSet['semester1_from'] ?? '');
    $semester1To = (string)($periodSet['semester1_to'] ?? '');
    $semester2From = (string)($periodSet['semester2_from'] ?? '');
    $semester2To = (string)($periodSet['semester2_to'] ?? '');
    if($semester1From !== '' && $semester1To !== '' && $date >= $semester1From && $date <= $semester1To){
      return 'semester1';
    }
    if($semester2From !== '' && $semester2To !== '' && $date >= $semester2From && $date <= $semester2To){
      return 'semester2';
    }
  }

  $month = (int)date('n', strtotime($date) ?: time());
  return ($month >= 9 || $month === 1) ? 'semester1' : 'semester2';
}

function final_assessment_default_period_set_id(array $periodSets, ?string $date = null): int {
  if(!$periodSets) return 0;
  foreach($periodSets as $periodSet){
    if((int)($periodSet['is_current'] ?? 0) === 1){
      return (int)($periodSet['id'] ?? 0);
    }
  }
  $date = $date ?: date('Y-m-d');
  foreach($periodSets as $periodSet){
    $from = (string)($periodSet['semester1_from'] ?? '');
    $to = (string)($periodSet['semester2_to'] ?? '');
    if($from !== '' && $to !== '' && $date >= $from && $date <= $to){
      return (int)($periodSet['id'] ?? 0);
    }
  }

  return (int)($periodSets[0]['id'] ?? 0);
}

function final_assessment_next_student_id(array $rows, int $studentId): ?int {
  $ids = array_map(static fn(array $row): int => (int)$row['student_id'], $rows);
  $idx = array_search($studentId, $ids, true);
  if($idx === false) return null;
  $nextIdx = (int)$idx + 1;
  return isset($ids[$nextIdx]) ? (int)$ids[$nextIdx] : null;
}

function final_assessment_grade_options(): array {
  return [
    1 => 'Sehr gut (1)',
    2 => 'Gut (2)',
    3 => 'Befriedigend (3)',
    4 => 'Genügend (4)',
    5 => 'Nicht genügend (5)',
  ];
}

function final_assessment_grade_label(?int $grade): string {
  if($grade === null || $grade <= 0) return 'noch nicht festgelegt';
  return final_assessment_grade_options()[$grade] ?? ((string)$grade);
}

function final_assessment_status_label(string $status): string {
  return $status === 'final' ? 'final gespeichert' : 'Entwurf';
}

function final_assessment_period_meta(array $periodSet, string $scope): array {
  $scope = isset(final_assessment_scope_options()[$scope]) ? $scope : 'semester1';
  $schoolYearLabel = trim((string)($periodSet['label'] ?? ''));
  if($schoolYearLabel === ''){
    $schoolYearLabel = school_period_year_label((string)$periodSet['semester1_from'], (string)$periodSet['semester2_to']);
  }

  if($scope === 'semester2'){
    return [
      'school_period_set_id' => (int)$periodSet['id'],
      'scope' => 'semester2',
      'scope_label' => '2. Semester',
      'school_year_label' => $schoolYearLabel,
      'assessment_label' => '2. Semester '.$schoolYearLabel,
      'from' => (string)$periodSet['semester2_from'],
      'to' => (string)$periodSet['semester2_to'],
    ];
  }

  if($scope === 'year'){
    return [
      'school_period_set_id' => (int)$periodSet['id'],
      'scope' => 'year',
      'scope_label' => 'Jahresbeurteilung',
      'school_year_label' => $schoolYearLabel,
      'assessment_label' => 'Jahresbeurteilung '.$schoolYearLabel,
      'from' => (string)$periodSet['semester1_from'],
      'to' => (string)$periodSet['semester2_to'],
    ];
  }

  return [
    'school_period_set_id' => (int)$periodSet['id'],
    'scope' => 'semester1',
    'scope_label' => '1. Semester',
    'school_year_label' => $schoolYearLabel,
    'assessment_label' => '1. Semester '.$schoolYearLabel,
    'from' => (string)$periodSet['semester1_from'],
    'to' => (string)$periodSet['semester1_to'],
  ];
}

function final_assessment_existing_map(PDO $pdo, int $classId, int $subjectId, int $periodSetId, string $scope): array {
  $st = $pdo->prepare("SELECT fa.*,
                              cu.username AS created_by_username,
                              uu.username AS updated_by_username
                       FROM final_assessments fa
                       LEFT JOIN users cu ON cu.id=fa.created_by
                       LEFT JOIN users uu ON uu.id=fa.updated_by
                       WHERE fa.class_id=? AND fa.subject_id=? AND fa.school_period_set_id=? AND fa.assessment_scope=?");
  $st->execute([$classId, $subjectId, $periodSetId, $scope]);
  $map = [];
  foreach($st->fetchAll() as $row){
    $row['id'] = (int)$row['id'];
    $row['student_id'] = (int)$row['student_id'];
    $row['final_grade'] = $row['final_grade'] !== null ? (int)$row['final_grade'] : null;
    $row['suggestion_value'] = $row['suggestion_value'] !== null ? (int)$row['suggestion_value'] : null;
    $row['participation_count'] = (int)$row['participation_count'];
    $row['documented_day_count'] = (int)$row['documented_day_count'];
    $row['positive_count'] = (int)$row['positive_count'];
    $row['neutral_count'] = (int)$row['neutral_count'];
    $row['negative_count'] = (int)$row['negative_count'];
    $row['unrated_count'] = (int)$row['unrated_count'];
    $row['oral_count'] = (int)$row['oral_count'];
    $row['written_count'] = (int)$row['written_count'];
    $row['deviation_flag'] = (int)$row['deviation_flag'];
    $map[(int)$row['student_id']] = $row;
  }
  return $map;
}

function final_assessment_class_context(PDO $pdo, int $classId): array {
  $st = $pdo->prepare("SELECT assessment_system FROM classes WHERE id=? LIMIT 1");
  $st->execute([$classId]);
  $value = $st->fetchColumn();
  $assessmentSystem = $value !== false ? trim((string)$value) : '';
  $normalized = class_assessment_system_is_valid($assessmentSystem) ? $assessmentSystem : null;
  return [
    'value' => $normalized,
    'label' => class_assessment_system_label($normalized),
    'note' => class_assessment_system_note($normalized),
    'tone' => class_assessment_system_tone($normalized),
  ];
}

function final_assessment_history(PDO $pdo, int $assessmentId, int $limit = 5): array {
  if($assessmentId <= 0) return [];
  $limit = max(1, min(20, $limit));
  $st = $pdo->prepare("SELECT h.*,
                              u.username AS changed_by_username
                       FROM final_assessment_history h
                       LEFT JOIN users u ON u.id=h.changed_by
                       WHERE h.final_assessment_id=?
                       ORDER BY h.created_at DESC, h.id DESC
                       LIMIT $limit");
  $st->execute([$assessmentId]);
  return $st->fetchAll();
}

function final_assessment_year_trend(?array $semester1Summary, ?array $semester2Summary, ?array $semester1Saved, ?array $semester2Saved): array {
  $sem1Reference = $semester1Saved['final_grade'] ?? $semester1Summary['note_proposal']['value'] ?? null;
  $sem2Reference = $semester2Saved['final_grade'] ?? $semester2Summary['note_proposal']['value'] ?? null;

  if($sem1Reference === null && $sem2Reference === null){
    return [
      'code' => 'unknown',
      'label' => 'Semesterentwicklung noch offen',
      'explanation' => 'Es liegen noch keine ausreichend vergleichbaren Semesterdaten für eine Entwicklungsaussage vor.',
      'tone' => 'neutral',
    ];
  }

  if($sem1Reference !== null && $sem2Reference !== null){
    $delta = (int)$sem1Reference - (int)$sem2Reference;
    if($delta >= 2){
      return [
        'code' => 'strong_improvement',
        'label' => 'deutliche Verbesserung im 2. Semester',
        'explanation' => 'Die zweite Semesterphase wirkt im Gesamtbild klar stärker als die erste.',
        'tone' => 'positive',
      ];
    }
    if($delta === 1){
      return [
        'code' => 'improvement',
        'label' => 'leichte Verbesserung im 2. Semester',
        'explanation' => 'Die zweite Semesterphase wirkt im Gesamtbild etwas stärker.',
        'tone' => 'positive',
      ];
    }
    if($delta <= -2){
      return [
        'code' => 'strong_decline',
        'label' => 'Leistungsabfall im 2. Semester',
        'explanation' => 'Die zweite Semesterphase wirkt im Gesamtbild deutlich schwächer als die erste.',
        'tone' => 'critical',
      ];
    }
    if($delta === -1){
      return [
        'code' => 'decline',
        'label' => 'leichter Leistungsabfall im 2. Semester',
        'explanation' => 'Die zweite Semesterphase wirkt im Gesamtbild etwas schwächer.',
        'tone' => 'critical',
      ];
    }

    if((int)$sem2Reference <= 2){
      return [
        'code' => 'stable_positive',
        'label' => 'Jahrestendenz stabil positiv',
        'explanation' => 'Beide Semester wirken insgesamt konsistent positiv.',
        'tone' => 'positive',
      ];
    }
    if((int)$sem2Reference >= 4){
      return [
        'code' => 'stable_critical',
        'label' => 'Jahrestendenz kritisch',
        'explanation' => 'Beide Semester zeigen im Gesamtbild eine kritische Tendenz.',
        'tone' => 'critical',
      ];
    }

    return [
      'code' => 'mixed',
      'label' => 'Semesterleistungen uneinheitlich',
      'explanation' => 'Die Semester wirken im Vergleich weder eindeutig verbessert noch verschlechtert; eine pädagogische Gesamtschau bleibt wichtig.',
      'tone' => 'neutral',
    ];
  }

  if($sem2Reference !== null){
    return [
      'code' => 'recent_only',
      'label' => 'Schwerpunkt auf aktueller Entwicklung',
      'explanation' => 'Für die Jahresbeurteilung ist die aktuelle Entwicklung sichtbarer als eine abgeschlossene Semesterlinie.',
      'tone' => ((int)$sem2Reference <= 2) ? 'positive' : (((int)$sem2Reference >= 4) ? 'critical' : 'neutral'),
    ];
  }

  return [
    'code' => 'early_only',
    'label' => 'Vorläufige Jahressicht',
    'explanation' => 'Es liegt vor allem eine belastbare Grundlage aus dem ersten Semester vor; die Jahresbeurteilung erfordert zusätzliche pädagogische Einordnung.',
    'tone' => 'neutral',
  ];
}

function final_assessment_compute_proposal(array $summary, array $subjectContext, string $scope = 'semester1', array $yearTrend = []): array {
  $base = $summary['note_proposal'] ?? ['value' => null, 'explanation' => '', 'label' => ''];
  $dataBasis = $summary['data_basis'] ?? ['can_estimate' => false, 'label' => 'Keine Daten vorhanden', 'explanation' => ''];

  $signals = [];
  $adjustment = 0;
  $value = $base['value'] ?? null;

  if(!($dataBasis['can_estimate'] ?? false) || $value === null){
    $signals[] = $dataBasis['label'] ?? 'Datenlage prüfen';
    if(!empty($subjectContext['short_note'])) $signals[] = $subjectContext['short_note'];
    return [
      'value' => null,
      'label' => (($dataBasis['level'] ?? '') === 'none') ? 'Keine Daten vorhanden' : 'Datenlage prüfen',
      'short' => (($dataBasis['level'] ?? '') === 'none') ? 'keine Daten' : 'prüfen',
      'tone' => 'neutral',
      'explanation' => trim(($dataBasis['explanation'] ?? '').' '.implode(' · ', array_unique($signals))),
      'base_value' => null,
      'adjustment' => 0,
      'signals' => array_values(array_unique($signals)),
    ];
  }

  $signals[] = 'Mitarbeitstendenz: '.($summary['quality']['label'] ?? 'noch offen');
  $signals[] = $base['explanation'] ?? '';

  if(($summary['oral_positive_count'] ?? 0) > 0 && ($summary['oral_negative_count'] ?? 0) === 0){
    $signals[] = 'besondere mündliche Leistungen stützen das Gesamtbild';
  } elseif(($summary['oral_negative_count'] ?? 0) > 0){
    $signals[] = 'besondere mündliche Leistungen pädagogisch mitprüfen';
  }

  if(($subjectContext['status'] ?? 'unset') === 'no'){
    if(($summary['written_count'] ?? 0) > 0 && ($summary['written_avg'] ?? null) !== null){
      $writtenAvg = (float)$summary['written_avg'];
      if($writtenAvg <= 1.8 && $value > 1){
        $adjustment = -1;
        $signals[] = 'schriftliche Leistungen stützen einen etwas besseren Vorschlag';
      } elseif($writtenAvg >= 4.2 && $value < 5){
        $adjustment = 1;
        $signals[] = 'schriftliche Leistungen sprechen für einen vorsichtigeren Vorschlag';
      } elseif($writtenAvg <= 2.6){
        $signals[] = 'schriftliche Leistungen stützen den positiven Eindruck';
      } elseif($writtenAvg >= 3.8){
        $signals[] = 'schriftliche Leistungen relativieren den Vorschlag';
      }
    } else {
      $signals[] = 'fehlende schriftliche Sonderleistungen werden nicht negativ gewertet';
    }
  } else {
    if(($subjectContext['status'] ?? 'unset') === 'yes'){
      $signals[] = 'Schularbeitsleistungen gesondert berücksichtigen';
    } else {
      $signals[] = 'Fachstatus für die Interpretation prüfen';
    }
  }

  if($scope === 'year' && $yearTrend){
    $signals[] = $yearTrend['label'] ?? 'Jahresentwicklung prüfen';
  }

  $value = max(1, min(5, (int)$value + $adjustment));
  $tone = $value <= 2 ? 'positive' : ($value >= 4 ? 'critical' : 'neutral');

  return [
    'value' => $value,
    'label' => 'Notenvorschlag '.(string)$value,
    'short' => (string)$value,
    'tone' => $tone,
    'explanation' => implode(' · ', array_values(array_filter(array_unique($signals)))),
    'base_value' => $base['value'] ?? null,
    'adjustment' => $adjustment,
    'signals' => array_values(array_filter(array_unique($signals))),
  ];
}

function final_assessment_snapshot_payload(array $summary, array $proposal, array $subjectContext, array $periodMeta, ?array $yearTrend = null, array $semesterContext = []): array {
  return [
    'saved_at' => now_iso(),
    'period' => $periodMeta,
    'class_context' => $summary['class_context'] ?? null,
    'subject_context' => $subjectContext,
    'summary' => [
      'student_id' => $summary['student_id'],
      'student_name' => $summary['student_name'],
      'participation_count' => $summary['participation_count'],
      'documented_day_count' => $summary['documented_day_count'],
      'positive_count' => $summary['positive_count'],
      'neutral_count' => $summary['neutral_count'],
      'negative_count' => $summary['negative_count'],
      'unrated_count' => $summary['unrated_count'],
      'top_criteria' => $summary['top_criteria'],
      'comments_text' => $summary['comments_text'],
      'quality' => $summary['quality'],
      'data_basis' => $summary['data_basis'],
      'written_count' => $summary['written_count'],
      'written_text' => $summary['written_text'],
      'written_avg' => $summary['written_avg'],
      'written_type_counts' => $summary['written_type_counts'] ?? [],
      'oral_count' => $summary['oral_count'],
      'oral_text' => $summary['oral_text'],
      'oral_positive_count' => $summary['oral_positive_count'],
      'oral_neutral_count' => $summary['oral_neutral_count'],
      'oral_negative_count' => $summary['oral_negative_count'],
      'semester_hint' => $summary['semester_hint'],
    ],
    'proposal' => $proposal,
    'year_trend' => $yearTrend,
    'semester_context' => $semesterContext,
  ];
}

function final_assessment_build_rows(PDO $pdo, int $classId, int $subjectId, array $periodSet, string $scope): array {
  $periodMeta = final_assessment_period_meta($periodSet, $scope);
  $subjectContext = report_eval_subject_context($pdo, $subjectId);
  $classContext = final_assessment_class_context($pdo, $classId);
  $existingCurrent = final_assessment_existing_map($pdo, $classId, $subjectId, (int)$periodMeta['school_period_set_id'], $scope);
  $summaries = report_build_student_summaries($pdo, $classId, $subjectId, (string)$periodMeta['from'], (string)$periodMeta['to']);

  $semester1Map = [];
  $semester2Map = [];
  $semester1Saved = [];
  $semester2Saved = [];
  $semester1Meta = final_assessment_period_meta($periodSet, 'semester1');
  $semester2Meta = final_assessment_period_meta($periodSet, 'semester2');

  if($scope === 'semester2' || $scope === 'year'){
    $semester1Saved = final_assessment_existing_map($pdo, $classId, $subjectId, (int)$periodMeta['school_period_set_id'], 'semester1');
  }
  if($scope === 'semester2'){
    foreach(report_build_student_summaries($pdo, $classId, $subjectId, (string)$semester1Meta['from'], (string)$semester1Meta['to']) as $row){
      $semester1Map[(int)$row['student_id']] = $row;
    }
  }

  if($scope === 'year'){
    foreach(report_build_student_summaries($pdo, $classId, $subjectId, (string)$semester1Meta['from'], (string)$semester1Meta['to']) as $row){
      $semester1Map[(int)$row['student_id']] = $row;
    }
    foreach(report_build_student_summaries($pdo, $classId, $subjectId, (string)$semester2Meta['from'], (string)$semester2Meta['to']) as $row){
      $semester2Map[(int)$row['student_id']] = $row;
    }
    $semester1Saved = final_assessment_existing_map($pdo, $classId, $subjectId, (int)$periodMeta['school_period_set_id'], 'semester1');
    $semester2Saved = final_assessment_existing_map($pdo, $classId, $subjectId, (int)$periodMeta['school_period_set_id'], 'semester2');
  }

  $rows = [];
  foreach($summaries as $summary){
    $sid = (int)$summary['student_id'];
    $summary['class_context'] = $classContext;
    $yearTrend = [];
    $semesterContext = [];
    if($scope === 'semester2'){
      $semesterContext = [
        'semester1_saved' => $semester1Saved[$sid] ?? null,
        'semester1_summary' => $semester1Map[$sid] ?? null,
      ];
    } elseif($scope === 'year'){
      $yearTrend = final_assessment_year_trend(
        $semester1Map[$sid] ?? null,
        $semester2Map[$sid] ?? null,
        $semester1Saved[$sid] ?? null,
        $semester2Saved[$sid] ?? null
      );
      $semesterContext = [
        'semester1_saved' => $semester1Saved[$sid] ?? null,
        'semester2_saved' => $semester2Saved[$sid] ?? null,
        'semester1_summary' => $semester1Map[$sid] ?? null,
        'semester2_summary' => $semester2Map[$sid] ?? null,
      ];
    }

    $proposal = final_assessment_compute_proposal($summary, $subjectContext, $scope, $yearTrend);
    $existing = $existingCurrent[$sid] ?? null;

    $rows[] = [
      'student_id' => $sid,
      'student_name' => $summary['student_name'],
      'summary' => $summary,
      'proposal' => $proposal,
      'existing' => $existing,
      'period_meta' => $periodMeta,
      'subject_context' => $subjectContext,
      'class_context' => $classContext,
      'year_trend' => $yearTrend,
      'semester_context' => $semesterContext,
    ];
  }

  return [
    'period_meta' => $periodMeta,
    'subject_context' => $subjectContext,
    'class_context' => $classContext,
    'rows' => $rows,
  ];
}

function final_assessment_build_payload(array $teacher, int $classId, int $subjectId, array $row, ?int $finalGrade, string $status, string $teacherComment, string $changeNote): array {
  $summary = $row['summary'];
  $proposal = $row['proposal'];
  $subjectContext = $row['subject_context'];
  $periodMeta = $row['period_meta'];
  $yearTrend = $row['year_trend'] ?? null;
  $semesterContext = $row['semester_context'] ?? [];
  $teacherComment = trim($teacherComment);
  $changeNote = trim($changeNote);

  $snapshot = final_assessment_snapshot_payload($summary, $proposal, $subjectContext, $periodMeta, $yearTrend, $semesterContext);
  $deviationFlag = ($finalGrade !== null && ($proposal['value'] ?? null) !== null && $finalGrade !== (int)$proposal['value']) ? 1 : 0;

  return [
    'teacher_id' => (int)$teacher['id'],
    'class_id' => $classId,
    'subject_id' => $subjectId,
    'student_id' => (int)$summary['student_id'],
    'school_period_set_id' => (int)$periodMeta['school_period_set_id'],
    'assessment_scope' => (string)$periodMeta['scope'],
    'assessment_label' => (string)$periodMeta['assessment_label'],
    'school_year_label' => (string)$periodMeta['school_year_label'],
    'period_from' => (string)$periodMeta['from'],
    'period_to' => (string)$periodMeta['to'],
    'subject_is_schularbeit' => (($subjectContext['status'] ?? 'unset') === 'yes') ? 1 : ((($subjectContext['status'] ?? 'unset') === 'no') ? 0 : null),
    'suggestion_value' => $proposal['value'] ?? null,
    'suggestion_label' => (string)($proposal['label'] ?? ''),
    'suggestion_explanation' => (string)($proposal['explanation'] ?? ''),
    'final_grade' => $finalGrade,
    'deviation_flag' => $deviationFlag,
    'deviation_note' => $deviationFlag ? ($teacherComment !== '' ? $teacherComment : null) : null,
    'teacher_comment' => $teacherComment !== '' ? $teacherComment : null,
    'data_basis_level' => (string)($summary['data_basis']['level'] ?? 'none'),
    'data_basis_label' => (string)($summary['data_basis']['label'] ?? 'Keine Daten vorhanden'),
    'data_basis_explanation' => (string)($summary['data_basis']['explanation'] ?? ''),
    'participation_count' => (int)$summary['participation_count'],
    'documented_day_count' => (int)$summary['documented_day_count'],
    'positive_count' => (int)$summary['positive_count'],
    'neutral_count' => (int)$summary['neutral_count'],
    'negative_count' => (int)$summary['negative_count'],
    'unrated_count' => (int)$summary['unrated_count'],
    'participation_quality_label' => (string)($summary['quality']['label'] ?? ''),
    'participation_quality_avg' => ($summary['quality']['avg'] ?? null) !== null ? round((float)$summary['quality']['avg'], 2) : null,
    'top_criteria' => (string)$summary['top_criteria'],
    'comments_summary' => (string)$summary['comments_text'],
    'oral_count' => (int)$summary['oral_count'],
    'oral_positive_count' => (int)$summary['oral_positive_count'],
    'oral_neutral_count' => (int)$summary['oral_neutral_count'],
    'oral_negative_count' => (int)$summary['oral_negative_count'],
    'oral_summary_text' => (string)$summary['oral_text'],
    'written_count' => (int)$summary['written_count'],
    'written_avg' => ($summary['written_avg'] ?? null) !== null ? round((float)$summary['written_avg'], 2) : null,
    'written_summary_text' => (string)$summary['written_text'],
    'written_type_summary_json' => json_encode($summary['written_type_counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'semester_hint' => (string)$summary['semester_hint'],
    'year_trend_label' => ((string)$periodMeta['scope'] === 'year' ? (string)($yearTrend['label'] ?? '') : null),
    'status' => $status,
    'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'last_change_note' => $changeNote !== '' ? $changeNote : null,
  ];
}

function final_assessment_requires_change_note(?array $existing, array $payload): bool {
  if(!$existing || (string)($existing['status'] ?? '') !== 'final') return false;
  $beforeGrade = $existing['final_grade'] !== null ? (int)$existing['final_grade'] : null;
  $beforeComment = trim((string)($existing['teacher_comment'] ?? ''));
  $beforeStatus = (string)($existing['status'] ?? 'draft');
  $afterGrade = $payload['final_grade'];
  $afterComment = trim((string)($payload['teacher_comment'] ?? ''));
  $afterStatus = (string)($payload['status'] ?? 'draft');
  return $beforeGrade !== $afterGrade || $beforeComment !== $afterComment || $beforeStatus !== $afterStatus;
}

function final_assessment_store(PDO $pdo, ?array $existing, array $payload, array $teacher): int {
  $now = now_iso();
  $teacherId = (int)$teacher['id'];
  $finalizedAt = ($payload['status'] === 'final') ? $now : null;

  if($existing){
    $sql = "UPDATE final_assessments SET
              assessment_label=?,
              school_year_label=?,
              period_from=?,
              period_to=?,
              subject_is_schularbeit=?,
              suggestion_value=?,
              suggestion_label=?,
              suggestion_explanation=?,
              final_grade=?,
              deviation_flag=?,
              deviation_note=?,
              teacher_comment=?,
              data_basis_level=?,
              data_basis_label=?,
              data_basis_explanation=?,
              participation_count=?,
              documented_day_count=?,
              positive_count=?,
              neutral_count=?,
              negative_count=?,
              unrated_count=?,
              participation_quality_label=?,
              participation_quality_avg=?,
              top_criteria=?,
              comments_summary=?,
              oral_count=?,
              oral_positive_count=?,
              oral_neutral_count=?,
              oral_negative_count=?,
              oral_summary_text=?,
              written_count=?,
              written_avg=?,
              written_summary_text=?,
              written_type_summary_json=?,
              semester_hint=?,
              year_trend_label=?,
              status=?,
              snapshot_json=?,
              last_change_note=?,
              updated_by=?,
              updated_at=?,
              finalized_at=?
            WHERE id=?";
    $st = $pdo->prepare($sql);
    $st->execute([
      $payload['assessment_label'],
      $payload['school_year_label'],
      $payload['period_from'],
      $payload['period_to'],
      $payload['subject_is_schularbeit'],
      $payload['suggestion_value'],
      $payload['suggestion_label'],
      $payload['suggestion_explanation'],
      $payload['final_grade'],
      $payload['deviation_flag'],
      $payload['deviation_note'],
      $payload['teacher_comment'],
      $payload['data_basis_level'],
      $payload['data_basis_label'],
      $payload['data_basis_explanation'],
      $payload['participation_count'],
      $payload['documented_day_count'],
      $payload['positive_count'],
      $payload['neutral_count'],
      $payload['negative_count'],
      $payload['unrated_count'],
      $payload['participation_quality_label'],
      $payload['participation_quality_avg'],
      $payload['top_criteria'],
      $payload['comments_summary'],
      $payload['oral_count'],
      $payload['oral_positive_count'],
      $payload['oral_neutral_count'],
      $payload['oral_negative_count'],
      $payload['oral_summary_text'],
      $payload['written_count'],
      $payload['written_avg'],
      $payload['written_summary_text'],
      $payload['written_type_summary_json'],
      $payload['semester_hint'],
      $payload['year_trend_label'],
      $payload['status'],
      $payload['snapshot_json'],
      $payload['last_change_note'],
      $teacherId,
      $now,
      $finalizedAt,
      (int)$existing['id'],
    ]);
    $id = (int)$existing['id'];
  } else {
    $sql = "INSERT INTO final_assessments(
              class_id,subject_id,student_id,school_period_set_id,assessment_scope,assessment_label,school_year_label,
              period_from,period_to,subject_is_schularbeit,suggestion_value,suggestion_label,suggestion_explanation,
              final_grade,deviation_flag,deviation_note,teacher_comment,data_basis_level,data_basis_label,data_basis_explanation,
              participation_count,documented_day_count,positive_count,neutral_count,negative_count,unrated_count,
              participation_quality_label,participation_quality_avg,top_criteria,comments_summary,
              oral_count,oral_positive_count,oral_neutral_count,oral_negative_count,oral_summary_text,
              written_count,written_avg,written_summary_text,written_type_summary_json,semester_hint,year_trend_label,
              status,snapshot_json,last_change_note,created_by,updated_by,created_at,updated_at,finalized_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $st = $pdo->prepare($sql);
    $st->execute([
      $payload['class_id'],
      $payload['subject_id'],
      $payload['student_id'],
      $payload['school_period_set_id'],
      $payload['assessment_scope'],
      $payload['assessment_label'],
      $payload['school_year_label'],
      $payload['period_from'],
      $payload['period_to'],
      $payload['subject_is_schularbeit'],
      $payload['suggestion_value'],
      $payload['suggestion_label'],
      $payload['suggestion_explanation'],
      $payload['final_grade'],
      $payload['deviation_flag'],
      $payload['deviation_note'],
      $payload['teacher_comment'],
      $payload['data_basis_level'],
      $payload['data_basis_label'],
      $payload['data_basis_explanation'],
      $payload['participation_count'],
      $payload['documented_day_count'],
      $payload['positive_count'],
      $payload['neutral_count'],
      $payload['negative_count'],
      $payload['unrated_count'],
      $payload['participation_quality_label'],
      $payload['participation_quality_avg'],
      $payload['top_criteria'],
      $payload['comments_summary'],
      $payload['oral_count'],
      $payload['oral_positive_count'],
      $payload['oral_neutral_count'],
      $payload['oral_negative_count'],
      $payload['oral_summary_text'],
      $payload['written_count'],
      $payload['written_avg'],
      $payload['written_summary_text'],
      $payload['written_type_summary_json'],
      $payload['semester_hint'],
      $payload['year_trend_label'],
      $payload['status'],
      $payload['snapshot_json'],
      $payload['last_change_note'],
      $teacherId,
      $teacherId,
      $now,
      $now,
      $finalizedAt,
    ]);
    $id = (int)$pdo->lastInsertId();
  }

  $historyType = $existing
    ? (($existing['status'] ?? 'draft') === 'final'
        ? (($payload['status'] ?? 'draft') === 'final' ? 'edit_final' : 'reopen_to_draft')
        : (($payload['status'] ?? 'draft') === 'final' ? 'finalize' : 'update_draft'))
    : (($payload['status'] ?? 'draft') === 'final' ? 'create_final' : 'create_draft');

  $st = $pdo->prepare("INSERT INTO final_assessment_history(
                        final_assessment_id,changed_by,change_type,status_before,status_after,final_grade_before,final_grade_after,change_note,snapshot_json,created_at
                      ) VALUES (?,?,?,?,?,?,?,?,?,?)");
  $st->execute([
    $id,
    $teacherId,
    $historyType,
    $existing['status'] ?? null,
    $payload['status'],
    $existing['final_grade'] ?? null,
    $payload['final_grade'],
    $payload['last_change_note'],
    $payload['snapshot_json'],
    $now,
  ]);

  return $id;
}
