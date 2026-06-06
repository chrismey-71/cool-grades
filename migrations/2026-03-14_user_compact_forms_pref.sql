SET @has_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'pref_compact_forms_enabled'
);

SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE users ADD COLUMN pref_compact_forms_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pref_legal_hints_enabled',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
