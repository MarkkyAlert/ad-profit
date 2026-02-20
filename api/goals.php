<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$wantsJson = str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$goalRepository = new GoalRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$goalService = new GoalService($goalRepository, $shopRepository);

$normalizeMonth = static function (?string $month): string {
    if (!is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return date('Y-m');
    }

    return $month;
};

$parseOptionalAmount = static function ($raw): array {
    $normalized = trim((string)$raw);
    if ($normalized === '') {
        return [
            'valid' => true,
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
};

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
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
};

$resolveRedirectPath = static function (string $fallback = '/dashboard.php'): string {
    $basePath = (string)(parse_url(APP_URL, PHP_URL_PATH) ?? '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');

    $candidate = isset($_POST['redirect_to']) ? trim((string)$_POST['redirect_to']) : '';
    if ($candidate === '' && isset($_SERVER['HTTP_REFERER'])) {
        $candidate = trim((string)$_SERVER['HTTP_REFERER']);
    }

    if ($candidate === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $candidate) === 1) {
        $parsed = parse_url($candidate);
        if (!is_array($parsed)) {
            return $fallback;
        }

        $path = (string)($parsed['path'] ?? '');
        if ($path === '') {
            return $fallback;
        }

        $query = (string)($parsed['query'] ?? '');
        $candidate = $path . ($query !== '' ? '?' . $query : '');
    }

    if (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
        return $fallback;
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
};

$redirectPath = $resolveRedirectPath('/dashboard.php');

if ($action === 'upsert') {
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, $redirectPath);
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, $redirectPath);
    }

    $goalMonth = $normalizeMonth(isset($_POST['goal_month']) ? (string)$_POST['goal_month'] : null);
    $targetRevenueParsed = $parseOptionalAmount($_POST['target_revenue'] ?? null);
    $targetProfitParsed = $parseOptionalAmount($_POST['target_profit'] ?? null);

    if (($targetRevenueParsed['valid'] ?? false) !== true || ($targetProfitParsed['valid'] ?? false) !== true) {
        $respond([
            'success' => false,
            'error' => 'รูปแบบเป้าหมายไม่ถูกต้อง',
        ], 422, $redirectPath);
    }

    $result = $goalService->upsertGoal(
        $userId,
        $shopId,
        $goalMonth,
        $targetRevenueParsed['value'] ?? null,
        $targetProfitParsed['value'] ?? null
    );

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => 'บันทึกเป้าหมายเรียบร้อยแล้ว',
        ], 200, $redirectPath);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถบันทึกเป้าหมายได้'),
    ], 422, $redirectPath);
}

if ($action === 'delete') {
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, $redirectPath);
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, $redirectPath);
    }

    $goalMonth = $normalizeMonth(isset($_POST['goal_month']) ? (string)$_POST['goal_month'] : null);
    $result = $goalService->deleteGoal($userId, $shopId, $goalMonth);

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => 'ลบเป้าหมายเรียบร้อยแล้ว',
        ], 200, $redirectPath);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถลบเป้าหมายได้'),
    ], 422, $redirectPath);
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, $redirectPath);
