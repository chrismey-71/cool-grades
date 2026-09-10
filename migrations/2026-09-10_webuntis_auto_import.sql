-- Per-teacher choice (account.php "WebUntis-Stundenplan" -> "Import-
-- Zeitpunkt"): whether the WebUntis import may also run automatically in
-- the background via a cron job (tools/webuntis_cron_import.php), instead
-- of only through the "Jetzt importieren" button. Defaults to off so no
-- existing teacher is auto-imported without having opted in.
-- The application applies this itself (see _ensure_schema() in lib/db.php);
-- this file documents the equivalent statement for a manual/production run.

ALTER TABLE users ADD COLUMN webuntis_auto_import_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER webuntis_ical_last_import_summary;
