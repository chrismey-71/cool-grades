<?php
return [
  'db' => ['host'=>'localhost','name'=>'coolgrades','user'=>'coolgrades','pass'=>'CHANGE_ME','charset'=>'utf8mb4'],
  'base_path' => '',
  'session_name' => 'coolgrades_sid',
  // Einmaliges Installationstoken: vor der Installation durch einen langen Zufallswert ersetzen.
  // Aufruf: install.php?token=IHR_LANGES_ZUFALLSTOKEN
  'install_token' => 'CHANGE_ME_LONG_RANDOM_INSTALL_TOKEN',
  // Empfehlung: Logs außerhalb des öffentlich erreichbaren Webroots speichern.
  'log_dir' => __DIR__.'/../cool-grades-logs',
];
