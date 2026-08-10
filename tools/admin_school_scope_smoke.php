<?php
require_once __DIR__.'/../lib/schools.php';

function ass_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
    exit(1);
  }
}

function ass_assert_throws(callable $fn, string $message): void {
  try{
    $fn();
  }catch(Throwable $e){
    return;
  }
  fwrite(STDERR, "FAIL: {$message}\nExpected exception, got none.\n");
  exit(1);
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

foreach([
  'CREATE TABLE schools (id INTEGER PRIMARY KEY,name TEXT,active INTEGER)',
  'CREATE TABLE school_forms (id INTEGER PRIMARY KEY,school_id INTEGER,code TEXT,name TEXT,active INTEGER)',
  'CREATE TABLE teacher_schools (teacher_id INTEGER,school_id INTEGER,PRIMARY KEY(teacher_id,school_id))',
  'CREATE TABLE classes (id INTEGER PRIMARY KEY,school_form_id INTEGER)',
  'CREATE TABLE students (id INTEGER PRIMARY KEY,first_name TEXT,last_name TEXT,class_id INTEGER,is_active INTEGER)',
  'CREATE TABLE class_enrollments (student_id INTEGER,class_id INTEGER)',
  'CREATE TABLE subjects (id INTEGER PRIMARY KEY,code TEXT)',
  'CREATE TABLE subject_school_forms (subject_id INTEGER,school_form_id INTEGER,PRIMARY KEY(subject_id,school_form_id))',
  'CREATE TABLE criteria_suggestions (id INTEGER PRIMARY KEY,label TEXT)',
  'CREATE TABLE criteria_suggestion_school_forms (suggestion_id INTEGER,school_form_id INTEGER,PRIMARY KEY(suggestion_id,school_form_id))',
  'CREATE TABLE school_period_sets (id INTEGER PRIMARY KEY,school_id INTEGER,label TEXT)',
] as $sql){ $pdo->exec($sql); }

$pdo->exec("INSERT INTO schools VALUES(1,'Schule A',1),(2,'Schule B',1)");
$pdo->exec("INSERT INTO school_forms VALUES(10,1,'HLS','HLS',1),(20,2,'FSB','FSB',1)");
$pdo->exec('INSERT INTO teacher_schools VALUES(2,1),(3,2),(9,1),(9,2)');
$pdo->exec('INSERT INTO classes VALUES(100,10),(200,20)');
$pdo->exec("INSERT INTO students VALUES(1000,'Anna','A',100,1),(2000,'Berta','B',200,1)");
$pdo->exec('INSERT INTO class_enrollments VALUES(1000,100),(2000,200)');
$pdo->exec("INSERT INTO subjects VALUES(500,'RW'),(600,'AM')");
$pdo->exec('INSERT INTO subject_school_forms VALUES(500,10),(500,20),(600,20)');
$pdo->exec("INSERT INTO criteria_suggestions VALUES(700,'A'),(800,'B')");
$pdo->exec('INSERT INTO criteria_suggestion_school_forms VALUES(700,10),(800,20)');
$pdo->exec("INSERT INTO school_period_sets VALUES(900,NULL,'global'),(901,1,'A'),(902,2,'B')");

ass_assert_same(true,admin_is_superadmin($pdo,1),'Admin ohne Schulzuordnung muss Superadmin sein.');
ass_assert_same(false,admin_is_superadmin($pdo,2),'Admin mit Schulzuordnung darf kein Superadmin sein.');
ass_assert_same([1],admin_assigned_school_ids($pdo,2),'Schulgebundener Admin muss seine Schule laden.');
ass_assert_same([1],array_map('intval',array_column(admin_schools_load($pdo,2,true),'id')),'Schulgebundener Admin sieht nur eigene Schulen.');
ass_assert_same([1,2],array_map('intval',array_column(admin_schools_load($pdo,1,true),'id')),'Superadmin sieht alle Schulen.');

ass_assert_same(true,admin_can_access_school($pdo,2,1),'Admin Schule A darf Schule A sehen.');
ass_assert_same(false,admin_can_access_school($pdo,2,2),'Admin Schule A darf Schule B nicht sehen.');
ass_assert_same(true,admin_can_access_class($pdo,2,100),'Admin Schule A darf Klasse A verwalten.');
ass_assert_same(false,admin_can_access_class($pdo,2,200),'Admin Schule A darf Klasse B nicht verwalten.');
ass_assert_same(true,admin_can_access_student($pdo,2,1000),'Admin Schule A darf Schüler:in A verwalten.');
ass_assert_same(false,admin_can_access_student($pdo,2,2000),'Admin Schule A darf Schüler:in B nicht verwalten.');
ass_assert_same(true,admin_can_access_subject($pdo,2,500),'Admin Schule A darf Fach mit eigener Schulform sehen.');
ass_assert_same(false,admin_can_access_subject($pdo,2,600),'Admin Schule A darf reines Fach der Schule B nicht sehen.');
ass_assert_same(true,admin_subject_has_external_school_forms($pdo,2,500),'Schulübergreifendes Fach muss als extern markiert werden.');
ass_assert_same(true,admin_can_access_school_period($pdo,2,900,true),'Globales Schuljahr darf für Bestand gelesen werden.');
ass_assert_same(false,admin_can_access_school_period($pdo,2,900,false),'Globales Schuljahr darf vom schulgebundenen Admin nicht geändert werden.');

subject_school_forms_sync_for_admin($pdo,2,500,[10]);
ass_assert_same([10,20],subject_school_form_ids($pdo,500),'Scoped Sync darf fremde Fach-Schulform-Zuordnung nicht entfernen.');
ass_assert_throws(fn() => subject_school_forms_sync_for_admin($pdo,2,500,[20]),'Scoped Sync darf keine fremde Schulform setzen.');

criteria_suggestion_school_forms_sync_for_admin($pdo,2,700,[10]);
ass_assert_same([10],criteria_suggestion_school_form_ids($pdo,700),'Scoped Sync für Vorschläge muss eigene Schulform speichern.');
ass_assert_throws(fn() => criteria_suggestion_school_forms_sync_for_admin($pdo,2,700,[20]),'Scoped Sync für Vorschläge darf keine fremde Schulform setzen.');

user_schools_sync($pdo,4,[],false);
ass_assert_same([],user_school_ids($pdo,4),'Leere Schulzuordnung muss für Superadmin-Konten speicherbar sein.');

echo "OK: admin school scope smoke tests passed.\n";
