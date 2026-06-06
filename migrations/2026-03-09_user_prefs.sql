-- Add per-user UI preferences

ALTER TABLE users ADD COLUMN pref_quick_entry_ui VARCHAR(16) NULL AFTER must_change_password;
ALTER TABLE users ADD COLUMN pref_theme VARCHAR(16) NULL AFTER pref_quick_entry_ui;
