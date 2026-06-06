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
