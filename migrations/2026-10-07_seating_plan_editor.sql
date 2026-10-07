-- Sitzplan-Editor (Version 1.81.6)
--
-- Freie Tischanordnung neben dem klassischen Raster (Spalten x Reihen):
-- teacher_seating_plans.layout_type = 'free' speichert die Tische in
-- layout_json ({"version":1,"tables":[{"type":"t2","x":500,"y":205,"rot":0}, ...]}).
-- Die Platzbelegung bleibt in teacher_seating_plan_seats: seat_col ist die
-- Tischnummer (1-basiert, Reihenfolge in layout_json), seat_row der Platz am Tisch.
-- Bestehende Sitzpläne (layout_type = 'grid') bleiben unverändert.
--
-- Die App führt diese Änderungen beim ersten Seitenaufruf auch selbst aus
-- (lib/db.php, _ensure_schema); dieses Skript ist für manuelle Updates gedacht.

ALTER TABLE teacher_seating_plans ADD COLUMN layout_json MEDIUMTEXT NULL AFTER grid_rows;

-- Persönliche Einstellung "Sitzplan" (Konto > Persönliche Einstellungen):
-- 'classic' = Raster wie bisher, 'editor' = Sitzplan-Editor.
-- pref_seating_templates: Komma-Liste der angebotenen Vorlagen, NULL = alle.
ALTER TABLE users ADD COLUMN pref_seating_design VARCHAR(16) NOT NULL DEFAULT 'classic' AFTER pref_lesson_lookback_days;
ALTER TABLE users ADD COLUMN pref_seating_templates TEXT NULL AFTER pref_seating_design;
