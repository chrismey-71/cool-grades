-- Rechtstexte in globalen Einstellungen speichern.
-- Die Spalte muss längere HTML-Inhalte aufnehmen können.

CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE app_settings MODIFY COLUMN `value` LONGTEXT NOT NULL;

INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at)
VALUES
  ('legal_imprint_html','<p>Das Impressum wurde noch nicht hinterlegt.</p>',NOW(),NOW()),
  ('legal_privacy_html','<p>Die Datenschutzbestimmung wurde noch nicht hinterlegt.</p>',NOW(),NOW());
