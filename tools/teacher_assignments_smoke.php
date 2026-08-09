<?php
require_once __DIR__.'/../lib/teacher_assignments.php';

function ta_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");
    exit(1);
  }
}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach([
  'CREATE TABLE participation_events (teacher_id INTEGER,class_id INTEGER,subject_id INTEGER)',
  'CREATE TABLE lesson_sessions (teacher_id INTEGER,class_id INTEGER,subject_id INTEGER)',
  'CREATE TABLE oral_assessments (teacher_id INTEGER,class_id INTEGER,subject_id INTEGER)',
  'CREATE TABLE exams (teacher_id INTEGER,class_id INTEGER,subject_id INTEGER)',
  'CREATE TABLE teacher_student_groups (teacher_id INTEGER,class_id INTEGER,subject_id INTEGER)',
  'CREATE TABLE assessment_weight_settings (teacher_id INTEGER,class_id INTEGER,subject_id INTEGER)',
  'CREATE TABLE final_assessments (id INTEGER PRIMARY KEY,created_by INTEGER,updated_by INTEGER,class_id INTEGER,subject_id INTEGER)',
] as $sql){ $pdo->exec($sql); }

$assignments=[
  ['teacher_id'=>7,'class_id'=>10,'subject_id'=>100],
  ['teacher_id'=>7,'class_id'=>20,'subject_id'=>200],
  ['teacher_id'=>8,'class_id'=>10,'subject_id'=>100],
];
$pdo->exec('INSERT INTO participation_events VALUES (7,10,100),(8,10,100)');
$pdo->exec('INSERT INTO lesson_sessions VALUES (7,10,100)');
$pdo->exec('INSERT INTO oral_assessments VALUES (7,10,100)');
$pdo->exec('INSERT INTO exams VALUES (7,10,100)');
$pdo->exec('INSERT INTO teacher_student_groups VALUES (7,10,100)');
$pdo->exec('INSERT INTO assessment_weight_settings VALUES (7,10,100)');
$pdo->exec('INSERT INTO final_assessments VALUES (1,7,7,10,100),(2,7,9,10,100),(3,8,8,10,100)');

$map=teacher_assignment_documentation_map($pdo,$assignments);
$withData=$map['7:10:100'];
$empty=$map['7:20:200'];
$otherTeacher=$map['8:10:100'];

ta_assert_same(1,$withData['participation'],'Nur die eigenen Mitarbeitseinträge dürfen zählen.');
ta_assert_same(1,$withData['lessons'],'Eigene Stunden müssen eine Löschung verhindern.');
ta_assert_same(1,$withData['oral'],'Eigene mündliche Leistungen müssen zählen.');
ta_assert_same(1,$withData['written'],'Eigene schriftliche Leistungen müssen zählen.');
ta_assert_same(2,$withData['final_assessments'],'Erstellte oder aktualisierte Abschlussbeurteilungen müssen erhalten bleiben.');
ta_assert_same(true,$withData['has_documentation'],'Dokumentierte Zuweisungen dürfen nicht endgültig gelöscht werden.');
ta_assert_same(false,$empty['has_documentation'],'Ohne Daten muss eine Zuweisung löschbar bleiben.');
ta_assert_same(1,$otherTeacher['participation'],'Dokumentationen anderer Lehrkräfte dürfen nicht vermischt werden.');
ta_assert_same('1 Mitarbeit · 1 Stunden · 1 mündlich · 1 schriftlich · 2 Abschluss · Gruppen · Gewichtung',teacher_assignment_documentation_text($withData),'Die Dokumentationsanzeige muss kompakt und nachvollziehbar sein.');

echo "OK: teacher assignment lifecycle smoke tests passed.\n";
