<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(true);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$wantsJson = str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';

$userId = (int)($_SESSION['user_id'] ?? 0);

$userRepository = new UserRepository($pdo);
$profileService = new ProfileService($userRepository);

$resolveRedirectPath = static function (string $fallback = '/profile.php'): string {
    $basePath = (string)(parse_url(APP_URL, PHP_URL_PATH) ?? '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');

    $candidates = [];
    if (isset($_POST['redirect_to'])) {
        $candidates[] = (string)$_POST['redirect_to'];
    }
    if (isset($_SERVER['HTTP_REFERER'])) {
        $candidates[] = (string)$_SERVER['HTTP_REFERER'];
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
};

$redirectPath = $resolveRedirectPath('/profile.php');

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

if ($action === 'update_profile') {
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

        $respond([
            'success' => true,
            'message' => 'เปลี่ยนอีเมลเรียบร้อยแล้ว',
            'data' => $data,
        ], 200, $redirectPath);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถเปลี่ยนอีเมลได้'),
    ], 422, $redirectPath);
}

if ($action === 'change_password') {
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

    $result = $profileService->changePassword(
        $userId,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['password'] ?? ''),
        (string)($_POST['password_confirm'] ?? '')
    );

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว',
        ], 200, $redirectPath);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถเปลี่ยนรหัสผ่านได้'),
    ], 422, $redirectPath);
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, $redirectPath);
