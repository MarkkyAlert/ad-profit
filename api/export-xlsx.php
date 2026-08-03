<?php

declare(strict_types=1);

// bootstrap.php โหลด vendor/autoload ให้แล้ว → PhpOffice\* ใช้ได้เลย
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

// รับปีเป็น ค.ศ. หรือ พ.ศ. ก็ได้ (แปลงแบบเดียวกับ annual.php) — service เป็นคนตัดสินว่าปีถูกช่วงไหม
$selectedYear = isset($_GET['year']) ? (int)trim((string)$_GET['year']) : (int)date('Y');
if ($selectedYear >= 2400 && $selectedYear <= 2700) {
    $selectedYear -= 543;
}

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);
$exportService = new ExportService($recordService, $shopRepository);

$result = $exportService->buildYearlyDailyPayload($userId, $shopId, $selectedYear);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถ export ข้อมูลได้');

    if ($wantsJson) {
        jsonResponse([
            'success' => false,
            'error' => $errorMessage,
        ], infer_http_status_from_error($errorMessage));
    }

    set_flash('error', $errorMessage);
    redirect('/annual.php?year=' . ($selectedYear + 543));
}

$payload = (array)($result['data'] ?? []);
$shopName = (string)($payload['shop_name'] ?? 'shop');

$spreadsheet = (new XlsxReportService())->buildDailySheet($payload);

$filenameUtf8 = $exportService->buildYearlyXlsxFilename($shopName, $selectedYear);
$asciiBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($filenameUtf8, PATHINFO_FILENAME)) ?? 'export';
$asciiBase = trim($asciiBase, '_');
if ($asciiBase === '') {
    $asciiBase = 'export';
}

$asciiFilename = $asciiBase . '.xlsx';

if (ob_get_length() !== false && ob_get_length() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header(
    'Content-Disposition: attachment; filename="' . $asciiFilename . '"; '
    . "filename*=UTF-8''" . rawurlencode($filenameUtf8)
);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
exit;
