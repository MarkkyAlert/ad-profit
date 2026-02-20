<?php

declare(strict_types=1);

if (!defined('APP_ENV')) {
    define('APP_ENV', (string)(getenv('APP_ENV') ?: 'development'));
}

if (!defined('APP_NAME')) {
    define('APP_NAME', (string)(getenv('APP_NAME') ?: 'Ad Profit'));
}

if (!defined('APP_URL')) {
    $documentRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $detectedAppUrl = '';

    if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
        $detectedAppUrl = substr($projectRoot, strlen($documentRoot));
        if ($detectedAppUrl === false) {
            $detectedAppUrl = '';
        }
    }

    define('APP_URL', rtrim((string)(getenv('APP_URL') ?: $detectedAppUrl), '/'));
}

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', (string)(getenv('APP_TIMEZONE') ?: 'Asia/Bangkok'));
}

if (!defined('DB_HOST')) {
    define('DB_HOST', (string)(getenv('DB_HOST') ?: '127.0.0.1'));
}

if (!defined('DB_PORT')) {
    define('DB_PORT', (string)(getenv('DB_PORT') ?: '3306'));
}

if (!defined('DB_NAME')) {
    define('DB_NAME', (string)(getenv('DB_NAME') ?: 'ad_profit'));
}

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', (string)(getenv('DB_CHARSET') ?: 'utf8mb4'));
}

if (!defined('DB_USER')) {
    define('DB_USER', (string)(getenv('DB_USER') ?: 'root'));
}

if (!defined('DB_PASS')) {
    define('DB_PASS', (string)(getenv('DB_PASS') ?: ''));
}

if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) {
    define('RATE_LIMIT_MAX_ATTEMPTS', 5);
}

if (!defined('RATE_LIMIT_WINDOW_SECONDS')) {
    define('RATE_LIMIT_WINDOW_SECONDS', 60);
}

if (!defined('LOG_FILE')) {
    define('LOG_FILE', dirname(__DIR__) . '/logs/php-error.log');
}
