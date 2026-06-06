-- Global app settings (key/value)

CREATE TABLE IF NOT EXISTS app_settings (
  `key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at)
VALUES ('session_timeout_minutes','30',NOW(),NOW());
