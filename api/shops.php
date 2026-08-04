<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

// Hardening: state-changing actions must come from POST only.
$action = (string)($_POST['action'] ?? '');
$wantsJson = wants_json_response();

$userId = (int)($_SESSION['user_id'] ?? 0);

$shopRepository = new ShopRepository($pdo);
$userRepository = new UserRepository($pdo);
$shopService = new ShopService($shopRepository, $userRepository, $pdo);

$redirectPath = resolve_safe_redirect_path(
    '/dashboard.php',
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

if ($action === 'create') {
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $shopName = (string)($_POST['name'] ?? '');
    $createResult = $shopService->createShop($userId, $shopName);

    if (($createResult['success'] ?? false) !== true) {
        $respond([
            'success' => false,
            'error' => (string)($createResult['error'] ?? 'ไม่สามารถสร้างร้านค้าได้'),
        ], 422, $redirectPath);
    }

    $newShopId = (int)($createResult['shop_id'] ?? 0);
    if ($newShopId <= 0) {
        $respond([
            'success' => false,
            'error' => 'ไม่สามารถสร้างร้านค้าได้',
        ], 422, $redirectPath);
    }

    $alreadyExists = (bool)($createResult['already_exists'] ?? false);
    $switchResult = $shopService->switchShop($userId, $newShopId);

    if (($switchResult['success'] ?? false) !== true || !is_array($switchResult['shop'] ?? null)) {
        $respond([
            'success' => false,
            'error' => 'สร้างร้านสำเร็จ แต่ไม่สามารถสลับร้านได้',
        ], 500, $redirectPath);
    }

    $shop = (array)$switchResult['shop'];
    $_SESSION['current_shop_id'] = (int)($shop['id'] ?? 0);
    $_SESSION['current_shop_name'] = (string)($shop['name'] ?? '');

    $respond([
        'success' => true,
        // ⚠️ ต้องบอก **ชื่อร้านที่ถูกสลับไป** เสมอ ไม่ใช่แค่ "ร้านนี้มีอยู่แล้ว"
        //
        // ระบบถือว่า "ชื่อเดียวกัน" แบบไม่สนตัวพิมพ์ใหญ่/เล็กและสระเสียง ("ร้าน A" =
        // "ร้าน a" = "Café" = "Cafe") ผู้ใช้ที่พิมพ์ชื่อคล้ายกันจึงถูกพาไปอีกร้านได้
        // โดยไม่รู้ตัว แล้วข้อมูลที่บันทึกต่อจากนั้นลงผิดร้านทั้งหมด
        'message' => $alreadyExists
            ? 'มีร้านชื่อ "' . (string)($shop['name'] ?? '') . '" อยู่แล้ว ระบบสลับไปใช้งานร้านนั้นให้แล้ว'
            : 'สร้างร้านค้าเรียบร้อยแล้ว',
        'data' => [
            'shop_id' => (int)($shop['id'] ?? 0),
            'shop_name' => (string)($shop['name'] ?? ''),
        ],
    ], 200, $redirectPath);
}

if ($action === 'rename') {
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $shopId = (int)($_POST['shop_id'] ?? 0);
    $shopName = (string)($_POST['name'] ?? '');
    $renameResult = $shopService->renameShop($userId, $shopId, $shopName);

    if (($renameResult['success'] ?? false) !== true || !is_array($renameResult['shop'] ?? null)) {
        $errorMessage = (string)($renameResult['error'] ?? 'ไม่สามารถอัปเดตชื่อร้านค้าได้');
        $statusCode = str_contains($errorMessage, 'ไม่มีสิทธิ์') ? 403 : 422;

        $respond([
            'success' => false,
            'error' => $errorMessage,
        ], $statusCode, $redirectPath);
    }

    $shop = (array)$renameResult['shop'];
    if ((int)($_SESSION['current_shop_id'] ?? 0) === (int)($shop['id'] ?? 0)) {
        $_SESSION['current_shop_name'] = (string)($shop['name'] ?? '');
    }

    $respond([
        'success' => true,
        'message' => 'อัปเดตชื่อร้านค้าเรียบร้อยแล้ว',
        'data' => [
            'shop_id' => (int)($shop['id'] ?? 0),
            'shop_name' => (string)($shop['name'] ?? ''),
        ],
    ], 200, $redirectPath);
}

if ($action === 'switch') {
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $shopId = (int)($_POST['shop_id'] ?? 0);
    $switchResult = $shopService->switchShop($userId, $shopId);

    if (($switchResult['success'] ?? false) !== true || !is_array($switchResult['shop'] ?? null)) {
        $errorMessage = (string)($switchResult['error'] ?? 'ไม่สามารถสลับร้านค้าได้');
        $statusCode = str_contains($errorMessage, 'ไม่มีสิทธิ์') ? 403 : 422;

        $respond([
            'success' => false,
            'error' => $errorMessage,
        ], $statusCode, $redirectPath);
    }

    $shop = (array)$switchResult['shop'];
    $_SESSION['current_shop_id'] = (int)($shop['id'] ?? 0);
    $_SESSION['current_shop_name'] = (string)($shop['name'] ?? '');

    $respond([
        'success' => true,
        'message' => 'สลับร้านเรียบร้อยแล้ว',
        'data' => [
            'shop_id' => (int)($shop['id'] ?? 0),
            'shop_name' => (string)($shop['name'] ?? ''),
        ],
    ], 200, $redirectPath);
}

if ($action === 'delete') {
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $shopId = (int)($_POST['shop_id'] ?? ($_SESSION['current_shop_id'] ?? 0));

    // การเทียบชื่อยืนยันย้ายไปอยู่ใน ShopService::deleteShop แล้ว
    // (เทียบกับชื่อจากแถวที่ล็อกไว้ในทรานแซกชันเดียวกัน ไม่ใช่แถวที่อ่านแยกมาก่อนหน้า)
    $deleteResult = $shopService->deleteShop(
        $userId,
        $shopId,
        (string)($_POST['confirm_shop_name'] ?? '')
    );

    if (($deleteResult['success'] ?? false) !== true) {
        $errorMessage = (string)($deleteResult['error'] ?? 'ไม่สามารถลบร้านค้าได้');
        $statusCode = str_contains($errorMessage, 'ไม่มีสิทธิ์') ? 403 : 422;

        $respond([
            'success' => false,
            'error' => $errorMessage,
        ], $statusCode, $redirectPath);
    }

    $deletedShop = is_array($deleteResult['deleted_shop'] ?? null) ? (array)$deleteResult['deleted_shop'] : [];
    $deletedShopId = (int)($deletedShop['id'] ?? 0);

    $currentSessionShopId = (int)($_SESSION['current_shop_id'] ?? 0);
    $activeShop = null;

    if ($currentSessionShopId > 0 && $currentSessionShopId !== $deletedShopId) {
        $activeShopResult = $shopService->switchShop($userId, $currentSessionShopId);
        if (($activeShopResult['success'] ?? false) === true && is_array($activeShopResult['shop'] ?? null)) {
            $activeShop = (array)$activeShopResult['shop'];
        }
    }

    if ($activeShop === null) {
        $nextShop = is_array($deleteResult['next_shop'] ?? null) ? (array)$deleteResult['next_shop'] : null;
        if ($nextShop === null) {
            $respond([
                'success' => false,
                'error' => 'ไม่พบร้านค้าสำหรับใช้งานต่อ',
            ], 500, '/login.php');
        }

        $activeShop = $nextShop;
    }

    $_SESSION['current_shop_id'] = (int)($activeShop['id'] ?? 0);
    $_SESSION['current_shop_name'] = (string)($activeShop['name'] ?? '');

    $respond([
        'success' => true,
        'message' => 'ลบร้านค้าเรียบร้อยแล้ว',
        'data' => [
            'shop_id' => (int)($activeShop['id'] ?? 0),
            'shop_name' => (string)($activeShop['name'] ?? ''),
        ],
    ], 200, $redirectPath);
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, $redirectPath);
