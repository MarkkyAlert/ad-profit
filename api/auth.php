<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$wantsJson = str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';

$userRepository = new UserRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$authService = new AuthService($pdo, $userRepository, $shopRepository);

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

if ($action === 'register') {
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, '/login.php?tab=register');
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, '/login.php?tab=register');
    }

    $result = $authService->register(
        (string)($_POST['username'] ?? ''),
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
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, '/login.php?tab=login');
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, '/login.php?tab=login');
    }

    $result = $authService->login(
        (string)($_POST['username'] ?? ''),
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
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, '/dashboard.php');
    }

    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        $respond([
            'success' => false,
            'error' => 'Unauthorized',
        ], 401, '/login.php');
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, '/dashboard.php');
    }

    $authService->logout();

    $respond([
        'success' => true,
        'message' => 'ออกจากระบบเรียบร้อยแล้ว',
    ], 200, '/login.php');
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, '/login.php');
