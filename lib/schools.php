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
