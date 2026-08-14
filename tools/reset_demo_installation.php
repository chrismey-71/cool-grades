<?php
/**
 * Resets a COOL-Grades demo installation to the bundled sample data.
 *
 * Cron example:
 *   0 3 * * * php /path/to/cool-grades/tools/reset_demo_installation.php
 */

if(PHP_SAPI !== 'cli'){
  http_response_code(403);
  echo "Dieses Skript darf nur über die Kommandozeile ausgeführt werden.\n";
  exit(1);
}

require_once __DIR__.'/../lib/db.php';
require_once __DIR__.'/../lib/demo_installation.php';

$year = null;
foreach(array_slice($argv, 1) as $arg){
  if(str_starts_with($arg, '--year=')){
    $year = substr($arg, 7);
  }
}

try{
  $pdo = db();
  $result = demo_installation_reset($pdo, $year);
  echo "Demoinstallation wurde zurückgesetzt.\n";
  echo "Schuljahr: ".$result['school_year']."\n";
  echo "Demo-Admin: ".$result['admin_username']." / ".$result['password']."\n";
  echo "Demo-Lehrer:in: ".$result['teacher_username']." / ".$result['password']."\n";
  exit(0);
}catch(Throwable $e){
  fwrite(STDERR, "Demo-Reset fehlgeschlagen: ".$e->getMessage()."\n");
  exit(2);
}
