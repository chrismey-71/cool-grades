ALTER TABLE participation_events
  ADD COLUMN IF NOT EXISTS pedagogical_mode VARCHAR(16) NULL AFTER note;
