<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/participation_options.php';

$u=require_role('teacher');
$pdo=db();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

$type = (string)($_POST['type'] ?? '');
$subject_id = (int)($_POST['subject_id'] ?? 0);
$ids = $_POST['ids'] ?? [];
if(!is_array($ids)) $ids = [];
$ids = array_values(array_filter(array_map('intval', $ids), function($x){ return (int)$x > 0; }));

$valid_types = ['reason','impact','performance','social_form','phase','homework'];
if(!in_array($type,$valid_types,true)){
  echo json_encode(['ok'=>false,'error'=>'invalid type']);
  exit;
}
if(count($ids) < 1){
  echo json_encode(['ok'=>false,'error'=>'no ids']);
  exit;
}

if($subject_id > 0){
  $st = $pdo->prepare("SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND subject_id=? LIMIT 1");
  $st->execute([(int)$u['id'], $subject_id]);
  if(!(bool)$st->fetchColumn()){
    echo json_encode(['ok'=>false,'error'=>'not allowed']);
    exit;
  }
}

try{
  $pdo->beginTransaction();
  $mat = materialize_teacher_participation_options($pdo,(int)$u['id'],$subject_id,$type);
  $target_ids = [];
  foreach($ids as $display_id){
    $target_id = (int)($mat['map'][$display_id] ?? 0);
    if($target_id > 0) $target_ids[] = $target_id;
  }
  $target_ids = array_values(array_unique($target_ids));
  if(count($target_ids) !== count($ids)){
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'not allowed']);
    exit;
  }

  $in = '('.implode(',', array_fill(0, count($target_ids), '?')).')';
  $params = $target_ids;
  $params[] = $type;
  $params[] = (int)$u['id'];
  $target_subject_id = participation_option_target_subject_id($subject_id);
  if($target_subject_id === null){
    $sql = "SELECT id FROM participation_options
            WHERE id IN $in AND opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id IS NULL AND IFNULL(archived,0)=0";
  } else {
    $sql = "SELECT id FROM participation_options
            WHERE id IN $in AND opt_type=? AND scope='teacher' AND teacher_id=? AND subject_id=? AND IFNULL(archived,0)=0";
    $params[] = $target_subject_id;
  }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
  if(count($found) !== count($target_ids)){
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'not allowed']);
    exit;
  }

  $upd = $pdo->prepare("UPDATE participation_options SET sort=? WHERE id=? AND scope='teacher' AND teacher_id=?");
  $i=1;
  foreach($target_ids as $id){
    $upd->execute([$i*10, $id, (int)$u['id']]);
    $i++;
  }
  $pdo->commit();

  emit_event('teacher_options_reordered',['type'=>$type,'count'=>count($target_ids),'subject_id'=>$subject_id?:null]);
  echo json_encode(['ok'=>true]);
}catch(Exception $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'db']);
}
