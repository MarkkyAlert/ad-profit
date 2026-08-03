<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * ประกอบ workbook จาก payload ที่ ExportService เตรียมไว้
 *
 * แยกจาก controller เพื่อให้เทสต์ได้ (controller เหลือแค่ auth + stream)
 * ต่างจาก Service อื่นตรงที่คืน object ไม่ใช่ result-array — เป็น builder ไม่ใช่ business operation
 */
class XlsxReportService
{
    private const HEADER_FILL_ARGB = 'FF1F4E79';
    private const MONEY_FORMAT = '#,##0.00';
    private const RATIO_FORMAT = '0.00';
    private const DATE_FORMAT = 'yyyy-mm-dd';

    /**
     * @param array<string,mixed> $payload ผลจาก ExportService::buildYearlyDailyPayload()['data']
     */
    public function buildDailySheet(array $payload): Spreadsheet
    {
        $rows = array_values((array)($payload['rows'] ?? []));
        $totals = (array)($payload['totals'] ?? []);
        $noteColumn = Coordinate::stringFromColumnIndex((int)($payload['note_column_index'] ?? 6));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('รายวัน');

        $sheet->fromArray(['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'โน้ต'], null, 'A1');

        $headerRange = 'A1:' . $noteColumn . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL_ARGB);
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
                $sheet->getStyle('A' . $rowNumber)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            } else {
                $sheet->setCellValue('A' . $rowNumber, $recordDate);
            }

            $sheet->setCellValue('B' . $rowNumber, (float)($row['revenue'] ?? 0));
            $sheet->setCellValue('C' . $rowNumber, (float)($row['ad_cost'] ?? 0));
            $sheet->setCellValue('D' . $rowNumber, (float)($row['profit'] ?? 0));

            if (isset($row['roas']) && $row['roas'] !== null) {
                $sheet->setCellValue('E' . $rowNumber, (float)$row['roas']);
            }

            $note = (string)($row['note'] ?? '');
            if ($note !== '') {
                // TYPE_STRING = เซลล์เป็น shared string (t="s") → Excel ไม่ประมวลผลเป็นสูตร
                // ต่างจาก CSV ที่ไม่มี type จึงต้องเติม ' นำหน้า; ที่นี่ไม่ต้อง โน้ตจึงตรงตามที่ผู้ใช้พิมพ์
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

        $totalsRange = 'A' . $totalsRowNumber . ':' . $noteColumn . $totalsRowNumber;
        $sheet->getStyle($totalsRange)->getFont()->setBold(true);
        $sheet->getStyle($totalsRange)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle('B2:D' . $totalsRowNumber)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getStyle('E2:E' . $totalsRowNumber)->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);

        foreach (range('A', $noteColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
