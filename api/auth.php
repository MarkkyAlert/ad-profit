<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

// Hardening: state-changing actions must come from POST only.
$action = (string)($_POST['action'] ?? '');
$wantsJson = wants_json_response();

// ทุก action ในไฟล์นี้เปลี่ยนสถานะทั้งหมด → ตรวจ method/content-type ก่อนอ่าน $action
// (เดิมตรวจอยู่ในแต่ละ branch ซึ่งไม่มีวันทำงาน เพราะ $action อ่านจาก $_POST ที่ว่างเปล่า
//  เมื่อเป็น GET หรือ JSON → ตกไป "Invalid action" 404 แทนที่จะเป็น 405/415)
ensure_post_request_or_respond($wantsJson, '/login.php');
ensure_form_content_type_or_respond($wantsJson, '/login.php');

$userRepository = new UserRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$passwordResetRepository = new PasswordResetRepository($pdo);
$emailService = new EmailService();
$authService = new AuthService($pdo, $userRepository, $shopRepository, $passwordResetRepository, $emailService);

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
    api_respond($payload, $statusCode, $redirectUrl, $wantsJson);
};

if ($action === 'register') {
    ensure_valid_csrf_or_respond($wantsJson, '/login.php?tab=register', (string)($_POST['csrf_token'] ?? ''));

    $result = $authService->register(
        (string)($_POST['email'] ?? ''),
        (string)($_POST['password'] ?? ''),
        (string)($_POST['password_confirm'] ?? ''),
        client_ip()
    );

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => 'สมัครสมาชิกสำเร็จ',
            'data' => [
                'user_id' => (int)$result['user_id'],
                'shop_id' => (int)$result['shop_id'],
            ],
        ], 200, '/dashboard.php');
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถสมัครสมาชิกได้'),
    ], 422, '/login.php?tab=register');
}

if ($action === 'login') {
    ensure_valid_csrf_or_respond($wantsJson, '/login.php?tab=login', (string)($_POST['csrf_token'] ?? ''));

    $result = $authService->login(
        (string)($_POST['email'] ?? ''),
        (string)($_POST['password'] ?? ''),
        client_ip()
    );

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'data' => [
                'user_id' => (int)$result['user_id'],
                'shop_id' => (int)$result['shop_id'],
            ],
        ], 200, '/dashboard.php');
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถเข้าสู่ระบบได้'),
    ], 422, '/login.php?tab=login');
}

if ($action === 'logout') {

    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        $respond([
            'success' => false,
            'error' => 'Unauthorized',
        ], 401, '/login.php');
    }

    ensure_valid_csrf_or_respond($wantsJson, '/dashboard.php', (string)($_POST['csrf_token'] ?? ''));

    $authService->logout();

    $respond([
        'success' => true,
        'message' => 'ออกจากระบบเรียบร้อยแล้ว',
    ], 200, '/login.php');
}

if ($action === 'forgot_password') {
    ensure_valid_csrf_or_respond($wantsJson, '/forgot-password.php', (string)($_POST['csrf_token'] ?? ''));

    $result = $authService->requestPasswordReset(
        (string)($_POST['email'] ?? ''),
        client_ip()
    );

    if (($result['success'] ?? false) === true) {
        $responseData = [
            'success' => true,
            'message' => (string)($result['message'] ?? 'หากอีเมลนี้มีอยู่ในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน'),
        ];

        if (APP_ENV === 'development' && EXPOSE_DEV_RESET_LINK && isset($result['token'])) {
            $resetLink = app_url('/reset-password.php?token=' . $result['token']);
            $responseData['reset_link'] = $resetLink;
            set_flash('reset_link', $resetLink);
        }

        $respond($responseData, 200, '/forgot-password.php?sent=1');
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถขอรีเซ็ตรหัสผ่านได้'),
    ], 422, '/forgot-password.php');
}

if ($action === 'reset_password') {
    ensure_valid_csrf_or_respond($wantsJson, '/reset-password.php', (string)($_POST['csrf_token'] ?? ''));

    $resetToken = trim((string)($_POST['token'] ?? ($_SESSION['password_reset_token'] ?? '')));

    $result = $authService->resetPassword(
        $resetToken,
        (string)($_POST['password'] ?? ''),
        (string)($_POST['password_confirm'] ?? ''),
        client_ip()
    );

    if (($result['success'] ?? false) === true) {
        unset($_SESSION['password_reset_token']);

        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'รีเซ็ตรหัสผ่านสำเร็จ'),
        ], 200, '/login.php');
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถรีเซ็ตรหัสผ่านได้'),
    ], 422, '/reset-password.php');
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, '/login.php');
