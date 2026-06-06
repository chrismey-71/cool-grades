CREATE TABLE IF NOT EXISTS school_period_sets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(32) NOT NULL,
  semester1_from DATE NOT NULL,
  semester1_to DATE NOT NULL,
  semester2_from DATE NOT NULL,
  semester2_to DATE NOT NULL,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_school_period_archived_dates (archived, semester1_from, semester2_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO school_period_sets (
  label,
  semester1_from,
  semester1_to,
  semester2_from,
  semester2_to,
  archived,
  created_at,
  updated_at
)
SELECT
  CASE
    WHEN CAST(SUBSTRING(src.semester1_from, 1, 4) AS UNSIGNED) > 0
         AND CAST(SUBSTRING(src.semester2_to, 1, 4) AS UNSIGNED) = CAST(SUBSTRING(src.semester1_from, 1, 4) AS UNSIGNED) + 1
      THEN CONCAT(
        SUBSTRING(src.semester1_from, 1, 4),
        '/',
        LPAD(MOD(CAST(SUBSTRING(src.semester2_to, 1, 4) AS UNSIGNED), 100), 2, '0')
      )
    ELSE SUBSTRING(src.semester1_from, 1, 4)
  END AS label,
  src.semester1_from,
  src.semester1_to,
  src.semester2_from,
  src.semester2_to,
  0,
  NOW(),
  NOW()
FROM (
  SELECT
    MAX(CASE WHEN `key`='semester1_from' THEN `value` END) AS semester1_from,
    MAX(CASE WHEN `key`='semester1_to' THEN `value` END) AS semester1_to,
    MAX(CASE WHEN `key`='semester2_from' THEN `value` END) AS semester2_from,
    MAX(CASE WHEN `key`='semester2_to' THEN `value` END) AS semester2_to
  FROM app_settings
) AS src
WHERE src.semester1_from IS NOT NULL
  AND src.semester1_to IS NOT NULL
  AND src.semester2_from IS NOT NULL
  AND src.semester2_to IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM school_period_sets);
