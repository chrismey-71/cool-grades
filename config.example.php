<?php
return [
  'db' => ['host'=>'localhost','name'=>'coolgrades','user'=>'coolgrades','pass'=>'CHANGE_ME','charset'=>'utf8mb4'],
  'base_path' => '',
  'session_name' => 'coolgrades_sid',
  // Lokale Zeitzone für gespeicherte und angezeigte App-Zeitpunkte.
  'timezone' => 'Europe/Vienna',
  // Öffentlich sichtbarer Hinweis in Demoinstallationen; keine echte Crontab und keinen Serverpfad eintragen.
  'demo_reset_schedule' => 'täglich ca. 03:00 Uhr',
  // Einmaliges Installationstoken: vor der Installation durch einen langen Zufallswert ersetzen.
  // Aufruf: install.php?token=IHR_LANGES_ZUFALLSTOKEN
  'install_token' => 'CHANGE_ME_LONG_RANDOM_INSTALL_TOKEN',
  // Optional: true erzwingt zusätzlich, dass install.php nur von 127.0.0.1/::1 aufgerufen werden darf.
  // Für normale Webhosting-Installationen mit sicherem install_token auf false lassen oder weglassen.
  'install_local_only' => false,
  // Empfehlung: Logs außerhalb des öffentlich erreichbaren Webroots speichern.
  'log_dir' => __DIR__.'/../cool-grades-logs',
];
