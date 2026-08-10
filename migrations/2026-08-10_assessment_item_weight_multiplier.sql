ALTER TABLE exams
  ADD COLUMN IF NOT EXISTS weight_multiplier DECIMAL(3,1) NOT NULL DEFAULT 1.0 AFTER exam_type;

ALTER TABLE oral_assessments
  ADD COLUMN IF NOT EXISTS weight_multiplier DECIMAL(3,1) NOT NULL DEFAULT 1.0 AFTER impact_kind;
