<?php
require_once __DIR__.'/../lib/layout.php';

$u = require_role('teacher');
$pdo = db();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

$known = participation_tile_keys();
$posted = $_POST['order'] ?? [];
if(!is_array($posted)) $posted = [];

// Same validation as participation_tile_order(): keep only known keys, drop
// duplicates, then append any missing known tile at the end so a stray or
// incomplete client-side order can never make a tile disappear.
$clean = [];
foreach($posted as $key){
  $key = (string)$key;
  if(in_array($key, $known, true) && !in_array($key, $clean, true)) $clean[] = $key;
}
foreach($known as $key){
  if(!in_array($key, $clean, true)) $clean[] = $key;
}

try{
  $st = $pdo->prepare("UPDATE users SET pref_participation_tile_order=? WHERE id=?");
  $st->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), (int)$u['id']]);
  echo json_encode(['ok'=>true]);
}catch(Exception $e){
  echo json_encode(['ok'=>false,'error'=>'db']);
}
