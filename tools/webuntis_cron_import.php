<?php
/**
 * Regelmaessiger WebUntis-Import fuer alle Lehrkraefte, die das in ihren
 * Kontoeinstellungen aktiviert haben (account.php -> "WebUntis-Stundenplan"
 * -> "Import-Zeitpunkt" -> "Automatisch"). Andere Lehrkraefte (Standard:
 * "Manuell") werden hier bewusst uebersprungen - sie importieren weiterhin
 * nur ueber den "Jetzt importieren"-Button.
 *
 * Cron-Beispiel (taeglich um 3 Uhr nachts):
 *   0 3 * * * php /pfad/zu/cool-grades/tools/webuntis_cron_import.php >> /pfad/zu/cool-grades/logs/webuntis_cron.log 2>&1
 *
 * Ein Fehler bei einer einzelnen Lehrkraft (z. B. ein abgelaufener oder
 * nicht erreichbarer WebUntis-Link) bricht den Lauf nicht ab - die uebrigen
 * Lehrkraefte werden trotzdem importiert; der Fehler wird ausgegeben und
 * geloggt (app_log), damit er nicht unbemerkt bleibt.
 */

if(PHP_SAPI !== 'cli'){
  http_response_code(403);
  echo "Dieses Skript darf nur ueber die Kommandozeile ausgefuehrt werden.\n";
  exit(1);
}

require_once __DIR__.'/../lib/webuntis_ical.php';

$pdo = db();
$st = $pdo->query("SELECT * FROM users
                    WHERE role='teacher' AND is_active=1
                      AND webuntis_auto_import_enabled=1
                      AND webuntis_ical_url_enc IS NOT NULL AND webuntis_ical_url_enc<>''");
$teachers = $st->fetchAll();

echo '['.date('Y-m-d H:i:s')."] WebUntis-Cron-Import gestartet: ".count($teachers)." Lehrkraft/-kraefte mit aktiviertem Auto-Import.\n";

$ok = 0;
$failed = 0;

foreach($teachers as $teacher){
  $label = trim((string)($teacher['first_name'] ?? '').' '.(string)($teacher['last_name'] ?? '')).' ('.($teacher['username'] ?? ('#'.$teacher['id'])).')';
  try{
    $summary = webuntis_import_for_teacher($pdo, $teacher);
    $ok++;
    echo sprintf(
      "  OK      %-40s importiert=%d aktualisiert=%d entfernt=%d\n",
      $label,
      (int)($summary['imported'] ?? 0),
      (int)($summary['updated'] ?? 0),
      (int)($summary['pruned_stale'] ?? 0)
    );
  }catch(Throwable $e){
    $failed++;
    echo sprintf("  FEHLER  %-40s %s\n", $label, $e->getMessage());
    app_log('error','webuntis cron import failed',[
      'teacher_id'=>(int)($teacher['id'] ?? 0),
      'exception'=>get_class($e),
      'message'=>$e->getMessage(),
    ]);
  }
}

echo '['.date('Y-m-d H:i:s')."] Fertig: $ok erfolgreich, $failed fehlgeschlagen.\n";
exit(($failed > 0 && $ok === 0) ? 2 : 0);
