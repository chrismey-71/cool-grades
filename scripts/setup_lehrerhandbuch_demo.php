#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
$db = $config['db'] ?? [];

$host = (string)($db['host'] ?? '');
$name = (string)($db['name'] ?? '');
$user = (string)($db['user'] ?? '');
$pass = (string)($db['pass'] ?? '');
$charset = (string)($db['charset'] ?? 'utf8mb4');

if ($host !== '127.0.0.1;port=3307' || $name !== 'coolgrades') {
    fwrite(STDERR, "Abbruch: Dieses Demo-Skript ist nur für die lokale Testdatenbank 127.0.0.1:3307 / coolgrades gedacht.\n");
    exit(1);
}

$dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$schema = file_get_contents($root . '/schema.sql');
if ($schema === false) {
    throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
}
$pdo->exec($schema);

$pdo->exec("
CREATE TABLE IF NOT EXISTS participation_event_lbvo (
  event_id INT NOT NULL,
  tag CHAR(1) NOT NULL,
  source VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (event_id, tag, source),
  INDEX idx_lbvo_event_source (event_id, source),
  FOREIGN KEY (event_id) REFERENCES participation_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

foreach ([
    "ALTER TABLE criteria ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER active",
    "ALTER TABLE participation_options ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER active",
    "ALTER TABLE lesson_sessions ADD UNIQUE KEY uniq_lesson_slot (class_id, subject_id, lesson_date, lesson_unit)",
] as $sql) {
    try {
        $pdo->exec($sql);
    } catch (Throwable $ignored) {
        // idempotent on reruns
    }
}

function now_iso_demo(): string {
    return date('Y-m-d H:i:s');
}

function hash_pw(string $pw): string {
    return password_hash($pw, PASSWORD_DEFAULT);
}

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'participation_event_lbvo',
        'final_assessment_history',
        'final_assessments',
        'school_period_sets',
        'teacher_student_group_members',
        'teacher_student_groups',
        'participation_event_criteria',
        'participation_event_options',
        'participation_events',
        'exam_grades',
        'exams',
        'oral_assessments',
        'lesson_sessions',
        'teacher_participation_presets',
        'teacher_assignments',
        'criteria',
        'criteria_sets',
        'students',
        'subjects',
        'classes',
        'users',
        'events',
    ] as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
    }
    $pdo->exec("DELETE FROM participation_options WHERE scope='teacher'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    $yearSettings = [
        'semester1_from' => '2025-09-01',
        'semester1_to' => '2026-01-31',
        'semester2_from' => '2026-02-01',
        'semester2_to' => '2026-07-10',
        'brand_primary_color' => '#2F6F3A',
        'event_retention_days' => '30',
        'session_timeout_minutes' => '30',
    ];
    $stSetting = $pdo->prepare("INSERT INTO app_settings(`key`,`value`,updated_at,created_at) VALUES(?,?,?,?)
      ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=VALUES(updated_at)");
    foreach ($yearSettings as $key => $value) {
        $stSetting->execute([$key, $value, now_iso_demo(), now_iso_demo()]);
    }
    $pdo->prepare("INSERT INTO school_period_sets(label,semester1_from,semester1_to,semester2_from,semester2_to,archived,created_at,updated_at)
                   VALUES (?,?,?,?,?,0,?,?)")
        ->execute(['2025/26', '2025-09-01', '2026-01-31', '2026-02-01', '2026-07-10', now_iso_demo(), now_iso_demo()]);

    $stUser = $pdo->prepare("INSERT INTO users
      (username,first_name,last_name,role,pass_hash,is_active,must_change_password,pref_quick_entry_ui,pref_theme,pref_participation_quick_pick_enabled,pref_participation_quick_pick_limit,pref_legal_hints_enabled,pref_compact_forms_enabled,pref_visual_contrast,pref_simple_participation_entry,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stUser->execute(['admin.demo','Demo','Admin','admin',hash_pw('DemoAdmin123!'),1,0,null,'light',1,10,1,0,'normal',0,now_iso_demo()]);
    $adminId = (int)$pdo->lastInsertId();
    $stUser->execute(['lehrer.demo','Maria','Muster','teacher',hash_pw('DemoLehrer123!'),1,0,'buttons','light',1,8,1,0,'contrast',0,now_iso_demo()]);
    $teacherId = (int)$pdo->lastInsertId();

    $stClass = $pdo->prepare("INSERT INTO classes(name,school_type,year,label) VALUES (?,?,?,?)");
    $stClass->execute(['3A Demo','HLS',3,'HLS 3A Demo']);
    $classId = (int)$pdo->lastInsertId();

    $stSubject = $pdo->prepare("INSERT INTO subjects(code,name,is_schularbeit_subject) VALUES (?,?,?)");
    $stSubject->execute(['AM','Angewandte Mathematik',0]);
    $amId = (int)$pdo->lastInsertId();
    $stSubject->execute(['D','Deutsch',1]);
    $dId = (int)$pdo->lastInsertId();

    $stAssign = $pdo->prepare("INSERT INTO teacher_assignments(teacher_id,class_id,subject_id) VALUES (?,?,?)");
    $stAssign->execute([$teacherId,$classId,$amId]);
    $stAssign->execute([$teacherId,$classId,$dId]);

    $students = [
        ['Anna','Adler'],
        ['Ben','Berger'],
        ['Clara','Cerny'],
        ['Daniel','Dorn'],
        ['Eva','Eder'],
        ['Felix','Falk'],
        ['Greta','Gruen'],
        ['Hugo','Haller'],
    ];
    $studentIds = [];
    $stStudent = $pdo->prepare("INSERT INTO students(class_id,first_name,last_name,is_active) VALUES (?,?,?,1)");
    foreach ($students as [$first,$last]) {
        $stStudent->execute([$classId,$first,$last]);
        $studentIds[$first] = (int)$pdo->lastInsertId();
    }

    $stSet = $pdo->prepare("INSERT INTO criteria_sets(name,scope,subject_id,teacher_id) VALUES (?,?,?,?)");
    $stSet->execute(['AM – Beobachtungskriterien','teacher',$amId,$teacherId]);
    $criteriaSetId = (int)$pdo->lastInsertId();
    $stCriterion = $pdo->prepare("INSERT INTO criteria(criteria_set_id,label,category,active,archived) VALUES (?,?,?,?,0)");
    $criteriaSeed = [
        ['Fachlich','Zusammenhänge erklärt'],
        ['Fachlich','Lösungsweg korrekt dargestellt'],
        ['Fachlich','Begriffe sicher angewendet'],
        ['Arbeitsweise','sorgfältig gerechnet'],
        ['Arbeitsweise','selbstständig gearbeitet'],
        ['Kooperation','hilfreich in der Gruppe beigetragen'],
    ];
    $criteriaIds = [];
    foreach ($criteriaSeed as [$category,$label]) {
        $stCriterion->execute([$criteriaSetId,$label,$category,1]);
        $criteriaIds[$label] = (int)$pdo->lastInsertId();
    }

    $stOption = $pdo->prepare("INSERT INTO participation_options(opt_type,scope,subject_id,teacher_id,label,pedagogical_hint_mode,active,archived,sort,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $teacherOptions = [
        ['impact','positiv (+)','',1,0,10],
        ['impact','neutral','',1,0,20],
        ['impact','negativ (-)','',1,0,30],
        ['impact','auffällig positiv','',1,1,110],
        ['impact','unauffällig','',1,1,120],
        ['impact','auffällig negativ','',1,1,130],
        ['impact','nur beobachtet','',1,1,140],
        ['reason','Diskussion','formative',1,0,15],
        ['reason','Abschlussabfrage','summative',1,0,25],
        ['reason','mündliche Mitarbeit','',1,1,110],
        ['reason','Hausübung / Sicherung','',1,1,120],
        ['reason','Arbeitsauftrag im Unterricht','',1,1,130],
        ['reason','Gruppenarbeit / Projekt','',1,1,140],
        ['reason','Präsentation / Referat','',1,1,150],
        ['reason','Sonstiges','',1,1,160],
    ];
    foreach ($teacherOptions as [$type,$label,$mode,$active,$archived,$sort]) {
        $stOption->execute([$type,'teacher',$amId,$teacherId,$label,$mode ?: null,$active,$archived,$sort,now_iso_demo()]);
    }

    $optRows = $pdo->query("SELECT id,opt_type,label,scope,subject_id,teacher_id FROM participation_options")->fetchAll();
    $optionId = [];
    foreach ($optRows as $row) {
        $key = implode('|', [
            $row['opt_type'],
            $row['scope'],
            (string)($row['subject_id'] ?? ''),
            (string)($row['teacher_id'] ?? ''),
            $row['label'],
        ]);
        $optionId[$key] = (int)$row['id'];
    }
    $findOption = static function(string $type, string $label, string $scope='global', ?int $subjectId=null, ?int $teacherId=null) use ($optionId): int {
        $key = implode('|', [$type,$scope,(string)($subjectId ?? ''),(string)($teacherId ?? ''),$label]);
        if (!isset($optionId[$key])) {
            throw new RuntimeException("Option nicht gefunden: {$key}");
        }
        return $optionId[$key];
    };

    $reasonDiscussion = $findOption('reason','Diskussion','teacher',$amId,$teacherId);
    $reasonAbschluss = $findOption('reason','Abschlussabfrage','teacher',$amId,$teacherId);
    $impactPositive = $findOption('impact','positiv (+)','teacher',$amId,$teacherId);
    $impactNeutral = $findOption('impact','neutral','teacher',$amId,$teacherId);
    $impactNegative = $findOption('impact','negativ (-)','teacher',$amId,$teacherId);
    $perfMuendlich = $findOption('performance','mündlich');
    $perfSchriftlich = $findOption('performance','schriftlich');
    $perfPraktisch = $findOption('performance','praktisch');
    $groupVerstehen = $findOption('observation_group','Verstehen / Erfassen');
    $groupTransfer = $findOption('observation_group','Anwenden / Transfer');
    $groupErklaeren = $findOption('observation_group','Argumentieren / Erklären');
    $groupArbeitsweise = $findOption('observation_group','Arbeitsweise / Genauigkeit');
    $groupKooperation = $findOption('observation_group','Kooperation / Selbstständigkeit');
    $socialAllein = $findOption('social_form','Alleinarbeit');
    $socialGruppe = $findOption('social_form','Gruppenarbeit');
    $phaseErarbeitung = $findOption('phase','Erarbeitung');
    $phaseUebung = $findOption('phase','Übung');
    $phasePraesentation = $findOption('phase','Präsentation');
    $homeworkErledigt = $findOption('homework','erledigt');
    $homeworkTeilweise = $findOption('homework','teilweise');

    $stLesson = $pdo->prepare("INSERT INTO lesson_sessions(teacher_id,class_id,subject_id,lesson_date,lesson_unit,topic,created_at) VALUES (?,?,?,?,?,?,?)");
    $lessons = [
        ['2026-02-18','1','Lineare Funktionen'],
        ['2026-02-25','1','Zinsrechnung'],
        ['2026-03-04','2','Gleichungssysteme'],
        ['2026-03-18','1','Statistik und Diagramme'],
        ['2026-04-08','2','Prozentrechnung'],
        ['2026-04-22','1','Wirtschaftsmathematische Modelle'],
        ['2026-05-06','2','Abschlussabfrage Semester'],
    ];
    $lessonIds = [];
    foreach ($lessons as [$date,$unit,$topic]) {
        $stLesson->execute([$teacherId,$classId,$amId,$date,$unit,$topic,now_iso_demo()]);
        $lessonIds[$date] = (int)$pdo->lastInsertId();
    }

    $stEvent = $pdo->prepare("INSERT INTO participation_events
      (student_id,teacher_id,class_id,subject_id,lesson_id,event_date,reason_option_id,reason_label,impact_option_id,rating,social_form_option_id,phase_option_id,homework_option_id,reason_text,note,pedagogical_mode,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stEventOption = $pdo->prepare("INSERT INTO participation_event_options(event_id,option_id) VALUES (?,?)");
    $stEventCriterion = $pdo->prepare("INSERT INTO participation_event_criteria(event_id,criteria_id) VALUES (?,?)");
    $stLbvo = $pdo->prepare("INSERT INTO participation_event_lbvo(event_id,tag,source,created_at) VALUES (?,?,?,?)");

    $events = [
        ['Anna','2026-02-18',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseErarbeitung,null,'Erklärt die Grundidee der linearen Funktion sicher.','Bringt zwei passende Beispiele ein.','formative',[$groupVerstehen,$groupErklaeren],[$perfMuendlich],['Zusammenhänge erklärt'],['a','d']],
        ['Anna','2026-02-25',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseUebung,$homeworkErledigt,'Bezieht Hausübung nachvollziehbar ein.','Überträgt die Rechenstrategie korrekt.','formative',[$groupTransfer,$groupArbeitsweise],[$perfMuendlich],['selbstständig gearbeitet'],['a','b','e']],
        ['Anna','2026-03-04',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseErarbeitung,null,'Löst das Gleichungssystem schlüssig.','Erklärt den Zwischenschritt verständlich.','formative',[$groupErklaeren,$groupArbeitsweise],[$perfMuendlich],['Lösungsweg korrekt dargestellt'],['a','d','e']],
        ['Anna','2026-03-18',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialGruppe,$phasePraesentation,null,'Deutet das Diagramm korrekt.','Hilft der Gruppe bei der Auswertung.','formative',[$groupVerstehen,$groupKooperation],[$perfMuendlich],['hilfreich in der Gruppe beigetragen'],['a','d']],
        ['Anna','2026-04-08',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseUebung,null,'Rechnet sicher mit Prozenten.','Arbeitet sehr sorgfältig.','formative',[$groupArbeitsweise],[$perfSchriftlich],['sorgfältig gerechnet'],['a','e']],
        ['Anna','2026-04-22',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseErarbeitung,null,'Ordnet Modell und Aufgabe richtig zu.','Verwendet Fachbegriffe sicher.','formative',[$groupTransfer],[$perfMuendlich],['Begriffe sicher angewendet'],['a','e']],
        ['Anna','2026-05-06',$reasonAbschluss,'Abschlussabfrage',$impactPositive,'positiv (+)',$socialAllein,$phaseUebung,null,'Zeigt zum Semesterende einen sicheren Überblick.','Kann den Lösungsweg frei darstellen.','summative',[$groupErklaeren,$groupTransfer],[$perfMuendlich],['Lösungsweg korrekt dargestellt'],['a','d','e']],
        ['Ben','2026-02-18',$reasonDiscussion,'Diskussion',$impactNeutral,'neutral',$socialAllein,$phaseErarbeitung,null,'Beteiligt sich zurückhaltend, versteht die Aufgabe aber.','Braucht noch Starthilfe.','formative',[$groupVerstehen],[$perfMuendlich],['Zusammenhänge erklärt'],['a','d']],
        ['Ben','2026-03-18',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialGruppe,$phasePraesentation,null,'Erklärt der Gruppe ein Zwischenergebnis korrekt.','Wirkt sicherer als zu Beginn.','formative',[$groupKooperation,$groupErklaeren],[$perfMuendlich],['hilfreich in der Gruppe beigetragen'],['a','d']],
        ['Ben','2026-05-06',$reasonAbschluss,'Abschlussabfrage',$impactNeutral,'neutral',$socialAllein,$phaseUebung,null,'Löst Standardaufgaben mit kleinen Unsicherheiten.','Grundidee vorhanden.','summative',[$groupTransfer],[$perfSchriftlich],['selbstständig gearbeitet'],['a','e']],
        ['Clara','2026-02-25',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseUebung,$homeworkTeilweise,'Bearbeitet die Hausübung nachvollziehbar.','Noch nicht vollständig, aber fachlich passend.','formative',[$groupArbeitsweise],[$perfSchriftlich],['sorgfältig gerechnet'],['a','b']],
        ['Clara','2026-04-22',$reasonDiscussion,'Diskussion',$impactNeutral,'neutral',$socialAllein,$phaseErarbeitung,null,'Versteht den Ansatz, braucht aber noch Impulse für den Transfer.','Dokumentation knapp.','formative',[$groupVerstehen],[$perfMuendlich],['Zusammenhänge erklärt'],['a','d']],
        ['Daniel','2026-02-18',$reasonDiscussion,'Diskussion',$impactNegative,'negativ (-)',$socialAllein,$phaseErarbeitung,null,'Beginnt die Aufgabe erst nach Erinnerung.','Zentrale Fachfrage bleibt unklar.','formative',[$groupArbeitsweise],[$perfMuendlich],['selbstständig gearbeitet'],['c','d']],
        ['Daniel','2026-03-04',$reasonDiscussion,'Diskussion',$impactNeutral,'neutral',$socialAllein,$phaseErarbeitung,null,'Kann den ersten Schritt nachvollziehen, verliert aber den weiteren Lösungsweg.','Benötigt noch Strukturhilfe.','formative',[$groupVerstehen],[$perfMuendlich],['Lösungsweg korrekt dargestellt'],['d']],
        ['Daniel','2026-05-06',$reasonAbschluss,'Abschlussabfrage',$impactNegative,'negativ (-)',$socialAllein,$phaseUebung,null,'Kann die Strategie in der Abschlussabfrage noch nicht sicher anwenden.','Datenlage zeigt Förderbedarf.','summative',[$groupTransfer],[$perfSchriftlich],['Begriffe sicher angewendet'],['e']],
        ['Eva','2026-02-18',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseErarbeitung,null,'Formuliert die Grundidee sicher.','Bringt sich regelmäßig ein.','formative',[$groupErklaeren],[$perfMuendlich],['Zusammenhänge erklärt'],['a','d']],
        ['Eva','2026-03-18',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialGruppe,$phasePraesentation,null,'Erklärt das Diagramm der Gruppe verständlich.','Hilft bei der Präsentation.','formative',[$groupKooperation,$groupErklaeren],[$perfMuendlich],['hilfreich in der Gruppe beigetragen'],['a']],
        ['Felix','2026-02-25',$reasonDiscussion,'Diskussion',$impactNeutral,'neutral',$socialAllein,$phaseUebung,$homeworkTeilweise,'Rechnet nachvollziehbar, aber noch langsam.','Braucht mehr Sicherheit im Verfahren.','formative',[$groupArbeitsweise],[$perfSchriftlich],['sorgfältig gerechnet'],['a','b']],
        ['Felix','2026-04-08',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseUebung,null,'Setzt die Prozentrechnung korrekt um.','Deutlich sicherer als zuvor.','formative',[$groupTransfer],[$perfSchriftlich],['Lösungsweg korrekt dargestellt'],['a','e']],
        ['Greta','2026-03-04',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseErarbeitung,null,'Erläutert den Ansatz der Gruppe präzise.','Wirkt sehr selbstständig.','formative',[$groupErklaeren,$groupKooperation],[$perfMuendlich],['selbstständig gearbeitet'],['a','d']],
        ['Greta','2026-04-22',$reasonDiscussion,'Diskussion',$impactPositive,'positiv (+)',$socialAllein,$phaseErarbeitung,null,'Überträgt das Modell sicher auf die Aufgabe.','Sehr klare Struktur.','formative',[$groupTransfer],[$perfMuendlich],['Begriffe sicher angewendet'],['a','e']],
        ['Hugo','2026-02-18',$reasonDiscussion,'Diskussion',$impactNeutral,'neutral',$socialAllein,$phaseErarbeitung,null,'Beteiligt sich punktuell, fachlich aber passend.','Noch wenig regelmäßig.','formative',[$groupVerstehen],[$perfMuendlich],['Zusammenhänge erklärt'],['a','d']],
        ['Hugo','2026-05-06',$reasonAbschluss,'Abschlussabfrage',$impactPositive,'positiv (+)',$socialAllein,$phaseUebung,null,'Zeigt zum Abschluss eine stabile Basis.','Kann Kernbegriffe richtig einordnen.','summative',[$groupTransfer],[$perfSchriftlich],['Begriffe sicher angewendet'],['a','e']],
    ];

    foreach ($events as [$studentName,$date,$reasonId,$reasonLabel,$impactId,$rating,$socialId,$phaseId,$hwId,$reasonText,$note,$pedMode,$groupIds,$perfIds,$criterionLabels,$lbvoTags]) {
        $lessonId = $lessonIds[$date] ?? null;
        $stEvent->execute([
            $studentIds[$studentName],
            $teacherId,
            $classId,
            $amId,
            $lessonId,
            $date,
            $reasonId,
            $reasonLabel,
            $impactId,
            $rating,
            $socialId,
            $phaseId,
            $hwId,
            $reasonText,
            $note,
            $pedMode,
            now_iso_demo(),
        ]);
        $eventId = (int)$pdo->lastInsertId();
        foreach (array_merge($groupIds, $perfIds) as $optionIdValue) {
            $stEventOption->execute([$eventId, $optionIdValue]);
        }
        foreach ($criterionLabels as $criterionLabel) {
            $stEventCriterion->execute([$eventId, $criteriaIds[$criterionLabel]]);
        }
        foreach ($lbvoTags as $tag) {
            $stLbvo->execute([$eventId, $tag, 'auto', now_iso_demo()]);
        }
    }

    $stOral = $pdo->prepare("INSERT INTO oral_assessments(class_id,subject_id,teacher_id,student_id,assessment_type,assessment_date,impact_option_id,impact_label,topic_area,questions,category,title,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stOral->execute([$classId,$amId,$teacherId,$studentIds['Anna'],'ORAL_EXERCISE','2026-04-30',$impactPositive,'positiv (+)',null,null,'Kurzpräsentation','Diagramme vergleichen',now_iso_demo()]);
    $stOral->execute([$classId,$amId,$teacherId,$studentIds['Daniel'],'ORAL_EXAM','2026-05-07',$impactNegative,'negativ (-)','Lineare Funktionen','Was bedeutet die Steigung? | Wie liest man den Achsenabschnitt?',null,null,now_iso_demo()]);
    $stOral->execute([$classId,$dId,$teacherId,$studentIds['Greta'],'ORAL_EXERCISE','2026-04-24',$impactPositive,'positiv (+)',null,null,'Referat','Sprachliche Mittel in Sachtexten',now_iso_demo()]);

    $stExam = $pdo->prepare("INSERT INTO exams(class_id,subject_id,teacher_id,exam_type,exam_date,title,created_at) VALUES (?,?,?,?,?,?,?)");
    $stGrade = $pdo->prepare("INSERT INTO exam_grades(exam_id,student_id,grade,tendency,remark) VALUES (?,?,?,?,?)");

    $stExam->execute([$classId,$amId,$teacherId,'TEST','2026-03-20','Lineare Funktionen – Test',now_iso_demo()]);
    $amTestId = (int)$pdo->lastInsertId();
    $stExam->execute([$classId,$amId,$teacherId,'REVIEW','2026-04-24','Zinsrechnung – schriftliche Wiederholung',now_iso_demo()]);
    $amReviewId = (int)$pdo->lastInsertId();
    $stExam->execute([$classId,$amId,$teacherId,'TASK','2026-05-08','Arbeitsauftrag Statistik',now_iso_demo()]);
    $amTaskId = (int)$pdo->lastInsertId();
    $stExam->execute([$classId,$dId,$teacherId,'SA','2026-04-28','Argumentierender Text',now_iso_demo()]);
    $dSaId = (int)$pdo->lastInsertId();

    $gradeSets = [
        [$amTestId, 'Anna', 1, 'plus', 'sicher und vollständig'],
        [$amTestId, 'Ben', 3, null, 'solide Basis'],
        [$amTestId, 'Clara', 2, null, 'gute Entwicklung'],
        [$amTestId, 'Daniel', 4, 'minus', 'Transfer noch unsicher'],
        [$amReviewId, 'Anna', 1, null, 'konstant stark'],
        [$amReviewId, 'Felix', 2, 'plus', 'deutlich verbessert'],
        [$amReviewId, 'Hugo', 3, null, 'noch einzelne Fehler'],
        [$amTaskId, 'Eva', 2, null, 'sauber bearbeitet'],
        [$amTaskId, 'Greta', 1, null, 'sehr klare Struktur'],
        [$dSaId, 'Greta', 2, null, 'sprachlich sicher'],
        [$dSaId, 'Anna', 2, 'minus', 'gute Argumentation, knapper Schluss'],
        [$dSaId, 'Ben', 3, null, 'Aufbau nachvollziehbar'],
    ];
    foreach ($gradeSets as [$examId,$studentName,$grade,$tendency,$remark]) {
        $stGrade->execute([$examId, $studentIds[$studentName], $grade, $tendency, $remark]);
    }

    $stGroup = $pdo->prepare("INSERT INTO teacher_student_groups(teacher_id,class_id,subject_id,name,note,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
    $stGroupMember = $pdo->prepare("INSERT INTO teacher_student_group_members(group_id,student_id,sort) VALUES (?,?,?)");
    $stGroup->execute([$teacherId,$classId,$amId,'COOL-Gruppe A','Projektgruppe für kooperative Aufgaben',now_iso_demo(),now_iso_demo()]);
    $groupA = (int)$pdo->lastInsertId();
    foreach (['Anna','Ben','Clara','Daniel'] as $idx => $studentName) {
        $stGroupMember->execute([$groupA,$studentIds[$studentName],$idx+1]);
    }
    $stGroup->execute([$teacherId,$classId,$amId,'COOL-Gruppe B','Zweite Arbeitsgruppe',now_iso_demo(),now_iso_demo()]);
    $groupB = (int)$pdo->lastInsertId();
    foreach (['Eva','Felix','Greta','Hugo'] as $idx => $studentName) {
        $stGroupMember->execute([$groupB,$studentIds[$studentName],$idx+1]);
    }

    $stPreset = $pdo->prepare("INSERT INTO teacher_participation_presets(teacher_id,class_id,subject_id,name,payload_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
    $presets = [
        [
            'Standard-Mitarbeit',
            [
                'reason_option_id' => $reasonDiscussion,
                'impact_option_id' => $impactPositive,
                'performance_option_ids' => [$perfMuendlich],
                'group_option_ids' => [$groupVerstehen],
                'social_form_option_id' => $socialAllein,
                'phase_option_id' => $phaseErarbeitung,
                'homework_option_id' => 0,
                'reason_text' => 'Fachlicher Beitrag im Unterricht',
                'note' => 'Kurz und sachlich dokumentieren.',
                'criteria_ids' => [$criteriaIds['Zusammenhänge erklärt']],
            ],
        ],
        [
            'Gruppenarbeit',
            [
                'reason_option_id' => $reasonDiscussion,
                'impact_option_id' => $impactNeutral,
                'performance_option_ids' => [$perfMuendlich, $perfPraktisch],
                'group_option_ids' => [$groupKooperation, $groupErklaeren],
                'social_form_option_id' => $socialGruppe,
                'phase_option_id' => $phasePraesentation,
                'homework_option_id' => 0,
                'reason_text' => 'Beitrag in der Gruppenphase',
                'note' => 'Auf Ergebnisbeitrag und Fachsprache achten.',
                'criteria_ids' => [$criteriaIds['hilfreich in der Gruppe beigetragen']],
            ],
        ],
    ];
    foreach ($presets as [$presetName,$payload]) {
        $stPreset->execute([$teacherId,null,$amId,$presetName,json_encode($payload, JSON_UNESCAPED_UNICODE),now_iso_demo(),now_iso_demo()]);
    }

    echo "Demo-Umgebung eingerichtet.\n";
    echo "Admin:   admin.demo / DemoAdmin123!\n";
    echo "Lehrer:  lehrer.demo / DemoLehrer123!\n";
    echo "Klasse:  3A Demo\n";
    echo "Fächer:  AM, D\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Demo-Setup: " . $e->getMessage() . "\n");
    exit(1);
}
