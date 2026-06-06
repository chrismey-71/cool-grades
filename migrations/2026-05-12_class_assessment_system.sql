ALTER TABLE classes
  ADD COLUMN assessment_system ENUM('sost','nost','yearly') NOT NULL DEFAULT 'yearly' AFTER label;

UPDATE classes
SET assessment_system='yearly'
WHERE assessment_system IS NULL OR assessment_system='';
