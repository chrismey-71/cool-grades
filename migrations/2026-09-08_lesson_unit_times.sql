-- Admin-configurable clock time per UE (Unterrichtseinheit, 1..12), per
-- school (different schools using the same install can have different
-- period-time grids). Used to auto-translate WebUntis imports (which only
-- carry start_time/end_time) into a school's existing UE numbering:
-- lib/lesson_unit_times.php reads this table to derive
-- lesson_sessions.lesson_unit from an imported start_time/end_time (a
-- contiguous run of UEs whose defined times fall inside the imported
-- event's span becomes a comma-joined double/triple period, e.g. "1,2").
-- Left empty until an admin fills it in on admin/lesson_unit_times.php;
-- nothing changes for existing installs until then. The application
-- applies this itself (see _ensure_schema() in lib/db.php); this file
-- documents the equivalent statement for a manual/production run.

CREATE TABLE IF NOT EXISTS lesson_unit_times (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  unit TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_lesson_unit_time (school_id, unit),
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
