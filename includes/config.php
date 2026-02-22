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
