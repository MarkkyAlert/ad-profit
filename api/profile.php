<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$wantsJson = wants_json_response();

$userId = (int)($_SESSION['user_id'] ?? 0);

$userRepository = new UserRepository($pdo);
$profileService = new ProfileService($userRepository);

$redirectPath = resolve_safe_redirect_path(
    '/profile.php',
    isset($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : null,
    isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : null
);

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
    api_respond($payload, $statusCode, $redirectUrl, $wantsJson);
};

$profileClientIp = client_ip();

$getProfileRateLimitBucket = static function (string $action, int $userId, string $clientIp): array {
    if (!isset($_SESSION['profile_rate_limits']) || !is_array($_SESSION['profile_rate_limits'])) {
        $_SESSION['profile_rate_limits'] = [];
    }

    $key = hash('sha256', 'profile|' . $action . '|' . $userId . '|' . $clientIp);
    $now = time();

    $bucket = $_SESSION['profile_rate_limits'][$key] ?? [
        'attempts' => 0,
        'started_at' => $now,
    ];

    $startedAt = (int)($bucket['started_at'] ?? $now);
    $attempts = max(0, (int)($bucket['attempts'] ?? 0));

    if (($now - $startedAt) >= RATE_LIMIT_WINDOW_SECONDS) {
        $startedAt = $now;
        $attempts = 0;
    }

    $normalizedBucket = [
        'attempts' => $attempts,
        'started_at' => $startedAt,
        'key' => $key,
    ];

    $_SESSION['profile_rate_limits'][$key] = [
        'attempts' => $attempts,
        'started_at' => $startedAt,
    ];

    return $normalizedBucket;
};

$isProfileRateLimited = static function (string $action, int $userId, string $clientIp) use ($getProfileRateLimitBucket): bool {
    $bucket = $getProfileRateLimitBucket($action, $userId, $clientIp);
    return (int)$bucket['attempts'] >= RATE_LIMIT_MAX_ATTEMPTS;
};

$markProfileRateLimitFailedAttempt = static function (string $action, int $userId, string $clientIp) use ($getProfileRateLimitBucket): void {
    $bucket = $getProfileRateLimitBucket($action, $userId, $clientIp);
    $key = (string)($bucket['key'] ?? '');
    if ($key === '') {
        return;
    }

    $_SESSION['profile_rate_limits'][$key] = [
        'attempts' => (int)$bucket['attempts'] + 1,
        'started_at' => (int)$bucket['started_at'],
    ];
};

$clearProfileRateLimit = static function (string $action, int $userId, string $clientIp): void {
    if (!isset($_SESSION['profile_rate_limits']) || !is_array($_SESSION['profile_rate_limits'])) {
        return;
    }

    $key = hash('sha256', 'profile|' . $action . '|' . $userId . '|' . $clientIp);
    unset($_SESSION['profile_rate_limits'][$key]);
};

if ($action === 'update_profile') {
    ensure_post_request_or_respond($wantsJson, $redirectPath);
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $result = $profileService->updateProfile($userId, (string)($_POST['display_name'] ?? ''));

    if (($result['success'] ?? false) === true) {
        $data = is_array($result['data'] ?? null) ? (array)$result['data'] : [];
        $displayName = trim((string)($data['display_name'] ?? ''));
        $_SESSION['display_name'] = $displayName;

        $respond([
            'success' => true,
            'message' => 'บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว',
            'data' => $data,
        ], 200, $redirectPath);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถบันทึกข้อมูลส่วนตัวได้'),
    ], 422, $redirectPath);
}

if ($action === 'change_email') {
    ensure_post_request_or_respond($wantsJson, $redirectPath);
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $rateLimitAction = 'change_email';
    if ($isProfileRateLimited($rateLimitAction, $userId, $profileClientIp)) {
        $respond([
            'success' => false,
            'error' => 'ลองเปลี่ยนอีเมลบ่อยเกินไป กรุณารอแล้วลองใหม่อีกครั้ง',
        ], 429, $redirectPath);
    }

    $result = $profileService->changeEmail(
        $userId,
        (string)($_POST['email'] ?? ''),
        (string)($_POST['current_password'] ?? '')
    );

    if (($result['success'] ?? false) === true) {
        $data = is_array($result['data'] ?? null) ? (array)$result['data'] : [];
        $email = (string)($data['email'] ?? '');

        if ($email !== '') {
            $_SESSION['email'] = $email;
        }

        $clearProfileRateLimit($rateLimitAction, $userId, $profileClientIp);

        $respond([
            'success' => true,
            'message' => 'เปลี่ยนอีเมลเรียบร้อยแล้ว',
            'data' => $data,
        ], 200, $redirectPath);
    }

    $markProfileRateLimitFailedAttempt($rateLimitAction, $userId, $profileClientIp);

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถเปลี่ยนอีเมลได้'),
    ], 422, $redirectPath);
}

if ($action === 'change_password') {
    ensure_post_request_or_respond($wantsJson, $redirectPath);
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $rateLimitAction = 'change_password';
    if ($isProfileRateLimited($rateLimitAction, $userId, $profileClientIp)) {
        $respond([
            'success' => false,
            'error' => 'ลองเปลี่ยนรหัสผ่านบ่อยเกินไป กรุณารอแล้วลองใหม่อีกครั้ง',
        ], 429, $redirectPath);
    }

    $result = $profileService->changePassword(
        $userId,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['password'] ?? ''),
        (string)($_POST['password_confirm'] ?? '')
    );

    if (($result['success'] ?? false) === true) {
        $clearProfileRateLimit($rateLimitAction, $userId, $profileClientIp);

        try {
            $_SESSION['session_version'] = $userRepository->getSessionVersion($userId);
        } catch (Throwable $exception) {
            $_SESSION['session_version'] = max(1, (int)($_SESSION['session_version'] ?? 1));
        }

        $respond([
            'success' => true,
            'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว',
        ], 200, $redirectPath);
    }

    $markProfileRateLimitFailedAttempt($rateLimitAction, $userId, $profileClientIp);

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถเปลี่ยนรหัสผ่านได้'),
    ], 422, $redirectPath);
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, $redirectPath);
