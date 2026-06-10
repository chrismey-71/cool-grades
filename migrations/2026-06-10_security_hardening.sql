-- Sicherheits-Härtung: Login-Ratenbegrenzung und konfigurierbare Passwortregeln.

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(128) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  INDEX idx_login_attempt_lookup (username, ip_hash, success, attempted_at),
  INDEX idx_login_attempt_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at)
VALUES
  ('login_rate_limit_max_attempts','5',NOW(),NOW()),
  ('login_rate_limit_delay_seconds','2',NOW(),NOW()),
  ('login_rate_limit_lockout_minutes','15',NOW(),NOW()),
  ('password_min_length','12',NOW(),NOW()),
  ('password_require_upper','1',NOW(),NOW()),
  ('password_require_lower','1',NOW(),NOW()),
  ('password_require_digit','1',NOW(),NOW()),
  ('password_require_special','1',NOW(),NOW());
