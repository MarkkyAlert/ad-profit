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
// อ่านอย่างเดียว → ซ่อม session ที่ชี้ร้านที่ถูกลบไปแล้วเหมือนที่ทุกเพจทำ
// ⚠️ ทางที่ "เขียน" ข้อมูลห้ามซ่อม — ต้องล้มดัง ๆ ไม่ใช่เงียบ ๆ เปลี่ยนร้านปลายทางให้
$shopId = resolve_current_shop_id($pdo, $userId);

$rangeType = isset($_GET['range']) ? trim((string)$_GET['range']) : 'month_this';
$allowedRangeTypes = ['week_this', 'week_last', 'month_this', 'month_last', 'month_pick', 'custom'];
if (!in_array($rangeType, $allowedRangeTypes, true)) {
    $rangeType = 'month_this';
}

$customStartDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : null;
$customEndDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : null;
// ต้องตรงกับ dashboard.php ทุกกรณี รวมถึง month= ที่ว่างเปล่า (= ไม่ได้เลือก ไม่ใช่เดือนนี้)
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : null;
if ($selectedMonth === '') {
    $selectedMonth = null;
}
if ($selectedMonth !== null) {
    $selectedMonth = resolve_calendar_month($selectedMonth);
}

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
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    jsonResponse([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode);
}

jsonResponse([
    'success' => true,
    'data' => $result['data'] ?? [],
]);
