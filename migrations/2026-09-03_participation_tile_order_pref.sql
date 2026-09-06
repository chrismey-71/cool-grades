-- Per-user saved tile order for teacher/participation_new.php (JSON array of
-- tile keys, e.g. ["students","hours","preset","core",...]). NULL/empty means
-- the default order.

ALTER TABLE users ADD COLUMN pref_participation_tile_order TEXT NULL AFTER pref_nav_style;
