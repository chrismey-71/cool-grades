ALTER TABLE participation_options
  ADD COLUMN IF NOT EXISTS pedagogical_hint_mode VARCHAR(16) NULL AFTER label;
