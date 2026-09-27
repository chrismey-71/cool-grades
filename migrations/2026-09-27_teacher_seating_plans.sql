CREATE TABLE IF NOT EXISTS teacher_seating_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  layout_type VARCHAR(16) NOT NULL DEFAULT 'grid',
  columns INT NOT NULL DEFAULT 4,
  rows INT NOT NULL DEFAULT 4,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_teacher_seating_plan (teacher_id,class_id,subject_id),
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
