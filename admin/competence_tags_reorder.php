<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/competence_observations.php';

$u=require_role('admin');
$pdo=db();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

$category = (string)($_POST['category'] ?? '');
$ids = $_POST['ids'] ?? [];
if(!is_array($ids)) $ids = [];
$ids = array_values(array_filter(array_map('intval', $ids), function($x){ return (int)$x > 0; }));

$valid_categories = array_keys(competence_category_labels());
if(!in_array($category,$valid_categories,true)){
  echo json_encode(['ok'=>false,'error'=>'invalid category']);
  exit;
}
if(count($ids) < 1){
  echo json_encode(['ok'=>false,'error'=>'no ids']);
  exit;
}

$in = '('.implode(',', array_fill(0, count($ids), '?')).')';
$params = $ids;
$params[] = $category;

$sql = "SELECT id FROM competence_tags WHERE id IN $in AND category=? AND IFNULL(archived,0)=0";
$st = $pdo->prepare($sql);
$st->execute($params);
$found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
if(count($found) !== count($ids)){
  echo json_encode(['ok'=>false,'error'=>'not allowed']);
  exit;
}

try{
  $pdo->beginTransaction();
  $upd = $pdo->prepare("UPDATE competence_tags SET sort=? WHERE id=? AND category=?");
  $i=1;
  foreach($ids as $id){
    $upd->execute([$i*10, $id, $category]);
    $i++;
  }
  $pdo->commit();
  emit_event('admin_competence_tags_reordered',['category'=>$category,'count'=>count($ids)]);
  echo json_encode(['ok'=>true]);
}catch(Exception $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'db']);
}
