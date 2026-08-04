<?php

declare(strict_types=1);

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

if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) {
    define('RATE_LIMIT_MAX_ATTEMPTS', 5);
}

if (!defined('RATE_LIMIT_WINDOW_SECONDS')) {
    define('RATE_LIMIT_WINDOW_SECONDS', 60);
}

if (!defined('PASSWORD_MIN_LENGTH')) {
    define('PASSWORD_MIN_LENGTH', max(4, (int)(getenv('PASSWORD_MIN_LENGTH') ?: 8)));
}

// bcrypt (PASSWORD_DEFAULT) ตัดรหัสผ่านที่ 72 byte — ยาวกว่านี้ส่วนเกินไม่มีผลต่อการตรวจ
if (!defined('PASSWORD_MAX_BYTES')) {
    define('PASSWORD_MAX_BYTES', 72);
}

if (!defined('SESSION_IDLE_TIMEOUT_SECONDS')) {
    define('SESSION_IDLE_TIMEOUT_SECONDS', (int)(getenv('SESSION_IDLE_TIMEOUT_SECONDS') ?: 14400));
}

if (!defined('SESSION_ABSOLUTE_TIMEOUT_SECONDS')) {
    define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', (int)(getenv('SESSION_ABSOLUTE_TIMEOUT_SECONDS') ?: 86400));
}

if (!defined('SCHEMA_GUARD_ENABLED')) {
    define('SCHEMA_GUARD_ENABLED', filter_var(getenv('SCHEMA_GUARD_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
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
    define('PASSWORD_RESET_TOKEN_TTL_HOURS', (int)(getenv('PASSWORD_RESET_TOKEN_TTL_HOURS') ?: 1));
}

if (!defined('MAIL_ENABLED')) {
    define('MAIL_ENABLED', filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));
}

if (!defined('MAIL_HOST')) {
    define('MAIL_HOST', (string)(getenv('MAIL_HOST') ?: 'smtp.gmail.com'));
}

if (!defined('MAIL_PORT')) {
    define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
}

if (!defined('MAIL_TIMEOUT_SECONDS')) {
    define('MAIL_TIMEOUT_SECONDS', (int)(getenv('MAIL_TIMEOUT_SECONDS') ?: 15));
}

if (!defined('MAIL_RETRY_ATTEMPTS')) {
    define('MAIL_RETRY_ATTEMPTS', max(0, (int)(getenv('MAIL_RETRY_ATTEMPTS') ?: 1)));
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
