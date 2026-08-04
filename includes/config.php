<?php

declare(strict_types=1);

/**
 * อ่านค่าตัวเลขบวกจาก environment — ค่าที่ตั้งผิด (ติดลบ/ศูนย์/ไม่ใช่ตัวเลข) ใช้ค่าปริยายแทน
 *
 * ⚠️ `(int)"4h"` = 4 และ `(int)"-1"` = -1 ซึ่งทั้งคู่บูตผ่านโดยไม่มีอะไรเตือน
 * (ค่าติดลบใน SESSION_*_TIMEOUT ทำให้การหมดอายุถูกปิดเงียบ ๆ)
 *
 * ⚠️ ห่อด้วย function_exists เพราะไฟล์นี้ถูก include ได้จากหลายทาง (bootstrap, เทสต์,
 * สคริปต์ CLI) — เดิม include ซ้ำ = fatal "Cannot redeclare" ทั้งที่ค่าคงที่ข้างล่าง
 * ป้องกันตัวเองด้วย `if (!defined(...))` อยู่แล้ว
 */
if (!function_exists('config_positive_int')) {
    function config_positive_int(string $key, int $default): int
    {
        // ⚠️ `getenv($key) ?: ''` กลืน "0" ทิ้ง เพราะ "0" เป็น falsy — ค่าที่ตั้งผิดจึง
        // เงียบสนิท เหมือนไม่ได้ตั้งอะไรเลย ต้องเทียบกับ false ตรง ๆ
        $rawEnv = getenv($key);
        $raw = $rawEnv === false ? '' : trim($rawEnv);

        if ($raw === '') {
            return $default;
        }

        if (preg_match('/^\d+$/', $raw) !== 1 || (int)$raw <= 0) {
            error_log(sprintf('[config] %s="%s" ใช้ไม่ได้ (ต้องเป็นจำนวนเต็มบวก) — ใช้ค่าปริยาย %d', $key, $raw, $default));

            return $default;
        }

        return (int)$raw;
    }
}

$projectRoot = dirname(__DIR__);
$envFilePath = $projectRoot . '/.env';

if (is_file($envFilePath) && is_readable($envFilePath)) {
    $envLines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (is_array($envLines)) {
        foreach ($envLines as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                continue;
            }

            $separatorPosition = strpos($trimmedLine, '=');
            if ($separatorPosition === false) {
                continue;
            }

            $name = trim(substr($trimmedLine, 0, $separatorPosition));
            $value = trim(substr($trimmedLine, $separatorPosition + 1));

            if ($name === '' || preg_match('/^[A-Z][A-Z0-9_]*$/i', $name) !== 1) {
                continue;
            }

            $firstChar = $value[0] ?? '';
            $lastChar = $value !== '' ? substr($value, -1) : '';
            if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}

if (!defined('APP_ENV')) {
    define('APP_ENV', (string)(getenv('APP_ENV') ?: 'production'));
}

if (!defined('APP_NAME')) {
    define('APP_NAME', (string)(getenv('APP_NAME') ?: 'Ad Profit'));
}

if (!defined('APP_URL')) {
    $configuredAppUrl = trim((string)(getenv('APP_URL') ?: ''));

    if ($configuredAppUrl !== '') {
        define('APP_URL', rtrim($configuredAppUrl, '/'));
    } else {
        $documentRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $projectRoot = str_replace('\\', '/', dirname(__DIR__));
        $detectedAppPath = '';

        if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
            $detectedAppPath = substr($projectRoot, strlen($documentRoot));
            if ($detectedAppPath === false) {
                $detectedAppPath = '';
            }
        }

        if (APP_ENV === 'production') {
            // In production, force explicit APP_URL from environment to avoid Host header poisoning.
            define('APP_URL', '');
        } else {
            define('APP_URL', rtrim($detectedAppPath, '/'));
        }
    }
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

// ⚠️ ต้องอ่านจาก env จริง — .env.example เขียนสองค่านี้ไว้ให้ผู้ดูแลปรับ แต่เดิมโค้ด
// ฮาร์ดโค้ดไว้ ตั้งค่าใน .env แล้วไม่มีผลอะไรและไม่มีอะไรเตือน
if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) {
    define('RATE_LIMIT_MAX_ATTEMPTS', config_positive_int('RATE_LIMIT_MAX_ATTEMPTS', 5));
}

if (!defined('RATE_LIMIT_WINDOW_SECONDS')) {
    define('RATE_LIMIT_WINDOW_SECONDS', config_positive_int('RATE_LIMIT_WINDOW_SECONDS', 60));
}

