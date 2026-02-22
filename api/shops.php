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

$shopRepository = new ShopRepository($pdo);
$shopService = new ShopService($shopRepository, $pdo);

$resolveRedirectPath = static function (string $fallback = '/dashboard.php'): string {
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

$redirectPath = $resolveRedirectPath('/dashboard.php');

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

if ($action === 'create') {
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
        'message' => $alreadyExists
            ? 'ร้านนี้มีอยู่แล้ว ระบบสลับไปใช้งานร้านนี้ให้แล้ว'
            : 'สร้างร้านค้าเรียบร้อยแล้ว',
        'data' => [
            'shop_id' => (int)($shop['id'] ?? 0),
            'shop_name' => (string)($shop['name'] ?? ''),
        ],
    ], 200, $redirectPath);
}

if ($action === 'rename') {
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

    $shopId = (int)($_POST['shop_id'] ?? ($_SESSION['current_shop_id'] ?? 0));
    $deleteResult = $shopService->deleteShop($userId, $shopId);

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
