<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(true);

// read-only endpoint — GET เท่านั้น (ไม่เปลี่ยน state จึงไม่ต้องมี CSRF ตาม pattern ของ *-data.php)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Method Not Allowed',
    ], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$selectedMonth = resolve_calendar_month($_GET['month'] ?? null);

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$result = $recordService->buildEditableMonthGrid($userId, $shopId, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลของเดือนนี้ได้');
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
