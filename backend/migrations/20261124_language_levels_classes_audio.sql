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
