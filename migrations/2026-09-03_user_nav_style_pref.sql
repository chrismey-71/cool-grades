-- Per-user preference for main navigation style (text / icon / icon+text)

ALTER TABLE users ADD COLUMN pref_nav_style VARCHAR(16) NOT NULL DEFAULT 'text' AFTER pref_simple_participation_entry;
