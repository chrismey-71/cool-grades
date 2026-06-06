<?php
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/helpers.php';
require_once __DIR__.'/lib/logger.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){
  http_response_code(405);
  exit('Method Not Allowed');
}
verify_csrf();
if(!empty($_GET['timeout'])){
  start_session();
  app_log('info','session timeout logout requested',[
    'uid'=>(int)($_SESSION['uid'] ?? 0),
  ]);
}
logout();
$q = (!empty($_GET['timeout'])) ? '?timeout=1' : '';
redirect('/login.php'.$q);
