<?php
require_once __DIR__.'/db.php';

function schools_load(PDO $pdo, bool $includeInactive = false): array {
  $sql = "SELECT * FROM schools";
  if(!$includeInactive) $sql .= " WHERE active=1";
  $sql .= " ORDER BY active DESC, name";
  return $pdo->query($sql)->fetchAll();
}

function school_forms_load(PDO $pdo, bool $includeInactive = false): array {
  $sql = "SELECT sf.*, s.name AS school_name
          FROM school_forms sf
          JOIN schools s ON s.id=sf.school_id";
  if(!$includeInactive) $sql .= " WHERE sf.active=1 AND s.active=1";
  $sql .= " ORDER BY s.name, sf.code, sf.name";
  return $pdo->query($sql)->fetchAll();
}

function school_form_find(PDO $pdo, int $id): ?array {
  if($id <= 0) return null;
  $st=$pdo->prepare("SELECT sf.*, s.name AS school_name
                     FROM school_forms sf
                     JOIN schools s ON s.id=sf.school_id
                     WHERE sf.id=? LIMIT 1");
  $st->execute([$id]);
  $row=$st->fetch();
  return $row ?: null;
}

function school_form_default_id(PDO $pdo): int {
  $id=(int)$pdo->query("SELECT sf.id
                        FROM school_forms sf
                        JOIN schools s ON s.id=sf.school_id
                        WHERE sf.active=1 AND s.active=1
                        ORDER BY s.name, sf.code
                        LIMIT 1")->fetchColumn();
  return $id;
}

function school_form_label(array $form): string {
  $code=(string)($form['code'] ?? '');
  $name=(string)($form['name'] ?? '');
  $school=(string)($form['school_name'] ?? '');
  $label = trim($name) !== '' ? $name : $code;
  if($code !== '' && $label !== $code) $label .= ' ('.$code.')';
  if($school !== '') $label = $school.' · '.$label;
  return $label;
}

function school_forms_by_id(array $forms): array {
  $out=[];
  foreach($forms as $form) $out[(int)$form['id']]=$form;
  return $out;
}

function teacher_school_ids(PDO $pdo, int $teacherId): array {
  if($teacherId<=0) return [];
  $st=$pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id=? ORDER BY school_id");
  $st->execute([$teacherId]);
  return array_map('intval',array_column($st->fetchAll(),'school_id'));
}

function teacher_schools_sync(PDO $pdo, int $teacherId, array $schoolIds): void {
  $schoolIds=array_values(array_unique(array_filter(array_map('intval',$schoolIds),static fn(int $id): bool => $id>0)));
  $valid=[];
  if($schoolIds){
    $in=implode(',',array_fill(0,count($schoolIds),'?'));
    $st=$pdo->prepare("SELECT id FROM schools WHERE id IN ($in)");
    $st->execute($schoolIds);
    $valid=array_map('intval',array_column($st->fetchAll(),'id'));
  }
  if(!$valid) throw new RuntimeException('Bitte mindestens eine gültige Schule zuordnen.');

  $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id=?")->execute([$teacherId]);
  $st=$pdo->prepare("INSERT INTO teacher_schools(teacher_id,school_id) VALUES(?,?)");
  foreach($valid as $schoolId) $st->execute([$teacherId,$schoolId]);
}

function subject_school_form_ids(PDO $pdo, int $subjectId): array {
  if($subjectId<=0) return [];
  $st=$pdo->prepare("SELECT school_form_id FROM subject_school_forms WHERE subject_id=? ORDER BY school_form_id");
  $st->execute([$subjectId]);
  return array_map('intval',array_column($st->fetchAll(),'school_form_id'));
}

function subject_school_forms_sync(PDO $pdo, int $subjectId, array $schoolFormIds): void {
  $schoolFormIds=array_values(array_unique(array_filter(array_map('intval',$schoolFormIds),static fn(int $id): bool => $id>0)));
  $valid=[];
  if($schoolFormIds){
    $in=implode(',',array_fill(0,count($schoolFormIds),'?'));
    $st=$pdo->prepare("SELECT id FROM school_forms WHERE id IN ($in)");
    $st->execute($schoolFormIds);
    $valid=array_map('intval',array_column($st->fetchAll(),'id'));
  }
  if(!$valid) throw new RuntimeException('Bitte mindestens eine Schulform für das Fach auswählen.');

  $pdo->prepare("DELETE FROM subject_school_forms WHERE subject_id=?")->execute([$subjectId]);
  $st=$pdo->prepare("INSERT INTO subject_school_forms(subject_id,school_form_id) VALUES(?,?)");
  foreach($valid as $schoolFormId) $st->execute([$subjectId,$schoolFormId]);
}

function criteria_suggestion_school_form_ids(PDO $pdo, int $suggestionId): array {
  if($suggestionId<=0) return [];
  $st=$pdo->prepare("SELECT school_form_id FROM criteria_suggestion_school_forms WHERE suggestion_id=? ORDER BY school_form_id");
  $st->execute([$suggestionId]);
  return array_map('intval',array_column($st->fetchAll(),'school_form_id'));
}

function criteria_suggestion_school_forms_sync(PDO $pdo, int $suggestionId, array $schoolFormIds): void {
  $schoolFormIds=array_values(array_unique(array_filter(array_map('intval',$schoolFormIds),static fn(int $id): bool => $id>0)));
  $valid=[];
  if($schoolFormIds){
    $in=implode(',',array_fill(0,count($schoolFormIds),'?'));
    $st=$pdo->prepare("SELECT id FROM school_forms WHERE id IN ($in)");
    $st->execute($schoolFormIds);
    $valid=array_map('intval',array_column($st->fetchAll(),'id'));
  }
  $pdo->prepare("DELETE FROM criteria_suggestion_school_forms WHERE suggestion_id=?")->execute([$suggestionId]);
  if(!$valid) return; // Ohne Auswahl: Vorschlag gilt bewusst für alle Schulformen.
  $st=$pdo->prepare("INSERT INTO criteria_suggestion_school_forms(suggestion_id,school_form_id) VALUES(?,?)");
  foreach($valid as $schoolFormId) $st->execute([$suggestionId,$schoolFormId]);
}

function class_school_id(PDO $pdo, int $classId): int {
  if($classId<=0) return 0;
  $st=$pdo->prepare("SELECT sf.school_id FROM classes c JOIN school_forms sf ON sf.id=c.school_form_id WHERE c.id=? LIMIT 1");
  $st->execute([$classId]);
  return (int)($st->fetchColumn() ?: 0);
}

function teacher_assignment_context_allowed(PDO $pdo, int $teacherId, int $classId, int $subjectId): bool {
  if($teacherId<=0 || $classId<=0 || $subjectId<=0) return false;
  $st=$pdo->prepare("SELECT 1
                     FROM classes c
                     JOIN school_forms sf ON sf.id=c.school_form_id
                     JOIN teacher_schools ts ON ts.school_id=sf.school_id AND ts.teacher_id=?
                     JOIN subject_school_forms ssf ON ssf.school_form_id=sf.id AND ssf.subject_id=?
                     WHERE c.id=?
                     LIMIT 1");
  $st->execute([$teacherId,$subjectId,$classId]);
  return (bool)$st->fetchColumn();
}
