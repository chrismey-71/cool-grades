<?php
// Bulk counterpart to lesson_delete.php: deletes several marked
// lesson_sessions rows at once (teacher/lesson.php "Bisherige Stunden"
// table), for cleaning up old/stale entries - e.g. ones left behind by a
// WebUntis schedule change that the automatic import cleanup could not
// safely reach because they fell outside the currently exported feed
// window (2026-09 teacher feedback).
//
// Same protection as the single delete: a lesson with linked
// participation_events is never deleted, whether alone or in a batch. A
// bad/tampered id (wrong teacher, ended assignment, archived class) is
// simply skipped rather than failing the whole request - unlike
// lesson_delete.php, this endpoint processes many ids in one request, so a
// single problematic one must not block the rest. Nothing here uses
// require_teacher_active_assignment()/require_class_writable() for that
// reason - both call exit() on failure, which single-item lesson_delete.php
// relies on but a batch cannot; teacher_can_edit_assignment()/
// class_is_readonly() are the same checks in boolean form.

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

$return=(string)($_POST['return'] ?? '');

function _bulk_starts_with($haystack,$needle){
  return $needle!=='' && substr($haystack,0,strlen($needle))===$needle;
}

$rawIds=$_POST['lesson_ids'] ?? [];
if(!is_array($rawIds)) $rawIds=[];
$lessonIds=array_values(array_unique(array_filter(array_map('intval',$rawIds), fn($n)=>$n>0)));

$dest=($return && _bulk_starts_with($return,$bp.'/')) ? $return : ($bp.'/teacher/lesson.php');

if(!$lessonIds){
  app_log('warn','lesson_bulk_delete rejected: no ids marked',['teacher_id'=>(int)$u['id']]);
  $sep=(strpos($dest,'?')!==false) ? '&' : '?';
  header('Location: '.$dest.$sep.http_build_query(['err'=>'Bitte mindestens eine Stunde markieren.']));
  exit;
}

app_log('info','lesson_bulk_delete requested',[
  'teacher_id'=>(int)$u['id'],
  'requested_count'=>count($lessonIds),
]);

$deleted=0;
$blockedEntries=0;
$skippedDenied=0;

foreach($lessonIds as $lessonId){
  $st=$pdo->prepare("SELECT * FROM lesson_sessions WHERE id=?");
  $st->execute([$lessonId]);
  $ls=$st->fetch();
  if(!$ls){ $skippedDenied++; continue; }

  $class_id=(int)$ls['class_id'];
  $subject_id=(int)$ls['subject_id'];

  if(!teacher_can_edit_assignment((int)$u['id'],$class_id,$subject_id)){
    $skippedDenied++;
    continue;
  }
  $class=class_context($pdo,$class_id);
  if(!$class || class_is_readonly($class)){
    $skippedDenied++;
    continue;
  }

  $cst=$pdo->prepare("SELECT COUNT(*) FROM participation_events WHERE lesson_id=?");
  $cst->execute([$lessonId]);
  if((int)$cst->fetchColumn() > 0){
    $blockedEntries++;
    continue;
  }

  $del=$pdo->prepare("DELETE FROM lesson_sessions WHERE id=?");
  $del->execute([$lessonId]);
  if($del->rowCount()===1){
    $deleted++;
    try{
      emit_event('lesson_deleted',[
        'lesson_id'=>$lessonId,
        'class_id'=>$class_id,
        'subject_id'=>$subject_id,
        'bulk'=>true,
      ]);
    }catch(Throwable $eventError){
      app_log('error','lesson_bulk_delete audit event failed',[
        'teacher_id'=>(int)$u['id'],
        'lesson_id'=>$lessonId,
        'exception'=>get_class($eventError),
        'message'=>$eventError->getMessage(),
      ]);
    }
  } else {
    $skippedDenied++;
  }
}

app_log('info','lesson_bulk_delete completed',[
  'teacher_id'=>(int)$u['id'],
  'requested_count'=>count($lessonIds),
  'deleted'=>$deleted,
  'blocked_entries'=>$blockedEntries,
  'skipped_denied'=>$skippedDenied,
]);

$parts=[];
if($deleted>0) $parts[]=$deleted.' gelöscht';
if($blockedEntries>0) $parts[]=$blockedEntries.' wegen vorhandener Mitarbeit-Einträge übersprungen';
if($skippedDenied>0) $parts[]=$skippedDenied.' nicht gefunden oder nicht zulässig';
$bulkMsg=$parts ? implode(', ',$parts).'.' : 'Keine Stunde gelöscht.';

$sep=(strpos($dest,'?')!==false) ? '&' : '?';
header('Location: '.$dest.$sep.http_build_query(['bulk_msg'=>$bulkMsg]));
exit;
