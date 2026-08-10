<?php
require_once __DIR__.'/../lib/schools.php';
require_once __DIR__.'/../lib/school_years.php';

function dashboard_school_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
    exit(1);
  }
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach([
  'CREATE TABLE teacher_schools (teacher_id INTEGER, school_id INTEGER)',
  'CREATE TABLE teacher_assignments (teacher_id INTEGER, class_id INTEGER, status TEXT)',
  'CREATE TABLE school_forms (id INTEGER PRIMARY KEY, school_id INTEGER)',
  'CREATE TABLE school_period_sets (id INTEGER PRIMARY KEY, label TEXT, archived INTEGER, semester1_from TEXT)',
  'CREATE TABLE classes (id INTEGER PRIMARY KEY, name TEXT, school_period_set_id INTEGER, school_form_id INTEGER, is_archived INTEGER, is_departed INTEGER, school_type TEXT, year INTEGER)',
] as $sql){ $pdo->exec($sql); }

$pdo->exec("INSERT INTO teacher_schools VALUES (9,1),(9,2)");
$pdo->exec("INSERT INTO school_forms VALUES (11,1),(22,2)");
$pdo->exec("INSERT INTO school_period_sets VALUES (101,'2026/27',0,'2026-09-01')");
$pdo->exec("INSERT INTO classes VALUES (1001,'1HLS',101,11,0,0,'HLS',1),(2001,'1FSB',101,22,0,0,'FSB',1)");
$pdo->exec("INSERT INTO teacher_assignments VALUES (9,1001,'active'),(9,2001,'active')");

dashboard_school_assert_same([1,2], teacher_school_ids($pdo,9), 'Mehrfachzuordnung der Lehrkraft muss vollständig ermittelt werden.');
$schoolOneClasses=load_teacher_classes($pdo,9,101,false,false,false,1);
$schoolTwoClasses=load_teacher_classes($pdo,9,101,false,false,false,2);
dashboard_school_assert_same([1001], array_map('intval',array_column($schoolOneClasses,'id')), 'Schule 1 darf nur ihre Klassen im Dashboard liefern.');
dashboard_school_assert_same([2001], array_map('intval',array_column($schoolTwoClasses,'id')), 'Schule 2 darf nur ihre Klassen im Dashboard liefern.');

echo "OK: teacher dashboard school filter smoke tests passed.\n";
