<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$wantsJson = wants_json_response();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$goalRepository = new GoalRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$goalService = new GoalService($goalRepository, $shopRepository);

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
    api_respond($payload, $statusCode, $redirectUrl, $wantsJson);
};

$redirectPath = resolve_safe_redirect_path(
    '/dashboard.php',
    isset($_POST['redirect_to']) ? (string)$_POST['redirect_to'] : null,
    isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : null
);

if ($action === 'upsert') {
    ensure_post_request_or_respond($wantsJson, $redirectPath);
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $goalMonth = normalize_month_input(isset($_POST['goal_month']) ? (string)$_POST['goal_month'] : null);
    $targetRevenueParsed = parse_decimal_input($_POST['target_revenue'] ?? null, true);
    $targetProfitParsed = parse_decimal_input($_POST['target_profit'] ?? null, true);

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
    ensure_post_request_or_respond($wantsJson, $redirectPath);
    ensure_valid_csrf_or_respond($wantsJson, $redirectPath, (string)($_POST['csrf_token'] ?? ''));

    $goalMonth = normalize_month_input(isset($_POST['goal_month']) ? (string)$_POST['goal_month'] : null);
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
