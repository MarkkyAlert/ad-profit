<?php

declare(strict_types=1);

function app_path(string $path = ''): string
{
    $basePath = dirname(__DIR__);
    if ($path === '') {
        return $basePath;
    }

    return $basePath . '/' . ltrim($path, '/');
}

function app_url(string $path = ''): string
{
    $baseUrl = APP_URL;

    if ($path === '') {
        return $baseUrl;
    }

    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $normalizedPath = '/' . ltrim($path, '/');

    return $baseUrl . $normalizedPath;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatMoney(float|int $value): string
{
    return '฿' . number_format((float)$value, 0);
}

function formatRoas(?float $value): string
{
    return $value === null ? '–' : number_format($value, 2);
}

function formatPercent(?float $value): string
{
    return $value === null ? '–' : number_format($value, 1) . '%';
}

function formatThaiDate(string $date): string
{
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        return $date;
    }

    $thaiMonths = [
        '01' => 'ม.ค.',
        '02' => 'ก.พ.',
        '03' => 'มี.ค.',
        '04' => 'เม.ย.',
        '05' => 'พ.ค.',
        '06' => 'มิ.ย.',
        '07' => 'ก.ค.',
        '08' => 'ส.ค.',
        '09' => 'ก.ย.',
        '10' => 'ต.ค.',
        '11' => 'พ.ย.',
        '12' => 'ธ.ค.',
    ];

    $month = $thaiMonths[$dateObject->format('m')] ?? $dateObject->format('m');
    $thaiYear = (int)$dateObject->format('Y') + 543;

    return $dateObject->format('j') . ' ' . $month . ' ' . $thaiYear;
}

function formatThaiMonth(string $month): string
{
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return $month;
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $month . '-01');
    if (!$dateObject) {
        return $month;
    }

    $thaiMonths = [
        '01' => 'ม.ค.',
        '02' => 'ก.พ.',
        '03' => 'มี.ค.',
        '04' => 'เม.ย.',
        '05' => 'พ.ค.',
        '06' => 'มิ.ย.',
        '07' => 'ก.ค.',
        '08' => 'ส.ค.',
        '09' => 'ก.ย.',
        '10' => 'ต.ค.',
        '11' => 'พ.ย.',
        '12' => 'ธ.ค.',
    ];

    $monthText = $thaiMonths[$dateObject->format('m')] ?? $dateObject->format('m');
    $thaiYear = (int)$dateObject->format('Y') + 543;

    return $monthText . ' ' . $thaiYear;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function is_post_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_api_request(): bool
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    return str_contains($scriptName, '/api/');
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function set_flash(string $key, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return is_string($message) ? $message : null;
}

function client_ip(): string
{
    $candidates = [];

    if (defined('TRUST_PROXY') && TRUST_PROXY) {
        $forwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $forwardedIp) {
                $candidate = trim($forwardedIp);
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '') {
            $candidates[] = $realIp;
        }
    }

    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '') {
        $candidates[] = $remoteAddr;
    }

    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            continue;
        }

        return substr($candidate, 0, 45);
    }

    return 'unknown';
}
