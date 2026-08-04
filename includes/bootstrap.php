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
    // ⚠️ PHP มีตัวเก็บกวาด session ของตัวเอง (`gc_maxlifetime` ค่าปริยาย 1440 วิ = 24 นาที)
    // ถ้าไม่ตั้งให้ยาวอย่างน้อยเท่ากับ idle timeout ของแอป (14400 วิ = 4 ชม.) ไฟล์ session
    // จะถูกลบก่อน แล้วผู้ใช้หลุดจากระบบตั้งแต่พักไป ~24 นาที ทั้งที่หน้าจอบอกว่า 4 ชั่วโมง
    // (พิสูจน์แล้วว่าเกิดจริง — session ที่ไม่ถูกแตะ 30 นาทีถูกลบทิ้ง)
    // เผื่อไว้อีก 1 ชม. เพราะการเก็บกวาดเป็นการสุ่ม ไม่ได้ตรงเวลาเป๊ะ
    $sessionLifetime = max(
        (int)SESSION_IDLE_TIMEOUT_SECONDS,
        (int)SESSION_ABSOLUTE_TIMEOUT_SECONDS
    ) + 3600;

    // ⚠️ ตรึง cache_limiter เอง — ไม่งั้นหน้าที่ต้องล็อกอิน (ยอดขาย กำไร โปรไฟล์)
    // จะไม่มี `Cache-Control: no-store` บนโฮสต์ที่ตั้ง `session.cache_limiter=` ว่างไว้
    // แล้วเบราว์เซอร์/พร็อกซีเก็บหน้าไว้ให้คนถัดไปที่ใช้เครื่องเดียวกันกดย้อนกลับมาดูได้
    // (แอปตั้ง no-store เองแค่ 2 ที่คือหน้าดาวน์โหลดไฟล์)
    ini_set('session.cache_limiter', 'nocache');

    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $cookieSecure,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'gc_maxlifetime' => $sessionLifetime,
    ]);
}

date_default_timezone_set(APP_TIMEZONE);

// ⚠️ ต้องตั้ง display_errors **ก่อน** แตะระบบไฟล์
//
// เดิมสร้างโฟลเดอร์ log ก่อน ถ้าเส้นทางเขียนไม่ได้ (พิมพ์ผิดใน .env, open_basedir,
// โฟลเดอร์ temp เขียนไม่ได้) `mkdir()` จะเตือนออกมา **ก่อน** ที่แอปจะปิดการแสดง error
// ผลคือ (1) เส้นทางเต็มของเซิร์ฟเวอร์หลุดออกไปในหน้าเว็บ และ (2) response ถูกส่งไปแล้ว
// `header()` ทุกตัวหลังจากนั้นจึงไม่ทำงาน — หน้าที่ควรตอบ 503 กลายเป็น 200
// และ endpoint ที่ควรตอบ JSON จะมี HTML ของคำเตือนนำหน้าจนเบราว์เซอร์อ่านไม่ออก
error_reporting(E_ALL);
$displayErrors = APP_ENV === 'development';
ini_set('display_errors', $displayErrors ? '1' : '0');

$logDir = dirname(LOG_FILE);
if (!is_dir($logDir)) {
    // ⚠️ กลืนคำเตือนไว้ตรงนี้เท่านั้น — เขียน log ไม่ได้ไม่ใช่เหตุให้หน้าเว็บพัง
    // แต่ต้องไม่เงียบสนิทด้วย จึงพยายามบอกผ่าน error_log ของ PHP เอง (stderr/syslog)
    if (!@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        error_log('[bootstrap] สร้างโฟลเดอร์ log ไม่ได้: ' . $logDir);
    }
}

ini_set('log_errors', '1');

