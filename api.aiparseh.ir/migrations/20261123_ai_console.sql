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
