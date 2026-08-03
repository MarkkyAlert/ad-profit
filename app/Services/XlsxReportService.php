<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ประกอบ workbook จาก payload ที่ ExportService เตรียมไว้
 *
 * แยกจาก controller เพื่อให้เทสต์ได้ (controller เหลือแค่ auth + stream)
 * ต่างจาก Service อื่นตรงที่คืน object ไม่ใช่ result-array — เป็น builder ไม่ใช่ business operation
 */
class XlsxReportService
{
    /** ชื่อเดือนไทยสำหรับแสดงผล — presentation layer จึงเก็บไว้ในนี้ */
    private const THAI_MONTHS = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
        7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    private const HEADER_FILL_ARGB = 'FF1F4E79';
    private const NEGATIVE_FONT_ARGB = 'FFC00000';
    private const POSITIVE_FONT_ARGB = 'FF107C41';
    private const PERCENT_FORMAT = '0.0"%"';
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
            $dayProfit = (float)($row['profit'] ?? 0);
            $sheet->setCellValue('D' . $rowNumber, $dayProfit);
            $this->paintNegative($sheet, 'D' . $rowNumber, $dayProfit);

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
        $this->paintNegative($sheet, 'D' . $totalsRowNumber, (float)($totals['profit'] ?? 0));

        // filter เฉพาะแถวข้อมูล — ไม่คลุมแถวรวม ไม่งั้นกรองแล้วยอดรวมหาย
        $lastDataRow = $rowNumber - 1;
        if ($lastDataRow >= 2) {
            $sheet->setAutoFilter('A1:' . $noteColumn . $lastDataRow);
        }

