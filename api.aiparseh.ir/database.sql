-- SOURCE FILE: backend/schema.sql
-- Parse Admin / Parse API schema
-- Safe to execute repeatedly: CREATE TABLE IF NOT EXISTS only; no data is dropped.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','admin','developer_admin','education_admin') NOT NULL DEFAULT 'user',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_expiry (expires_at),
    KEY idx_auth_tokens_user (user_id),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(254) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_ip_time (ip_address, created_at),
    KEY idx_login_attempts_email_time (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question TEXT NOT NULL,
    subject VARCHAR(120) NULL,
    grade VARCHAR(80) NULL,
    source_type VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_questions_created (created_at),
    KEY idx_questions_subject (subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    type VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sources_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(80) NULL,
    target_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_audit_created (created_at),
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- SOURCE FILE: backend/migrations/20261123_ai_console.sql
CREATE TABLE IF NOT EXISTS ai_provider_settings (
    id TINYINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'paasta',
    api_key_ciphertext MEDIUMTEXT NULL,
    api_key_nonce VARCHAR(128) NULL,
    default_model VARCHAR(190) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_ai_settings_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_provider_settings (id, provider) VALUES (1, 'paasta');

CREATE TABLE IF NOT EXISTS ai_usage_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NULL,
    operation VARCHAR(64) NOT NULL,
    endpoint VARCHAR(190) NOT NULL,
    model VARCHAR(190) NULL,
    request_id VARCHAR(190) NULL,
    status_code SMALLINT UNSIGNED NOT NULL,
    input_tokens BIGINT UNSIGNED NULL,
    output_tokens BIGINT UNSIGNED NULL,
    total_tokens BIGINT UNSIGNED NULL,
    cached_input_tokens BIGINT UNSIGNED NULL,
    charged_toman DECIMAL(20,4) NULL,
    latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_usage_created (created_at),
    KEY idx_ai_usage_admin_created (admin_user_id, created_at),
    KEY idx_ai_usage_model_created (model, created_at),
    KEY idx_ai_usage_request_id (request_id),
    CONSTRAINT fk_ai_usage_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_generation_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NULL,
    generation_type ENUM('question','practice','review','chat','response') NOT NULL,
    title VARCHAR(255) NULL,
    model VARCHAR(190) NULL,
    prompt MEDIUMTEXT NULL,
    output MEDIUMTEXT NULL,
    request_id VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_history_created (created_at),
    KEY idx_ai_history_type (generation_type, created_at),
    CONSTRAINT fk_ai_history_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_prompt_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(190) NOT NULL,
    generation_type VARCHAR(64) NOT NULL,
    system_prompt MEDIUMTEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ai_prompt_templates_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_prompt_templates (slug,title,generation_type,system_prompt) VALUES
('question-fa','تولید سؤال فارسی','question','تو یک طراح ارشد سؤال آموزشی هستی. بر اساس موضوع، پایه و سطح دشواری داده‌شده، سؤال‌های دقیق و غیرتکراری به زبان فارسی تولید کن. برای هر سؤال پاسخ صحیح و توضیح کوتاه بده. خروجی را مرتب و قابل کپی ارائه کن.'),
('practice-fa','طراحی تمرین فارسی','practice','تو یک طراح تمرین آموزشی هستی. تمرین‌های مرحله‌بندی‌شده و قابل اجرا به زبان فارسی بساز. هدف یادگیری، دستورالعمل، مثال و معیار ارزیابی را مشخص کن.'),
('review-fa','بررسی و ویرایش متن فارسی','review','تو ویراستار و ارزیاب دقیق زبان فارسی هستی. متن را از نظر املا، دستور زبان، انسجام، وضوح، لحن و صحت ساختاری بررسی کن. ابتدا ایرادها و علت را فهرست کن و سپس نسخه اصلاح‌شده را ارائه بده. ادعای factual را بدون مدرک قطعی تأیید نکن.');


-- SOURCE FILE: backend/migrations/20261123_ai_usage_metadata.sql
-- Additive migration: preserve provider billing metadata returned for each request.
SET @provider_meta_sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ai_usage_logs ADD COLUMN provider_meta_json LONGTEXT NULL AFTER error_code',
    'SELECT 1'
  )
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ai_usage_logs'
    AND column_name = 'provider_meta_json'
);
PREPARE provider_meta_stmt FROM @provider_meta_sql;
EXECUTE provider_meta_stmt;
DEALLOCATE PREPARE provider_meta_stmt;


-- SOURCE FILE: backend/migrations/20261123_learning_platform.sql
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
  storage_path VARCHAR(1000) NULL,
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


CREATE TABLE IF NOT EXISTS admin_ui_settings (
  id TINYINT UNSIGNED NOT NULL,
  api_base_url VARCHAR(500) NOT NULL DEFAULT 'https://api.aiparseh.ir/api',
  custom_font_filename VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO admin_ui_settings (id,api_base_url) VALUES (1,'https://api.aiparseh.ir/api');


-- SOURCE FILE: backend/migrations/20261123_tracks_and_activities.sql
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


-- SOURCE FILE: backend/migrations/20261124_admin_roles.sql
-- Idempotently extend account roles without changing existing accounts or credentials.
ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','developer_admin','education_admin','teacher') NOT NULL DEFAULT 'user';


-- SOURCE FILE: backend/migrations/20261124_language_levels_classes_audio.sql
-- Shared education catalogs for English, German and French; safe to replay.
CREATE TABLE IF NOT EXISTS learning_languages (
  language_code VARCHAR(16) NOT NULL,
  language_name VARCHAR(120) NOT NULL,
  native_name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (language_code),
  KEY idx_learning_languages_active (active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO learning_languages(language_code,language_name,native_name,sort_order) VALUES
('en','English','انگلیسی',1),
('de','German','آلمانی',2),
('fr','French','فرانسوی',3);

CREATE TABLE IF NOT EXISTS learning_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  language_code VARCHAR(16) NOT NULL,
  level_code VARCHAR(16) NOT NULL,
  level_name VARCHAR(120) NOT NULL,
  level_order SMALLINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_learning_level_language (language_code,level_code),
  KEY idx_learning_levels_active (language_code,active,level_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO learning_levels(language_code,level_code,level_name,level_order) VALUES
('en','A1','Beginner',1),('en','A2','Elementary',2),('en','B1','Intermediate',3),('en','B2','Upper Intermediate',4),('en','C1','Advanced',5),('en','C2','Proficiency',6),
('de','A1','Anfänger',1),('de','A2','Grundstufe',2),('de','B1','Mittelstufe',3),('de','B2','Gute Mittelstufe',4),('de','C1','Fortgeschritten',5),('de','C2','Nahezu muttersprachlich',6),
('fr','A1','Débutant',1),('fr','A2','Élémentaire',2),('fr','B1','Intermédiaire',3),('fr','B2','Intermédiaire avancé',4),('fr','C1','Avancé',5),('fr','C2','Maîtrise',6);

CREATE TABLE IF NOT EXISTS class_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  title VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_types_code (code),
  KEY idx_class_types_active (active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO class_types(code,title,description,sort_order) VALUES
('in_person','حضوری','برگزاری در محل آموزشگاه',1),
('online','آنلاین','برگزاری به‌صورت اینترنتی',2),
('hybrid','ترکیبی','ترکیب جلسات حضوری و آنلاین',3),
('private','خصوصی','کلاس تک‌نفره',4),
('group','گروهی','کلاس چندنفره',5);

SET @parse_schema = DATABASE();
SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='learning_tracks' AND column_name='class_type_id'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE learning_tracks ADD COLUMN class_type_id BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

SET @parse_has_col = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@parse_schema AND table_name='question_bank' AND column_name='audio_url'
);
SET @parse_sql = IF(@parse_has_col=0,
  'ALTER TABLE question_bank ADD COLUMN audio_url VARCHAR(1000) NULL',
  'SELECT 1');
PREPARE parse_stmt FROM @parse_sql; EXECUTE parse_stmt; DEALLOCATE PREPARE parse_stmt;

CREATE TABLE IF NOT EXISTS student_audio_responses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  context_type ENUM('activity','exam') NOT NULL,
  context_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(1000) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_audio_response (student_id,context_type,context_id,question_id),
  KEY idx_student_audio_context (context_type,context_id),
  CONSTRAINT fk_student_audio_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_student_audio_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- SOURCE FILE: backend/migrations/20261124_teacher_and_model_routes.sql
-- Add teacher role and per-operation model routing.
ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','developer_admin','education_admin','teacher') NOT NULL DEFAULT 'user';
CREATE TABLE IF NOT EXISTS ai_operation_model_routes (operation_key VARCHAR(60) NOT NULL, model_id VARCHAR(190) NOT NULL DEFAULT '', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(operation_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

