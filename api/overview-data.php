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
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', $selectedMonth) !== 1) {
    $selectedMonth = date('Y-m');
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$overviewService = new OverviewService($recordRepository, $shopRepository);

$result = $overviewService->buildOverview($userId, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    jsonResponse([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลภาพรวมทุกร้านได้'),
    ], 422);
}

jsonResponse([
    'success' => true,
    'data' => $result['data'] ?? [],
]);
