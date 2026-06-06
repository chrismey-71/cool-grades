<?php
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/participation_options.php';
require_once __DIR__.'/participation_observation_groups.php';

function load_participation_criteria(PDO $pdo, int $teacher_id, int $subject_id): array {
  $st=$pdo->prepare("SELECT c.id,c.label,c.category, cs.scope
                     FROM criteria c
                     JOIN criteria_sets cs ON cs.id=c.criteria_set_id
                     WHERE c.active=1 AND IFNULL(c.archived,0)=0 AND (
                       (cs.scope='teacher' AND cs.teacher_id=? AND cs.subject_id=?)
                       OR (cs.scope='subject' AND cs.subject_id=?)
                     )
                     ORDER BY (cs.scope='teacher') DESC, c.category, c.label");
  $st->execute([$teacher_id,$subject_id,$subject_id]);
  return $st->fetchAll();
}

function load_participation_presets(PDO $pdo, int $teacher_id, int $subject_id=0): array {
  $sql="SELECT p.id, p.teacher_id, p.class_id, p.subject_id, p.name, p.payload_json, p.created_at, p.updated_at,
               c.name AS class_name, s.code AS subject_code, s.name AS subject_name
        FROM teacher_participation_presets p
        LEFT JOIN classes c ON c.id=p.class_id
        JOIN subjects s ON s.id=p.subject_id
        WHERE p.teacher_id=?";
  $params=[$teacher_id];
  if($subject_id>0){
    $sql.=" AND p.subject_id=?";
    $params[]=$subject_id;
  }
  $sql.=" ORDER BY s.code ASC, p.updated_at DESC, p.name ASC";
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $rows=$st->fetchAll();
  foreach($rows as &$row){
    $payload=json_decode((string)($row['payload_json'] ?? ''),true);
    $row['payload']=is_array($payload) ? $payload : [];
  }
  unset($row);
  return $rows;
}

function find_participation_preset(PDO $pdo, int $teacher_id, int $preset_id): ?array {
  if($preset_id<=0) return null;
  $st=$pdo->prepare("SELECT p.id, p.teacher_id, p.class_id, p.subject_id, p.name, p.payload_json, p.created_at, p.updated_at,
                            c.name AS class_name, s.code AS subject_code, s.name AS subject_name
                     FROM teacher_participation_presets p
                     LEFT JOIN classes c ON c.id=p.class_id
                     JOIN subjects s ON s.id=p.subject_id
                     WHERE p.id=? AND p.teacher_id=?
                     LIMIT 1");
  $st->execute([$preset_id,$teacher_id]);
  $row=$st->fetch();
  if(!$row) return null;
  $payload=json_decode((string)($row['payload_json'] ?? ''),true);
  $row['payload']=is_array($payload) ? $payload : [];
  return $row;
}

function participation_preset_payload_from_request(array $src): array {
  return [
    'reason_option_id'=>(int)($src['reason_option_id'] ?? 0),
    'impact_option_id'=>(int)($src['impact_option_id'] ?? 0),
    'performance_option_ids'=>array_values(array_filter(array_map('intval',(array)($src['performance_option_ids'] ?? [])), fn($v)=>$v>0)),
    'group_option_ids'=>array_values(array_filter(array_map('intval',(array)($src['group_option_ids'] ?? [])), fn($v)=>$v>0)),
    'social_form_option_id'=>(int)($src['social_form_option_id'] ?? 0),
    'phase_option_id'=>(int)($src['phase_option_id'] ?? 0),
    'homework_option_id'=>(int)($src['homework_option_id'] ?? 0),
    'reason_text'=>trim((string)($src['reason_text'] ?? '')),
    'note'=>trim((string)($src['note'] ?? '')),
    'criteria_ids'=>array_values(array_filter(array_map('intval',(array)($src['criteria_ids'] ?? [])), fn($v)=>$v>0)),
  ];
}

function apply_participation_preset_to_request(array $payload): void {
  $_POST['reason_option_id']=(int)($payload['reason_option_id'] ?? 0);
  $_POST['impact_option_id']=(int)($payload['impact_option_id'] ?? 0);
  $_POST['performance_option_ids']=array_values((array)($payload['performance_option_ids'] ?? []));
  $_POST['group_option_ids']=array_values((array)($payload['group_option_ids'] ?? []));
  $_POST['social_form_option_id']=(int)($payload['social_form_option_id'] ?? 0);
  $_POST['phase_option_id']=(int)($payload['phase_option_id'] ?? 0);
  $_POST['homework_option_id']=(int)($payload['homework_option_id'] ?? 0);
  $_POST['reason_text']=(string)($payload['reason_text'] ?? '');
  $_POST['note']=(string)($payload['note'] ?? '');
  $_POST['criteria_ids']=array_values((array)($payload['criteria_ids'] ?? []));
}

function participation_preset_name(string $name): string {
  $name=trim($name);
  if(function_exists('mb_substr')) return mb_substr($name,0,120);
  return substr($name,0,120);
}

function save_participation_preset(PDO $pdo, int $teacher_id, int $subject_id, string $name, array $payload, int $preset_id=0): int {
  $name=participation_preset_name($name);
  $payload_json=json_encode($payload,JSON_UNESCAPED_UNICODE);
  $now=now_iso();

  if($preset_id>0){
    $st=$pdo->prepare("UPDATE teacher_participation_presets
                       SET name=?, payload_json=?, updated_at=?
                       WHERE id=? AND teacher_id=? AND subject_id=?");
    $st->execute([$name,$payload_json,$now,$preset_id,$teacher_id,$subject_id]);
    return $preset_id;
  }

  $st=$pdo->prepare("INSERT INTO teacher_participation_presets
    (teacher_id,class_id,subject_id,name,payload_json,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json), updated_at=VALUES(updated_at)");
  $st->execute([$teacher_id,null,$subject_id,$name,$payload_json,$now,$now]);

  $st=$pdo->prepare("SELECT id FROM teacher_participation_presets
                     WHERE teacher_id=? AND subject_id=? AND name=?
                     LIMIT 1");
  $st->execute([$teacher_id,$subject_id,$name]);
  return (int)($st->fetchColumn() ?: 0);
}

function delete_participation_preset(PDO $pdo, int $teacher_id, int $preset_id): bool {
  $st=$pdo->prepare("DELETE FROM teacher_participation_presets WHERE id=? AND teacher_id=?");
  $st->execute([$preset_id,$teacher_id]);
  return $st->rowCount()>0;
}
