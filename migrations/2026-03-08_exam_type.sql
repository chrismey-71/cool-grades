-- Add exam_type to allow Schularbeit vs. Test
ALTER TABLE exams
  ADD COLUMN exam_type VARCHAR(16) NOT NULL DEFAULT 'SA' AFTER teacher_id;
