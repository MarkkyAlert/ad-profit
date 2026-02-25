<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Method Not Allowed',
    ], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$rangeType = isset($_GET['range']) ? trim((string)$_GET['range']) : 'month_this';
$allowedRangeTypes = ['week_this', 'week_last', 'month_this', 'month_last', 'month_pick', 'custom'];
if (!in_array($rangeType, $allowedRangeTypes, true)) {
    $rangeType = 'month_this';
}

$customStartDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : null;
$customEndDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : null;
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : null;

if ($customStartDate === '') {
    $customStartDate = null;
}

if ($customEndDate === '') {
    $customEndDate = null;
}

if ($selectedMonth === '') {
    $selectedMonth = null;
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$goalRepository = new GoalRepository($pdo);
$dashboardService = new DashboardService($recordRepository, $shopRepository, $goalRepository);

$result = $dashboardService->buildDashboard($userId, $shopId, $rangeType, $customStartDate, $customEndDate, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลแดชบอร์ดได้');
    $statusCode = str_contains($errorMessage, 'ไม่มีสิทธิ์') ? 403 : 422;

    jsonResponse([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode);
}

jsonResponse([
    'success' => true,
    'data' => $result['data'] ?? [],
]);
