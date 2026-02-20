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

$selectedYearRaw = isset($_GET['year']) ? trim((string)$_GET['year']) : date('Y');
if (preg_match('/^\d{4}$/', $selectedYearRaw) !== 1) {
    $selectedYearRaw = date('Y');
}

$selectedYear = (int)$selectedYearRaw;
if ($selectedYear >= 2400 && $selectedYear <= 2700) {
    $selectedYear -= 543;
}

$currentYear = (int)date('Y');
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$annualService = new AnnualService($recordRepository, $shopRepository);

$result = $annualService->buildYearlySummary($userId, $shopId, $selectedYear);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลสรุปประจำปีได้');
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
