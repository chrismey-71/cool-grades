<?php
require_once __DIR__.'/../lib/schools.php';

function ss_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR,"FAIL: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
    exit(1);
  }
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach([
  'CREATE TABLE schools (id INTEGER PRIMARY KEY,name TEXT)',
  'CREATE TABLE school_forms (id INTEGER PRIMARY KEY,school_id INTEGER,code TEXT)',
  'CREATE TABLE criteria_suggestions (id INTEGER PRIMARY KEY,label TEXT)',
  'CREATE TABLE criteria_suggestion_school_forms (suggestion_id INTEGER,school_form_id INTEGER,PRIMARY KEY(suggestion_id,school_form_id))',
  'CREATE TABLE users (id INTEGER PRIMARY KEY)',
  'CREATE TABLE subjects (id INTEGER PRIMARY KEY)',
  'CREATE TABLE classes (id INTEGER PRIMARY KEY,school_form_id INTEGER)',
  'CREATE TABLE teacher_schools (teacher_id INTEGER,school_id INTEGER,PRIMARY KEY(teacher_id,school_id))',
  'CREATE TABLE subject_school_forms (subject_id INTEGER,school_form_id INTEGER,PRIMARY KEY(subject_id,school_form_id))',
] as $sql){ $pdo->exec($sql); }
$pdo->exec("INSERT INTO schools VALUES(1,'Schule A'),(2,'Schule B')");
$pdo->exec("INSERT INTO school_forms VALUES(10,1,'HLS'),(20,2,'FSB')");
$pdo->exec("INSERT INTO criteria_suggestions VALUES(100,'Global'),(200,'Spezifisch')");

criteria_suggestion_school_forms_sync($pdo,100,[]);
ss_assert_same([],criteria_suggestion_school_form_ids($pdo,100),'Ohne Auswahl muss ein Vorschlag global gültig bleiben.');
criteria_suggestion_school_forms_sync($pdo,200,[10,20,20]);
ss_assert_same([10,20],criteria_suggestion_school_form_ids($pdo,200),'Konkrete Schulformen müssen eindeutig gespeichert werden.');
criteria_suggestion_school_forms_sync($pdo,200,[20]);
ss_assert_same([20],criteria_suggestion_school_form_ids($pdo,200),'Beim Bearbeiten müssen alte Schulformzuordnungen ersetzt werden.');

echo "OK: school scoping smoke tests passed.\n";
