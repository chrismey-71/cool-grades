<?php
require_once __DIR__.'/logger.php';

function cfg(): array {
  static $cfg=null;
  if ($cfg!==null) return $cfg;
  $file=__DIR__.'/../config.php';
  if (!file_exists($file)) die('Missing config.php (copy config.example.php to config.php).');
  $cfg=require $file; return $cfg;
}

function _ensure_schema(PDO $pdo): void {
  static $done=false;
  if($done) return;
  $done=true;

  // Global app settings (key/value)
  try{
    $st=$pdo->query("SHOW TABLES LIKE 'app_settings'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE app_settings (
        `key` VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` LONGTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
    $st=$pdo->query("SHOW COLUMNS FROM app_settings LIKE 'value'");
    $col=$st->fetch();
    if($col && stripos((string)($col['Type'] ?? ''), 'longtext') === false){
      $pdo->exec("ALTER TABLE app_settings MODIFY COLUMN `value` LONGTEXT NOT NULL");
    }
    // Default: 30 minutes inactivity timeout
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('session_timeout_minutes','30',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('brand_primary_color','#2F6F3A',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('event_retention_days','30',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('legal_imprint_html','<p>Das Impressum wurde noch nicht hinterlegt.</p>',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('legal_privacy_html','<p>Die Datenschutzbestimmung wurde noch nicht hinterlegt.</p>',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('login_rate_limit_max_attempts','5',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('login_rate_limit_delay_seconds','2',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('login_rate_limit_lockout_minutes','15',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('password_min_length','12',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('password_require_upper','1',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('password_require_lower','1',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('password_require_digit','1',NOW(),NOW())");
    $pdo->exec("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES('password_require_special','1',NOW(),NOW())");
    $year=(int)date('Y');
    $month=(int)date('n');
    $startYear=($month >= 9) ? $year : ($year - 1);
    $defaultPeriods=[
      'semester1'=>[
        'from'=>sprintf('%04d-09-01', $startYear),
        'to'=>sprintf('%04d-01-31', $startYear + 1),
      ],
      'semester2'=>[
        'from'=>sprintf('%04d-02-01', $startYear + 1),
        'to'=>sprintf('%04d-08-31', $startYear + 1),
      ],
    ];
    $st=$pdo->prepare("INSERT IGNORE INTO app_settings(`key`,`value`,updated_at,created_at) VALUES(?,?,NOW(),NOW())");
    $st->execute(['semester1_from',$defaultPeriods['semester1']['from']]);
    $st->execute(['semester1_to',$defaultPeriods['semester1']['to']]);
    $st->execute(['semester2_from',$defaultPeriods['semester2']['from']]);
    $st->execute(['semester2_to',$defaultPeriods['semester2']['to']]);
  }catch(Exception $e){ /* ignore */ }

  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(128) NOT NULL,
      ip_hash CHAR(64) NOT NULL,
      success TINYINT(1) NOT NULL DEFAULT 0,
      attempted_at DATETIME NOT NULL,
      INDEX idx_login_attempt_lookup (username, ip_hash, success, attempted_at),
      INDEX idx_login_attempt_cleanup (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  }catch(Exception $e){ /* ignore */ }

  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_period_sets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      label VARCHAR(32) NOT NULL,
      semester1_from DATE NOT NULL,
      semester1_to DATE NOT NULL,
      semester2_from DATE NOT NULL,
      semester2_to DATE NOT NULL,
      archived TINYINT(1) NOT NULL DEFAULT 0,
      is_current TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      INDEX idx_school_period_archived_dates (archived, semester1_from, semester2_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $st=$pdo->query("SHOW COLUMNS FROM school_period_sets LIKE 'is_current'");
    if(!$st->fetch()){
      $pdo->exec("ALTER TABLE school_period_sets ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 0 AFTER archived");
    }

    $periodCount = (int)$pdo->query("SELECT COUNT(*) FROM school_period_sets")->fetchColumn();
    if($periodCount === 0){
      $defaults = $defaultPeriods ?? [
        'semester1'=>['from'=>date('Y').'-09-01','to'=>(date('Y') + 1).'-01-31'],
        'semester2'=>['from'=>(date('Y') + 1).'-02-01','to'=>(date('Y') + 1).'-08-31'],
      ];
      $fetchSetting = $pdo->prepare("SELECT value FROM app_settings WHERE `key`=? LIMIT 1");
      $fetchSetting->execute(['semester1_from']);
      $semester1From = (string)($fetchSetting->fetchColumn() ?: $defaults['semester1']['from']);
      $fetchSetting->execute(['semester1_to']);
      $semester1To = (string)($fetchSetting->fetchColumn() ?: $defaults['semester1']['to']);
      $fetchSetting->execute(['semester2_from']);
      $semester2From = (string)($fetchSetting->fetchColumn() ?: $defaults['semester2']['from']);
      $fetchSetting->execute(['semester2_to']);
      $semester2To = (string)($fetchSetting->fetchColumn() ?: $defaults['semester2']['to']);
      $startYear = (int)substr($semester1From, 0, 4);
      $endYear = (int)substr($semester2To, 0, 4);
      $label = ($startYear > 0 && $endYear === ($startYear + 1))
        ? sprintf('%04d/%02d', $startYear, $endYear % 100)
        : (string)$startYear;
      $st = $pdo->prepare("INSERT INTO school_period_sets(label,semester1_from,semester1_to,semester2_from,semester2_to,archived,created_at,updated_at)
                           VALUES(?,?,?,?,?,0,NOW(),NOW())");
      $st->execute([$label,$semester1From,$semester1To,$semester2From,$semester2To]);
    }
    $currentCount = (int)$pdo->query("SELECT COUNT(*) FROM school_period_sets WHERE is_current=1")->fetchColumn();
    if($currentCount === 0){
      $pdo->exec("UPDATE school_period_sets SET is_current=1 WHERE id=(SELECT id FROM (SELECT id FROM school_period_sets WHERE archived=0 ORDER BY semester1_from DESC, id DESC LIMIT 1) x)");
    } elseif($currentCount > 1){
      $pdo->exec("UPDATE school_period_sets SET is_current=0 WHERE id NOT IN (SELECT id FROM (SELECT id FROM school_period_sets WHERE is_current=1 ORDER BY semester1_from DESC, id DESC LIMIT 1) x)");
    }
  }catch(Exception $e){ /* ignore */ }

  // Lightweight, idempotent schema tweaks (keeps updates resilient)
  try{
    $st=$pdo->query("SHOW COLUMNS FROM criteria LIKE 'archived'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE criteria ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER active");
    }
  }catch(Exception $e){ /* ignore (no privileges / already exists / etc.) */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM participation_options LIKE 'archived'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE participation_options ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER active");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW INDEX FROM lesson_sessions WHERE Key_name='uniq_lesson_slot'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE lesson_sessions ADD UNIQUE KEY uniq_lesson_slot (class_id, subject_id, lesson_date, lesson_unit)");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'criteria_suggestions'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE criteria_suggestions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_type ENUM('FSB','HLS','BOTH') NOT NULL DEFAULT 'BOTH',
        subject_code VARCHAR(8) NOT NULL,
        category VARCHAR(64) NOT NULL,
        label VARCHAR(200) NOT NULL,
        description VARCHAR(255) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        archived TINYINT(1) NOT NULL DEFAULT 0,
        sort INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        INDEX idx_subject (subject_code),
        INDEX idx_active (active, archived),
        UNIQUE KEY uniq_suggestion (school_type, subject_code, category, label)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM exams LIKE 'exam_type'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE exams ADD COLUMN exam_type VARCHAR(16) NOT NULL DEFAULT 'SA' AFTER teacher_id");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM exam_grades LIKE 'tendency'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE exam_grades ADD COLUMN tendency VARCHAR(120) NULL AFTER grade");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM exam_grades LIKE 'remark'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE exam_grades ADD COLUMN remark TEXT NULL AFTER tendency");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'oral_assessments'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE oral_assessments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        student_id INT NOT NULL,
        assessment_type VARCHAR(24) NOT NULL DEFAULT 'ORAL_EXAM',
        assessment_date DATE NOT NULL,
        impact_option_id INT NULL,
        impact_label VARCHAR(128) NULL,
        topic_area VARCHAR(255) NULL,
        questions TEXT NULL,
        category VARCHAR(120) NULL,
        title VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (impact_option_id) REFERENCES participation_options(id) ON DELETE SET NULL,
        INDEX idx_oral_teacher_date (teacher_id, assessment_date),
        INDEX idx_oral_class_subject_date (class_id, subject_id, assessment_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  // User preferences (UI mode, theme, participation quick-pick)
  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_quick_entry_ui'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_quick_entry_ui VARCHAR(16) NULL AFTER must_change_password");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_theme'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_theme VARCHAR(16) NULL AFTER pref_quick_entry_ui");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_participation_quick_pick_enabled'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_participation_quick_pick_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pref_theme");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_participation_quick_pick_limit'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_participation_quick_pick_limit INT NOT NULL DEFAULT 10 AFTER pref_participation_quick_pick_enabled");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_legal_hints_enabled'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_legal_hints_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER pref_participation_quick_pick_limit");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_compact_forms_enabled'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_compact_forms_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER pref_legal_hints_enabled");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_visual_contrast'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_visual_contrast VARCHAR(16) NOT NULL DEFAULT 'normal' AFTER pref_compact_forms_enabled");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM users LIKE 'pref_simple_participation_entry'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE users ADD COLUMN pref_simple_participation_entry TINYINT(1) NOT NULL DEFAULT 0 AFTER pref_visual_contrast");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM classes LIKE 'assessment_system'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE classes ADD COLUMN assessment_system ENUM('sost','nost','yearly') NOT NULL DEFAULT 'yearly' AFTER label");
    }
    $pdo->exec("UPDATE classes SET assessment_system='yearly' WHERE assessment_system IS NULL OR assessment_system=''");
  }catch(Exception $e){ /* ignore */ }

  try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schools (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(180) NOT NULL,
      address TEXT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      UNIQUE KEY uniq_school_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS school_forms (
      id INT AUTO_INCREMENT PRIMARY KEY,
      school_id INT NOT NULL,
      code VARCHAR(32) NOT NULL,
      name VARCHAR(180) NOT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      UNIQUE KEY uniq_school_form_code (school_id, code),
      INDEX idx_school_form_active (active, code),
      FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $schoolCount=(int)$pdo->query("SELECT COUNT(*) FROM schools")->fetchColumn();
    if($schoolCount === 0){
      $pdo->exec("INSERT INTO schools(name,address,active,created_at,updated_at) VALUES('Standardschule','',1,NOW(),NOW())");
    }
    $defaultSchoolId=(int)$pdo->query("SELECT id FROM schools ORDER BY active DESC, id ASC LIMIT 1")->fetchColumn();
    if($defaultSchoolId > 0){
      $ins=$pdo->prepare("INSERT IGNORE INTO school_forms(school_id,code,name,active,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())");
      $ins->execute([$defaultSchoolId,'HLS','Höhere Lehranstalt',1]);
      $ins->execute([$defaultSchoolId,'FSB','Fachschule',1]);
    }

    try{ $pdo->exec("ALTER TABLE classes MODIFY school_type VARCHAR(32) NOT NULL DEFAULT 'HLS'"); }catch(Exception $ignored){}
    $st=$pdo->query("SHOW COLUMNS FROM classes LIKE 'school_form_id'");
    if(!$st->fetch()){
      $pdo->exec("ALTER TABLE classes ADD COLUMN school_form_id INT NULL AFTER school_type");
      $pdo->exec("ALTER TABLE classes ADD INDEX idx_classes_school_form (school_form_id)");
      try{ $pdo->exec("ALTER TABLE classes ADD CONSTRAINT fk_classes_school_form FOREIGN KEY (school_form_id) REFERENCES school_forms(id) ON DELETE SET NULL"); }catch(Exception $ignored){}
    }
    $forms=$pdo->query("SELECT id, code FROM school_forms ORDER BY active DESC, id ASC")->fetchAll();
    foreach($forms as $form){
      $st=$pdo->prepare("UPDATE classes SET school_form_id=? WHERE (school_form_id IS NULL OR school_form_id=0) AND school_type=?");
      $st->execute([(int)$form['id'], (string)$form['code']]);
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM classes LIKE 'school_period_set_id'");
    if(!$st->fetch()){
      $pdo->exec("ALTER TABLE classes ADD COLUMN school_period_set_id INT NULL AFTER id");
      $pdo->exec("ALTER TABLE classes ADD INDEX idx_classes_school_period (school_period_set_id)");
    }
    $currentPeriod = (int)$pdo->query("SELECT id FROM school_period_sets WHERE is_current=1 LIMIT 1")->fetchColumn();
    if($currentPeriod <= 0){
      $currentPeriod = (int)$pdo->query("SELECT id FROM school_period_sets ORDER BY semester1_from DESC, id DESC LIMIT 1")->fetchColumn();
    }
    if($currentPeriod > 0){
      $st=$pdo->prepare("UPDATE classes SET school_period_set_id=? WHERE school_period_set_id IS NULL OR school_period_set_id=0");
      $st->execute([$currentPeriod]);
    }
    foreach(['predecessor_class_id INT NULL AFTER assessment_system','is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER predecessor_class_id','is_departed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived'] as $ddl){
      $col = strtok($ddl, ' ');
      $st=$pdo->query("SHOW COLUMNS FROM classes LIKE ".$pdo->quote($col));
      if(!$st->fetch()){
        $pdo->exec("ALTER TABLE classes ADD COLUMN ".$ddl);
      }
    }
    $st=$pdo->query("SHOW INDEX FROM classes WHERE Key_name='name'");
    if($st->fetch()){
      try{ $pdo->exec("ALTER TABLE classes DROP INDEX name"); }catch(Exception $ignored){}
    }
    $st=$pdo->query("SHOW INDEX FROM classes WHERE Key_name='uniq_class_school_year_name'");
    if(!$st->fetch()){
      try{ $pdo->exec("ALTER TABLE classes ADD UNIQUE KEY uniq_class_school_year_name (school_period_set_id,name)"); }catch(Exception $ignored){}
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'class_enrollments'");
    if(!$st->fetch()){
      $pdo->exec("CREATE TABLE class_enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NOT NULL,
        school_period_set_id INT NOT NULL,
        status ENUM('active','repeated','transferred','left','archived') NOT NULL DEFAULT 'active',
        entry_date DATE NULL,
        exit_date DATE NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_student_class_enrollment (student_id,class_id),
        INDEX idx_class_enrollment_class (class_id,status),
        INDEX idx_class_enrollment_student (student_id,school_period_set_id),
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (school_period_set_id) REFERENCES school_period_sets(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
    $pdo->exec("INSERT IGNORE INTO class_enrollments(student_id,class_id,school_period_set_id,status,entry_date,created_at,updated_at)
                SELECT s.id,s.class_id,COALESCE(c.school_period_set_id,(SELECT id FROM school_period_sets WHERE is_current=1 LIMIT 1)),'active',NULL,NOW(),NOW()
                FROM students s
                JOIN classes c ON c.id=s.class_id
                WHERE s.class_id IS NOT NULL");
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'teacher_participation_presets'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE teacher_participation_presets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        class_id INT NULL,
        subject_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_teacher_preset_subject (teacher_id, subject_id, name),
        INDEX idx_teacher_preset_subject_lookup (teacher_id, subject_id, updated_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM teacher_participation_presets LIKE 'class_id'");
    $col=$st->fetch();
    if($col && strtoupper((string)($col['Null'] ?? ''))!=='YES'){
      $pdo->exec("ALTER TABLE teacher_participation_presets MODIFY class_id INT NULL");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $pdo->exec("DELETE p1 FROM teacher_participation_presets p1
                JOIN teacher_participation_presets p2
                  ON p1.teacher_id=p2.teacher_id
                 AND p1.subject_id=p2.subject_id
                 AND p1.name=p2.name
                 AND (p1.updated_at<p2.updated_at OR (p1.updated_at=p2.updated_at AND p1.id<p2.id))");
  }catch(Exception $e){ /* ignore */ }

  try{
    $pdo->exec("UPDATE teacher_participation_presets SET class_id=NULL WHERE class_id IS NOT NULL");
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW INDEX FROM teacher_participation_presets WHERE Key_name='uniq_teacher_preset_subject'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE teacher_participation_presets ADD UNIQUE KEY uniq_teacher_preset_subject (teacher_id, subject_id, name)");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW INDEX FROM teacher_participation_presets WHERE Key_name='idx_teacher_preset_subject_lookup'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE teacher_participation_presets ADD INDEX idx_teacher_preset_subject_lookup (teacher_id, subject_id, updated_at)");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'teacher_student_groups'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE teacher_student_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        note TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_teacher_student_group_name (teacher_id,class_id,subject_id,name),
        INDEX idx_teacher_student_group_lookup (teacher_id,class_id,subject_id,updated_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'teacher_student_group_members'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE teacher_student_group_members (
        group_id INT NOT NULL,
        student_id INT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        PRIMARY KEY (group_id, student_id),
        FOREIGN KEY (group_id) REFERENCES teacher_student_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        INDEX idx_teacher_student_group_member_student (student_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'final_assessments'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE final_assessments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        student_id INT NOT NULL,
        school_period_set_id INT NOT NULL,
        assessment_scope VARCHAR(16) NOT NULL,
        assessment_label VARCHAR(80) NOT NULL,
        school_year_label VARCHAR(32) NOT NULL,
        period_from DATE NOT NULL,
        period_to DATE NOT NULL,
        subject_is_schularbeit TINYINT(1) NULL,
        suggestion_value TINYINT NULL,
        suggestion_label VARCHAR(64) NULL,
        suggestion_explanation TEXT NULL,
        final_grade TINYINT NULL,
        deviation_flag TINYINT(1) NOT NULL DEFAULT 0,
        deviation_note TEXT NULL,
        teacher_comment TEXT NULL,
        data_basis_level VARCHAR(16) NOT NULL,
        data_basis_label VARCHAR(64) NOT NULL,
        data_basis_explanation VARCHAR(255) NULL,
        participation_count INT NOT NULL DEFAULT 0,
        documented_day_count INT NOT NULL DEFAULT 0,
        positive_count INT NOT NULL DEFAULT 0,
        neutral_count INT NOT NULL DEFAULT 0,
        negative_count INT NOT NULL DEFAULT 0,
        unrated_count INT NOT NULL DEFAULT 0,
        participation_quality_label VARCHAR(64) NULL,
        participation_quality_avg DECIMAL(5,2) NULL,
        top_criteria VARCHAR(255) NULL,
        comments_summary TEXT NULL,
        oral_count INT NOT NULL DEFAULT 0,
        oral_positive_count INT NOT NULL DEFAULT 0,
        oral_neutral_count INT NOT NULL DEFAULT 0,
        oral_negative_count INT NOT NULL DEFAULT 0,
        oral_summary_text VARCHAR(255) NULL,
        written_count INT NOT NULL DEFAULT 0,
        written_avg DECIMAL(5,2) NULL,
        written_summary_text VARCHAR(255) NULL,
        written_type_summary_json LONGTEXT NULL,
        semester_hint VARCHAR(255) NULL,
        year_trend_label VARCHAR(128) NULL,
        status ENUM('draft','final') NOT NULL DEFAULT 'draft',
        snapshot_json LONGTEXT NOT NULL,
        last_change_note TEXT NULL,
        created_by INT NOT NULL,
        updated_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        finalized_at DATETIME NULL,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (school_period_set_id) REFERENCES school_period_sets(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_final_assessment_slot (class_id,subject_id,student_id,school_period_set_id,assessment_scope),
        INDEX idx_final_assessment_lookup (class_id,subject_id,school_period_set_id,assessment_scope,status),
        INDEX idx_final_assessment_student (student_id,school_period_set_id,assessment_scope)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW TABLES LIKE 'final_assessment_history'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("CREATE TABLE final_assessment_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        final_assessment_id INT NOT NULL,
        changed_by INT NOT NULL,
        change_type VARCHAR(24) NOT NULL,
        status_before VARCHAR(16) NULL,
        status_after VARCHAR(16) NOT NULL,
        final_grade_before TINYINT NULL,
        final_grade_after TINYINT NULL,
        change_note TEXT NULL,
        snapshot_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (final_assessment_id) REFERENCES final_assessments(id) ON DELETE CASCADE,
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_final_assessment_history_lookup (final_assessment_id,created_at),
        INDEX idx_final_assessment_history_user (changed_by,created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $pdo->exec("INSERT IGNORE INTO participation_options (opt_type,scope,subject_id,teacher_id,label,active,sort,created_at) VALUES
      ('observation_group','global',NULL,NULL,'Verstehen / Erfassen',1,10,NOW()),
      ('observation_group','global',NULL,NULL,'Anwenden / Transfer',1,20,NOW()),
      ('observation_group','global',NULL,NULL,'Argumentieren / Erklären',1,30,NOW()),
      ('observation_group','global',NULL,NULL,'Arbeitsweise / Genauigkeit',1,40,NOW()),
      ('observation_group','global',NULL,NULL,'Kooperation / Selbstständigkeit',1,50,NOW())");
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM participation_events LIKE 'pedagogical_mode'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE participation_events ADD COLUMN pedagogical_mode VARCHAR(16) NULL AFTER note");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM participation_options LIKE 'pedagogical_hint_mode'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE participation_options ADD COLUMN pedagogical_hint_mode VARCHAR(16) NULL AFTER label");
    }
  }catch(Exception $e){ /* ignore */ }

  try{
    $st=$pdo->query("SHOW COLUMNS FROM subjects LIKE 'is_schularbeit_subject'");
    $has=$st->fetch();
    if(!$has){
      $pdo->exec("ALTER TABLE subjects ADD COLUMN is_schularbeit_subject TINYINT(1) NULL AFTER name");
    }
  }catch(Exception $e){ /* ignore */ }

}

function db(): PDO {
  static $pdo=null;
  if ($pdo) return $pdo;
  $c=cfg()['db'];
  $dsn="mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
  $pdo=new PDO($dsn,$c['user'],$c['pass'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
  ]);

  _ensure_schema($pdo);
  return $pdo;
}
