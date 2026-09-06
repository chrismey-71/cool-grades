-- "Notiz (optional)" was a second free-text field alongside "Kurze
-- Beobachtung / Anlass" (reason_text) on Mitarbeit-Einträgen
-- (participation_events). To simplify entry, the two are consolidated into
-- one field: entry masks (teacher/participation_new.php,
-- teacher/participation_edit.php) stop offering Notiz entirely, so its
-- content is merged into reason_text here for every existing row and the
-- note column is then cleared - nothing is lost, it now lives in
-- reason_text. The application applies this merge itself (see
-- _ensure_schema() in lib/db.php, guarded so it only ever runs once); this
-- file documents the equivalent statement for a manual/production run.

UPDATE participation_events
SET reason_text = CASE
  WHEN TRIM(IFNULL(reason_text,'')) = '' THEN TRIM(note)
  WHEN INSTR(reason_text, TRIM(note)) > 0 THEN reason_text
  ELSE CONCAT(reason_text, ' · ', TRIM(note))
END,
note = NULL
WHERE note IS NOT NULL AND TRIM(note) <> '';
