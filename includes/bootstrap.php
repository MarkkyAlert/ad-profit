<?php

declare(strict_types=1);

$httpsEnabled = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $httpsEnabled,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

require_once __DIR__ . '/config.php';

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
