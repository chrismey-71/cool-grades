-- Per-teacher default for the sort dropdown on teacher/lesson.php. The best
-- default changes over the school year (date_asc / "Datum alt zuerst" early
-- on, something else later), so each teacher can save their own default
-- instead of it being hard-coded. The application applies this itself (see
-- _ensure_schema() in lib/db.php); this file documents the equivalent
-- statement for a manual/production run.

ALTER TABLE users ADD COLUMN pref_lesson_sort_default VARCHAR(16) NOT NULL DEFAULT 'date_asc' AFTER pref_participation_tile_order;
