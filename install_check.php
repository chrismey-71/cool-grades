<?php
if(!headers_sent()){
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'install.php?step=system'.($query !== '' ? '&'.$query : '');
header('Location: '.$target, true, 302);
exit;
