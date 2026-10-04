-- Führt die "Kompetenz-Beobachtung" ein: ein schlankes, von der täglichen
-- Mitarbeitserfassung bewusst getrenntes Schnellnotiz-Werkzeug für Methoden-,
-- Sozial- und Selbst-/Personalkompetenz-Beobachtungen während des
-- Unterrichts. Löst konzeptionell die bisherige "Achse 2"
-- (Arbeitsweise/Genauigkeit, Kooperation/Selbstständigkeit) des
-- Beobachtungsbereichs ab, ohne deren historische Daten anzutasten -
-- diese Migration ist rein additiv.
--
-- Datenmodell bewusst eigenständig (nicht über participation_options):
-- die Tag-Liste ist eine feste, global gepflegte Taxonomie ohne die
-- Lehrkraft-/Fach-Override-Logik der Mitarbeit-Optionen, und die
-- Beobachtungen selbst sind vollständig unabhängig von
-- participation_events.
--
-- competence_tags: feste Liste wählbarer Kurz-Tags je Kompetenzbereich.
CREATE TABLE IF NOT EXISTS competence_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(16) NOT NULL, -- 'methoden' | 'sozial' | 'selbst'
  label VARCHAR(120) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_competence_tag (category, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- competence_observations: eine Notiz pro Schüler:in/Tag/Klasse/Fach/
-- Lehrkraft. Erneutes Speichern am selben Tag ersetzt die Tag-Auswahl
-- (keine wachsende Liste von Einzel-Events), damit eine Notiz im Laufe
-- der Stunde unkompliziert nachgeschärft werden kann.
CREATE TABLE IF NOT EXISTS competence_observations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  student_id INT NOT NULL,
  class_id INT NOT NULL,
  subject_id INT NOT NULL,
  observation_date DATE NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_competence_observation (teacher_id, student_id, class_id, subject_id, observation_date),
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  INDEX idx_competence_obs_lookup (class_id, subject_id, observation_date),
  INDEX idx_competence_obs_student (student_id, observation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competence_observation_tags (
  observation_id INT NOT NULL,
  tag_id INT NOT NULL,
  PRIMARY KEY (observation_id, tag_id),
  FOREIGN KEY (observation_id) REFERENCES competence_observations(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES competence_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed der festen Tag-Taxonomie (idempotent über den UNIQUE-Key).
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'methoden' AS category,'Strukturiertes Vorgehen' AS label,10 AS sort,1 AS active) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='methoden' AND label='Strukturiertes Vorgehen');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'methoden','Vorgehen/Ablauf erklärt',20,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='methoden' AND label='Vorgehen/Ablauf erklärt');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'methoden','Eigenständige Zeitplanung',30,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='methoden' AND label='Eigenständige Zeitplanung');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'methoden','Informationen zielgerichtet beschafft',40,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='methoden' AND label='Informationen zielgerichtet beschafft');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'methoden','Lösungsweg nachvollziehbar dokumentiert',50,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='methoden' AND label='Lösungsweg nachvollziehbar dokumentiert');

INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'sozial','Mitschüler:in unterstützt',10,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='sozial' AND label='Mitschüler:in unterstützt');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'sozial','Verantwortung übernommen',20,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='sozial' AND label='Verantwortung übernommen');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'sozial','Konstruktiv im Team mitgearbeitet',30,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='sozial' AND label='Konstruktiv im Team mitgearbeitet');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'sozial','Auf andere eingegangen / zugehört',40,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='sozial' AND label='Auf andere eingegangen / zugehört');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'sozial','Konflikt sachlich gelöst',50,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='sozial' AND label='Konflikt sachlich gelöst');

INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'selbst','Erstmals vor der Klasse gesprochen',10,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='selbst' AND label='Erstmals vor der Klasse gesprochen');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'selbst','Eigeninitiative gezeigt',20,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='selbst' AND label='Eigeninitiative gezeigt');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'selbst','Mit Rückschlag konstruktiv umgegangen',30,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='selbst' AND label='Mit Rückschlag konstruktiv umgegangen');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'selbst','Eigene Fehler erkannt und korrigiert',40,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='selbst' AND label='Eigene Fehler erkannt und korrigiert');
INSERT INTO competence_tags (category,label,sort,active)
SELECT * FROM (SELECT 'selbst','Über sich hinausgewachsen',50,1) t
WHERE NOT EXISTS (SELECT 1 FROM competence_tags WHERE category='selbst' AND label='Über sich hinausgewachsen');
