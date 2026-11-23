-- Parse learning platform schema. Safe to run repeatedly.
CREATE TABLE IF NOT EXISTS students (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  student_code VARCHAR(50) NULL,
  phone VARCHAR(20) NULL,
  name VARCHAR(200) NOT NULL DEFAULT '',
  email VARCHAR(254) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','disabled','pending') NOT NULL DEFAULT 'active',
  level VARCHAR(80) NULL,
  group_name VARCHAR(120) NULL,
  last_seen_at DATETIME NULL,
  last_seen_platform VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_students_username (username),
  UNIQUE KEY uq_students_code (student_code),
  UNIQUE KEY uq_students_phone (phone),
  KEY idx_students_status_seen (status,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_auth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_token_hash (token_hash),
  KEY idx_student_tokens_student (student_id),
  KEY idx_student_tokens_expiry (expires_at),
  CONSTRAINT fk_student_token_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_activity_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  route VARCHAR(190) NULL,
  metadata_json JSON NULL,
  ip_address VARCHAR(45) NULL,
  platform VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_student_events_created (created_at),
  KEY idx_student_events_student_created (student_id,created_at),
  CONSTRAINT fk_student_event_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_resources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_type ENUM('book','handout','lesson','course','source') NOT NULL DEFAULT 'handout',
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  subject VARCHAR(120) NULL,
  level VARCHAR(80) NULL,
  content_text LONGTEXT NULL,
  file_url VARCHAR(1000) NULL,
  original_filename VARCHAR(255) NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_resources_type_published (resource_type,published),
  KEY idx_resources_subject_level (subject,level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_bank (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id BIGINT UNSIGNED NULL,
  target_student_id BIGINT UNSIGNED NULL,
  subject VARCHAR(120) NOT NULL,
  level VARCHAR(80) NULL,
  skill ENUM('listening','speaking','reading','writing','grammar','vocabulary','general') NOT NULL DEFAULT 'general',
  question_type ENUM('multiple_choice','written','true_false','fill_blank','matching','ordering','listening','speaking') NOT NULL DEFAULT 'multiple_choice',
  question_text MEDIUMTEXT NOT NULL,
  options_json JSON NULL,
  answer_json JSON NULL,
  explanation MEDIUMTEXT NULL,
  difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
  points DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  tags_json JSON NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_question_bank_subject_type (subject,question_type),
  KEY idx_question_bank_student (target_student_id),
  KEY idx_question_bank_resource (resource_id),
  CONSTRAINT fk_question_bank_student FOREIGN KEY (target_student_id) REFERENCES students(id) ON DELETE SET NULL,
  CONSTRAINT fk_question_bank_resource FOREIGN KEY (resource_id) REFERENCES learning_resources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS learning_activities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_type ENUM('practice','assignment') NOT NULL DEFAULT 'practice',
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  subject VARCHAR(120) NULL,
  level VARCHAR(80) NULL,
  instructions MEDIUMTEXT NULL,
  due_at DATETIME NULL,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_learning_activities_status (status,activity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_questions (
  activity_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (activity_id,question_id),
  CONSTRAINT fk_activity_question_activity FOREIGN KEY (activity_id) REFERENCES learning_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_activity_question_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_activity_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  activity_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  score DECIMAL(8,2) NULL,
  status ENUM('assigned','in_progress','submitted','reviewed') NOT NULL DEFAULT 'assigned',
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_activity (activity_id,student_id),
  KEY idx_student_assignments (student_id,status),
  CONSTRAINT fk_student_assignment_activity FOREIGN KEY (activity_id) REFERENCES learning_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_student_assignment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  subject VARCHAR(120) NULL,
  level VARCHAR(80) NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  target_student_id BIGINT UNSIGNED NULL,
  shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
  shuffle_options TINYINT(1) NOT NULL DEFAULT 0,
  show_result TINYINT(1) NOT NULL DEFAULT 1,
  passing_score DECIMAL(8,2) NOT NULL DEFAULT 50.00,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exams_status_dates (status,starts_at,ends_at),
  KEY idx_exams_student (target_student_id),
  CONSTRAINT fk_exam_target_student FOREIGN KEY (target_student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_questions (
  exam_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  points DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (exam_id,question_id),
  CONSTRAINT fk_exam_question_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_question_bank FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  submitted_at DATETIME NULL,
  score DECIMAL(8,2) NULL,
  total_points DECIMAL(8,2) NULL,
  status ENUM('in_progress','submitted','expired','reviewed') NOT NULL DEFAULT 'in_progress',
  PRIMARY KEY (id),
  KEY idx_exam_attempt_exam (exam_id,status),
  KEY idx_exam_attempt_student (student_id,started_at),
  CONSTRAINT fk_exam_attempt_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_attempt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  answer_json JSON NULL,
  is_correct TINYINT(1) NULL,
  awarded_points DECIMAL(8,2) NULL,
  teacher_feedback TEXT NULL,
  answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_attempt_answer (attempt_id,question_id),
  CONSTRAINT fk_exam_answer_attempt FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_exam_answer_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