        $sheet->getStyle('B2:D' . $totalsRowNumber)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getStyle('E2:E' . $totalsRowNumber)->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);

        foreach (range('A', $noteColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * เพิ่ม sheet "รายเดือน" (ตาราง + กราฟแท่งกำไร) เข้า workbook ที่มีอยู่
     * แล้วดันให้เป็น tab แรก — เปิดไฟล์มาเจอสรุปก่อน รายวันเป็นรายละเอียด
     *
     * ⚠️ ผู้เรียกต้อง $writer->setIncludeCharts(true) ก่อน save() ไม่งั้นกราฟหายเงียบ
     *
     * รับแถวเดือนจาก AnnualService (มี days_count / profit_per_day / yoy_change_percent ครบ)
     * รองรับทั้ง month_label และ month (แปลงชื่อไทยเอง)
     *
     * @param array<string,mixed> $payload ต้องมีคีย์ 'months'
     */
    public function buildMonthlySheet(Spreadsheet $spreadsheet, array $payload): void
    {
        $months = array_values((array)($payload['months'] ?? []));

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('รายเดือน');

        $sheet->fromArray(
            ['เดือน', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'วันที่กรอก', 'กำไร/วัน', 'เทียบปีก่อน'],
            null,
            'A1'
        );

        $headerRange = 'A1:H1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL_ARGB);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A2');

        $rowNumber = 2;
        foreach ($months as $month) {
            $profit = (float)($month['profit'] ?? 0);
            $label = (string)($month['month_label'] ?? (self::THAI_MONTHS[(int)($month['month'] ?? 0)] ?? ''));

            $sheet->setCellValueExplicit('A' . $rowNumber, $label, DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $rowNumber, (float)($month['revenue'] ?? $month['total_revenue'] ?? 0));
            $sheet->setCellValue('C' . $rowNumber, (float)($month['ad_cost'] ?? $month['total_ad_cost'] ?? 0));
            $sheet->setCellValue('D' . $rowNumber, $profit);

            if (isset($month['roas']) && $month['roas'] !== null) {
                $sheet->setCellValue('E' . $rowNumber, (float)$month['roas']);
            }

            $sheet->setCellValue('F' . $rowNumber, (int)($month['days_count'] ?? 0));

            if (isset($month['profit_per_day']) && $month['profit_per_day'] !== null) {
                $sheet->setCellValue('G' . $rowNumber, (float)$month['profit_per_day']);
            }

            if (isset($month['yoy_change_percent']) && $month['yoy_change_percent'] !== null) {
                $sheet->setCellValue('H' . $rowNumber, (float)$month['yoy_change_percent']);
            }

            $this->paintNegative($sheet, 'D' . $rowNumber, $profit);
            $this->paintNegative($sheet, 'G' . $rowNumber, (float)($month['profit_per_day'] ?? 0));
            $this->paintNegative($sheet, 'H' . $rowNumber, (float)($month['yoy_change_percent'] ?? 0));

            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;

        if ($lastRow >= 2) {
            $sheet->getStyle('B2:D' . $lastRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('E2:E' . $lastRow)->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);
            $sheet->getStyle('G2:G' . $lastRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('H2:H' . $lastRow)->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->setAutoFilter('A1:H' . $lastRow);
        }

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        if ($lastRow >= 2) {
            $sheet->addChart($this->buildProfitChart($sheet->getTitle(), $lastRow));
        }

        // สรุปมาก่อนรายละเอียด
        $spreadsheet->setIndexByName('รายเดือน', 0);
        $spreadsheet->setActiveSheetIndex(0);
    }

    /**
     * เพิ่ม sheet "รายปี" (สรุป + YoY ของร้านเดี่ยว) แล้วดันเป็น tab แรก
     *
     * reuse summary ที่ผู้เรียกส่งมา — ไม่ fetch เอง · ไม่มีกราฟ
     * ต้องเรียกท้ายสุด เพื่อให้ setIndexByName ดันขึ้นหน้าได้จริง
     *
     * @param array<string,mixed> $summary ผลจาก AnnualService::buildYearlySummary()['data']['summary']
     */
    public function buildAnnualSheet(Spreadsheet $spreadsheet, array $summary, int $year, string $shopName): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('รายปี');

        $sheet->setCellValueExplicit(
            'A1',
            sprintf('สรุปรายปี %s ปี %d', $shopName, $year + 543),
            DataType::TYPE_STRING
        );
        $sheet->mergeCells('A1:C1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $profit = (float)($summary['profit'] ?? 0);

        $rowNumber = 3;
        $sheet->setCellValueExplicit('A' . $rowNumber, 'ยอดรวมทั้งปี', DataType::TYPE_STRING);
        $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);
        $rowNumber++;

        $moneyRows = [
            ['รายได้', (float)($summary['total_revenue'] ?? 0)],
            ['ค่าแอด', (float)($summary['total_ad_cost'] ?? 0)],
            ['กำไร', $profit],
        ];
        foreach ($moneyRows as [$label, $value]) {
            $sheet->setCellValueExplicit('A' . $rowNumber, $label, DataType::TYPE_STRING);
            $sheet->setCellValue('B' . $rowNumber, $value);
            $sheet->getStyle('B' . $rowNumber)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            if ($value < 0) {
                $sheet->getStyle('B' . $rowNumber)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
            }
            $rowNumber++;
        }

        $sheet->setCellValueExplicit('A' . $rowNumber, 'ROAS', DataType::TYPE_STRING);
        if (isset($summary['roas']) && $summary['roas'] !== null) {
            $sheet->setCellValue('B' . $rowNumber, (float)$summary['roas']);
            $sheet->getStyle('B' . $rowNumber)->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);
        } else {
            $sheet->setCellValueExplicit('B' . $rowNumber, '–', DataType::TYPE_STRING);
        }
        $rowNumber++;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'อัตรากำไร', DataType::TYPE_STRING);
        if (isset($summary['profit_margin']) && $summary['profit_margin'] !== null) {
            $sheet->setCellValue('B' . $rowNumber, (float)$summary['profit_margin']);
            $sheet->getStyle('B' . $rowNumber)->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
        } else {
            $sheet->setCellValueExplicit('B' . $rowNumber, '–', DataType::TYPE_STRING);
        }
        $rowNumber += 2;

        // YoY เทียบช่วงเดียวกันของปีก่อน
        $percent = $summary['yoy_profit_change_percent'] ?? null;
        $sheet->setCellValueExplicit(
            'A' . $rowNumber,
            sprintf('เทียบ %d (ช่วงเดียวกัน)', (int)($summary['prev_year'] ?? ($year - 1)) + 543),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);

        if ($percent === null) {
            $sheet->setCellValueExplicit('B' . $rowNumber, 'ไม่มีข้อมูลปีก่อน', DataType::TYPE_STRING);
        } else {
            $percentValue = (float)$percent;
            $change = (float)($summary['yoy_profit_change'] ?? 0);
            $sheet->setCellValueExplicit(
                'B' . $rowNumber,
                sprintf(
                    'กำไร %s%s%% (%s%s) · ปีก่อน %s',
                    $percentValue > 0 ? '↑' : ($percentValue < 0 ? '↓' : ''),
                    number_format(abs($percentValue), 1),
                    $change >= 0 ? '+' : '-',
                    number_format(abs($change), 2),
                    number_format((float)($summary['prev_year_profit'] ?? 0), 2)
                ),
                DataType::TYPE_STRING
            );

            if (abs($percentValue) >= 0.05) {
                $sheet->getStyle('B' . $rowNumber)->getFont()->getColor()->setARGB(
                    $percentValue > 0 ? self::POSITIVE_FONT_ARGB : self::NEGATIVE_FONT_ARGB
                );
            }
        }
        $rowNumber += 2;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนกำไรดีสุด', DataType::TYPE_STRING);
        $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $this->describeMonth($summary['best_month'] ?? null),
            DataType::TYPE_STRING
        );
        $rowNumber++;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนกำไรแย่สุด', DataType::TYPE_STRING);
        $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $this->describeMonth($summary['worst_month'] ?? null),
            DataType::TYPE_STRING
        );
        $rowNumber++;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนที่มีข้อมูล', DataType::TYPE_STRING);
        $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            sprintf(
                '%d เดือน · กำไร %d / ขาดทุน %d',
                (int)($summary['months_with_data'] ?? 0),
                (int)($summary['profit_months'] ?? 0),
                (int)($summary['loss_months'] ?? 0)
            ),
            DataType::TYPE_STRING
        );
        $rowNumber += 2;

        $this->writeProjection($sheet, (array)($summary['projection'] ?? []), $rowNumber);

        foreach (range('A', 'C') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        // สรุปทั้งปีมาก่อนทุกอย่าง
        $spreadsheet->setIndexByName('รายปี', 0);
        $spreadsheet->setActiveSheetIndex(0);
    }

    /**
     * เพิ่ม sheet "เทียบร้าน" (portfolio ทุกร้าน) — ตารางล้วน ไม่มีกราฟ
     * ผู้เรียกต้องเช็ก can_view เอง (หน้ารวมร้านดูได้เมื่อมี ≥ 2 ร้าน)
     *
     * @param array<string,mixed> $payload ผลจาก OverviewAnnualService::buildYearlyOverview()['data']
     */
    public function buildShopComparisonSheet(Spreadsheet $spreadsheet, array $payload): void
    {
        $shops = array_values((array)($payload['shops'] ?? []));
        $summary = (array)($payload['summary'] ?? []);
        $year = (int)($payload['year'] ?? 0);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('เทียบร้าน');

        $sheet->setCellValueExplicit(
            'A1',
            sprintf('เทียบทุกร้าน ปี %d', $year + 543),
            DataType::TYPE_STRING
        );
        $sheet->mergeCells('A1:H1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headerRow = 3;
        $sheet->fromArray(
            ['ร้าน', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'อัตรากำไร', 'สัดส่วนกำไร', 'วันที่กรอก'],
            null,
            'A' . $headerRow
        );

        $headerRange = 'A' . $headerRow . ':H' . $headerRow;
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL_ARGB);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A' . ($headerRow + 1));

        $rowNumber = $headerRow + 1;
        foreach ($shops as $shop) {
            $profit = (float)($shop['profit'] ?? 0);
            $share = isset($shop['profit_share']) && $shop['profit_share'] !== null
                ? (float)$shop['profit_share']
                : null;

            $sheet->setCellValueExplicit(
                'A' . $rowNumber,
                (string)($shop['shop_name'] ?? 'ร้านค้า'),
                DataType::TYPE_STRING
            );
            $sheet->setCellValue('B' . $rowNumber, (float)($shop['total_revenue'] ?? 0));
            $sheet->setCellValue('C' . $rowNumber, (float)($shop['total_ad_cost'] ?? 0));
            $sheet->setCellValue('D' . $rowNumber, $profit);

            if (isset($shop['roas']) && $shop['roas'] !== null) {
                $sheet->setCellValue('E' . $rowNumber, (float)$shop['roas']);
            }

            if (isset($shop['profit_margin']) && $shop['profit_margin'] !== null) {
                $sheet->setCellValue('F' . $rowNumber, (float)$shop['profit_margin']);
            }

            if ($share !== null) {
                $sheet->setCellValue('G' . $rowNumber, $share);
            }

            $sheet->setCellValue('H' . $rowNumber, (int)($shop['days_count'] ?? 0));

            // ร้านตัวถ่วง — ให้เห็นทันทีว่าใครดึงพอร์ตลง
            if ($profit < 0) {
                $sheet->getStyle('D' . $rowNumber)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
            }
            if ($share !== null && $share < 0) {
                $sheet->getStyle('G' . $rowNumber)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
            }

            $rowNumber++;
        }

        $lastShopRow = $rowNumber - 1;
        if ($lastShopRow >= $headerRow + 1) {
            $sheet->getStyle('B' . ($headerRow + 1) . ':D' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('E' . ($headerRow + 1) . ':E' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);
            $sheet->getStyle('F' . ($headerRow + 1) . ':G' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->setAutoFilter('A' . $headerRow . ':H' . $lastShopRow);
        }

        $this->writeComparisonSummary($sheet, $summary, $lastShopRow + 2);

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * แถบสรุปใต้ตาราง — เดือนดี/แย่สุด + YoY รวมร้าน (same-period)
     *
     * @param array<string,mixed> $summary
     */
    private function writeComparisonSummary(Worksheet $sheet, array $summary, int $startRow): void
    {
        $rowNumber = $startRow;

        $sheet->getStyle('A' . $rowNumber . ':A' . ($rowNumber + 3))->getFont()->setBold(true);

        $sheet->setCellValueExplicit('A' . $rowNumber, 'กำไรรวมทุกร้าน', DataType::TYPE_STRING);
        $sheet->setCellValue('B' . $rowNumber, (float)($summary['profit'] ?? 0));
        $sheet->getStyle('B' . $rowNumber)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        if ((float)($summary['profit'] ?? 0) < 0) {
            $sheet->getStyle('B' . $rowNumber)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
        }
        $rowNumber++;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนกำไรดีสุด', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $this->describeMonth($summary['best_month'] ?? null),
            DataType::TYPE_STRING
        );
        $rowNumber++;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนกำไรแย่สุด', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $this->describeMonth($summary['worst_month'] ?? null),
            DataType::TYPE_STRING
        );
        $rowNumber++;

        $previousYear = (int)($summary['prev_year'] ?? 0);
        $percent = $summary['yoy_profit_change_percent'] ?? null;

        $sheet->setCellValueExplicit(
            'A' . $rowNumber,
            sprintf('เทียบ %d (ช่วงเดียวกัน)', $previousYear + 543),
            DataType::TYPE_STRING
        );

        if ($percent === null) {
            $sheet->setCellValueExplicit('B' . $rowNumber, 'ไม่มีข้อมูลปีก่อน', DataType::TYPE_STRING);
            return;
        }

        $percentValue = (float)$percent;
        $change = (float)($summary['yoy_profit_change'] ?? 0);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            sprintf(
                '%s%s%% (%s%s) · ปีก่อน %s',
                $percentValue > 0 ? '↑' : ($percentValue < 0 ? '↓' : ''),
                number_format(abs($percentValue), 1),
                $change >= 0 ? '+' : '-',
                number_format(abs($change), 2),
                number_format((float)($summary['prev_year_profit'] ?? 0), 2)
            ),
            DataType::TYPE_STRING
        );

        if ($percentValue < 0) {
            $sheet->getStyle('B' . $rowNumber)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
        }
    }

    /**
     * แถบประมาณการสิ้นปี — ต้องอ่านออกทันทีว่า "ไม่ใช่ตัวเลขจริง"
     * (เหมือนหน้าเว็บ: โชว์ช่วง ไม่ใช่เลขเดียว + บอกสมมติฐาน)
     *
     * @param array<string,mixed> $projection
     */
    private function writeProjection(Worksheet $sheet, array $projection, int $startRow): void
    {
        $sheet->setCellValueExplicit(
            'A' . $startRow,
            '🔮 ประมาณการสิ้นปี (ไม่ใช่ตัวเลขจริง)',
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A' . $startRow)->getFont()->setBold(true)->setItalic(true);

        if (($projection['available'] ?? false) !== true) {
            $sheet->setCellValueExplicit(
                'B' . $startRow,
                'ข้อมูลยังไม่พอประมาณการ',
                DataType::TYPE_STRING
            );
            $sheet->getStyle('B' . $startRow)->getFont()->setItalic(true);

            return;
        }

        $sheet->setCellValueExplicit(
            'B' . $startRow,
            sprintf(
                '%s – %s (กลาง %s)',
                number_format((float)($projection['projection_low'] ?? 0), 2),
                number_format((float)($projection['projection_high'] ?? 0), 2),
                number_format((float)($projection['projection_mid'] ?? 0), 2)
            ),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('B' . $startRow)->getFont()->setItalic(true);

        $sheet->setCellValueExplicit(
            'B' . ($startRow + 1),
            sprintf(
                'สมมติเดือนที่เหลือ (%d) ทำได้เท่า %d เดือนล่าสุด · ไม่คิดฤดูกาล — ใช้ประกอบ ไม่ใช่ตัวเลขที่เกิดขึ้นจริง',
                (int)($projection['months_remaining'] ?? 0),
                (int)($projection['basis_month_count'] ?? 0)
            ),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('B' . ($startRow + 1))->getFont()->setItalic(true)->setSize(9);
    }

    /** ทาแดงเฉพาะค่าติดลบ — ใช้ร่วมทุกชีตให้สัญญาณสีสม่ำเสมอ */
    private function paintNegative(Worksheet $sheet, string $coordinate, float $value): void
    {
        if ($value < 0) {
            $sheet->getStyle($coordinate)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
        }
    }

    /**
     * @param mixed $month แถวเดือนจาก summary (best_month/worst_month) — null ได้เมื่อยังไม่มีข้อมูล
     */
    private function describeMonth($month): string
    {
        if (!is_array($month)) {
            return '–';
        }

        $label = self::THAI_MONTHS[(int)($month['month'] ?? 0)] ?? '–';

        return sprintf('%s (%s)', $label, number_format((float)($month['profit'] ?? 0), 2));
    }

    /**
     * กราฟแท่งกำไรรายเดือน — อ้าง range เซลล์ในชีต "รายเดือน" โดยตรง
     * (แก้ตัวเลขในตาราง กราฟขยับตาม เพราะ Excel ผูกกับ range ไม่ใช่ค่าที่ copy มา)
     */
    private function buildProfitChart(string $sheetTitle, int $lastRow): Chart
    {
        $quotedTitle = '\'' . $sheetTitle . '\'';

        $categories = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                $quotedTitle . '!$A$2:$A$' . $lastRow,
                null,
                $lastRow - 1
            ),
        ];

        $values = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $quotedTitle . '!$D$2:$D$' . $lastRow,
                null,
                $lastRow - 1
            ),
        ];

        $legendLabels = [
            new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $quotedTitle . '!$D$1', null, 1),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            $legendLabels,
            $categories,
            $values
        );
        // แท่งตั้ง — กำไรติดลบจะยื่นลงล่างเองตามค่าจริง
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $chart = new Chart('profit-by-month', new Title('กำไรรายเดือน'), null, new PlotArea(null, [$series]));
        // วางขวาตาราง — ต้องพ้นคอลัมน์ H (เทียบปีก่อน) ไม่งั้นทับข้อมูล
        $chart->setTopLeftPosition('J2');
        $chart->setBottomRightPosition('R20');

        return $chart;
    }
}
