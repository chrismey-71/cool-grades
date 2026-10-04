<?php
require_once __DIR__.'/helpers.php';

// Kompetenz-Beobachtung: schlankes, von der taeglichen Mitarbeitserfassung
// bewusst getrenntes Schnellnotiz-Werkzeug fuer Methoden-, Sozial- und
// Selbst-/Personalkompetenz-Beobachtungen waehrend des Unterrichts. Siehe
// migrations/2026-10-01_competence_observations.sql fuer das Datenmodell.

/** Feste Kategorie-Reihenfolge + Anzeige-Label. */
function competence_category_labels(): array {
  return [
    'methoden' => 'Methodenkompetenz',
    'sozial' => 'Sozialkompetenz',
    'selbst' => 'Selbst-/Personalkompetenz',
  ];
}

function competence_category_label(string $category): string {
  return competence_category_labels()[$category] ?? $category;
}

/**
 * Laedt die aktiven Tags, gruppiert nach Kategorie (in der festen
 * Kategorie-Reihenfolge, Tags je Kategorie nach sort/label sortiert).
 * Rückgabe: ['methoden'=>[['id'=>..,'label'=>..],...], 'sozial'=>[...], 'selbst'=>[...]]
 */
function competence_tags_by_category(PDO $pdo): array {
  $grouped = [];
  foreach(array_keys(competence_category_labels()) as $cat){ $grouped[$cat] = []; }

  $st = $pdo->query("SELECT id,category,label FROM competence_tags WHERE active=1 AND IFNULL(archived,0)=0 ORDER BY category,sort,label");
  foreach($st->fetchAll() as $row){
    $cat = (string)$row['category'];
    if(!isset($grouped[$cat])) $grouped[$cat] = [];
    $grouped[$cat][] = ['id'=>(int)$row['id'],'label'=>(string)$row['label']];
  }
  return $grouped;
}

/**
 * Anzahl der Beobachtungen, die einen bestimmten Tag bereits verwenden.
 * Entscheidet in admin/competence_tags.php, ob ein geloeschter Tag hart
 * entfernt oder (um historische Daten nicht per ON DELETE CASCADE
 * mitzureissen) nur archiviert wird.
 */
function competence_tag_used_count(PDO $pdo, int $tagId): int {
  $st = $pdo->prepare("SELECT COUNT(*) AS c FROM competence_observation_tags WHERE tag_id=?");
  $st->execute([$tagId]);
  return (int)($st->fetch()['c'] ?? 0);
}

/**
 * Liefert fuer eine Klasse/Fach/Lehrkraft/Datum, welche Schüler:innen bereits
 * eine Notiz haben und mit welchen Tag-IDs. Rückgabe:
 * [student_id => ['observation_id'=>int,'tag_ids'=>[int,...]]]
 */
