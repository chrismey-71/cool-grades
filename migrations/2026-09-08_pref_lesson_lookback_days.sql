-- How many days back a teacher can still pick up an existing lesson in the
-- "Stundenkontext" of teacher/participation_new.php (entries are often made
-- after the fact). Configurable per teacher in account.php, default 14 days.
-- The application applies this itself (see _ensure_schema() in lib/db.php);
-- this file documents the equivalent statement for a manual/production run.

ALTER TABLE users ADD COLUMN pref_lesson_lookback_days SMALLINT UNSIGNED NOT NULL DEFAULT 14 AFTER pref_lesson_sort_default;