// ⚠️ ต้องถามถึง **ตัวไฟล์** ด้วย ไม่ใช่แค่โฟลเดอร์
//
// โฟลเดอร์เขียนได้ ไม่ได้แปลว่าไฟล์เขียนได้ · บนโฮสต์จริง ไฟล์ log มักถูกสร้างโดย
// ผู้ใช้คนอื่น (root ตอน deploy, ตัวหมุน log ของ cPanel) แล้วเหลือสิทธิ์อ่านอย่างเดียว
// เงื่อนไขเดิมมองแค่โฟลเดอร์ จึงชี้ `error_log` ไปที่ไฟล์ที่เขียนไม่ได้ — ข้อความ
// ทุกบรรทัดของทั้งระบบหายเงียบ ทั้งที่โค้ดตรงนี้เขียนขึ้นมาเพื่อกันกรณีนี้พอดี
$logFileWritable = file_exists(LOG_FILE)
    ? is_writable(LOG_FILE)
    : (is_dir($logDir) && is_writable($logDir));

if ($logFileWritable) {
    ini_set('error_log', LOG_FILE);
} else {
    // ปล่อยให้ error_log ไปตามค่าปริยายของ host แทนที่จะชี้ไปที่เขียนไม่ได้
    // (ชี้ผิดที่ = log หายเงียบ ๆ ทั้งระบบ ซึ่งแย่กว่าไปกองรวมกับ log ของเซิร์ฟเวอร์)
    error_log('[bootstrap] เขียน LOG_FILE ไม่ได้ ใช้ปลายทางปริยายของเซิร์ฟเวอร์แทน: ' . LOG_FILE);
}

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

        // ⚠️ ชื่อตาราง/คอลัมน์/index ครบ ไม่ได้แปลว่าโครงสร้างใช้ได้
        //
        // เดิมการ์ดดูแค่ "มีชื่อนี้ไหม" — ย่อ `note` เหลือ VARCHAR(50) หรือเปลี่ยน
        // `revenue` เป็น DECIMAL(8,2) แล้วการ์ดยังผ่าน ทั้งที่ข้อมูลจะถูกตัดทิ้งเงียบ ๆ
        // บนโฮสต์ที่ไม่ได้เปิด strict mode · ลบ foreign key แล้วการลบร้านจะ "สำเร็จ"
        // โดยข้อมูลข้างในค้างอยู่ · เปลี่ยนเป็น MyISAM แล้ว rollBack() ไม่ทำอะไรเลย
        foreach (schema_required_column_types() as [$tableName, $columnName, $expectedType]) {
            $check = schema_column_type_matches($pdo, $tableName, $columnName, $expectedType);
            if (($check['ok'] ?? false) !== true) {
                $cachedResult = [
                    'ok' => false,
                    'message' => sprintf(
                        'Column %s.%s must be %s (found %s)',
                        $tableName,
                        $columnName,
                        $expectedType,
                        (string)($check['actual'] ?? '?')
                    ),
                ];
                return $cachedResult;
            }
        }

        // ⚠️ collation คือกติกา "ข้อความสองอันเท่ากันไหม" — `COLUMN_TYPE` มองไม่เห็นมัน
        // ถ้า migration ล้มกลางคัน แอปจะบูตขึ้นมารายงานว่าปกติ ทั้งที่บั๊กยังอยู่ครบ
        foreach (schema_required_collations() as [$tableName, $columnName, $expectedCollation]) {
            $check = schema_collation_matches($pdo, $tableName, $columnName, $expectedCollation);
            if (($check['ok'] ?? false) !== true) {
                $cachedResult = [
                    'ok' => false,
                    'message' => sprintf(
                        'Column %s.%s must use collation %s (found %s) — run database/migrations',
                        $tableName,
                        $columnName,
                        $expectedCollation,
                        (string)($check['actual'] ?? '?')
                    ),
                ];
                return $cachedResult;
            }
        }

        foreach (schema_transactional_tables() as $tableName) {
            if (!schema_table_is_innodb($pdo, $tableName)) {
                $cachedResult = [
                    'ok' => false,
                    'message' => 'Table ' . $tableName . ' must use InnoDB (transactions are required)',
                ];
                return $cachedResult;
            }
        }

        foreach (schema_required_cascades() as [$tableName, $constraintName]) {
            if (!schema_cascade_exists($pdo, $tableName, $constraintName)) {
                $cachedResult = [
                    'ok' => false,
                    'message' => 'Missing ON DELETE CASCADE ' . $tableName . '.' . $constraintName,
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
