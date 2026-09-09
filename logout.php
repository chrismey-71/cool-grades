<?php
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/helpers.php';
require_once __DIR__.'/lib/logger.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){
  // A plain GET here performs no logout (that stays POST+CSRF-only to avoid a
  // logout-CSRF hole) - it just sends the visitor on to the login page
  // instead of a bare "Method Not Allowed" page. This happens in practice
  // when a browser reloads a stale tab whose last request was the
  // auto-logout POST to this URL (2026-09 report: after a long idle period,
  // the tab was left on logout.php?timeout=1 showing "Method Not Allowed").
  $q = (!empty($_GET['timeout'])) ? '?timeout=1' : '';
  redirect('/login.php'.$q);
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
