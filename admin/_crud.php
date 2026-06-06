<?php
require_once __DIR__.'/../lib/db.php';
function _crud_schema(): array {
  return [
    'classes' => ['name','school_period_set_id','school_type','school_form_id','year','label','assessment_system','predecessor_class_id','is_archived','is_departed'],
    'subjects' => ['code','name','is_schularbeit_subject'],
    'students' => ['first_name','last_name','class_id','is_active'],
    'users' => ['username','first_name','last_name','role','pass_hash','is_active','must_change_password','created_at'],
    'criteria_suggestions' => ['school_type','subject_code','category','label','description','active','archived','sort','created_at','updated_at'],
    'criteria_sets' => ['name','scope','subject_id','teacher_id'],
    'criteria' => ['criteria_set_id','label','category','active','archived'],
  ];
}
function _crud_guard(string $table, array $data=[]): void {
  $schema = _crud_schema();
  if(!isset($schema[$table])) throw new InvalidArgumentException('Invalid table');
  foreach(array_keys($data) as $col){
    if(!in_array($col,$schema[$table],true)) throw new InvalidArgumentException('Invalid column');
  }
}
function upsert(string $table,array $data,?int $id=null): int {
  _crud_guard($table,$data);
  $pdo=db();
  if($id===null){
    $cols=array_keys($data);
    $sql="INSERT INTO $table (".implode(',',$cols).") VALUES (".implode(',',array_fill(0,count($cols),'?')).")";
    $st=$pdo->prepare($sql); $st->execute(array_values($data)); return (int)$pdo->lastInsertId();
  }
  $cols=array_keys($data);
  $sets=implode(',',array_map(fn($c)=>"$c=?",$cols));
  $sql="UPDATE $table SET $sets WHERE id=?";
  $st=$pdo->prepare($sql); $vals=array_values($data); $vals[]=$id; $st->execute($vals); return $id;
}
function del(string $table,int $id): void {
  _crud_guard($table);
  $st=db()->prepare("DELETE FROM $table WHERE id=?");
  $st->execute([$id]);
}
