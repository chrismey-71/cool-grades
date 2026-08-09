<?php

/**
 * Assignment records remain the historical access record for a teacher. They
 * may be ended, but must not be deleted while they still protect documented work.
 */
function teacher_assignment_status_label(string $status): string {
  return $status === 'ended' ? 'beendet · Lesemodus' : 'aktiv';
}

function teacher_assignment_documentation_map(PDO $pdo, array $assignments): array {
  $map=[];
  $teacherIds=[];
  $classIds=[];
  $subjectIds=[];
  foreach($assignments as $assignment){
    $teacherId=(int)($assignment['teacher_id'] ?? 0);
    $classId=(int)($assignment['class_id'] ?? 0);
    $subjectId=(int)($assignment['subject_id'] ?? 0);
    if($teacherId<=0 || $classId<=0 || $subjectId<=0) continue;
    $key=$teacherId.':'.$classId.':'.$subjectId;
    $map[$key]=[
      'participation'=>0,
      'lessons'=>0,
      'oral'=>0,
      'written'=>0,
      'final_assessments'=>0,
      'groups'=>0,
      'weights'=>0,
      'has_documentation'=>false,
      'document_count'=>0,
    ];
    $teacherIds[$teacherId]=$teacherId;
    $classIds[$classId]=$classId;
    $subjectIds[$subjectId]=$subjectId;
  }
  if(!$map) return $map;

  $teacherPlaceholders=implode(',',array_fill(0,count($teacherIds),'?'));
  $classPlaceholders=implode(',',array_fill(0,count($classIds),'?'));
  $subjectPlaceholders=implode(',',array_fill(0,count($subjectIds),'?'));
  $params=array_merge(array_values($teacherIds),array_values($classIds),array_values($subjectIds));

  $load=function(string $table, string $counter, string $teacherColumn='teacher_id') use ($pdo,$teacherPlaceholders,$classPlaceholders,$subjectPlaceholders,$params,&$map): void {
    $sql="SELECT {$teacherColumn} AS teacher_id,class_id,subject_id,COUNT(*) AS item_count
          FROM {$table}
          WHERE {$teacherColumn} IN ({$teacherPlaceholders})
            AND class_id IN ({$classPlaceholders})
            AND subject_id IN ({$subjectPlaceholders})
          GROUP BY {$teacherColumn},class_id,subject_id";
    $st=$pdo->prepare($sql);
    $st->execute($params);
    foreach($st->fetchAll() as $row){
      $key=(int)$row['teacher_id'].':'.(int)$row['class_id'].':'.(int)$row['subject_id'];
      if(isset($map[$key])) $map[$key][$counter]=(int)$row['item_count'];
    }
  };

  $load('participation_events','participation');
  $load('lesson_sessions','lessons');
  $load('oral_assessments','oral');
  $load('exams','written');
  $load('teacher_student_groups','groups');
  $load('assessment_weight_settings','weights');

  // A final assessment can be created by one teacher and later changed by another.
  $finalSql="SELECT ownership.teacher_id,ownership.class_id,ownership.subject_id,COUNT(DISTINCT ownership.id) AS item_count
             FROM (
               SELECT id,created_by AS teacher_id,class_id,subject_id FROM final_assessments
               UNION
               SELECT id,updated_by AS teacher_id,class_id,subject_id FROM final_assessments
             ) ownership
             WHERE ownership.teacher_id IN ({$teacherPlaceholders})
               AND ownership.class_id IN ({$classPlaceholders})
               AND ownership.subject_id IN ({$subjectPlaceholders})
             GROUP BY ownership.teacher_id,ownership.class_id,ownership.subject_id";
  $st=$pdo->prepare($finalSql);
  $st->execute($params);
  foreach($st->fetchAll() as $row){
    $key=(int)$row['teacher_id'].':'.(int)$row['class_id'].':'.(int)$row['subject_id'];
    if(isset($map[$key])) $map[$key]['final_assessments']=(int)$row['item_count'];
  }

  foreach($map as &$summary){
    $summary['document_count']=(int)$summary['participation']+(int)$summary['lessons']+(int)$summary['oral']+(int)$summary['written']+(int)$summary['final_assessments'];
    $summary['has_documentation']=$summary['document_count']>0 || (int)$summary['groups']>0 || (int)$summary['weights']>0;
  }
  unset($summary);
  return $map;
}

function teacher_assignment_documentation_text(array $summary): string {
  $parts=[];
  $labels=[
    'participation'=>'Mitarbeit',
    'lessons'=>'Stunden',
    'oral'=>'mündlich',
    'written'=>'schriftlich',
    'final_assessments'=>'Abschluss',
  ];
  foreach($labels as $key=>$label){
    $count=(int)($summary[$key] ?? 0);
    if($count>0) $parts[]=$count.' '.$label;
  }
  if((int)($summary['groups'] ?? 0)>0) $parts[]='Gruppen';
  if((int)($summary['weights'] ?? 0)>0) $parts[]='Gewichtung';
  return $parts ? implode(' · ',$parts) : 'keine Dokumentationen';
}
