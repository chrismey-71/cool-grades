<?php
require_once __DIR__.'/../lib/layout.php';
require_once __DIR__.'/../lib/events.php';
require_once __DIR__.'/../lib/school_years.php';

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
  app_log('warn','lesson_delete rejected: missing lesson id',[
    'teacher_id'=>(int)$u['id'],
  ]);
  http_response_code(400);
  exit('lesson_id fehlt');
}

app_log('info','lesson_delete requested',[
  'teacher_id'=>(int)$u['id'],
  'lesson_id'=>$lesson_id,
]);

$st=$pdo->prepare("SELECT * FROM lesson_sessions WHERE id=?");
$st->execute([$lesson_id]);
$ls=$st->fetch();
if(!$ls){
  app_log('warn','lesson_delete rejected: lesson not found',[
    'teacher_id'=>(int)$u['id'],
    'lesson_id'=>$lesson_id,
  ]);
  http_response_code(404);
  exit('Stunde nicht gefunden');
}

$class_id=(int)$ls['class_id'];
$subject_id=(int)$ls['subject_id'];
require_teacher_active_assignment($u,$class_id,$subject_id);
require_class_writable($pdo,$class_id);

$st=$pdo->prepare("SELECT COUNT(*) FROM participation_events WHERE lesson_id=?");
$st->execute([$lesson_id]);
$cnt=(int)$st->fetchColumn();

if($cnt>0){
  app_log('warn','lesson_delete rejected: linked participation exists',[
    'teacher_id'=>(int)$u['id'],
    'lesson_id'=>$lesson_id,
    'class_id'=>$class_id,
    'subject_id'=>$subject_id,
    'participation_count'=>$cnt,
  ]);
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
  if($del->rowCount() !== 1){
    throw new RuntimeException('Die Stunde konnte nicht eindeutig gelöscht werden.');
  }

  // The lesson is already deleted at this point. A failing audit write must not
  // turn that successful action into a misleading error for the teacher.
  try{
    emit_event('lesson_deleted',[
      'lesson_id'=>$lesson_id,
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
    ]);
  }catch(Throwable $eventError){
    app_log('error','lesson_delete audit event failed',[
      'teacher_id'=>(int)$u['id'],
      'lesson_id'=>$lesson_id,
      'class_id'=>$class_id,
      'subject_id'=>$subject_id,
      'exception'=>get_class($eventError),
      'message'=>$eventError->getMessage(),
    ]);
  }

  app_log('info','lesson_delete completed',[
    'teacher_id'=>(int)$u['id'],
    'lesson_id'=>$lesson_id,
    'class_id'=>$class_id,
    'subject_id'=>$subject_id,
  ]);

  $dest=$return && _starts_with($return,$bp.'/') ? $return : ($bp.'/teacher/lesson.php?msg=deleted');
  header('Location: '.$dest);
  exit;
}catch(Throwable $e){
  app_log('error','lesson_delete failed',[
    'teacher_id'=>(int)$u['id'],
    'lesson_id'=>$lesson_id,
    'class_id'=>$class_id,
    'subject_id'=>$subject_id,
    'exception'=>get_class($e),
    'message'=>$e->getMessage(),
  ]);
  $dest=$return && _starts_with($return,$bp.'/') ? $return : ($bp.'/teacher/lesson.php');
  $sep = (strpos($dest,'?')!==false) ? '&' : '?';
  header('Location: '.$dest.$sep.http_build_query(['err'=>'Fehler beim Löschen: '.$e->getMessage()]));
  exit;
}
