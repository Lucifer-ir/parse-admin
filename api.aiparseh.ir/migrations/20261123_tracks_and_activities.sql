CREATE TABLE IF NOT EXISTS learning_tracks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  language_code VARCHAR(16) NOT NULL,
  language_name VARCHAR(120) NOT NULL,
  track_title VARCHAR(160) NOT NULL,
  course_title VARCHAR(160) NOT NULL,
  level_code VARCHAR(80) NOT NULL,
  description TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_learning_track (language_code,track_title,course_title,level_code),
  KEY idx_learning_tracks_active (active,language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL-compatible, repeatable column additions (the updater replays SQL files).
SET @parse_schema = DATABASE();

SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='students' AND column_name='track_id'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE students ADD COLUMN track_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='learning_resources' AND column_name='track_id'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE learning_resources ADD COLUMN track_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='question_bank' AND column_name='track_id'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE question_bank ADD COLUMN track_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='learning_activities' AND column_name='track_id'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE learning_activities ADD COLUMN track_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='exams' AND column_name='track_id'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE exams ADD COLUMN track_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

CREATE TABLE IF NOT EXISTS activity_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  submitted_at DATETIME NULL,
  score DECIMAL(8,2) NULL,
  total_points DECIMAL(8,2) NULL,
  status ENUM('in_progress','submitted','reviewed') NOT NULL DEFAULT 'in_progress',
  PRIMARY KEY (id),
  UNIQUE KEY uq_activity_submission (activity_id,student_id),
  KEY idx_activity_submission_status (status,submitted_at),
  CONSTRAINT fk_activity_submission_activity FOREIGN KEY (activity_id) REFERENCES learning_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_activity_submission_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  answer_json JSON NULL,
  is_correct TINYINT(1) NULL,
  awarded_points DECIMAL(8,2) NULL,
  teacher_feedback TEXT NULL,
  answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_activity_answer (submission_id,question_id),
  CONSTRAINT fk_activity_answer_submission FOREIGN KEY (submission_id) REFERENCES activity_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_activity_answer_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
