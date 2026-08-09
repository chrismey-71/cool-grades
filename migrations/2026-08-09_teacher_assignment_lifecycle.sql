-- Zuweisungen mit vorhandenen Dokumentationen werden künftig beendet statt gelöscht.
-- Die Laufzeitmigration in lib/db.php ist idempotent und ergänzt dieselben Felder
-- bei bestehenden Installationen automatisch.
ALTER TABLE teacher_assignments
  ADD COLUMN status ENUM('active','ended') NOT NULL DEFAULT 'active' AFTER subject_id,
  ADD COLUMN ended_at DATETIME NULL AFTER status,
  ADD COLUMN ended_by INT NULL AFTER ended_at,
  ADD COLUMN end_note TEXT NULL AFTER ended_by,
  ADD INDEX idx_teacher_assignment_status (teacher_id,status,class_id,subject_id),
  ADD CONSTRAINT fk_teacher_assignments_ended_by FOREIGN KEY (ended_by) REFERENCES users(id) ON DELETE SET NULL;
