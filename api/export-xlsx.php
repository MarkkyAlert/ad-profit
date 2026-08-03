<?php

declare(strict_types=1);

// bootstrap.php โหลด vendor/autoload ให้แล้ว → PhpOffice\* ใช้ได้เลย
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
$rows = array_values((array)($payload['rows'] ?? []));
$totals = (array)($payload['totals'] ?? []);
// service เป็นเจ้าของตำแหน่งคอลัมน์โน้ต — controller แปลงเป็นตัวอักษรคอลัมน์ตามนั้น
$noteColumn = Coordinate::stringFromColumnIndex((int)($payload['note_column_index'] ?? 6));

/**
 * กัน formula injection — เติม ' นำหน้าเซลล์ที่ Excel จะตีความเป็นสูตร
 * ทำเฉพาะคอลัมน์โน้ต (ช่องเดียวที่ผู้ใช้พิมพ์) เหมือน CSV export
 */
$sanitizeNote = static function (string $value): string {
    $trimmedLeft = ltrim($value);
    if ($trimmedLeft === '') {
        return $value;
    }

    return in_array($trimmedLeft[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
};

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('รายวัน');

$headers = ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'โน้ต'];
$sheet->fromArray($headers, null, 'A1');

$lastColumn = $noteColumn;
$headerRange = 'A1:' . $lastColumn . '1';
$sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
$sheet->getStyle($headerRange)->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setARGB('FF1F4E79');
$sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ตรึงแถวหัว — เลื่อนดูทั้งปีแล้วยังเห็นชื่อคอลัมน์
$sheet->freezePane('A2');

$rowNumber = 2;
foreach ($rows as $row) {
    $recordDate = (string)($row['record_date'] ?? '');
    // '!' รีเซ็ตเวลาเป็น 00:00:00 — ไม่งั้น createFromFormat เติมเวลาปัจจุบันให้
    // แล้ว Excel serial จะมีเศษทศนิยม (กรอง/จับคู่วันที่ตรง ๆ ใน Excel จะไม่ตรง)
    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $recordDate);

    if ($dateObject instanceof DateTimeImmutable && $dateObject->format('Y-m-d') === $recordDate) {
        // เขียนเป็น Excel date serial ไม่ใช่ข้อความ → เรียง/กรอง/pivot ได้จริง
        $sheet->setCellValue('A' . $rowNumber, ExcelDate::PHPToExcel($dateObject));
        $sheet->getStyle('A' . $rowNumber)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    } else {
        $sheet->setCellValue('A' . $rowNumber, $recordDate);
    }

    $sheet->setCellValue('B' . $rowNumber, (float)($row['revenue'] ?? 0));
    $sheet->setCellValue('C' . $rowNumber, (float)($row['ad_cost'] ?? 0));
    $sheet->setCellValue('D' . $rowNumber, (float)($row['profit'] ?? 0));

    if (isset($row['roas']) && $row['roas'] !== null) {
        $sheet->setCellValue('E' . $rowNumber, (float)$row['roas']);
    }

    $note = $sanitizeNote((string)($row['note'] ?? ''));
    if ($note !== '') {
        $sheet->setCellValueExplicit($noteColumn . $rowNumber, $note, DataType::TYPE_STRING);
    }

    $rowNumber++;
}

// เว้น 1 บรรทัดก่อนแถวรวม ให้ Excel ตัดขอบตารางตรงนั้น (เหมือน CSV export)
$totalsRowNumber = $rowNumber + 1;
$sheet->setCellValue('A' . $totalsRowNumber, 'รวมทั้งปี');
$sheet->setCellValue('B' . $totalsRowNumber, (float)($totals['revenue'] ?? 0));
$sheet->setCellValue('C' . $totalsRowNumber, (float)($totals['ad_cost'] ?? 0));
$sheet->setCellValue('D' . $totalsRowNumber, (float)($totals['profit'] ?? 0));
if (isset($totals['roas']) && $totals['roas'] !== null) {
    $sheet->setCellValue('E' . $totalsRowNumber, (float)$totals['roas']);
}

$totalsRange = 'A' . $totalsRowNumber . ':' . $lastColumn . $totalsRowNumber;
$sheet->getStyle($totalsRange)->getFont()->setBold(true);
$sheet->getStyle($totalsRange)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

// number format: เงิน 2 ตำแหน่ง มี thousands separator · ROAS 2 ตำแหน่ง
$lastDataRow = max(2, $totalsRowNumber);
$sheet->getStyle('B2:D' . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('E2:E' . $lastDataRow)->getNumberFormat()->setFormatCode('0.00');

foreach (range('A', $lastColumn) as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

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
