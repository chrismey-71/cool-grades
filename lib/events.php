<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/settings.php';

function cleanup_expired_events(): void {
  static $done = false;
  if($done) return;
  $done = true;

  $retentionDays = (int)app_setting_get('event_retention_days', 30);
  if($retentionDays <= 0) return;

  try{
    $cutoff = (new DateTimeImmutable('now'))->modify('-'.$retentionDays.' days')->format('Y-m-d H:i:s');
    $st = db()->prepare("DELETE FROM events WHERE created_at < ?");
    $st->execute([$cutoff]);
  }catch(Throwable $e){
    // Cleanup failures should never block the actual event write.
  }
}

function event_school_id_from_payload(PDO $pdo, array $payload): ?int {
  if(isset($payload['school_id']) && (int)$payload['school_id']>0) return (int)$payload['school_id'];
  foreach(['class_id','source_class_id','target_class_id'] as $field){
    $classId=(int)($payload[$field] ?? 0);
    if($classId<=0) continue;
    try{
      $st=$pdo->prepare("SELECT sf.school_id FROM classes c JOIN school_forms sf ON sf.id=c.school_form_id WHERE c.id=? LIMIT 1");
      $st->execute([$classId]);
      $schoolId=(int)($st->fetchColumn() ?: 0);
      if($schoolId>0) return $schoolId;
    }catch(Throwable $e){ /* Ereignisprotokoll darf Fachvorgänge nie blockieren. */ }
  }
  return null;
}

function emit_event(string $type,array $payload=[]): void {
  cleanup_expired_events();
  $u=current_user();
  $pdo=db();
  $data=$payload;
  if($u){
    $data=array_merge([
      'actor_id'=>(int)$u['id'],
      'actor_username'=>(string)$u['username'],
      'actor_name'=>trim(($u['last_name']??'').', '.($u['first_name']??'')),
      'actor_role'=>(string)$u['role'],
    ],$payload);
  }
  $schoolId=event_school_id_from_payload($pdo,$data);
  $st=$pdo->prepare("INSERT INTO events (type, actor_user_id, school_id, created_at, payload_json) VALUES (?,?,?,?,?)");
  $st->execute([$type,$u?(int)$u['id']:null,$schoolId,now_iso(),json_encode($data,JSON_UNESCAPED_UNICODE)]);
}
