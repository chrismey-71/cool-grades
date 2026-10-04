-- Führt das Zwei-Achsen-Modell für den Beobachtungsbereich ein:
-- Achse 1 = kognitiver Fokus (Verstehen/Erfassen, Anwenden/Transfer,
--   Argumentieren/Erklären, Gestalten/Eigene Lösung) - Pflicht, genau einer.
-- Achse 2 = Arbeits-/Sozialform (Arbeitsweise/Genauigkeit, Kooperation/
--   Selbstständigkeit) - optional, höchstens einer zusätzlich.
--
-- Die Migration ist rein additiv und idempotent: Es wird nur ein neues,
-- nullbares Metadatenfeld ergänzt sowie eine neue Option angelegt. Bestehende
-- Zuordnungen in participation_event_options (welche Beobachtungsbereiche an
-- welchem Mitarbeitseintrag hängen) werden nicht verändert oder gelöscht -
-- auch nicht bereits gespeicherte Kombinationen, die dem neuen Modell nicht
-- mehr entsprechen (z. B. zwei Bereiche aus derselben Achse). Solche
-- bestehenden Einträge werden im Bearbeitungsformular weiterhin unverändert
-- angezeigt und bleiben beim Speichern unangetastet, solange die Auswahl dort
-- nicht aktiv geändert wird.

ALTER TABLE participation_options
  ADD COLUMN IF NOT EXISTS observation_axis TINYINT NULL AFTER impact_kind;

-- Neue Option für Achse 1 ergänzen (schließt die bisher fehlende Bloom-Stufe
-- "Erschaffen"). Nicht per INSERT IGNORE: NULL in subject_id/teacher_id
-- verhindert bei MySQL eine zuverlässige Dubletten-Erkennung über den
-- Unique-Key, siehe auch den bestehenden Seed-Block in schema.sql.
INSERT INTO participation_options (opt_type,scope,subject_id,teacher_id,label,active,sort,created_at)
SELECT 'observation_group','global',NULL,NULL,'Gestalten / Eigene Lösung',1,35,NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM participation_options
  WHERE opt_type='observation_group' AND scope='global' AND subject_id IS NULL AND teacher_id IS NULL
    AND label='Gestalten / Eigene Lösung'
);

-- Achsen-Zuordnung für alle bestehenden observation_group-Optionen nachziehen
-- (global wie auch bereits pro Lehrkraft/Fach materialisierte Kopien) -
-- unabhängig davon, wann oder von welchem Installationspfad sie angelegt wurden.
UPDATE participation_options
SET observation_axis=1
WHERE opt_type='observation_group' AND observation_axis IS NULL
  AND (label LIKE 'Verstehen%' OR label LIKE 'Anwenden%' OR label LIKE 'Argumentieren%' OR label LIKE 'Gestalten%');

UPDATE participation_options
SET observation_axis=2
WHERE opt_type='observation_group' AND observation_axis IS NULL
  AND (label LIKE 'Arbeitsweise%' OR label LIKE 'Kooperation%');
