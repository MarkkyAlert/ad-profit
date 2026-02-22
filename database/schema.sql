-- schema.sql
-- โครงสร้างฐานข้อมูลหลักสำหรับระบบวิเคราะห์ยอดขาย

CREATE DATABASE IF NOT EXISTS ad_profit
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ad_profit;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS password_reset_tokens;
DROP TABLE IF EXISTS auth_rate_limits;
DROP TABLE IF EXISTS idempotency_requests;
DROP TABLE IF EXISTS monthly_goals;
DROP TABLE IF EXISTS daily_records;
DROP TABLE IF EXISTS goals;
DROP TABLE IF EXISTS shops;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shops (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shops_user_name (user_id, name),
    KEY idx_shops_user_id (user_id),
    CONSTRAINT fk_shops_user
        FOREIGN KEY (user_id)
        REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop_id BIGINT UNSIGNED NOT NULL,
    record_date DATE NOT NULL,
    revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ad_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_records_shop_date (shop_id, record_date),
    KEY idx_daily_records_record_date (record_date),
    KEY idx_daily_records_shop_date (shop_id, record_date),
    CONSTRAINT chk_daily_records_revenue_non_negative CHECK (revenue >= 0),
    CONSTRAINT chk_daily_records_ad_cost_non_negative CHECK (ad_cost >= 0),
    CONSTRAINT fk_daily_records_shop
        FOREIGN KEY (shop_id)
        REFERENCES shops (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_goals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop_id BIGINT UNSIGNED NOT NULL,
    goal_month CHAR(7) NOT NULL,
    target_revenue DECIMAL(12,2) NULL,
    target_profit DECIMAL(12,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monthly_goals_shop_month (shop_id, goal_month),
    KEY idx_monthly_goals_month (goal_month),
    CONSTRAINT chk_monthly_goals_revenue_non_negative CHECK (target_revenue IS NULL OR target_revenue >= 0),
    CONSTRAINT chk_monthly_goals_profit_non_negative CHECK (target_profit IS NULL OR target_profit >= 0),
    CONSTRAINT chk_monthly_goals_month_format CHECK (goal_month REGEXP '^[0-9]{4}-[0-9]{2}$'),
    CONSTRAINT fk_monthly_goals_shop
        FOREIGN KEY (shop_id)
        REFERENCES shops (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    response_payload JSON NULL,
    status ENUM('processing', 'completed', 'failed') NOT NULL DEFAULT 'processing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_scope (user_id, action_type, idempotency_key),
    KEY idx_idempotency_expire (expires_at),
    CONSTRAINT fk_idempotency_user
        FOREIGN KEY (user_id)
        REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_user (user_id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_token (token_hash),
    KEY idx_password_reset_expires (expires_at),
    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (user_id)
        REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
