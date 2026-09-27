CREATE TABLE IF NOT EXISTS teacher_seating_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT 'Standard',
  layout_type VARCHAR(16) NOT NULL DEFAULT 'grid',
  columns INT NOT NULL DEFAULT 4,
  grid_rows INT NOT NULL DEFAULT 4,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_teacher_seating_plan_name (teacher_id,class_id,subject_id,name),
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Falls dieses Skript schon einmal mit dem alten, einzelnen Sitzplan pro
-- Klasse/Fach gelaufen ist (Version 1.81.4, erster Stand): Name-Spalte und
-- die neue, erweiterte Eindeutigkeit nachrüsten.
-- ALTER TABLE teacher_seating_plans ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT 'Standard' AFTER subject_id;
-- ALTER TABLE teacher_seating_plans DROP INDEX uniq_teacher_seating_plan;
-- ALTER TABLE teacher_seating_plans ADD UNIQUE KEY uniq_teacher_seating_plan_name (teacher_id,class_id,subject_id,name);

CREATE TABLE IF NOT EXISTS teacher_seating_plan_seats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  student_id INT NOT NULL,
  seat_col INT NOT NULL,
  seat_row INT NOT NULL,
  UNIQUE KEY uniq_seating_plan_seat_position (plan_id,seat_col,seat_row),
  UNIQUE KEY uniq_seating_plan_seat_student (plan_id,student_id),
  FOREIGN KEY (plan_id) REFERENCES teacher_seating_plans(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
