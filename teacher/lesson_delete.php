<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';

$u=require_role('teacher');
$pdo=db();
$bp=cfg()['base_path'];

if($_SERVER['REQUEST_METHOD']!=='POST'){
  http_response_code(405);
  exit('Method Not Allowed');
}
verify_csrf();

$lesson_id=(int)($_POST['lesson_id'] ?? 0);
$return=(string)($_POST['return'] ?? '');

function _starts_with($haystack,$needle){
  return $needle!=='' && substr($haystack,0,strlen($needle))===$needle;
}

if(!$lesson_id){
  http_response_code(400);
  exit('lesson_id fehlt');
}

$st=$pdo->prepare("SELECT * FROM lesson_sessions WHERE id=?");
$st->execute([$lesson_id]);
$ls=$st->fetch();
if(!$ls){
  http_response_code(404);
  exit('Stunde nicht gefunden');
}

$class_id=(int)$ls['class_id'];
$subject_id=(int)$ls['subject_id'];
require_teacher_assignment($u,$class_id,$subject_id);

$st=$pdo->prepare("SELECT COUNT(*) FROM participation_events WHERE lesson_id=?");
$st->execute([$lesson_id]);
$cnt=(int)$st->fetchColumn();

if($cnt>0){
  $dest=$return && _starts_with($return,$bp.'/')
    ? $return
    : ($bp.'/teacher/participation_new.php?'.http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'lesson_id'=>$lesson_id]));
  $sep = (strpos($dest,'?')!==false) ? '&' : '?';
  header('Location: '.$dest.$sep.http_build_query(['err'=>'Löschen nicht möglich: Es gibt bereits Einträge zu dieser Stunde.']));
  exit;
}

try{
  $del=$pdo->prepare("DELETE FROM lesson_sessions WHERE id=?");
  $del->execute([$lesson_id]);

  emit_event('lesson_deleted',[
    'lesson_id'=>$lesson_id,
    'class_id'=>$class_id,
    'subject_id'=>$subject_id,
  ]);

  $dest=$return && _starts_with($return,$bp.'/') ? $return : ($bp.'/teacher/lesson.php?msg=deleted');
  header('Location: '.$dest);
  exit;
}catch(Exception $e){
  $dest=$return && _starts_with($return,$bp.'/') ? $return : ($bp.'/teacher/lesson.php');
  $sep = (strpos($dest,'?')!==false) ? '&' : '?';
  header('Location: '.$dest.$sep.http_build_query(['err'=>'Fehler beim Löschen: '.$e->getMessage()]));
  exit;
}
