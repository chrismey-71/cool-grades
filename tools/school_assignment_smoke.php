<?php
require_once __DIR__.'/../lib/schools.php';

function sa_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
    exit(1);
  }
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach([
  'CREATE TABLE schools (id INTEGER PRIMARY KEY,name TEXT)',
  'CREATE TABLE school_forms (id INTEGER PRIMARY KEY,school_id INTEGER,code TEXT)',
  'CREATE TABLE users (id INTEGER PRIMARY KEY,role TEXT)',
  'CREATE TABLE subjects (id INTEGER PRIMARY KEY,code TEXT)',
  'CREATE TABLE classes (id INTEGER PRIMARY KEY,school_form_id INTEGER)',
  'CREATE TABLE teacher_schools (teacher_id INTEGER,school_id INTEGER,PRIMARY KEY(teacher_id,school_id))',
  'CREATE TABLE subject_school_forms (subject_id INTEGER,school_form_id INTEGER,PRIMARY KEY(subject_id,school_form_id))',
] as $sql){ $pdo->exec($sql); }

$pdo->exec("INSERT INTO schools VALUES(1,'Schule A'),(2,'Schule B')");
$pdo->exec("INSERT INTO school_forms VALUES(10,1,'HLS'),(20,2,'FSB')");
$pdo->exec("INSERT INTO users VALUES(7,'teacher'),(8,'teacher')");
$pdo->exec("INSERT INTO subjects VALUES(100,'D'),(200,'RW')");
$pdo->exec('INSERT INTO classes VALUES(1000,10),(2000,20)');

teacher_schools_sync($pdo,7,[1]);
subject_school_forms_sync($pdo,100,[10]);
subject_school_forms_sync($pdo,200,[20]);

sa_assert_same([1],teacher_school_ids($pdo,7),'Eine Lehrkraft muss ihre gespeicherten Schulen wieder laden können.');
sa_assert_same([10],subject_school_form_ids($pdo,100),'Ein Fach muss seine gespeicherten Schulformen wieder laden können.');
sa_assert_same(true,teacher_assignment_context_allowed($pdo,7,1000,100),'Passende Schule, Klasse und Schulform müssen eine Zuweisung erlauben.');
sa_assert_same(false,teacher_assignment_context_allowed($pdo,7,1000,200),'Ein Fach einer anderen Schulform darf nicht zugewiesen werden.');
sa_assert_same(false,teacher_assignment_context_allowed($pdo,7,2000,200),'Eine Klasse einer nicht zugeordneten Schule darf nicht zugewiesen werden.');
sa_assert_same(false,teacher_assignment_context_allowed($pdo,8,1000,100),'Eine Lehrkraft ohne Schulzuordnung darf keine Zuweisung erhalten.');

echo "OK: teacher school and subject school-form assignment smoke tests passed.\n";
