-- A mündliche Übung (§ 6 LBV, e.g. a Referat) is a closed, prepared
-- performance. Teachers must justify the grade with a short written
-- Rückmeldung documenting how it was reached - unlike a mündliche Prüfung
-- (§ 5 LBV), which already documents itself via topic_area/questions. This
-- column stores that feedback; entry masks (teacher/oral_new.php,
-- teacher/oral_edit.php) require it for ORAL_EXERCISE rows and leave it NULL
-- for ORAL_EXAM rows. The application applies this itself (see
-- _ensure_schema() in lib/db.php); this file documents the equivalent
-- statement for a manual/production run.

ALTER TABLE oral_assessments ADD COLUMN feedback TEXT NULL AFTER title;
