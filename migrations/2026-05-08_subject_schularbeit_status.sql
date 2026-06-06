ALTER TABLE subjects
  ADD COLUMN IF NOT EXISTS is_schularbeit_subject TINYINT(1) NULL AFTER name;
