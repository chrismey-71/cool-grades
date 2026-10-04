-- Entfernt Achse 2 (Arbeits-/Sozialform: "Arbeitsweise / Genauigkeit",
-- "Kooperation / Selbstständigkeit") aus dem Beobachtungsbereich der
-- Mitarbeiterfassung. Sie wird konzeptionell durch die eigenständige
-- "Kompetenz-Beobachtung" abgelöst (siehe
-- migrations/2026-10-01_competence_observations.sql).
--
-- Rein additiv/nicht-destruktiv: vorhandene Achse-2-Zuordnungen an
-- bestehenden Mitarbeit-Einträgen (participation_event_options) bleiben
-- unverändert erhalten und werden im Bearbeitungsformular weiterhin über
-- den bestehenden "Legacy"-Kompatibilitätsmodus angezeigt (siehe
-- teacher/participation_edit.php, $groupSelectionIsLegacy) - es werden nur
-- die Achse-2-Optionen selbst archiviert, damit sie bei neuen Einträgen
-- nicht mehr auswählbar sind. Erfasst sowohl die globalen Standardoptionen
-- als auch evtl. bereits pro Lehrkraft/Fach materialisierte Kopien (siehe
-- materialize_teacher_participation_options in lib/participation_options.php).
UPDATE participation_options
SET archived=1
WHERE opt_type='observation_group' AND observation_axis=2 AND IFNULL(archived,0)=0;