if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', max(4, config_positive_int('PASSWORD_MIN_LENGTH', 8)));
}

// bcrypt (PASSWORD_DEFAULT) ตัดรหัสผ่านที่ 72 byte — ยาวกว่านี้ส่วนเกินไม่มีผลต่อการตรวจ
if (!defined('PASSWORD_MAX_BYTES')) {
    define('PASSWORD_MAX_BYTES', 72);
}

if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    define('SESSION_IDLE_TIMEOUT_SECONDS', config_positive_int('SESSION_IDLE_TIMEOUT_SECONDS', 14400));
}

if (!defined('SESSION_ABSOLUTE_TIMEOUT_SECONDS')) {
    define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', config_positive_int('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 86400));
}

if (!defined('SCHEMA_GUARD_ENABLED')) {
    // ⚠️ `getenv() ?: 'true'` กลับหัวความหมายของค่า "0" — "0" เป็น falsy จึงกลายเป็น
    // 'true' (เปิดการ์ด) ขณะที่ค่าขยะอย่าง "disabled" กลายเป็น false (ปิดการ์ดเงียบ ๆ)
    // คือทั้งสองทางตรงข้ามกับที่ผู้ตั้งค่าคาดหวังพอดี
    $schemaGuardRaw = getenv('SCHEMA_GUARD_ENABLED');
    $schemaGuardValue = $schemaGuardRaw === false ? 'true' : trim($schemaGuardRaw);
    $schemaGuardParsed = filter_var($schemaGuardValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if ($schemaGuardParsed === null) {
        error_log(sprintf(
            '[config] SCHEMA_GUARD_ENABLED="%s" ใช้ไม่ได้ (ต้องเป็น true/false) — เปิดการ์ดไว้เพื่อความปลอดภัย',
            $schemaGuardValue
        ));
        $schemaGuardParsed = true;
    }

    define('SCHEMA_GUARD_ENABLED', $schemaGuardParsed);
}

if (!defined('TRUST_PROXY')) {
    define('TRUST_PROXY', filter_var(getenv('TRUST_PROXY') ?: 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('TRUSTED_PROXIES')) {
    $trustedProxiesRaw = (string)(getenv('TRUSTED_PROXIES') ?: '');
    $trustedProxies = [];

    if ($trustedProxiesRaw !== '') {
        foreach (explode(',', $trustedProxiesRaw) as $proxyCandidate) {
            $proxyIp = trim($proxyCandidate);
            if ($proxyIp === '') {
                continue;
            }

            if (filter_var($proxyIp, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $trustedProxies[] = $proxyIp;
        }
    }

    define('TRUSTED_PROXIES', $trustedProxies);
}

if (!defined('EXPOSE_DEV_RESET_LINK')) {
    define('EXPOSE_DEV_RESET_LINK', filter_var(getenv('EXPOSE_DEV_RESET_LINK') ?: 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('LOG_FILE')) {
    $defaultLogFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ad-profit' . DIRECTORY_SEPARATOR . 'php-error.log';
    define('LOG_FILE', (string)(getenv('LOG_FILE') ?: $defaultLogFile));
}

if (!defined('PASSWORD_RESET_TOKEN_TTL_HOURS')) {
    define('PASSWORD_RESET_TOKEN_TTL_HOURS', config_positive_int('PASSWORD_RESET_TOKEN_TTL_HOURS', 1));
}

if (!defined('MAIL_ENABLED')) {
    define('MAIL_ENABLED', filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', (string)(getenv('MAIL_HOST') ?: 'smtp.gmail.com'));
}

if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', config_positive_int('MAIL_PORT', 587));
}

if (!defined('MAIL_TIMEOUT_SECONDS')) {
    define('MAIL_TIMEOUT_SECONDS', config_positive_int('MAIL_TIMEOUT_SECONDS', 15));
}

if (!defined('MAIL_RETRY_ATTEMPTS')) {
    define('MAIL_RETRY_ATTEMPTS', config_positive_int('MAIL_RETRY_ATTEMPTS', 1));
}

if (!defined('MAIL_USERNAME')) {
    define('MAIL_USERNAME', (string)(getenv('MAIL_USERNAME') ?: ''));
}

if (!defined('MAIL_PASSWORD')) {
    define('MAIL_PASSWORD', (string)(getenv('MAIL_PASSWORD') ?: ''));
}

if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', (string)(getenv('MAIL_FROM_ADDRESS') ?: ''));
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', (string)(getenv('MAIL_FROM_NAME') ?: APP_NAME));
}