function competence_observations_for_day(PDO $pdo, int $teacherId, int $classId, int $subjectId, string $date): array {
  $st = $pdo->prepare("SELECT co.id AS observation_id, co.student_id, cot.tag_id
                       FROM competence_observations co
                       LEFT JOIN competence_observation_tags cot ON cot.observation_id=co.id
                       WHERE co.teacher_id=? AND co.class_id=? AND co.subject_id=? AND co.observation_date=?");
  $st->execute([$teacherId,$classId,$subjectId,$date]);

  $result = [];
  foreach($st->fetchAll() as $row){
    $sid = (int)$row['student_id'];
    if(!isset($result[$sid])){
      $result[$sid] = ['observation_id'=>(int)$row['observation_id'],'tag_ids'=>[]];
    }
    if($row['tag_id'] !== null){
      $result[$sid]['tag_ids'][] = (int)$row['tag_id'];
    }
  }
  return $result;
}

/**
 * Speichert die Tag-Auswahl einer Schüler:in fuer einen Tag (ersetzt eine
 * evtl. bereits bestehende Notiz desselben Tages vollständig). Eine leere
 * Tag-Liste löscht die Notiz des Tages wieder. Gibt die gespeicherten
 * tag_ids zurück (leer, wenn die Notiz gelöscht wurde).
 */
function competence_save_student_tags(PDO $pdo, int $teacherId, int $studentId, int $classId, int $subjectId, string $date, array $tagIds): array {
  $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

  $st = $pdo->prepare("SELECT id FROM competence_observations WHERE teacher_id=? AND student_id=? AND class_id=? AND subject_id=? AND observation_date=?");
  $st->execute([$teacherId,$studentId,$classId,$subjectId,$date]);
  $existing = $st->fetch();
  $observationId = $existing ? (int)$existing['id'] : 0;

  if(!$tagIds){
    if($observationId){
      $pdo->prepare("DELETE FROM competence_observations WHERE id=?")->execute([$observationId]);
    }
    return [];
  }

  // Nur Tags übernehmen, die tatsächlich existieren und aktiv sind (schützt
  // vor veralteten IDs aus einem noch offenen Browser-Tab nach Deaktivierung
  // eines Tags).
  $in = implode(',', array_fill(0, count($tagIds), '?'));
  $validSt = $pdo->prepare("SELECT id FROM competence_tags WHERE active=1 AND id IN ($in)");
  $validSt->execute($tagIds);
  $validIds = array_map('intval', array_column($validSt->fetchAll(), 'id'));
  if(!$validIds){
    if($observationId){
      $pdo->prepare("DELETE FROM competence_observations WHERE id=?")->execute([$observationId]);
    }
    return [];
  }

  $now = now_iso();
  if($observationId){
    $pdo->prepare("UPDATE competence_observations SET updated_at=? WHERE id=?")->execute([$now,$observationId]);
    $pdo->prepare("DELETE FROM competence_observation_tags WHERE observation_id=?")->execute([$observationId]);
  } else {
    $pdo->prepare("INSERT INTO competence_observations (teacher_id,student_id,class_id,subject_id,observation_date,created_at,updated_at)
                   VALUES (?,?,?,?,?,?,?)")
        ->execute([$teacherId,$studentId,$classId,$subjectId,$date,$now,$now]);
    $observationId = (int)$pdo->lastInsertId();
  }

  $insTag = $pdo->prepare("INSERT INTO competence_observation_tags (observation_id,tag_id) VALUES (?,?)");
  foreach($validIds as $tagId){
    $insTag->execute([$observationId,$tagId]);
  }

  return $validIds;
}

/**
 * Aggregiert fuer eine Klasse/Fach alle Kompetenz-Beobachtungen im
 * angegebenen Zeitraum, je Schüler:in und Tag. Grundlage fuer das
 * Kompetenzprofil (teacher/competence_profile.php). Rückgabe:
 * [student_id => ['total'=>int, 'by_category'=>['methoden'=>int,...], 'tag_counts'=>[tag_id=>int,...]]]
 */
function competence_profile_aggregate(PDO $pdo, int $classId, int $subjectId, string $dateFrom, string $dateTo, int $teacherId = 0): array {
  $sql = "SELECT co.student_id, ct.id AS tag_id, ct.category, ct.label, COUNT(*) AS cnt
          FROM competence_observations co
          JOIN competence_observation_tags cot ON cot.observation_id=co.id
          JOIN competence_tags ct ON ct.id=cot.tag_id
          WHERE co.class_id=? AND co.subject_id=? AND co.observation_date BETWEEN ? AND ?";
  $params = [$classId,$subjectId,$dateFrom,$dateTo];
  if($teacherId>0){ $sql .= " AND co.teacher_id=?"; $params[] = $teacherId; }
  $sql .= " GROUP BY co.student_id, ct.id, ct.category, ct.label";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $result = [];
  foreach($st->fetchAll() as $row){
    $sid = (int)$row['student_id'];
    $cat = (string)$row['category'];
    $cnt = (int)$row['cnt'];
    if(!isset($result[$sid])){
      $result[$sid] = ['total'=>0,'by_category'=>[],'tags'=>[]];
    }
    $result[$sid]['total'] += $cnt;
    $result[$sid]['by_category'][$cat] = ($result[$sid]['by_category'][$cat] ?? 0) + $cnt;
    $result[$sid]['tags'][] = ['tag_id'=>(int)$row['tag_id'],'category'=>$cat,'label'=>(string)$row['label'],'count'=>$cnt];
  }
  return $result;
}

/**
 * Anzahl der Tage im Zeitraum, an denen für die Klasse/das Fach überhaupt
 * mindestens eine Kompetenz-Beobachtung erfasst wurde (für den Hinweis
 * "Datenbasis: X Tage" in der Auswertung).
 */
function competence_profile_days_with_data(PDO $pdo, int $classId, int $subjectId, string $dateFrom, string $dateTo, int $teacherId = 0): int {
  $sql = "SELECT COUNT(DISTINCT observation_date) AS c
          FROM competence_observations
          WHERE class_id=? AND subject_id=? AND observation_date BETWEEN ? AND ?";
  $params = [$classId,$subjectId,$dateFrom,$dateTo];
  if($teacherId>0){ $sql .= " AND teacher_id=?"; $params[] = $teacherId; }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)($st->fetch()['c'] ?? 0);
}
