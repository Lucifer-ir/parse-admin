-- Idempotently extend account roles without changing existing accounts or credentials.
ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','developer_admin','education_admin','teacher') NOT NULL DEFAULT 'user';
