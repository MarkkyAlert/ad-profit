<?php

declare(strict_types=1);

// Composer autoloader — โหลด PHPUnit + คลาสใน app/ (ผ่าน autoload-dev classmap ใน composer.json)
require dirname(__DIR__) . '/vendor/autoload.php';

// --- define constant ที่จำเป็น ---
// หมายเหตุ: ตั้งใจ "ไม่" include includes/bootstrap.php เต็ม เพราะมี side effect
// (session_start, security headers, db(), schema guard). unit test จึงกำหนด constant เอง
// แล้ว include เฉพาะ helper (functions.php) + guard (auth.php) เท่านั้น
if (!defined('APP_ENV')) {
    define('APP_ENV', 'testing');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Ad Profit Test');
}
if (!defined('APP_URL')) {
    define('APP_URL', '');
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Bangkok');
}
if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', 8);
}
if (!defined('PASSWORD_MAX_BYTES')) {
    define('PASSWORD_MAX_BYTES', 72);
}
if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) {
    define('RATE_LIMIT_MAX_ATTEMPTS', 5);
}
if (!defined('RATE_LIMIT_WINDOW_SECONDS')) {
    define('RATE_LIMIT_WINDOW_SECONDS', 60);
}
if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    define('SESSION_IDLE_TIMEOUT_SECONDS', 14400);
}
if (!defined('SESSION_ABSOLUTE_TIMEOUT_SECONDS')) {
    define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 86400);
}
if (!defined('PASSWORD_RESET_TOKEN_TTL_HOURS')) {
    define('PASSWORD_RESET_TOKEN_TTL_HOURS', 1);
}
if (!defined('EXPOSE_DEV_RESET_LINK')) {
    define('EXPOSE_DEV_RESET_LINK', false);
}

// MAIL_* ปิดไว้ตาม default ของ config.php — เทสต์ต้องไม่ส่งอีเมลจริงไม่ว่ากรณีใด
foreach ([
    'MAIL_ENABLED' => false,
    'MAIL_HOST' => 'smtp.example.invalid',
    'MAIL_PORT' => 587,
    'MAIL_TIMEOUT_SECONDS' => 15,
    'MAIL_RETRY_ATTEMPTS' => 1,
    'MAIL_USERNAME' => '',
    'MAIL_PASSWORD' => '',
    'MAIL_FROM_ADDRESS' => '',
    'MAIL_FROM_NAME' => 'Ad Profit Test',
] as $mailConstant => $mailValue) {
    if (!defined($mailConstant)) {
        define($mailConstant, $mailValue);
    }
}

date_default_timezone_set(APP_TIMEZONE);

// include เฉพาะ helper + auth guard (ห้าม include includes/bootstrap.php เต็ม)
require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/auth.php';
