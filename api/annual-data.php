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

$selectedYear = resolve_calendar_year($_GET['year'] ?? null);
$currentYear = (int)date('Y');

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$goalRepository = new GoalRepository($pdo);
$annualService = new AnnualService($recordRepository, $shopRepository, $goalRepository);

$result = $annualService->buildYearlySummary($userId, $shopId, $selectedYear);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลสรุปประจำปีได้');
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
