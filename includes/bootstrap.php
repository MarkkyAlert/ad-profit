<?php

declare(strict_types=1);

// โหลด Composer Autoloader
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// โหลดการตั้งค่า
require_once __DIR__ . '/config.php';

$httpsEnabled = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$cookieSecure = APP_ENV === 'production' ? true : $httpsEnabled;

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $cookieSecure,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

date_default_timezone_set(APP_TIMEZONE);

$logDir = dirname(LOG_FILE);
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

ini_set('log_errors', '1');
ini_set('error_log', LOG_FILE);
error_reporting(E_ALL);

$displayErrors = APP_ENV === 'development';
ini_set('display_errors', $displayErrors ? '1' : '0');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

spl_autoload_register(function (string $class): void {
    $className = ltrim($class, '\\');
    $normalizedClass = str_replace('\\', '/', $className);
    $leafName = basename($normalizedClass);

    $candidates = [
        dirname(__DIR__) . '/' . $normalizedClass . '.php',
        dirname(__DIR__) . '/app/' . $normalizedClass . '.php',
        dirname(__DIR__) . '/app/Services/' . $leafName . '.php',
        dirname(__DIR__) . '/app/Repositories/' . $leafName . '.php',
    ];

    foreach ($candidates as $filePath) {
        if (file_exists($filePath)) {
            require_once $filePath;
            return;
        }
    }
});

set_exception_handler(function (Throwable $exception): void {
    error_log(sprintf(
        '[exception] %s in %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    if (is_api_request()) {
        jsonResponse([
            'success' => false,
            'error' => 'เกิดข้อผิดพลาดภายในระบบ',
        ], 500);
    }

    http_response_code(500);
    echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>System Error</title></head><body>';
    echo '<h1>เกิดข้อผิดพลาด</h1><p>กรุณาลองใหม่อีกครั้ง</p>';
    echo '</body></html>';
    exit;
});

$pdo = db();

if (SCHEMA_GUARD_ENABLED) {
    $schemaCheck = check_schema_compatibility($pdo);
    if (($schemaCheck['ok'] ?? false) !== true) {
        $errorMessage = (string)($schemaCheck['message'] ?? 'Schema compatibility check failed');
        error_log('[schema_guard] ' . $errorMessage);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, '[schema_guard] Database schema upgrade required: ' . $errorMessage . PHP_EOL);
            exit(1);
        }

        if (is_api_request()) {
            jsonResponse([
                'success' => false,
                'error' => 'Database schema upgrade required',
            ], 503);
        }

        http_response_code(503);
        echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><title>Schema Upgrade Required</title></head><body>';
        echo '<h1>ต้องอัปเกรดโครงสร้างฐานข้อมูล</h1><p>กรุณารัน database/schema.sql หรือ migration ล่าสุดก่อนใช้งานระบบ</p>';
        echo '</body></html>';
        exit;
    }
}

function check_schema_compatibility(PDO $pdo): array
{
    static $cachedResult = null;
    if (is_array($cachedResult)) {
        return $cachedResult;
    }

    try {
        if (!schema_table_exists($pdo, 'auth_rate_limits')) {
            $cachedResult = [
                'ok' => false,
                'message' => 'Missing required table auth_rate_limits',
            ];
            return $cachedResult;
        }

        if (!schema_column_exists($pdo, 'users', 'display_name')) {
            $cachedResult = [
                'ok' => false,
                'message' => 'Missing required column users.display_name',
            ];
            return $cachedResult;
        }

        if (!schema_column_exists($pdo, 'users', 'session_version')) {
            $cachedResult = [
                'ok' => false,
                'message' => 'Missing required column users.session_version',
            ];
            return $cachedResult;
        }

        foreach (schema_required_unique_indexes() as [$tableName, $indexName]) {
            if (!schema_unique_index_exists($pdo, $tableName, $indexName)) {
                $cachedResult = [
                    'ok' => false,
                    'message' => 'Missing required unique index ' . $tableName . '.' . $indexName,
                ];
                return $cachedResult;
            }
        }
    } catch (Throwable $exception) {
        $cachedResult = [
            'ok' => false,
            'message' => 'Schema check error: ' . $exception->getMessage(),
        ];
        return $cachedResult;
    }

    $cachedResult = ['ok' => true];
    return $cachedResult;
}
