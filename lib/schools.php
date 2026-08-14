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

function user_school_ids(PDO $pdo, int $userId): array {
  if($userId<=0) return [];
  $st=$pdo->prepare("SELECT school_id FROM teacher_schools WHERE teacher_id=? ORDER BY school_id");
  $st->execute([$userId]);
  return array_map('intval',array_column($st->fetchAll(),'school_id'));
}

function teacher_school_ids(PDO $pdo, int $teacherId): array {
  return user_school_ids($pdo,$teacherId);
}

function teacher_school_context_id(PDO $pdo, int $teacherId): int {
  if($teacherId <= 0) return 0;

  $assigned = teacher_school_ids($pdo, $teacherId);
  if(!$assigned){
    $st=$pdo->prepare("SELECT DISTINCT sf.school_id
                       FROM teacher_assignments ta
                       JOIN classes c ON c.id=ta.class_id
                       JOIN school_forms sf ON sf.id=c.school_form_id
                       JOIN schools s ON s.id=sf.school_id
                       WHERE ta.teacher_id=? AND IFNULL(s.active,1)=1
                       ORDER BY s.name, sf.school_id");
    $st->execute([$teacherId]);
    $assigned = array_map('intval', array_column($st->fetchAll(), 'school_id'));
  }

  $assigned = array_values(array_unique(array_filter($assigned, static fn(int $id): bool => $id > 0)));
  if(!$assigned) return 0;

  $selected = (int)($_SESSION['teacher_school_context_id'] ?? 0);
  if($selected > 0 && in_array($selected, $assigned, true)) return $selected;

  $selected = (int)$assigned[0];
  $_SESSION['teacher_school_context_id'] = $selected;
  return $selected;
}

function admin_assigned_school_ids(PDO $pdo, $admin): array {
  $adminId=is_array($admin) ? (int)($admin['id'] ?? 0) : (int)$admin;
  if($adminId<=0) return [];
  return user_school_ids($pdo,$adminId);
}

function admin_is_superadmin(PDO $pdo, $admin): bool {
  return admin_assigned_school_ids($pdo,$admin) === [];
}

function admin_can_access_school(PDO $pdo, $admin, int $schoolId): bool {
  if($schoolId<=0) return admin_is_superadmin($pdo,$admin);
  if(admin_is_superadmin($pdo,$admin)) return true;
  return in_array($schoolId,admin_assigned_school_ids($pdo,$admin),true);
}

function require_admin_school_access(PDO $pdo, $admin, int $schoolId): void {
  if(admin_can_access_school($pdo,$admin,$schoolId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für diese Schule.');
}

function admin_allowed_school_ids(PDO $pdo, $admin): array {
  if(admin_is_superadmin($pdo,$admin)){
    $rows=$pdo->query("SELECT id FROM schools ORDER BY id")->fetchAll();
    return array_map('intval',array_column($rows,'id'));
  }
  return admin_assigned_school_ids($pdo,$admin);
}

function admin_schools_load(PDO $pdo, $admin, bool $includeInactive = false): array {
  if(admin_is_superadmin($pdo,$admin)) return schools_load($pdo,$includeInactive);
  $ids=admin_assigned_school_ids($pdo,$admin);
  if(!$ids) return [];
  $in=implode(',',array_fill(0,count($ids),'?'));
  $sql="SELECT * FROM schools WHERE id IN ($in)";
  if(!$includeInactive) $sql.=" AND active=1";
  $sql.=" ORDER BY active DESC, name";
  $st=$pdo->prepare($sql);
  $st->execute($ids);
  return $st->fetchAll();
}

function admin_school_forms_load(PDO $pdo, $admin, bool $includeInactive = false): array {
  if(admin_is_superadmin($pdo,$admin)) return school_forms_load($pdo,$includeInactive);
  $ids=admin_assigned_school_ids($pdo,$admin);
  if(!$ids) return [];
  $in=implode(',',array_fill(0,count($ids),'?'));
  $sql="SELECT sf.*, s.name AS school_name
        FROM school_forms sf
        JOIN schools s ON s.id=sf.school_id
        WHERE sf.school_id IN ($in)";
  if(!$includeInactive) $sql.=" AND sf.active=1 AND s.active=1";
  $sql.=" ORDER BY s.name, sf.code, sf.name";
  $st=$pdo->prepare($sql);
  $st->execute($ids);
  return $st->fetchAll();
}

function admin_can_access_school_form(PDO $pdo, $admin, int $schoolFormId): bool {
  if($schoolFormId<=0) return false;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $st=$pdo->prepare("SELECT school_id FROM school_forms WHERE id=? LIMIT 1");
  $st->execute([$schoolFormId]);
  $schoolId=(int)($st->fetchColumn() ?: 0);
  return $schoolId>0 && admin_can_access_school($pdo,$admin,$schoolId);
}

function require_admin_school_form_access(PDO $pdo, $admin, int $schoolFormId): void {
  if(admin_can_access_school_form($pdo,$admin,$schoolFormId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für diese Schulform.');
}

function admin_can_access_class(PDO $pdo, $admin, int $classId): bool {
  if($classId<=0) return false;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $schoolId=class_school_id($pdo,$classId);
  return $schoolId>0 && admin_can_access_school($pdo,$admin,$schoolId);
}

function require_admin_class_access(PDO $pdo, $admin, int $classId): void {
  if(admin_can_access_class($pdo,$admin,$classId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für diese Klasse.');
}

function admin_can_access_student(PDO $pdo, $admin, int $studentId): bool {
  if($studentId<=0) return false;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $ids=admin_assigned_school_ids($pdo,$admin);
  if(!$ids) return false;
  $in=implode(',',array_fill(0,count($ids),'?'));
  $sql="SELECT 1
        FROM students s
        LEFT JOIN class_enrollments ce ON ce.student_id=s.id
        LEFT JOIN classes c ON c.id=COALESCE(ce.class_id,s.class_id)
        LEFT JOIN school_forms sf ON sf.id=c.school_form_id
        WHERE s.id=? AND sf.school_id IN ($in)
        LIMIT 1";
  $st=$pdo->prepare($sql);
  $st->execute(array_merge([$studentId],$ids));
  return (bool)$st->fetchColumn();
}

function require_admin_student_access(PDO $pdo, $admin, int $studentId): void {
  if(admin_can_access_student($pdo,$admin,$studentId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für diese:n Schüler:in.');
}

function admin_can_access_subject(PDO $pdo, $admin, int $subjectId): bool {
  if($subjectId<=0) return false;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $ids=admin_assigned_school_ids($pdo,$admin);
  if(!$ids) return false;
  $in=implode(',',array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT 1
                     FROM subject_school_forms ssf
                     JOIN school_forms sf ON sf.id=ssf.school_form_id
                     WHERE ssf.subject_id=? AND sf.school_id IN ($in)
                     LIMIT 1");
  $st->execute(array_merge([$subjectId],$ids));
  return (bool)$st->fetchColumn();
}

function admin_subject_has_external_school_forms(PDO $pdo, $admin, int $subjectId): bool {
  if($subjectId<=0 || admin_is_superadmin($pdo,$admin)) return false;
  $ids=admin_assigned_school_ids($pdo,$admin);
  if(!$ids) return true;
  $in=implode(',',array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT 1
                     FROM subject_school_forms ssf
                     JOIN school_forms sf ON sf.id=ssf.school_form_id
                     WHERE ssf.subject_id=? AND sf.school_id NOT IN ($in)
                     LIMIT 1");
  $st->execute(array_merge([$subjectId],$ids));
  return (bool)$st->fetchColumn();
}

function require_admin_subject_access(PDO $pdo, $admin, int $subjectId): void {
  if(admin_can_access_subject($pdo,$admin,$subjectId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für dieses Fach.');
}

function admin_can_access_criteria_suggestion(PDO $pdo, $admin, int $suggestionId): bool {
  if($suggestionId<=0) return false;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $ids=admin_assigned_school_ids($pdo,$admin);
  if(!$ids) return false;
  $in=implode(',',array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT 1
                     FROM criteria_suggestion_school_forms cssf
                     JOIN school_forms sf ON sf.id=cssf.school_form_id
                     WHERE cssf.suggestion_id=? AND sf.school_id IN ($in)
                     LIMIT 1");
  $st->execute(array_merge([$suggestionId],$ids));
  return (bool)$st->fetchColumn();
}

function require_admin_criteria_suggestion_access(PDO $pdo, $admin, int $suggestionId): void {
  if(admin_can_access_criteria_suggestion($pdo,$admin,$suggestionId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für diesen Kriterienvorschlag.');
}

function admin_can_manage_user(PDO $pdo, $admin, int $targetUserId): bool {
  if($targetUserId<=0) return false;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $targetSchools=user_school_ids($pdo,$targetUserId);
  if(!$targetSchools) return false; // Superadmins werden nur von Superadmins verwaltet.
  return (bool)array_intersect($targetSchools,admin_assigned_school_ids($pdo,$admin));
}

function admin_user_has_external_schools(PDO $pdo, $admin, int $targetUserId): bool {
  if($targetUserId<=0 || admin_is_superadmin($pdo,$admin)) return false;
  $allowed=admin_assigned_school_ids($pdo,$admin);
  $targetSchools=user_school_ids($pdo,$targetUserId);
  if(!$targetSchools) return true;
  return (bool)array_diff($targetSchools,$allowed);
}

function require_admin_user_manage_access(PDO $pdo, $admin, int $targetUserId): void {
  if(admin_can_manage_user($pdo,$admin,$targetUserId)) return;
  http_response_code(403);
  exit('Keine Berechtigung für dieses Benutzerkonto.');
}

function admin_can_access_school_period(PDO $pdo, $admin, int $periodId, bool $allowGlobalRead = true): bool {
  if($periodId<=0) return true;
  if(admin_is_superadmin($pdo,$admin)) return true;
  $st=$pdo->prepare("SELECT school_id FROM school_period_sets WHERE id=? LIMIT 1");
  $st->execute([$periodId]);
  $value=$st->fetchColumn();
  if($value===false) return false;
  if($value===null) return $allowGlobalRead;
  return admin_can_access_school($pdo,$admin,(int)$value);
}

function require_admin_school_period_access(PDO $pdo, $admin, int $periodId, bool $allowGlobalRead = true): void {
  if(admin_can_access_school_period($pdo,$admin,$periodId,$allowGlobalRead)) return;
  http_response_code(403);
  exit('Keine Berechtigung für dieses Schuljahr.');
}

function admin_filter_school_id(PDO $pdo, $admin, int $requestedSchoolId, bool $allowAllForSuperadmin = true): int {
  if(admin_is_superadmin($pdo,$admin)) return $allowAllForSuperadmin ? max(0,$requestedSchoolId) : $requestedSchoolId;
  if($requestedSchoolId>0 && admin_can_access_school($pdo,$admin,$requestedSchoolId)) return $requestedSchoolId;
  $ids=admin_assigned_school_ids($pdo,$admin);
  return (int)($ids[0] ?? 0);
}

/** Returns a stable visual tone for school-related form controls. */
function school_tone_class(int $schoolId): string {
  return 'school-tone-'.(($schoolId % 5) + 1);
}

function user_schools_sync(PDO $pdo, int $userId, array $schoolIds, bool $requireAtLeastOne = true): void {
  $schoolIds=array_values(array_unique(array_filter(array_map('intval',$schoolIds),static fn(int $id): bool => $id>0)));
  $valid=[];
  if($schoolIds){
    $in=implode(',',array_fill(0,count($schoolIds),'?'));
    $st=$pdo->prepare("SELECT id FROM schools WHERE id IN ($in)");
    $st->execute($schoolIds);
    $valid=array_map('intval',array_column($st->fetchAll(),'id'));
  }
  if(!$valid && $requireAtLeastOne) throw new RuntimeException('Bitte mindestens eine gültige Schule zuordnen.');

  $pdo->prepare("DELETE FROM teacher_schools WHERE teacher_id=?")->execute([$userId]);
  $st=$pdo->prepare("INSERT INTO teacher_schools(teacher_id,school_id) VALUES(?,?)");
  foreach($valid as $schoolId) $st->execute([$userId,$schoolId]);
}

function teacher_schools_sync(PDO $pdo, int $teacherId, array $schoolIds): void {
  user_schools_sync($pdo,$teacherId,$schoolIds);
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

function subject_school_forms_sync_for_admin(PDO $pdo, $admin, int $subjectId, array $schoolFormIds): void {
  if(admin_is_superadmin($pdo,$admin)){
    subject_school_forms_sync($pdo,$subjectId,$schoolFormIds);
    return;
  }
  $allowedForms=admin_school_forms_load($pdo,$admin,true);
  $allowedIds=array_map('intval',array_column($allowedForms,'id'));
  $allowedSet=array_flip($allowedIds);
  $requested=array_values(array_unique(array_filter(array_map('intval',$schoolFormIds),static fn(int $id): bool => $id>0)));
  $valid=array_values(array_filter($requested,static fn(int $id): bool => isset($allowedSet[$id])));
  if(!$valid) throw new RuntimeException('Bitte mindestens eine Schulform aus den eigenen Schulen auswählen.');

  $in=implode(',',array_fill(0,count($allowedIds),'?'));
  $params=array_merge([$subjectId],$allowedIds);
  $pdo->prepare("DELETE FROM subject_school_forms WHERE subject_id=? AND school_form_id IN ($in)")->execute($params);
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

function criteria_suggestion_school_forms_sync_for_admin(PDO $pdo, $admin, int $suggestionId, array $schoolFormIds): void {
  if(admin_is_superadmin($pdo,$admin)){
    criteria_suggestion_school_forms_sync($pdo,$suggestionId,$schoolFormIds);
    return;
  }
  $allowedForms=admin_school_forms_load($pdo,$admin,true);
  $allowedIds=array_map('intval',array_column($allowedForms,'id'));
  if(!$allowedIds) throw new RuntimeException('Für dieses Administrationskonto ist keine Schule zugeordnet.');
  $allowedSet=array_flip($allowedIds);
  $requested=array_values(array_unique(array_filter(array_map('intval',$schoolFormIds),static fn(int $id): bool => $id>0)));
  $valid=array_values(array_filter($requested,static fn(int $id): bool => isset($allowedSet[$id])));
  if(!$valid) throw new RuntimeException('Bitte mindestens eine Schulform aus den eigenen Schulen auswählen.');

  $in=implode(',',array_fill(0,count($allowedIds),'?'));
  $params=array_merge([$suggestionId],$allowedIds);
  $pdo->prepare("DELETE FROM criteria_suggestion_school_forms WHERE suggestion_id=? AND school_form_id IN ($in)")->execute($params);
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
