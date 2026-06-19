<?php
require_once __DIR__.'/../lib/final_assessments.php';

function fa_overview_assert_same($expected, $actual, string $message): void {
  if($expected !== $actual){
    fwrite(STDERR, "FAIL: {$message}\nExpected: ".var_export($expected, true)."\nActual:   ".var_export($actual, true)."\n");
    exit(1);
  }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE classes (id INTEGER PRIMARY KEY, school_period_set_id INTEGER, name TEXT, assessment_system TEXT, is_archived INTEGER, is_departed INTEGER)');
$pdo->exec('CREATE TABLE subjects (id INTEGER PRIMARY KEY, code TEXT, name TEXT)');
$pdo->exec('CREATE TABLE students (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE teacher_assignments (teacher_id INTEGER, class_id INTEGER, subject_id INTEGER)');
$pdo->exec('CREATE TABLE class_enrollments (student_id INTEGER, class_id INTEGER, school_period_set_id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE final_assessments (
  id INTEGER PRIMARY KEY,
  class_id INTEGER,
  subject_id INTEGER,
  student_id INTEGER,
  school_period_set_id INTEGER,
  assessment_scope TEXT,
  status TEXT,
  final_grade INTEGER,
  suggestion_label TEXT,
  teacher_comment TEXT,
  updated_at TEXT
)');

$pdo->exec("INSERT INTO classes VALUES
  (10,4,'2A','sost',0,0),
  (20,4,'3B','yearly',0,0),
  (30,4,'Fremd','nost',0,0)");
$pdo->exec("INSERT INTO subjects VALUES (100,'BW','Betriebswirtschaft'),(200,'AM','Angewandte Mathematik')");
$pdo->exec("INSERT INTO students VALUES
  (1000,'Anna','Beispiel',1),
  (1001,'Max','Muster',1),
  (1002,'Lea','Test',1),
  (1003,'Fremd','Person',1)");
$pdo->exec("INSERT INTO teacher_assignments VALUES (7,10,100),(7,20,200),(8,30,100)");
$pdo->exec("INSERT INTO class_enrollments VALUES
  (1000,10,4,'active'),
  (1001,10,4,'active'),
  (1002,20,4,'active'),
  (1003,30,4,'active')");
$pdo->exec("INSERT INTO final_assessments VALUES
  (1,10,100,1000,4,'semester2','final',2,'Notenvorschlag 2','stabile Leistung','2026-06-10 10:00:00'),
  (2,10,100,1001,4,'semester2','draft',3,'Notenvorschlag 3','noch prüfen','2026-06-11 10:00:00'),
  (3,20,200,1002,4,'semester1','final',1,'Notenvorschlag 1','Schulnachricht','2026-01-20 10:00:00')");

$periodSet = [
  'id' => 4,
  'label' => '2025/26',
  'semester1_from' => '2025-09-01',
  'semester1_to' => '2026-01-31',
  'semester2_from' => '2026-02-01',
  'semester2_to' => '2026-08-31',
];

$overview = final_assessment_teacher_overview($pdo, 7, $periodSet, 'current', 0, 0, 'all', '2026-05-14');
fa_overview_assert_same(2, count($overview['combinations']), 'Nur die beiden Klasse-Fach-Zuordnungen der Lehrkraft dürfen erscheinen.');
fa_overview_assert_same(3, count($overview['rows']), 'Alle drei zugeordneten Schüler:innen müssen erscheinen.');
fa_overview_assert_same(1, $overview['stats']['final'], 'Eine finale Semesterbeurteilung muss gezählt werden.');
fa_overview_assert_same(1, $overview['stats']['draft'], 'Ein Entwurf muss gezählt werden.');
fa_overview_assert_same(1, $overview['stats']['open'], 'Die Jahresbeurteilung ohne Datensatz muss als offen erscheinen.');
fa_overview_assert_same('year', $overview['rows'][2]['scope'], 'Im Jahresmodell muss der aktuelle Sommerzeitraum die Jahresbeurteilung verwenden.');

$finalOnly = final_assessment_teacher_overview($pdo, 7, $periodSet, 'current', 0, 0, 'final', '2026-05-14');
fa_overview_assert_same(1, count($finalOnly['rows']), 'Der Statusfilter final muss nur finale Zeilen anzeigen.');
fa_overview_assert_same(3, $finalOnly['stats']['total'], 'Die Fortschrittszahlen müssen trotz Statusfilter den gesamten Bereich abbilden.');

$semester2 = final_assessment_teacher_overview($pdo, 7, $periodSet, 'semester2', 0, 0, 'all', '2026-05-14');
fa_overview_assert_same(1, $semester2['skipped_combinations'], 'Das Jahresmodell muss bei expliziter 2.-Semesterwahl ausgelassen werden.');
fa_overview_assert_same(2, count($semester2['rows']), 'In der 2.-Semesterübersicht dürfen nur SOST/NOST-Zeilen verbleiben.');

echo "OK: final assessment overview smoke tests passed.\n";
