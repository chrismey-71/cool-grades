-- Eindruck/Relevanz wird nicht mehr aus dem sichtbaren Text abgeleitet.
-- Die Migration ist idempotent: Bereits gespeicherte Wertungsrichtungen bleiben erhalten.

ALTER TABLE participation_options
  ADD COLUMN IF NOT EXISTS impact_kind VARCHAR(16) NULL AFTER pedagogical_hint_mode;

ALTER TABLE participation_events
  ADD COLUMN IF NOT EXISTS impact_kind VARCHAR(16) NULL AFTER rating;

ALTER TABLE oral_assessments
  ADD COLUMN IF NOT EXISTS impact_kind VARCHAR(16) NULL AFTER impact_label;

UPDATE participation_options
SET impact_kind=CASE
  WHEN LOWER(label) LIKE '%kaum nachweisbar%' OR LOWER(label) LIKE '%nicht nachweisbar%' OR LOWER(label) LIKE '%negativ%' OR LOWER(label) LIKE '%kritisch%' OR LOWER(label) LIKE '%unsicher%' OR LOWER(label) LIKE '%nicht genügend%' OR label REGEXP '(^|[^[:alnum:]])-+([^[:alnum:]]|$)' THEN 'negative'
  WHEN LOWER(label) LIKE '%nur beobachtet%' OR LOWER(label) LIKE '%ohne wertung%' THEN 'unrated'
  WHEN LOWER(label) LIKE '%positiv%' OR LOWER(label) LIKE '%sehr gut%' OR LOWER(label) LIKE '%sicher%' OR label REGEXP '\\+' THEN 'positive'
  ELSE 'neutral'
END
WHERE opt_type='impact' AND (impact_kind IS NULL OR impact_kind='');

UPDATE participation_events
SET impact_kind=CASE
  WHEN LOWER(rating) LIKE '%kaum nachweisbar%' OR LOWER(rating) LIKE '%nicht nachweisbar%' OR LOWER(rating) LIKE '%negativ%' OR LOWER(rating) LIKE '%kritisch%' OR LOWER(rating) LIKE '%unsicher%' OR LOWER(rating) LIKE '%nicht genügend%' OR rating REGEXP '(^|[^[:alnum:]])-+([^[:alnum:]]|$)' THEN 'negative'
  WHEN LOWER(rating) LIKE '%nur beobachtet%' OR LOWER(rating) LIKE '%ohne wertung%' THEN 'unrated'
  WHEN LOWER(rating) LIKE '%positiv%' OR LOWER(rating) LIKE '%sehr gut%' OR LOWER(rating) LIKE '%sicher%' OR rating REGEXP '\\+' THEN 'positive'
  ELSE 'neutral'
END
WHERE impact_kind IS NULL OR impact_kind='';

UPDATE oral_assessments
SET impact_kind=CASE
  WHEN LOWER(impact_label) LIKE '%kaum nachweisbar%' OR LOWER(impact_label) LIKE '%nicht nachweisbar%' OR LOWER(impact_label) LIKE '%negativ%' OR LOWER(impact_label) LIKE '%kritisch%' OR LOWER(impact_label) LIKE '%unsicher%' OR LOWER(impact_label) LIKE '%nicht genügend%' OR impact_label REGEXP '(^|[^[:alnum:]])-+([^[:alnum:]]|$)' THEN 'negative'
  WHEN LOWER(impact_label) LIKE '%nur beobachtet%' OR LOWER(impact_label) LIKE '%ohne wertung%' THEN 'unrated'
  WHEN LOWER(impact_label) LIKE '%positiv%' OR LOWER(impact_label) LIKE '%sehr gut%' OR LOWER(impact_label) LIKE '%sicher%' OR impact_label REGEXP '\\+' THEN 'positive'
  ELSE 'neutral'
END
WHERE impact_kind IS NULL OR impact_kind='';
