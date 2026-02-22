<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Method Not Allowed',
    ], 405);
}

$wantsJson = wants_json_response();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);
$selectedMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : date('Y-m');

if (preg_match('/^\d{4}-\d{2}$/', $selectedMonth) !== 1) {
    $selectedMonth = date('Y-m');
}

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);
$exportService = new ExportService($recordService, $shopRepository);

$result = $exportService->buildMonthlyCsvPayload($userId, $shopId, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถ export ข้อมูลได้');

    if ($wantsJson) {
        $statusCode = str_contains($errorMessage, 'ไม่มีสิทธิ์') ? 403 : 422;
        jsonResponse([
            'success' => false,
            'error' => $errorMessage,
        ], $statusCode);
    }

    set_flash('error', $errorMessage);
    redirect('/history.php?month=' . $selectedMonth);
}

$payload = (array)($result['data'] ?? []);
$shopName = (string)($payload['shop_name'] ?? 'shop');
$month = (string)($payload['month'] ?? $selectedMonth);
$headers = array_values((array)($payload['headers'] ?? []));
$rows = array_values((array)($payload['rows'] ?? []));
$totalsRow = array_values((array)($payload['totals_row'] ?? []));

$filenameUtf8 = $exportService->buildMonthlyCsvFilename($shopName, $month);
$asciiBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($filenameUtf8, PATHINFO_FILENAME)) ?? 'export';
$asciiBase = trim($asciiBase, '_');
if ($asciiBase === '') {
    $asciiBase = 'export';
}

$asciiFilename = $asciiBase . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filenameUtf8));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'wb');
if ($output === false) {
    if ($wantsJson) {
        jsonResponse([
            'success' => false,
            'error' => 'ไม่สามารถสร้างไฟล์ CSV ได้',
        ], 500);
    }

    set_flash('error', 'ไม่สามารถสร้างไฟล์ CSV ได้');
    redirect('/history.php?month=' . $selectedMonth);
}

echo "\xEF\xBB\xBF";

if (!empty($headers)) {
    fputcsv($output, array_map(static fn($value): string => (string)$value, $headers));
}

foreach ($rows as $row) {
    fputcsv($output, array_map(static fn($value): string => (string)$value, (array)$row));
}

if (!empty($totalsRow)) {
    fputcsv($output, array_map(static fn($value): string => (string)$value, $totalsRow));
}

fclose($output);
exit;
