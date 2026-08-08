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
// อ่านอย่างเดียว → ซ่อม session ที่ชี้ร้านที่ถูกลบไปแล้วเหมือนที่ทุกเพจทำ
// ⚠️ ทางที่ "เขียน" ข้อมูลห้ามซ่อม — ต้องล้มดัง ๆ ไม่ใช่เงียบ ๆ เปลี่ยนร้านปลายทางให้
$shopId = resolve_current_shop_id($pdo, $userId);
$selectedMonth = resolve_calendar_month($_GET['month'] ?? null);

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);
$exportService = new ExportService($recordService, $shopRepository);

// ⚠️⚠️ ไฟล์ที่ดาวน์โหลดต้องเป็นเดือนเดียวกับที่หน้าจอกำลังแสดงอยู่
//
// `history.php` ตั้งใจเปิด "เดือนอนาคตที่มีข้อมูลจริง" ได้ (แถวเก่าที่ลงล่วงหน้าไว้ก่อน
// กติกา actuals — ไม่งั้นแก้/ลบแถวพวกนั้นไม่ได้เลย) แต่ `resolve_calendar_month()`
// ด้านบนหดเดือนอนาคตกลับเป็นเดือนปัจจุบัน **เงียบ ๆ**
//
// วัดจริง: ดูหน้าประวัติเดือน ก.ย. (2 รายการ ฿110,000) กดปุ่ม Export CSV แล้วได้ไฟล์
// ของเดือน ส.ค. (3 แถว ฿3,000) ชื่อไฟล์ก็เป็น ส.ค. โดยไม่มีข้อความบอกอะไรเลย
//
// ใช้กติกาเดียวกับหน้าประวัติ: เดือนอนาคตที่ "มีข้อมูลจริง" เท่านั้นจึงยอมให้ผ่าน
$requestedMonth = trim((string)($_GET['month'] ?? ''));
$selectedMonth = resolve_month_allowing_legacy_future(
    $requestedMonth,
    $selectedMonth,
    static function (string $month) use ($recordService, $userId, $shopId): bool {
        $futureMonth = $recordService->getMonthlyRecords($userId, $shopId, $month);

        return ($futureMonth['success'] ?? false) === true && !empty($futureMonth['data']['records']);
    }
);

$result = $exportService->buildMonthlyCsvPayload($userId, $shopId, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถ export ข้อมูลได้');

    if ($wantsJson) {
        $statusCode = infer_http_status_from_error($errorMessage, 422);
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
$noteColumnIndex = isset($payload['note_column_index']) ? (int)$payload['note_column_index'] : null;
$blankRowBeforeTotals = (bool)($payload['blank_row_before_totals'] ?? false);

$filenameUtf8 = $exportService->buildMonthlyCsvFilename($shopName, $month);
$asciiBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($filenameUtf8, PATHINFO_FILENAME)) ?? 'export';
$asciiBase = trim($asciiBase, '_');
if ($asciiBase === '') {
    $asciiBase = 'export';
}

$asciiFilename = $asciiBase . '.csv';

$sanitizeCsvCell = static function (mixed $value): string {
    $stringValue = (string)$value;
    $trimmedLeft = ltrim($stringValue);

    if ($trimmedLeft !== '' && preg_match('/^[=+\-@\t\r]/', $trimmedLeft) === 1) {
        return "'" . $stringValue;
    }

    return $stringValue;
};

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

// ส่ง $escape เป็น '' ทุกจุด: PHP 8.4+ deprecate การไม่ระบุค่านี้ ซึ่งถ้า display_errors เปิด
// (โหมด development) ข้อความ deprecation จะถูกเขียนปนลงไฟล์ CSV จนไฟล์เสีย
// '' = ปิด escape แบบ backslash ตรงตามมาตรฐาน CSV (RFC-4180) เหมือนที่ parseImportCsv ใช้กับ fgetcsv

// หัวตารางเป็น text ที่ระบบกำหนดเอง ไม่ต้อง sanitize
if (!empty($headers)) {
    fputcsv($output, $headers, ',', '"', '');
}

// sanitize เฉพาะคอลัมน์โน้ต — ช่องเดียวที่ผู้ใช้พิมพ์เอง (จุดที่ inject สูตรได้จริง)
// เซลล์ที่ระบบสร้าง (วันที่/ตัวเลข/%) ปล่อยดิบ ไม่งั้น Excel จะเห็นเป็นข้อความแทนตัวเลข
foreach ($rows as $row) {
    $cells = array_values((array)$row);
    if ($noteColumnIndex !== null && array_key_exists($noteColumnIndex, $cells)) {
        $cells[$noteColumnIndex] = $sanitizeCsvCell($cells[$noteColumnIndex]);
    }

    fputcsv($output, $cells, ',', '"', '');
}

if (!empty($totalsRow)) {
    // เว้นบรรทัดก่อนแถวรวม เพื่อให้ Excel ตัดขอบตารางตรงนี้ (sort/filter ไม่กินแถวรวม)
    if ($blankRowBeforeTotals) {
        fputcsv($output, [''], ',', '"', '');
    }

    fputcsv($output, $totalsRow, ',', '"', '');
}

fclose($output);
exit;
