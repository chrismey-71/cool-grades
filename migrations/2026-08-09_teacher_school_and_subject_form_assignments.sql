CREATE TABLE teacher_schools (
  teacher_id INT NOT NULL,
  school_id INT NOT NULL,
  PRIMARY KEY (teacher_id,school_id),
  INDEX idx_teacher_schools_school (school_id,teacher_id),
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subject_school_forms (
  subject_id INT NOT NULL,
  school_form_id INT NOT NULL,
  PRIMARY KEY (subject_id,school_form_id),
  INDEX idx_subject_school_forms_form (school_form_id,subject_id),
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  FOREIGN KEY (school_form_id) REFERENCES school_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bestehende Zuweisungen bestimmen die erste Schulzuordnung der Lehrkräfte.
INSERT IGNORE INTO teacher_schools(teacher_id,school_id)
SELECT DISTINCT ta.teacher_id,sf.school_id
FROM teacher_assignments ta
JOIN classes c ON c.id=ta.class_id
JOIN school_forms sf ON sf.id=c.school_form_id;

-- Bestehende, bisher globale Fächer bleiben zunächst in allen vorhandenen Schulformen verfügbar.
INSERT IGNORE INTO subject_school_forms(subject_id,school_form_id)
SELECT su.id,sf.id FROM subjects su CROSS JOIN school_forms sf;
