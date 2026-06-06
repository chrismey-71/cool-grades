<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';

$u=require_role('admin');
$pdo=db();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

$type = (string)($_POST['type'] ?? '');
$scope = (string)($_POST['scope'] ?? 'global');
$subject_id = (int)($_POST['subject_id'] ?? 0);
$ids = $_POST['ids'] ?? [];
if(!is_array($ids)) $ids = [];
$ids = array_values(array_filter(array_map('intval', $ids), function($x){ return (int)$x > 0; }));

$valid_types = ['reason','impact','performance','social_form','phase','homework'];
if(!in_array($type,$valid_types,true)){
  echo json_encode(['ok'=>false,'error'=>'invalid type']);
  exit;
}
if(!in_array($scope,['global','subject'],true)){
  echo json_encode(['ok'=>false,'error'=>'invalid scope']);
  exit;
}
if($scope === 'subject' && $subject_id <= 0){
  echo json_encode(['ok'=>false,'error'=>'invalid subject']);
  exit;
}
if(count($ids) < 1){
  echo json_encode(['ok'=>false,'error'=>'no ids']);
  exit;
}

$in = '('.implode(',', array_fill(0, count($ids), '?')).')';
$params = $ids;
$params[] = $type;

if($scope === 'global'){
  $sql = "SELECT id FROM participation_options
          WHERE id IN $in AND opt_type=? AND scope='global' AND IFNULL(archived,0)=0";
} else {
  $sql = "SELECT id FROM participation_options
          WHERE id IN $in AND opt_type=? AND scope='subject' AND subject_id=? AND IFNULL(archived,0)=0";
  $params[] = $subject_id;
}

$st = $pdo->prepare($sql);
$st->execute($params);
$found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
if(count($found) !== count($ids)){
  echo json_encode(['ok'=>false,'error'=>'not allowed']);
  exit;
}

try{
  $pdo->beginTransaction();
  $upd = $pdo->prepare("UPDATE participation_options SET sort=? WHERE id=? AND scope=?");
  $i=1;
  foreach($ids as $id){
    $upd->execute([$i*10, $id, $scope]);
    $i++;
  }
  $pdo->commit();
  emit_event('admin_options_reordered',['type'=>$type,'scope'=>$scope,'count'=>count($ids),'subject_id'=>$scope==='subject' ? $subject_id : null]);
  echo json_encode(['ok'=>true]);
}catch(Exception $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'db']);
}
