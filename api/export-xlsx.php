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
// อ่านอย่างเดียว → ซ่อม session ที่ชี้ร้านที่ถูกลบไปแล้วเหมือนที่ทุกเพจทำ
// ⚠️ ทางที่ "เขียน" ข้อมูลห้ามซ่อม — ต้องล้มดัง ๆ ไม่ใช่เงียบ ๆ เปลี่ยนร้านปลายทางให้
$shopId = resolve_current_shop_id($pdo, $userId);

// รับปีเป็น ค.ศ. หรือ พ.ศ. ก็ได้ — helper เดียวกับ annual.php/overview.php
// เดิมที่นี่ไม่ตรวจรูปแบบและไม่ clamp ทำให้ ?year เดียวกันได้ผลต่างกัน: หน้า annual
// แสดงปีปัจจุบันเงียบ ๆ ส่วนปุ่ม export ตอบ error แล้ว redirect กลับไปปีที่อ่านไม่ออก
$selectedYear = resolve_calendar_year($_GET['year'] ?? null);

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

// เป้าหมาย — โผล่เฉพาะเมื่อมีเป้าอย่างน้อย 1 เดือน (ไม่งั้นได้ชีตเปล่า)
$goalProgress = array_values((array)($annualResult['data']['goal_progress'] ?? []));
if ($goalProgress !== []) {
    $reportService->buildGoalSheet($spreadsheet, $goalProgress, $selectedYear);
}

// ฤดูกาล 3 ปี — +1 query · ล้มเหลวก็แค่ไม่มีชีตนี้ ไม่ทำทั้งไฟล์พัง
// โผล่เฉพาะเมื่อมีข้อมูล >= 2 ปี (กฎเดียวกับชีตเป้าหมายที่ข้ามเมื่อไม่มีเป้า)
// ร้านเปิดปีนี้ปีเดียวจะได้กริดที่มีแถวเดียวมีเลข อีก 2 แถวว่าง = ไม่ใช่ "ฤดูกาล"
$heatmapResult = (new AnnualService($recordRepository, $shopRepository, new GoalRepository($pdo)))
    ->buildMonthlyHeatmap($userId, $shopId, $selectedYear, $today);

if (($heatmapResult['success'] ?? false) === true
    && ($heatmapResult['data']['comparable'] ?? false) === true
) {
    $reportService->buildSeasonSheet($spreadsheet, (array)($heatmapResult['data'] ?? []));
}

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
/* ⚠️⚠️ "ร้านนี้เคยกรอกอะไรไหม" ต้องถามด้วยวิธีเดียวกับหน้าจอ (`annual.php`)
   ไม่ใช่ให้ไฟล์เดาเอาจาก "ปีที่เลือกมีข้อมูลไหม" ซึ่งเป็นคนละคำถาม
   · ปีที่เลือกว่าง แต่ร้านมีข้อมูลปีอื่น → ทั้งจอและไฟล์ต้องตอบ ฿0
   · ร้านที่ยังไม่เคยเริ่ม → ทั้งจอและไฟล์ต้องเงียบ */
$lastRecordResult = $recordService->getDaysSinceLastRecord($userId, $shopId, $today);
$annualSummary = (array)($annualResult['data']['summary'] ?? []);
/* ⚠️ ใช้คีย์เดียวกับหน้าจอเป๊ะ ๆ (`has_records`) — และถ้าถามไม่สำเร็จ ให้ถือว่า "เคยกรอก"
   เพื่อให้ไฟล์แสดงตัวเลขตามปกติ แทนที่จะเงียบไปเฉย ๆ เพราะอ่านข้อมูลไม่ได้ */
$annualSummary['shop_has_ever_recorded'] = ($lastRecordResult['success'] ?? false) !== true
    || (bool)($lastRecordResult['data']['has_records'] ?? false);

$reportService->buildAnnualSheet(
    $spreadsheet,
    $annualSummary,
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
