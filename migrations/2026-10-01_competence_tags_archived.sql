-- Ergaenzt competence_tags um ein Archiv-Flag, analog zu
-- participation_options.archived. Hintergrund: die neue Admin-
-- Verwaltungsseite (admin/competence_tags.php) muss einen bereits in
-- competence_observation_tags verwendeten Tag beim Loeschen archivieren
-- statt hart zu entfernen, da ein hartes DELETE wegen
-- "FOREIGN KEY (tag_id) REFERENCES competence_tags(id) ON DELETE CASCADE"
-- sonst alle darauf verweisenden historischen Kompetenz-Beobachtungen
-- stillschweigend mitloeschen wuerde.
--
-- Rein additiv: bestehende Zeilen erhalten archived=0 (Default) und bleiben
-- unveraendert aktiv/sichtbar.
ALTER TABLE competence_tags
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
