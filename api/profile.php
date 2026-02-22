<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(true);

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
    ensure_post_request_or_respond($wantsJson, $redirectPath);
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

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
