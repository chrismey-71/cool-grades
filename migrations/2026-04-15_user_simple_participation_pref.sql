ALTER TABLE users
  ADD COLUMN IF NOT EXISTS pref_simple_participation_entry TINYINT(1) NOT NULL DEFAULT 0 AFTER pref_visual_contrast;
