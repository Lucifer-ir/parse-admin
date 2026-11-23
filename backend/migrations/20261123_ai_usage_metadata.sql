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
