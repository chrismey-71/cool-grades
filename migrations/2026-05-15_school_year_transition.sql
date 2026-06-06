ALTER TABLE school_period_sets
  ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0 AFTER archived;

UPDATE school_period_sets
SET is_current=1
WHERE is_current=0
  AND id=(SELECT id FROM (SELECT id FROM school_period_sets WHERE archived=0 ORDER BY semester1_from DESC, id DESC LIMIT 1) x);

ALTER TABLE classes
  ADD COLUMN school_period_set_id INT NULL AFTER id,
  ADD COLUMN predecessor_class_id INT NULL AFTER assessment_system,
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER predecessor_class_id,
  ADD COLUMN is_departed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived,
  ADD INDEX idx_classes_school_period (school_period_set_id);

UPDATE classes
SET school_period_set_id=(SELECT id FROM school_period_sets WHERE is_current=1 LIMIT 1)
WHERE school_period_set_id IS NULL OR school_period_set_id=0;

ALTER TABLE classes DROP INDEX name;
ALTER TABLE classes ADD UNIQUE KEY uniq_class_school_year_name (school_period_set_id,name);

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

INSERT IGNORE INTO class_enrollments(student_id,class_id,school_period_set_id,status,entry_date,created_at,updated_at)
SELECT s.id,s.class_id,c.school_period_set_id,'active',NULL,NOW(),NOW()
FROM students s
JOIN classes c ON c.id=s.class_id;
