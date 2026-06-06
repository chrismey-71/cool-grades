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

function emit_event(string $type,array $payload=[]): void {
  cleanup_expired_events();
  $u=current_user();
  $st=db()->prepare("INSERT INTO events (type, actor_user_id, created_at, payload_json) VALUES (?,?,?,?)");
  $data=$payload;
  if($u){
    $data=array_merge([
      'actor_id'=>(int)$u['id'],
      'actor_username'=>(string)$u['username'],
      'actor_name'=>trim(($u['last_name']??'').', '.($u['first_name']??'')),
      'actor_role'=>(string)$u['role'],
    ],$payload);
  }
  $st->execute([$type,$u?(int)$u['id']:null,now_iso(),json_encode($data,JSON_UNESCAPED_UNICODE)]);
}
