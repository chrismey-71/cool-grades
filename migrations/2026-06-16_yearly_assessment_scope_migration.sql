-- Jahresmodell-Korrektur:
-- Bisher wurden in Jahresmodell-Klassen teilweise Jahresnoten irrtümlich
-- unter "semester2" gespeichert. Diese Migration kopiert solche Datensätze
-- einmalig nach "year", wenn dort noch keine Jahresbeurteilung existiert.
-- Bestehende Jahresbeurteilungen werden nie ueberschrieben.

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  actor_user_id INT NULL,
  created_at DATETIME NOT NULL,
  payload_json JSON NOT NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO events(type, actor_user_id, created_at, payload_json)
SELECT
  'migration_yearly_semester2_to_year_conflict',
  NULL,
  NOW(),
  JSON_OBJECT(
    'message', 'Jahresmodell-Konflikt: 2. Semesterbeurteilung und Jahresbeurteilung existieren bereits. Keine automatische Änderung.',
    'class_id', sem2.class_id,
    'subject_id', sem2.subject_id,
    'student_id', sem2.student_id,
    'school_period_set_id', sem2.school_period_set_id,
    'semester2_assessment_id', sem2.id,
    'year_assessment_id', yearfa.id
  )
FROM final_assessments sem2
JOIN classes c ON c.id=sem2.class_id AND c.assessment_system='yearly'
JOIN final_assessments yearfa
  ON yearfa.class_id=sem2.class_id
 AND yearfa.subject_id=sem2.subject_id
 AND yearfa.student_id=sem2.student_id
 AND yearfa.school_period_set_id=sem2.school_period_set_id
 AND yearfa.assessment_scope='year'
WHERE sem2.assessment_scope='semester2'
  AND NOT EXISTS (
    SELECT 1 FROM app_settings marker
    WHERE marker.`key`='migration_2026_06_16_yearly_semester2_to_year_done'
  );

INSERT INTO final_assessments(
  class_id,subject_id,student_id,school_period_set_id,assessment_scope,assessment_label,school_year_label,
  period_from,period_to,subject_is_schularbeit,suggestion_value,suggestion_label,suggestion_explanation,
  final_grade,deviation_flag,deviation_note,teacher_comment,data_basis_level,data_basis_label,data_basis_explanation,
  participation_count,documented_day_count,positive_count,neutral_count,negative_count,unrated_count,
  participation_quality_label,participation_quality_avg,top_criteria,comments_summary,
  oral_count,oral_positive_count,oral_neutral_count,oral_negative_count,oral_summary_text,
  written_count,written_avg,written_summary_text,written_type_summary_json,semester_hint,year_trend_label,
  status,snapshot_json,last_change_note,created_by,updated_by,created_at,updated_at,finalized_at
)
SELECT
  sem2.class_id,sem2.subject_id,sem2.student_id,sem2.school_period_set_id,'year',
  CONCAT('Jahresbeurteilung ', sem2.school_year_label),
  sem2.school_year_label,
  sp.semester1_from,sp.semester2_to,sem2.subject_is_schularbeit,sem2.suggestion_value,sem2.suggestion_label,sem2.suggestion_explanation,
  sem2.final_grade,sem2.deviation_flag,sem2.deviation_note,sem2.teacher_comment,sem2.data_basis_level,sem2.data_basis_label,sem2.data_basis_explanation,
  sem2.participation_count,sem2.documented_day_count,sem2.positive_count,sem2.neutral_count,sem2.negative_count,sem2.unrated_count,
  sem2.participation_quality_label,sem2.participation_quality_avg,sem2.top_criteria,sem2.comments_summary,
  sem2.oral_count,sem2.oral_positive_count,sem2.oral_neutral_count,sem2.oral_negative_count,sem2.oral_summary_text,
  sem2.written_count,sem2.written_avg,sem2.written_summary_text,sem2.written_type_summary_json,sem2.semester_hint,sem2.year_trend_label,
  sem2.status,sem2.snapshot_json,
  CONCAT_WS('\n', NULLIF(sem2.last_change_note,''), 'Automatisch aus 2. Semesterbeurteilung in Jahresbeurteilung übertragen, da Klasse im Jahresmodell geführt wird.'),
  sem2.created_by,sem2.updated_by,sem2.created_at,NOW(),sem2.finalized_at
FROM final_assessments sem2
JOIN classes c ON c.id=sem2.class_id AND c.assessment_system='yearly'
JOIN school_period_sets sp ON sp.id=sem2.school_period_set_id
WHERE sem2.assessment_scope='semester2'
  AND NOT EXISTS (
    SELECT 1 FROM final_assessments yearfa
    WHERE yearfa.class_id=sem2.class_id
      AND yearfa.subject_id=sem2.subject_id
      AND yearfa.student_id=sem2.student_id
      AND yearfa.school_period_set_id=sem2.school_period_set_id
      AND yearfa.assessment_scope='year'
  )
  AND NOT EXISTS (
    SELECT 1 FROM app_settings marker
    WHERE marker.`key`='migration_2026_06_16_yearly_semester2_to_year_done'
  );

INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at)
VALUES('migration_2026_06_16_yearly_semester2_to_year_done', NOW(), NOW(), NOW());
