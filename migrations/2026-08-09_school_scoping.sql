-- Mehrschul-Führung: globale Bestandsdaten bleiben global (school_id = NULL).
ALTER TABLE school_period_sets
  ADD COLUMN school_id INT NULL AFTER id,
  ADD INDEX idx_school_period_school (school_id, archived, semester1_from),
  ADD CONSTRAINT fk_school_period_sets_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT;

-- Klassen dürfen in verschiedenen Schulformen eines Schuljahres gleich heißen.
ALTER TABLE classes DROP INDEX uniq_class_school_year_name;
ALTER TABLE classes ADD UNIQUE KEY uniq_class_school_year_form_name (school_period_set_id, school_form_id, name);

-- Konkrete Schulformzuordnung für Kriterienvorschläge; ohne Zeile = global gültig.
CREATE TABLE criteria_suggestion_school_forms (
  suggestion_id INT NOT NULL,
  school_form_id INT NOT NULL,
  PRIMARY KEY (suggestion_id, school_form_id),
  INDEX idx_criteria_suggestion_school_form (school_form_id, suggestion_id),
  FOREIGN KEY (suggestion_id) REFERENCES criteria_suggestions(id) ON DELETE CASCADE,
  FOREIGN KEY (school_form_id) REFERENCES school_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bestehende HLS-/FSB-Einträge werden auf gleich bezeichnete Schulformen übertragen.
-- BOTH bleibt absichtlich ohne Zuordnung und damit global gültig.
INSERT IGNORE INTO criteria_suggestion_school_forms(suggestion_id, school_form_id)
SELECT cs.id, sf.id
FROM criteria_suggestions cs
JOIN school_forms sf ON sf.code=cs.school_type
WHERE cs.school_type IN ('HLS','FSB');

-- Neue Ereignisse werden mit der Schule ihres Kontexts gespeichert.
ALTER TABLE events
  ADD COLUMN school_id INT NULL AFTER actor_user_id,
  ADD INDEX idx_events_school_created (school_id, created_at),
  ADD CONSTRAINT fk_events_school FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL;
