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

/**
 * Gesamt-Nutzungszahl je Kriterium fuer diese Lehrkraft/dieses Fach (ueber
 * alle Schueler:innen hinweg). Bestimmt in teacher/participation_new.php,
 * welche Kriterien als Schnell-Chips direkt sichtbar sind (die meist-
 * genutzten) und welche hinter "+ weitere Kriterien" stehen.
 * Rueckgabe: [criteria_id => count]
 */
function participation_criteria_usage_counts(PDO $pdo, int $teacher_id, int $subject_id): array {
  $st=$pdo->prepare("SELECT pec.criteria_id, COUNT(*) AS c
                     FROM participation_event_criteria pec
                     JOIN participation_events pe ON pe.id=pec.event_id
                     WHERE pe.teacher_id=? AND pe.subject_id=?
                     GROUP BY pec.criteria_id");
  $st->execute([$teacher_id,$subject_id]);
  $result=[];
  foreach($st->fetchAll() as $row){ $result[(int)$row['criteria_id']]=(int)$row['c']; }
  return $result;
}

/**
 * Je Kriterium, wie oft es bei einer bestimmten Schueler:in (dieselbe
 * Lehrkraft/Klasse/Fach) bereits verwendet wurde. Grundlage fuer die
 * dynamische Chip-Anzahl in teacher/participation_new.php, wenn genau eine
 * Person ausgewaehlt ist ("schon 3x bei ... notiert" statt einer
 * irrefuehrenden Gesamtzahl ueber alle Schueler:innen - siehe Feedback vom
 * 2026-10-02). Rueckgabe: [student_id => [criteria_id => count]]
 */
function participation_criteria_counts_by_student(PDO $pdo, int $teacher_id, int $class_id, int $subject_id, array $student_ids): array {
  if(!$student_ids) return [];
  $in=implode(',',array_fill(0,count($student_ids),'?'));
  $st=$pdo->prepare("SELECT pe.student_id, pec.criteria_id, COUNT(*) AS c
                     FROM participation_event_criteria pec
                     JOIN participation_events pe ON pe.id=pec.event_id
                     WHERE pe.teacher_id=? AND pe.class_id=? AND pe.subject_id=? AND pe.student_id IN ($in)
                     GROUP BY pe.student_id, pec.criteria_id");
  $st->execute(array_merge([$teacher_id,$class_id,$subject_id],array_map('intval',$student_ids)));
  $result=[];
  foreach($st->fetchAll() as $row){
    $sid=(int)$row['student_id'];
    if(!isset($result[$sid])) $result[$sid]=[];
    $result[$sid][(int)$row['criteria_id']]=(int)$row['c'];
  }
  return $result;
}

/**
 * Aggregierte Kriterien-Nutzung je Schueler:in einer Klasse/eines Fachs in
 * einem Zeitraum - Grundlage fuer teacher/criteria_profile.php
 * ("Kriterien-Profil", analog zu teacher/competence_profile.php). Nur
 * Schueler:innen mit mindestens einem Kriterien-Eintrag im Zeitraum werden
 * zurueckgegeben. 'total_entries' zaehlt die Mitarbeit-Eintraege (nicht die
 * Kriterien-Nennungen) mit mindestens einem erfassten Kriterium - ein
 * einzelner Eintrag kann mehrere Kriterien zugleich tragen.
 * Rueckgabe: [student_id => ['total_entries'=>int, 'by_criteria'=>[criteria_id=>count]]]
 */
function criteria_profile_aggregate(PDO $pdo, int $class_id, int $subject_id, string $date_from, string $date_to, int $teacher_id=0): array {
  $sql="SELECT pe.student_id, pec.criteria_id, COUNT(*) AS c
        FROM participation_event_criteria pec
        JOIN participation_events pe ON pe.id=pec.event_id
        WHERE pe.class_id=? AND pe.subject_id=? AND pe.event_date BETWEEN ? AND ?";
  $params=[$class_id,$subject_id,$date_from,$date_to];
  if($teacher_id>0){ $sql.=" AND pe.teacher_id=?"; $params[]=$teacher_id; }
  $sql.=" GROUP BY pe.student_id, pec.criteria_id";
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $result=[];
  foreach($st->fetchAll() as $row){
    $sid=(int)$row['student_id'];
    if(!isset($result[$sid])) $result[$sid]=['total_entries'=>0,'by_criteria'=>[]];
    $result[$sid]['by_criteria'][(int)$row['criteria_id']]=(int)$row['c'];
  }

  $sql2="SELECT pe.student_id, COUNT(DISTINCT pe.id) AS c
        FROM participation_events pe
        JOIN participation_event_criteria pec ON pec.event_id=pe.id
        WHERE pe.class_id=? AND pe.subject_id=? AND pe.event_date BETWEEN ? AND ?";
  $params2=[$class_id,$subject_id,$date_from,$date_to];
  if($teacher_id>0){ $sql2.=" AND pe.teacher_id=?"; $params2[]=$teacher_id; }
  $sql2.=" GROUP BY pe.student_id";
  $st2=$pdo->prepare($sql2);
  $st2->execute($params2);
  foreach($st2->fetchAll() as $row){
    $sid=(int)$row['student_id'];
    if(!isset($result[$sid])) $result[$sid]=['total_entries'=>0,'by_criteria'=>[]];
    $result[$sid]['total_entries']=(int)$row['c'];
  }
  return $result;
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
