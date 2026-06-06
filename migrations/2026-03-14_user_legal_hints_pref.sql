SET @has_col := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'pref_legal_hints_enabled'
);

SET @sql := IF(
  @has_col = 0,
  'ALTER TABLE users ADD COLUMN pref_legal_hints_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pref_participation_quick_pick_limit',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
