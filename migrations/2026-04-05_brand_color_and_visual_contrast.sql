ALTER TABLE users
  ADD COLUMN IF NOT EXISTS pref_visual_contrast VARCHAR(16) NOT NULL DEFAULT 'normal' AFTER pref_compact_forms_enabled;

INSERT INTO app_settings (`key`,`value`,updated_at,created_at)
SELECT 'brand_primary_color', '#2F6F3A', NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1
  FROM app_settings
  WHERE `key` = 'brand_primary_color'
);
