-- Add per-teacher quick-pick preferences for participation entry

SET @has_enabled := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'pref_participation_quick_pick_enabled'
);
SET @sql := IF(
  @has_enabled = 0,
  'ALTER TABLE users ADD COLUMN pref_participation_quick_pick_enabled TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_limit := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'pref_participation_quick_pick_limit'
);
SET @sql := IF(
  @has_limit = 0,
  'ALTER TABLE users ADD COLUMN pref_participation_quick_pick_limit INT NOT NULL DEFAULT 10',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
