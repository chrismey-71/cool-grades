ALTER TABLE teacher_participation_presets MODIFY class_id INT NULL;

DELETE p1 FROM teacher_participation_presets p1
JOIN teacher_participation_presets p2
  ON p1.teacher_id=p2.teacher_id
 AND p1.subject_id=p2.subject_id
 AND p1.name=p2.name
 AND (p1.updated_at<p2.updated_at OR (p1.updated_at=p2.updated_at AND p1.id<p2.id));

UPDATE teacher_participation_presets
SET class_id=NULL
WHERE class_id IS NOT NULL;

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'teacher_participation_presets'
      AND index_name = 'uniq_teacher_preset_subject'
  ),
  'SELECT 1'
  ,
  'ALTER TABLE teacher_participation_presets ADD UNIQUE KEY uniq_teacher_preset_subject (teacher_id, subject_id, name)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'teacher_participation_presets'
      AND index_name = 'idx_teacher_preset_subject_lookup'
  ),
  'SELECT 1'
  ,
  'ALTER TABLE teacher_participation_presets ADD INDEX idx_teacher_preset_subject_lookup (teacher_id, subject_id, updated_at)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
