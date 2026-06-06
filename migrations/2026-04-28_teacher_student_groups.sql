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
