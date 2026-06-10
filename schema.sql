CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  first_name VARCHAR(64) NOT NULL,
  last_name VARCHAR(64) NOT NULL,
  role ENUM('admin','teacher') NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  pref_quick_entry_ui VARCHAR(16) NULL,
  pref_theme VARCHAR(16) NULL,
  pref_participation_quick_pick_enabled TINYINT(1) NOT NULL DEFAULT 1,
  pref_participation_quick_pick_limit INT NOT NULL DEFAULT 10,
  pref_legal_hints_enabled TINYINT(1) NOT NULL DEFAULT 1,
  pref_compact_forms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  pref_visual_contrast VARCHAR(16) NOT NULL DEFAULT 'normal',
  pref_simple_participation_entry TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at)
VALUES
  ('session_timeout_minutes','30',NOW(),NOW()),
  ('brand_primary_color','#2F6F3A',NOW(),NOW()),
  ('event_retention_days','30',NOW(),NOW()),
  ('legal_imprint_html','<p>Das Impressum wurde noch nicht hinterlegt.</p>',NOW(),NOW()),
  ('legal_privacy_html','<p>Die Datenschutzbestimmung wurde noch nicht hinterlegt.</p>',NOW(),NOW()),
  ('login_rate_limit_max_attempts','5',NOW(),NOW()),
  ('login_rate_limit_delay_seconds','2',NOW(),NOW()),
  ('login_rate_limit_lockout_minutes','15',NOW(),NOW()),
  ('password_min_length','12',NOW(),NOW()),
  ('password_require_upper','1',NOW(),NOW()),
  ('password_require_lower','1',NOW(),NOW()),
  ('password_require_digit','1',NOW(),NOW()),
  ('password_require_special','1',NOW(),NOW());

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(128) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  INDEX idx_login_attempt_lookup (username, ip_hash, success, attempted_at),
  INDEX idx_login_attempt_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  address TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_school_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS school_forms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(180) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_school_form_code (school_id, code),
  INDEX idx_school_form_active (active, code),
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_period_set_id INT NULL,
  name VARCHAR(32) NOT NULL,
  school_type VARCHAR(32) NOT NULL DEFAULT 'HLS',
  school_form_id INT NULL,
  year INT NOT NULL,
  label VARCHAR(128) NULL,
  assessment_system ENUM('sost','nost','yearly') NOT NULL DEFAULT 'yearly',
  predecessor_class_id INT NULL,
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  is_departed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_class_school_year_name (school_period_set_id,name),
  INDEX idx_classes_school_period (school_period_set_id),
  INDEX idx_classes_school_form (school_form_id),
  FOREIGN KEY (school_form_id) REFERENCES school_forms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(8) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  is_schularbeit_subject TINYINT(1) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  first_name VARCHAR(64) NOT NULL,
  last_name VARCHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS criteria_sets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  scope ENUM('subject','teacher') NOT NULL,
  subject_id INT NULL,
  teacher_id INT NULL,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS criteria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  criteria_set_id INT NOT NULL,
  label VARCHAR(200) NOT NULL,
  category VARCHAR(64) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (criteria_set_id) REFERENCES criteria_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lesson_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  lesson_date DATE NOT NULL,
  lesson_unit VARCHAR(16) NULL,
  topic VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  INDEX idx_lesson_teacher_date (teacher_id, lesson_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participation_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opt_type VARCHAR(32) NOT NULL,
  scope ENUM('global','subject','teacher') NOT NULL DEFAULT 'global',
  subject_id INT NULL,
  teacher_id INT NULL,
  label VARCHAR(80) NOT NULL,
  pedagogical_hint_mode VARCHAR(16) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_opt_type_scope (opt_type, scope),
  INDEX idx_opt_subject (subject_id),
  INDEX idx_opt_teacher (teacher_id),
  UNIQUE KEY uniq_opt (opt_type, scope, subject_id, teacher_id, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participation_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  lesson_id INT NULL,
  event_date DATE NOT NULL,

  -- Editable picklists (IDs) + label snapshots for safety
  reason_option_id INT NULL,
  reason_label VARCHAR(80) NOT NULL,
  impact_option_id INT NULL,
  rating VARCHAR(32) NOT NULL,

  social_form_option_id INT NULL,
  phase_option_id INT NULL,
  homework_option_id INT NULL,

  reason_text VARCHAR(255) NULL,
  note TEXT NULL,
  pedagogical_mode VARCHAR(16) NULL,
  created_at DATETIME NOT NULL,

  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lesson_sessions(id) ON DELETE SET NULL,
  FOREIGN KEY (reason_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,
  FOREIGN KEY (impact_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,
  FOREIGN KEY (social_form_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,
  FOREIGN KEY (phase_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,
  FOREIGN KEY (homework_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,

  INDEX idx_part_teacher_date (teacher_id, event_date),
  INDEX idx_part_class_subject_date (class_id, subject_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participation_event_options (
  event_id INT NOT NULL,
  option_id INT NOT NULL,
  PRIMARY KEY (event_id, option_id),
  FOREIGN KEY (event_id) REFERENCES participation_events(id) ON DELETE CASCADE,
  FOREIGN KEY (option_id) REFERENCES participation_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  teacher_id INT NOT NULL,
  exam_type VARCHAR(16) NOT NULL DEFAULT 'SA',
  exam_date DATE NOT NULL,
  title VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS exam_grades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  grade TINYINT NOT NULL,
  tendency VARCHAR(120) NULL,
  remark TEXT NULL,
  FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oral_assessments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  teacher_id INT NOT NULL,
  student_id INT NOT NULL,
  assessment_type VARCHAR(24) NOT NULL DEFAULT 'ORAL_EXAM',
  assessment_date DATE NOT NULL,
  impact_option_id INT NULL,
  impact_label VARCHAR(128) NULL,
  topic_area VARCHAR(255) NULL,
  questions TEXT NULL,
  category VARCHAR(120) NULL,
  title VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (impact_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,
  INDEX idx_oral_teacher_date (teacher_id, assessment_date),
  INDEX idx_oral_class_subject_date (class_id, subject_id, assessment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS school_period_sets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(32) NOT NULL,
  semester1_from DATE NOT NULL,
  semester1_to DATE NOT NULL,
  semester2_from DATE NOT NULL,
  semester2_to DATE NOT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_school_period_archived_dates (archived, semester1_from, semester2_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS class_enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  class_id INT NOT NULL,
  school_period_set_id INT NOT NULL,
  status ENUM('active','repeated','transferred','left','archived') NOT NULL DEFAULT 'active',
  entry_date DATE NULL,
  exit_date DATE NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_student_class_enrollment (student_id,class_id),
  INDEX idx_class_enrollment_class (class_id,status),
  INDEX idx_class_enrollment_student (student_id,school_period_set_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (school_period_set_id) REFERENCES school_period_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  actor_user_id INT NULL,
  created_at DATETIME NOT NULL,
  payload_json JSON NOT NULL,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teacher_participation_presets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NULL,
  subject_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_teacher_preset_subject (teacher_id, subject_id, name),
  INDEX idx_teacher_preset_subject_lookup (teacher_id, subject_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS teacher_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  UNIQUE KEY uniq_teacher_class_subject (teacher_id,class_id,subject_id),
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teacher_student_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_teacher_student_group_name (teacher_id,class_id,subject_id,name),
  INDEX idx_teacher_student_group_lookup (teacher_id,class_id,subject_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teacher_student_group_members (
  group_id INT NOT NULL,
  student_id INT NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  PRIMARY KEY (group_id, student_id),
  FOREIGN KEY (group_id) REFERENCES teacher_student_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  INDEX idx_teacher_student_group_member_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS final_assessments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  student_id INT NOT NULL,
  school_period_set_id INT NOT NULL,
  assessment_scope VARCHAR(16) NOT NULL,
  assessment_label VARCHAR(80) NOT NULL,
  school_year_label VARCHAR(32) NOT NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  subject_is_schularbeit TINYINT(1) NULL,
  suggestion_value TINYINT NULL,
  suggestion_label VARCHAR(64) NULL,
  suggestion_explanation TEXT NULL,
  final_grade TINYINT NULL,
  deviation_flag TINYINT(1) NOT NULL DEFAULT 0,
  deviation_note TEXT NULL,
  teacher_comment TEXT NULL,
  data_basis_level VARCHAR(16) NOT NULL,
  data_basis_label VARCHAR(64) NOT NULL,
  data_basis_explanation VARCHAR(255) NULL,
  participation_count INT NOT NULL DEFAULT 0,
  documented_day_count INT NOT NULL DEFAULT 0,
  positive_count INT NOT NULL DEFAULT 0,
  neutral_count INT NOT NULL DEFAULT 0,
  negative_count INT NOT NULL DEFAULT 0,
  unrated_count INT NOT NULL DEFAULT 0,
  participation_quality_label VARCHAR(64) NULL,
  participation_quality_avg DECIMAL(5,2) NULL,
  top_criteria VARCHAR(255) NULL,
  comments_summary TEXT NULL,
  oral_count INT NOT NULL DEFAULT 0,
  oral_positive_count INT NOT NULL DEFAULT 0,
  oral_neutral_count INT NOT NULL DEFAULT 0,
  oral_negative_count INT NOT NULL DEFAULT 0,
  oral_summary_text VARCHAR(255) NULL,
  written_count INT NOT NULL DEFAULT 0,
  written_avg DECIMAL(5,2) NULL,
  written_summary_text VARCHAR(255) NULL,
  written_type_summary_json LONGTEXT NULL,
  semester_hint VARCHAR(255) NULL,
  year_trend_label VARCHAR(128) NULL,
  status ENUM('draft','final') NOT NULL DEFAULT 'draft',
  snapshot_json LONGTEXT NOT NULL,
  last_change_note TEXT NULL,
  created_by INT NOT NULL,
  updated_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  finalized_at DATETIME NULL,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (school_period_set_id) REFERENCES school_period_sets(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_final_assessment_slot (class_id,subject_id,student_id,school_period_set_id,assessment_scope),
  INDEX idx_final_assessment_lookup (class_id,subject_id,school_period_set_id,assessment_scope,status),
  INDEX idx_final_assessment_student (student_id,school_period_set_id,assessment_scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS final_assessment_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  final_assessment_id INT NOT NULL,
  changed_by INT NOT NULL,
  change_type VARCHAR(24) NOT NULL,
  status_before VARCHAR(16) NULL,
  status_after VARCHAR(16) NOT NULL,
  final_grade_before TINYINT NULL,
  final_grade_after TINYINT NULL,
  change_note TEXT NULL,
  snapshot_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (final_assessment_id) REFERENCES final_assessments(id) ON DELETE CASCADE,
  FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_final_assessment_history_lookup (final_assessment_id,created_at),
  INDEX idx_final_assessment_history_user (changed_by,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participation_event_criteria (
  event_id INT NOT NULL,
  criteria_id INT NOT NULL,
  PRIMARY KEY (event_id, criteria_id),
  FOREIGN KEY (event_id) REFERENCES participation_events(id) ON DELETE CASCADE,
  FOREIGN KEY (criteria_id) REFERENCES criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Default option labels (editable by teachers in teacher/options.php)
INSERT IGNORE INTO participation_options (opt_type,scope,subject_id,teacher_id,label,active,sort,created_at) VALUES
('reason','global',NULL,NULL,'mündliche Mitarbeit',1,10,NOW()),
('reason','global',NULL,NULL,'Hausübung / Sicherung',1,20,NOW()),
('reason','global',NULL,NULL,'Arbeitsauftrag im Unterricht',1,30,NOW()),
('reason','global',NULL,NULL,'Gruppenarbeit / Projekt',1,40,NOW()),
('reason','global',NULL,NULL,'Präsentation / Referat',1,50,NOW()),
('reason','global',NULL,NULL,'Sonstiges',1,60,NOW()),

('impact','global',NULL,NULL,'auffällig positiv',1,10,NOW()),
('impact','global',NULL,NULL,'unauffällig',1,20,NOW()),
('impact','global',NULL,NULL,'auffällig negativ',1,30,NOW()),
('impact','global',NULL,NULL,'nur beobachtet',1,40,NOW()),

('social_form','global',NULL,NULL,'Alleinarbeit',1,10,NOW()),
('social_form','global',NULL,NULL,'Partnerarbeit',1,20,NOW()),
('social_form','global',NULL,NULL,'Gruppenarbeit',1,30,NOW()),

('phase','global',NULL,NULL,'Einstieg',1,10,NOW()),
('phase','global',NULL,NULL,'Erarbeitung',1,20,NOW()),
('phase','global',NULL,NULL,'Übung',1,30,NOW()),
('phase','global',NULL,NULL,'Präsentation',1,40,NOW()),
('phase','global',NULL,NULL,'Reflexion',1,50,NOW()),

('homework','global',NULL,NULL,'erledigt',1,10,NOW()),
('homework','global',NULL,NULL,'teilweise',1,20,NOW()),
('homework','global',NULL,NULL,'nicht',1,30,NOW()),

('performance','global',NULL,NULL,'mündlich',1,10,NOW()),
('performance','global',NULL,NULL,'schriftlich',1,20,NOW()),
('performance','global',NULL,NULL,'praktisch',1,30,NOW()),
('performance','global',NULL,NULL,'graphisch',1,40,NOW()),

('observation_group','global',NULL,NULL,'Verstehen / Erfassen',1,10,NOW()),
('observation_group','global',NULL,NULL,'Anwenden / Transfer',1,20,NOW()),
('observation_group','global',NULL,NULL,'Argumentieren / Erklären',1,30,NOW()),
('observation_group','global',NULL,NULL,'Arbeitsweise / Genauigkeit',1,40,NOW()),
('observation_group','global',NULL,NULL,'Kooperation / Selbstständigkeit',1,50,NOW());
CREATE TABLE IF NOT EXISTS criteria_suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_type ENUM('FSB','HLS','BOTH') NOT NULL DEFAULT 'BOTH',
  subject_code VARCHAR(8) NOT NULL,
  category VARCHAR(64) NOT NULL,
  label VARCHAR(200) NOT NULL,
  description VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  INDEX idx_subject (subject_code),
  INDEX idx_active (active, archived),
  UNIQUE KEY uniq_suggestion (school_type, subject_code, category, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
