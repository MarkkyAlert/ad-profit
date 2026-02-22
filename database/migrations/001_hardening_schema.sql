-- Migration: 001_hardening_schema.sql
-- Description: Production hardening - adds auth_rate_limits table, unique constraints, and missing columns
-- Date: 2026-02-22
-- Run this BEFORE deploying the latest code

USE ad_profit;

-- 1. Add display_name column to users table if not exists
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'display_name'
);

SET @add_display_name = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN display_name VARCHAR(120) NULL AFTER email',
    'SELECT "Column users.display_name already exists"'
);

PREPARE stmt FROM @add_display_name;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1.1 Add session_version column to users table if not exists
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'session_version'
);

SET @add_session_version = IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER display_name',
    'SELECT "Column users.session_version already exists"'
);

PREPARE stmt FROM @add_session_version;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Create auth_rate_limits table for cross-session rate limiting
CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket_key CHAR(64) NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    client_ip VARCHAR(45) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_rate_limits_bucket (bucket_key),
    KEY idx_auth_rate_limits_action_ip (action_type, client_ip),
    KEY idx_auth_rate_limits_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add unique constraint on shops(user_id, name) to prevent duplicate shop names
-- Check if index already exists before adding
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'shops'
      AND INDEX_NAME = 'uq_shops_user_name'
);

SET @add_shop_unique = IF(@idx_exists = 0,
    'ALTER TABLE shops ADD UNIQUE KEY uq_shops_user_name (user_id, name)',
    'SELECT "Index shops.uq_shops_user_name already exists"'
);

PREPARE stmt FROM @add_shop_unique;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add unique constraint on password_reset_tokens(token_hash) to prevent token collision
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'password_reset_tokens'
      AND INDEX_NAME = 'uq_password_reset_token_hash'
);

SET @add_token_unique = IF(@idx_exists = 0,
    'ALTER TABLE password_reset_tokens ADD UNIQUE KEY uq_password_reset_token_hash (token_hash)',
    'SELECT "Index password_reset_tokens.uq_password_reset_token_hash already exists"'
);

PREPARE stmt FROM @add_token_unique;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verification queries (run these to confirm migration success)
-- SELECT 'auth_rate_limits' AS tbl, COUNT(*) AS exists FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_rate_limits';
-- SELECT 'users.display_name' AS col, COUNT(*) AS exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'display_name';
-- SELECT 'users.session_version' AS col, COUNT(*) AS exists FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version';
-- SELECT 'shops.uq_shops_user_name' AS idx, COUNT(*) AS exists FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shops' AND INDEX_NAME = 'uq_shops_user_name';
-- SELECT 'password_reset_tokens.uq_password_reset_token_hash' AS idx, COUNT(*) AS exists FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens' AND INDEX_NAME = 'uq_password_reset_token_hash';
