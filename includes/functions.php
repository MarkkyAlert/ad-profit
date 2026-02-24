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

function wants_json_response(): bool
{
    $acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_respond(array $payload, int $statusCode, string $redirectUrl, bool $wantsJson): never
{
    if ($wantsJson) {
        jsonResponse($payload, $statusCode);
    }

    if (($payload['success'] ?? false) === true) {
        if (isset($payload['message'])) {
            set_flash('success', (string)$payload['message']);
        }
    } elseif (isset($payload['error'])) {
        set_flash('error', (string)$payload['error']);
    }

    redirect($redirectUrl);
}

function resolve_safe_redirect_path(string $fallback, ?string $postRedirectTo = null, ?string $referer = null): string
{
    $basePath = (string)(parse_url(APP_URL, PHP_URL_PATH) ?? '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');

    $candidates = [];
    if (is_string($postRedirectTo) && trim($postRedirectTo) !== '') {
        $candidates[] = $postRedirectTo;
    }
    if (is_string($referer) && trim($referer) !== '') {
        $candidates[] = $referer;
    }

    foreach ($candidates as $candidateRaw) {
        $candidate = trim($candidateRaw);
        if ($candidate === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $parsedUrl = parse_url($candidate);
            if (!is_array($parsedUrl)) {
                continue;
            }

            $path = (string)($parsedUrl['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $query = (string)($parsedUrl['query'] ?? '');
            $candidate = $path . ($query !== '' ? '?' . $query : '');
        }

        if (!str_starts_with($candidate, '/')) {
            continue;
        }

        if (str_starts_with($candidate, '//')) {
            continue;
        }

        if ($basePath !== '') {
            if ($candidate === $basePath) {
                return $fallback;
            }

            if (str_starts_with($candidate, $basePath . '/')) {
                $candidate = substr($candidate, strlen($basePath));
                if ($candidate === '') {
                    return $fallback;
                }
            }
        }

        return $candidate;
    }

    return $fallback;
}

function ensure_post_request_or_respond(bool $wantsJson, string $redirectUrl): void
{
    if (!is_post_request()) {
        api_respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, $redirectUrl, $wantsJson);
    }
}

function ensure_form_content_type_or_respond(bool $wantsJson, string $redirectUrl): void
{
    if (!is_post_request()) {
        return;
    }

    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if ($contentType === '') {
        // Some clients may omit Content-Type for empty bodies; allow for small-system compatibility.
        return;
    }

    $isUrlEncoded = str_starts_with($contentType, 'application/x-www-form-urlencoded');
    $isMultipart = str_starts_with($contentType, 'multipart/form-data');

    if ($isUrlEncoded || $isMultipart) {
        return;
    }

    api_respond([
        'success' => false,
        'error' => 'Unsupported Media Type',
    ], 415, $redirectUrl, $wantsJson);
}

function ensure_valid_csrf_or_respond(bool $wantsJson, string $redirectUrl, ?string $token = null): void
{
    if (!verify_csrf($token)) {
        api_respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, $redirectUrl, $wantsJson);
    }
}

function normalize_month_input(?string $month, ?string $fallback = null): string
{
    $normalizedFallback = is_string($fallback) && preg_match('/^\d{4}-\d{2}$/', $fallback) === 1
        ? $fallback
        : date('Y-m');

    if (!is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return $normalizedFallback;
    }

    return $month;
}

function parse_decimal_input(mixed $raw, bool $allowEmpty = false): array
{
    $normalized = trim((string)$raw);
    if ($normalized === '') {
        return [
            'valid' => $allowEmpty,
            'value' => null,
        ];
    }

    $normalized = str_replace(',', '', $normalized);
    if (!is_numeric($normalized)) {
        return [
            'valid' => false,
            'value' => null,
        ];
    }

    return [
        'valid' => true,
        'value' => (float)$normalized,
    ];
}

function infer_http_status_from_error(string $errorMessage, int $defaultStatus = 422): int
{
    $normalized = strtolower(trim($errorMessage));
    if ($normalized === '') {
        return $defaultStatus;
    }

    if (str_contains($normalized, 'unauthorized') || str_contains($normalized, 'session expired')) {
        return 401;
    }

    if (str_contains($normalized, 'ไม่มีสิทธิ์') || str_contains($normalized, 'forbidden')) {
        return 403;
    }

    if (str_contains($normalized, 'method not allowed')) {
        return 405;
    }

    if (str_contains($normalized, 'invalid csrf token')) {
        return 403;
    }

    return $defaultStatus;
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
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    $candidates = [];
    $proxyHeadersAllowed = false;

    if (
        $remoteAddr !== ''
        && defined('TRUST_PROXY')
        && TRUST_PROXY
        && defined('TRUSTED_PROXIES')
        && is_array(TRUSTED_PROXIES)
        && in_array($remoteAddr, TRUSTED_PROXIES, true)
    ) {
        $proxyHeadersAllowed = true;
    }

    if ($proxyHeadersAllowed) {
        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $firstHop = trim((string)explode(',', $forwardedFor)[0]);
            if ($firstHop !== '') {
                $candidates[] = $firstHop;
            }
        }

        $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== '') {
            $candidates[] = $realIp;
        }
    }

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
