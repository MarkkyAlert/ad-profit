<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

// Hardening: state-changing actions must come from POST only.
$action = (string)($_POST['action'] ?? '');
$wantsJson = wants_json_response();

$userId = (int)($_SESSION['user_id'] ?? 0);

$userRepository = new UserRepository($pdo);
// ฉีด PasswordResetRepository เข้ามาด้วย — เปลี่ยนรหัสผ่าน/อีเมลแล้วต้องล้างลิงก์รีเซ็ตที่ค้างอยู่
$profileService = new ProfileService(
    $userRepository,
    $pdo,
    new PasswordResetRepository($pdo),
    new EmailChangeRepository($pdo),
    new EmailService(),
    new RateLimitRepository($pdo)
);

$redirectPath = resolve_safe_redirect_path(
    '/profile.php',
    isset($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : null,
    isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : null
);

// ทุก action ในไฟล์นี้เปลี่ยนสถานะทั้งหมด → ตรวจ method/content-type ก่อนอ่าน $action
// (เดิมตรวจอยู่ในแต่ละ branch ซึ่งไม่มีวันทำงาน เพราะ $action อ่านจาก $_POST ที่ว่างเปล่า
//  เมื่อเป็น GET หรือ JSON → ตกไป "Invalid action" 404 แทนที่จะเป็น 405/415)
ensure_post_request_or_respond($wantsJson, $redirectPath);
ensure_form_content_type_or_respond($wantsJson, $redirectPath);
ensure_post_body_not_truncated_or_respond($wantsJson, $redirectPath);

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

        $clearProfileRateLimit($rateLimitAction, $userId, $profileClientIp);

        // คำขอเปลี่ยนอีเมลถูกบันทึกก่อนส่งจดหมาย เพื่อให้การส่งซ้ำทำได้อย่างปลอดภัย
        // แต่สำหรับผู้ใช้ที่ล็อกอินอยู่ "ส่งไม่ออก" ต้องไม่ถูกตอบเป็น success มิฉะนั้น
        // api_respond() จะสร้าง flash สีเขียวทั้งที่ข้อความบอกว่าส่งล้มเหลว. กรณีอีเมล
        // เดิมไม่เปลี่ยนค่าไม่มี key นี้ จึงยังเป็น success ตามเดิม.
        if (array_key_exists('email_sent', $data) && ($data['email_sent'] ?? false) !== true) {
            $respond([
                'success' => false,
                'error' => (string)($result['message'] ?? 'ส่งอีเมลยืนยันไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'),
                'data' => $data,
            ], 503, $redirectPath);
        }

        // ⚠️ **อีเมลยังไม่เปลี่ยน** จนกว่าเจ้าของจะกดลิงก์ในกล่องจดหมายใหม่
        // จึงไม่แตะ `$_SESSION['email']` · ไม่ bump session_version · ไม่เตะ session อื่น
        // ทั้งหมดนั้นเกิดที่ `verify-email.php` ตอนยืนยันสำเร็จแทน
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'ส่งลิงก์ยืนยันไปที่อีเมลใหม่แล้ว'),
            'data' => $data,
        ], 200, $redirectPath);
    }

    // นับเฉพาะตอนที่รหัสผ่านปัจจุบันผิดจริง — เดิมนับทุก failure รวมถึง "ยืนยันรหัสผ่าน
    // ไม่ตรง" หรือ "อีเมลผิดรูปแบบ" ทำให้พิมพ์ผิด 5 ครั้งโดนล็อก 60 วิ ทั้งที่ไม่ได้เดารหัส
    if (($result['credential_failure'] ?? false) === true) {
        $markProfileRateLimitFailedAttempt($rateLimitAction, $userId, $profileClientIp);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถเปลี่ยนอีเมลได้'),
    ], ($result['rate_limited'] ?? false) === true ? 429 : 422, $redirectPath);
}

if ($action === 'change_password') {
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
            // อ่านค่าใหม่ไม่ได้ แต่ changePassword สำเร็จแล้ว = DB ถูก +1 ไปแน่นอน
            // ถ้าคงค่าเดิมไว้ request ถัดไปจะไม่ตรงกับ DB แล้วเตะเจ้าของรหัสผ่านออกทันที
            $_SESSION['session_version'] = max(1, (int)($_SESSION['session_version'] ?? 1)) + 1;
        }

        // เปลี่ยน credential แล้วต้องเปลี่ยน session id ด้วย เหมือนตอน login/reset
        session_regenerate_id(true);

        $respond([
            'success' => true,
            'message' => 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว',
        ], 200, $redirectPath);
    }

    // นับเฉพาะตอนที่รหัสผ่านปัจจุบันผิดจริง — เดิมนับทุก failure รวมถึง "ยืนยันรหัสผ่าน
    // ไม่ตรง" หรือ "อีเมลผิดรูปแบบ" ทำให้พิมพ์ผิด 5 ครั้งโดนล็อก 60 วิ ทั้งที่ไม่ได้เดารหัส
    if (($result['credential_failure'] ?? false) === true) {
        $markProfileRateLimitFailedAttempt($rateLimitAction, $userId, $profileClientIp);
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
