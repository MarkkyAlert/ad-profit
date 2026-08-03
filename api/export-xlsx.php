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

// today ตัวเดียวกันทุก payload — รับประกันว่าทุกแท็บใช้ขอบเขตเดียวกัน
// (กันเคสข้ามเที่ยงคืน/ข้ามเดือนระหว่างคำสั่ง แล้วยอดรวมไม่ตรง)
$today = date('Y-m-d');

$dailyResult = $exportService->buildYearlyDailyPayload($userId, $shopId, $selectedYear, $today);

// AnnualService คืนแถวเดือนที่มี days_count/profit_per_day/yoy ครบอยู่แล้ว
// → ใช้เป็นแหล่งเดียวของทั้งชีตรายเดือนและชีตรายปี (ไม่ต้องยิง monthly payload ซ้ำ)
$annualResult = (new AnnualService($recordRepository, $shopRepository, new GoalRepository($pdo)))
    ->buildYearlySummary($userId, $shopId, $selectedYear, $today);

foreach ([$dailyResult, $annualResult] as $result) {
    if (($result['success'] ?? false) === true) {
        continue;
    }

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

$payload = (array)($dailyResult['data'] ?? []);
$shopName = (string)($payload['shop_name'] ?? 'shop');

$reportService = new XlsxReportService();
$spreadsheet = $reportService->buildDailySheet($payload);
$reportService->buildMonthlySheet($spreadsheet, (array)($annualResult['data'] ?? []));

// portfolio ทุกร้าน — โผล่เฉพาะเมื่อมี ≥ 2 ร้าน (กฎเดียวกับหน้ารวมร้าน)
// ใช้ $today ตัวเดียวกับสองแท็บแรก → cutoff ตรงกันทั้งไฟล์
$overviewResult = (new OverviewAnnualService($recordRepository, $shopRepository))
    ->buildYearlyOverview($userId, $selectedYear, $today);

if (($overviewResult['success'] ?? false) === true
    && ($overviewResult['data']['can_view'] ?? false) === true
) {
    $reportService->buildShopComparisonSheet($spreadsheet, (array)$overviewResult['data']);
}

// รายปีต้องมาท้ายสุด — setIndexByName ของมันดันตัวเองเป็น tab แรกทับของเฟส 2
$reportService->buildAnnualSheet(
    $spreadsheet,
    (array)($annualResult['data']['summary'] ?? []),
    $selectedYear,
    $shopName
);

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
// ต้องเปิดก่อน save ไม่งั้นกราฟหายเงียบ (ไฟล์เปิดได้ปกติแต่ไม่มีกราฟ)
$writer->setIncludeCharts(true);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
exit;
