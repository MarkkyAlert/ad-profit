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
use PhpOffice\PhpSpreadsheet\Style\Conditional;
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
    private const PENDING_FONT_ARGB = 'FFB45309';
    private const GRID_LINE_ARGB = 'FFD9D9D9';
    private const BAND_FILL_ARGB = 'FFF4F7FB';
    private const HEADER_ROW_HEIGHT = 22;

    /** ฟอนต์ทั้ง workbook — Tahoma เรนเดอร์ไทยคมกว่า Calibri (ที่ต้อง fallback) */
    private const BASE_FONT = 'Tahoma';
    private const BASE_FONT_SIZE = 10;

    /** สีแท็บแยกกลุ่ม: ร้านเดี่ยว = น้ำเงินไล่เฉด · เป้าหมาย/ฤดูกาล/พอร์ต = คนละโทน */
    private const TAB_COLORS = [
        'รายปี' => 'FF1F4E79',
        'รายเดือน' => 'FF2E75B6',
        'รายวัน' => 'FF8EA9DB',
        'เป้าหมาย' => 'FF548235',
        'ฤดูกาล' => 'FFBF8F00',
        'เทียบร้าน' => 'FF7030A0',
    ];

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
        $spreadsheet->getDefaultStyle()->getFont()
            ->setName(self::BASE_FONT)
            ->setSize(self::BASE_FONT_SIZE);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('รายวัน');
        $this->applyReportLook($sheet, self::TAB_COLORS['รายวัน']);

        $sheet->fromArray(['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'โน้ต'], null, 'A1');

        $headerRange = 'A1:' . $noteColumn . '1';
        $this->styleHeaderRow($sheet, $headerRange);

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

        if ($lastDataRow >= 2) {
            $this->styleTableBody($sheet, $headerRange, 'A2:' . $noteColumn . $lastDataRow);
        }

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
        $chart = (array)($payload['chart'] ?? []);
        $cumulative = array_values((array)($chart['cumulative_profit'] ?? []));
        $prevCumulative = array_values((array)($chart['prev_cumulative_profit'] ?? []));

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('รายเดือน');
        $this->applyReportLook($sheet, self::TAB_COLORS['รายเดือน']);

        $sheet->fromArray(
            [
                'เดือน', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS',
                'วันที่กรอก', 'กำไร/วัน', 'เทียบปีก่อน',
                'กำไรปีก่อน', 'กำไรสะสม', 'สะสมปีก่อน',
            ],
            null,
            'A1'
        );

        $headerRange = 'A1:K1';
        $this->styleHeaderRow($sheet, $headerRange);
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

            // กำไรปีก่อนเดือนเดียวกัน + เส้นสะสม — คอลัมน์พวกนี้เป็นแหล่งข้อมูลของกราฟด้วย
            $prevProfit = (float)($month['prev_year_profit'] ?? 0);
            $sheet->setCellValue('I' . $rowNumber, $prevProfit);

            $index = $rowNumber - 2;
            if (array_key_exists($index, $cumulative)) {
                $sheet->setCellValue('J' . $rowNumber, (float)$cumulative[$index]);
                $this->paintNegative($sheet, 'J' . $rowNumber, (float)$cumulative[$index]);
            }
            if (array_key_exists($index, $prevCumulative)) {
                $sheet->setCellValue('K' . $rowNumber, (float)$prevCumulative[$index]);
                $this->paintNegative($sheet, 'K' . $rowNumber, (float)$prevCumulative[$index]);
            }

            $this->paintNegative($sheet, 'D' . $rowNumber, $profit);
            $this->paintNegative($sheet, 'G' . $rowNumber, (float)($month['profit_per_day'] ?? 0));
            $this->paintNegative($sheet, 'H' . $rowNumber, (float)($month['yoy_change_percent'] ?? 0));
            $this->paintNegative($sheet, 'I' . $rowNumber, $prevProfit);

            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;

        if ($lastRow >= 2) {
            $sheet->getStyle('B2:D' . $lastRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('E2:E' . $lastRow)->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);
            $sheet->getStyle('G2:G' . $lastRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('H2:H' . $lastRow)->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->getStyle('I2:K' . $lastRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->setAutoFilter('A1:K' . $lastRow);
            $this->styleTableBody($sheet, $headerRange, 'A2:K' . $lastRow);
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        if ($lastRow >= 2) {
            $sheet->addChart($this->buildProfitChart($sheet->getTitle(), $lastRow));
            $sheet->addChart($this->buildCumulativeChart($sheet->getTitle(), $lastRow));
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
    public function buildAnnualSheet(
        Spreadsheet $spreadsheet,
        array $summary,
        int $year,
        string $shopName,
        ?string $generatedAt = null
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('รายปี');
        $this->applyReportLook($sheet, self::TAB_COLORS['รายปี']);

        // ── หัวรายงาน ──────────────────────────────────────────────
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValueExplicit('A1', sprintf('สรุปรายปี %d', $year + 543), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL_ARGB);
        $sheet->getStyle('A1')->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setIndent(1);
        $sheet->getRowDimension(1)->setRowHeight(38);

        $generated = is_string($generatedAt) && trim($generatedAt) !== ''
            ? trim($generatedAt)
            : date('Y-m-d');

        $sheet->mergeCells('A2:H2');
        $sheet->setCellValueExplicit(
            'A2',
            sprintf('%s · ออกรายงาน %s', $shopName, $generated),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setARGB('FF808080');
        $sheet->getStyle('A2')->getAlignment()->setIndent(1);
        $sheet->getRowDimension(2)->setRowHeight(16);
        $sheet->getRowDimension(3)->setRowHeight(6);

        // ── การ์ดตัวเลขหลัก ────────────────────────────────────────
        $profit = (float)($summary['profit'] ?? 0);
        $cards = [
            ['A', 'B', 'กำไรทั้งปี', $profit, self::MONEY_FORMAT],
            ['C', 'D', 'รายได้', (float)($summary['total_revenue'] ?? 0), self::MONEY_FORMAT],
            ['E', 'F', 'ค่าแอด', (float)($summary['total_ad_cost'] ?? 0), self::MONEY_FORMAT],
            ['G', 'H', 'อัตรากำไร', $summary['profit_margin'] ?? null, self::PERCENT_FORMAT],
        ];

        foreach ($cards as [$left, $right, $label, $value, $format]) {
            $sheet->mergeCells($left . '4:' . $right . '4');
            $sheet->mergeCells($left . '5:' . $right . '5');

            $sheet->setCellValueExplicit($left . '4', $label, DataType::TYPE_STRING);
            $sheet->getStyle($left . '4')->getFont()->setSize(9)->getColor()->setARGB('FF595959');
            $sheet->getStyle($left . '4')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            if ($value === null) {
                $sheet->setCellValueExplicit($left . '5', '–', DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($left . '5', (float)$value);
                $sheet->getStyle($left . '5')->getNumberFormat()->setFormatCode($format);
            }

            $sheet->getStyle($left . '5')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle($left . '5')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            // พื้นการ์ด + เส้นขอบ ให้แยกจากพื้นหลังชัด
            $cardRange = $left . '4:' . $right . '5';
            $sheet->getStyle($cardRange)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::BAND_FILL_ARGB);
            $sheet->getStyle($cardRange)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setARGB(self::GRID_LINE_ARGB);
        }

        $this->paintNegative($sheet, 'A5', $profit);
        $sheet->getRowDimension(4)->setRowHeight(16);
        $sheet->getRowDimension(5)->setRowHeight(28);
        $sheet->getRowDimension(6)->setRowHeight(8);

        // ── บล็อกเทียบปีก่อน ───────────────────────────────────────
        $this->writeSectionHeader(
            $sheet,
            7,
            sprintf('เทียบ %d (ช่วงเดียวกัน)', (int)($summary['prev_year'] ?? ($year - 1)) + 543)
        );

        $percent = $summary['yoy_profit_change_percent'] ?? null;
        $sheet->mergeCells('A8:H8');
        if ($percent === null) {
            $sheet->setCellValueExplicit('A8', 'ไม่มีข้อมูลปีก่อน', DataType::TYPE_STRING);
        } else {
            $percentValue = (float)$percent;
            $change = (float)($summary['yoy_profit_change'] ?? 0);
            $sheet->setCellValueExplicit(
                'A8',
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
            $sheet->getStyle('A8')->getFont()->setBold(true)->setSize(12);

            if (abs($percentValue) >= 0.05) {
                $sheet->getStyle('A8')->getFont()->getColor()->setARGB(
                    $percentValue > 0 ? self::POSITIVE_FONT_ARGB : self::NEGATIVE_FONT_ARGB
                );
            }
        }
        $sheet->getStyle('A8')->getAlignment()->setIndent(1);
        $sheet->getRowDimension(9)->setRowHeight(8);

        // ── บล็อกเดือนที่โดดเด่น ───────────────────────────────────
        $this->writeSectionHeader($sheet, 10, 'เดือนที่โดดเด่น');

        $facts = [
            ['เดือนกำไรดีสุด', $this->describeMonth($summary['best_month'] ?? null)],
            ['เดือนกำไรแย่สุด', $this->describeMonth($summary['worst_month'] ?? null)],
            [
                'เดือนที่มีข้อมูล',
                sprintf(
                    '%d เดือน · กำไร %d / ขาดทุน %d',
                    (int)($summary['months_with_data'] ?? 0),
                    (int)($summary['profit_months'] ?? 0),
                    (int)($summary['loss_months'] ?? 0)
                ),
            ],
        ];

        $rowNumber = 11;
        foreach ($facts as [$label, $value]) {
            $sheet->setCellValueExplicit('A' . $rowNumber, $label, DataType::TYPE_STRING);
            $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);
            $sheet->getStyle('A' . $rowNumber)->getAlignment()->setIndent(1);

            $sheet->mergeCells('C' . $rowNumber . ':H' . $rowNumber);
            $sheet->setCellValueExplicit('C' . $rowNumber, $value, DataType::TYPE_STRING);
            $rowNumber++;
        }

        $sheet->getRowDimension($rowNumber)->setRowHeight(8);
        $rowNumber++;

        // ── บล็อกประมาณการ ─────────────────────────────────────────
        $this->writeSectionHeader($sheet, $rowNumber, '🔮 ประมาณการสิ้นปี (ไม่ใช่ตัวเลขจริง)');
        $this->writeProjection($sheet, (array)($summary['projection'] ?? []), $rowNumber + 1);

        $sheet->getColumnDimension('A')->setWidth(20);
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(14);
        }

        // สรุปทั้งปีมาก่อนทุกอย่าง
        $spreadsheet->setIndexByName('รายปี', 0);
        $spreadsheet->setActiveSheetIndex(0);
    }

    /** หัวข้อบล็อกในชีตรายปี — ตัวหนาสีเข้ม + เส้นใต้ ให้แยกส่วนด้วยตา */
    private function writeSectionHeader(Worksheet $sheet, int $rowNumber, string $title): void
    {
        $sheet->mergeCells('A' . $rowNumber . ':H' . $rowNumber);
        $sheet->setCellValueExplicit('A' . $rowNumber, $title, DataType::TYPE_STRING);
        $sheet->getStyle('A' . $rowNumber)->getFont()
            ->setBold(true)
            ->setSize(11)
            ->getColor()->setARGB(self::HEADER_FILL_ARGB);
        $sheet->getStyle('A' . $rowNumber)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setIndent(1);
        $sheet->getStyle('A' . $rowNumber . ':H' . $rowNumber)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB(self::HEADER_FILL_ARGB);
        $sheet->getRowDimension($rowNumber)->setRowHeight(20);
    }

    /**
     * เพิ่ม sheet "เป้าหมาย" — เป้า vs ผลจริง ต่อเดือน
     * ผู้เรียกต้องเช็กเองว่ามีเป้าอย่างน้อย 1 เดือน (ไม่งั้นได้ชีตเปล่า)
     *
     * @param array<int,array<string,mixed>> $goalProgress จาก AnnualService ['data']['goal_progress']
     */
    public function buildGoalSheet(Spreadsheet $spreadsheet, array $goalProgress, int $year): void
    {
        $rows = array_values($goalProgress);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('เป้าหมาย');
        $this->applyReportLook($sheet, self::TAB_COLORS['เป้าหมาย']);

        $sheet->setCellValueExplicit(
            'A1',
            sprintf('เป้าหมายรายเดือน ปี %d (เฉพาะเดือนที่ตั้งเป้า)', $year + 543),
            DataType::TYPE_STRING
        );
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headerRow = 3;
        $sheet->fromArray(
            [
                'เดือน',
                'เป้ารายได้', 'รายได้จริง', 'ทำได้', 'ถึงเป้า',
                'เป้ากำไร', 'กำไรจริง', 'ทำได้', 'ถึงเป้า',
            ],
            null,
            'A' . $headerRow
        );
        $this->styleHeaderRow($sheet, 'A' . $headerRow . ':I' . $headerRow);
        $sheet->freezePane('A' . ($headerRow + 1));

        $rowNumber = $headerRow + 1;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit(
                'A' . $rowNumber,
                self::THAI_MONTHS[(int)($row['month'] ?? 0)] ?? '',
                DataType::TYPE_STRING
            );

            // รายได้: เป้า / จริง / % / ถึงเป้า — ไม่ได้ตั้งเป้าไว้ก็เว้นว่างทั้งชุด
            $this->writeGoalPair(
                $sheet,
                $rowNumber,
                'B',
                $row['target_revenue'] ?? null,
                (float)($row['actual_revenue'] ?? 0),
                $row['revenue_progress'] ?? null,
                $row['revenue_reached'] ?? null
            );

            $this->writeGoalPair(
                $sheet,
                $rowNumber,
                'F',
                $row['target_profit'] ?? null,
                (float)($row['actual_profit'] ?? 0),
                $row['profit_progress'] ?? null,
                $row['profit_reached'] ?? null
            );

            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;
        if ($lastRow >= $headerRow + 1) {
            $sheet->getStyle('B' . ($headerRow + 1) . ':C' . $lastRow)
                ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('F' . ($headerRow + 1) . ':G' . $lastRow)
                ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('D' . ($headerRow + 1) . ':D' . $lastRow)
                ->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->getStyle('H' . ($headerRow + 1) . ':H' . $lastRow)
                ->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->setAutoFilter('A' . $headerRow . ':I' . $lastRow);
            $this->styleTableBody(
                $sheet,
                'A' . $headerRow . ':I' . $headerRow,
                'A' . ($headerRow + 1) . ':I' . $lastRow
            );
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * เขียนคู่ "เป้า / จริง / % / ถึงเป้า" ลง 4 คอลัมน์ติดกัน
     *
     * @param mixed $target
     * @param mixed $progress
     * @param mixed $reached
     */
    private function writeGoalPair(
        Worksheet $sheet,
        int $rowNumber,
        string $startColumn,
        $target,
        float $actual,
        $progress,
        $reached
    ): void {
        $columns = [];
        for ($offset = 0; $offset < 4; $offset++) {
            $columns[] = Coordinate::stringFromColumnIndex(
                Coordinate::columnIndexFromString($startColumn) + $offset
            );
        }

        // ไม่ได้ตั้งเป้าด้านนี้ → เว้นว่างทั้งชุด (ไม่ใช่ 0 ที่จะอ่านว่า "ตั้งเป้าไว้ศูนย์")
        if ($target === null) {
            return;
        }

        $sheet->setCellValue($columns[0] . $rowNumber, (float)$target);
        $sheet->setCellValue($columns[1] . $rowNumber, $actual);
        $this->paintNegative($sheet, $columns[1] . $rowNumber, $actual);

        if ($progress !== null) {
            $sheet->setCellValue($columns[2] . $rowNumber, (float)$progress);
            $this->paintNegative($sheet, $columns[2] . $rowNumber, (float)$progress);
        }

        if ($reached === null) {
            return;
        }

        $isReached = $reached === true;
        $sheet->setCellValueExplicit(
            $columns[3] . $rowNumber,
            $isReached ? '✓ ถึงเป้า' : 'ยังไม่ถึง',
            DataType::TYPE_STRING
        );
        $sheet->getStyle($columns[3] . $rowNumber)->getFont()->getColor()->setARGB(
            $isReached ? self::POSITIVE_FONT_ARGB : self::PENDING_FONT_ARGB
        );
    }

    /**
     * เพิ่ม sheet "ฤดูกาล" — กริดกำไร 12 เดือน × 3 ปี
     * ใช้ conditional formatting ของ Excel จริง (แก้ตัวเลขแล้วสีขยับตาม)
     *
     * @param array<string,mixed> $payload จาก AnnualService::buildMonthlyHeatmap()['data']
     */
    public function buildSeasonSheet(Spreadsheet $spreadsheet, array $payload): void
    {
        $years = array_values((array)($payload['years'] ?? []));
        $grid = (array)($payload['grid'] ?? []);

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('ฤดูกาล');
        $this->applyReportLook($sheet, self::TAB_COLORS['ฤดูกาล']);

        $sheet->setCellValueExplicit('A1', 'ฤดูกาลกำไร (3 ปี)', DataType::TYPE_STRING);
        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->setCellValueExplicit(
            'A2',
            'เดือนเดียวกันเขียวหลายปีติด = ฤดูกาลขายจริง ไม่ใช่ฟลุ๊ค · ช่องว่าง = ยังไม่มีข้อมูล',
            DataType::TYPE_STRING
        );
        // merge ด้วย ไม่งั้น autoSize ยืดคอลัมน์ A ตามความยาวข้อความบรรทัดนี้
        $sheet->mergeCells('A2:M2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        $headerRow = 4;
        $header = ['ปี'];
        for ($month = 1; $month <= 12; $month++) {
            $header[] = self::THAI_MONTHS[$month];
        }
        $sheet->fromArray($header, null, 'A' . $headerRow);
        $this->styleHeaderRow($sheet, 'A' . $headerRow . ':M' . $headerRow);
        $sheet->freezePane('B' . ($headerRow + 1));

        $rowNumber = $headerRow + 1;
        foreach ($years as $gridYear) {
            $sheet->setCellValueExplicit(
                'A' . $rowNumber,
                (string)((int)$gridYear + 543),
                DataType::TYPE_STRING
            );
            $sheet->getStyle('A' . $rowNumber)->getFont()->setBold(true);

            for ($month = 1; $month <= 12; $month++) {
                $cell = (array)($grid[$gridYear][$month] ?? []);
                // has_data=false → เว้นว่าง (ต่างจากเท่าทุนที่เป็น 0 จริง)
                if (($cell['has_data'] ?? false) !== true || ($cell['profit'] ?? null) === null) {
                    continue;
                }

                $column = Coordinate::stringFromColumnIndex($month + 1);
                $sheet->setCellValue($column . $rowNumber, (float)$cell['profit']);
            }

            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;
        if ($lastRow >= $headerRow + 1) {
            $dataRange = 'B' . ($headerRow + 1) . ':M' . $lastRow;
            $sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->setConditionalStyles($dataRange, $this->buildProfitConditionals());
            // ไม่ band — กริดนี้ใช้ conditional formatting ระบายสีอยู่แล้ว จะตีกัน
            $this->styleTableBody(
                $sheet,
                'A' . $headerRow . ':M' . $headerRow,
                'A' . ($headerRow + 1) . ':M' . $lastRow,
                false
            );
        }

        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * กฎ conditional formatting: กำไร > 0 เขียว · < 0 แดง · ช่องว่างไม่เข้าเงื่อนไขทั้งคู่
     *
     * @return array<int,Conditional>
     */
    private function buildProfitConditionals(): array
    {
        $positive = new Conditional();
        $positive->setConditionType(Conditional::CONDITION_CELLIS);
        $positive->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
        $positive->addCondition('0');
        $positive->getStyle()->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFC6EFCE');
        $positive->getStyle()->getFont()->getColor()->setARGB('FF006100');

        $negative = new Conditional();
        $negative->setConditionType(Conditional::CONDITION_CELLIS);
        $negative->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $negative->addCondition('0');
        $negative->getStyle()->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFC7CE');
        $negative->getStyle()->getFont()->getColor()->setARGB('FF9C0006');

        return [$positive, $negative];
    }

    /** หัวตารางสไตล์เดียวกันทุกชีต */
    private function styleHeaderRow(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL_ARGB);
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // แถวหัวสูงขึ้น — ให้ตารางมีที่หายใจ ไม่อัดกันเป็นพืด
        $headerRowNumber = (int)preg_replace('/\D/', '', explode(':', $range)[0]);
        if ($headerRowNumber > 0) {
            $sheet->getRowDimension($headerRowNumber)->setRowHeight(self::HEADER_ROW_HEIGHT);
        }
    }

    /**
     * ลุคพื้นฐานของทุกชีต — ปิดเส้นตารางของ Excel แล้ววาดเส้นเองเฉพาะที่ต้องการ
     * (จุดที่เปลี่ยนความรู้สึกจาก "สเปรดชีตดิบ" เป็น "รายงาน" มากที่สุด)
     */
    private function applyReportLook(Worksheet $sheet, string $tabColorArgb): void
    {
        $sheet->setShowGridlines(false);
        $sheet->getTabColor()->setARGB($tabColorArgb);
    }

    /**
     * เส้นขอบบาง + แถบสลับสีให้ตารางอ่านง่าย
     *
     * @param string $dataRange ช่วงแถวข้อมูล (ไม่รวมหัว)
     */
    private function styleTableBody(Worksheet $sheet, string $headerRange, string $dataRange, bool $banded = true): void
    {
        // ครอบตั้งแต่มุมซ้ายบนของหัว ถึงมุมขวาล่างของข้อมูล
        // (ต่อ string ตรง ๆ จะได้ 'A1:F1:F4' ซึ่งเป็น range ที่ Excel ไม่รู้จัก → เส้นไม่ติด)
        $sheet->getStyle(explode(':', $headerRange)[0] . ':' . explode(':', $dataRange)[1])
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB(self::GRID_LINE_ARGB);

        if (!$banded) {
            return;
        }

        [$start, $end] = explode(':', $dataRange);
        $startColumn = (string)preg_replace('/\d/', '', $start);
        $endColumn = (string)preg_replace('/\d/', '', $end);
        $firstRow = (int)preg_replace('/\D/', '', $start);
        $lastRow = (int)preg_replace('/\D/', '', $end);

        // ระบายเว้นแถว — อ่านตารางยาว ๆ ไม่หลุดบรรทัด
        for ($row = $firstRow + 1; $row <= $lastRow; $row += 2) {
            $sheet->getStyle($startColumn . $row . ':' . $endColumn . $row)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::BAND_FILL_ARGB);
        }
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
        $this->applyReportLook($sheet, self::TAB_COLORS['เทียบร้าน']);

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
        $this->styleHeaderRow($sheet, $headerRange);
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
            $this->styleTableBody($sheet, $headerRange, 'A' . ($headerRow + 1) . ':H' . $lastShopRow);
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
        // หัวข้อวาดโดย writeSectionHeader แล้ว — ที่นี่เขียนเฉพาะเนื้อ
        $sheet->mergeCells('A' . $startRow . ':H' . $startRow);
        $sheet->getStyle('A' . $startRow)->getAlignment()->setIndent(1);

        if (($projection['available'] ?? false) !== true) {
            $sheet->setCellValueExplicit(
                'A' . $startRow,
                'ข้อมูลยังไม่พอประมาณการ',
                DataType::TYPE_STRING
            );
            $sheet->getStyle('A' . $startRow)->getFont()->setItalic(true)
                ->getColor()->setARGB('FF808080');

            return;
        }

        $sheet->setCellValueExplicit(
            'A' . $startRow,
            sprintf(
                '%s – %s (กลาง %s)',
                number_format((float)($projection['projection_low'] ?? 0), 2),
                number_format((float)($projection['projection_high'] ?? 0), 2),
                number_format((float)($projection['projection_mid'] ?? 0), 2)
            ),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A' . $startRow)->getFont()->setItalic(true)->setBold(true)->setSize(12);

        $sheet->mergeCells('A' . ($startRow + 1) . ':H' . ($startRow + 1));
        $sheet->getStyle('A' . ($startRow + 1))->getAlignment()->setIndent(1);
        $sheet->setCellValueExplicit(
            'A' . ($startRow + 1),
            sprintf(
                'สมมติเดือนที่เหลือ (%d) ทำได้เท่า %d เดือนล่าสุด · ไม่คิดฤดูกาล — ใช้ประกอบ ไม่ใช่ตัวเลขที่เกิดขึ้นจริง',
                (int)($projection['months_remaining'] ?? 0),
                (int)($projection['basis_month_count'] ?? 0)
            ),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A' . ($startRow + 1))->getFont()->setItalic(true)->setSize(9)
            ->getColor()->setARGB('FF808080');
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
     * กราฟแท่งกำไรรายเดือน + เส้นประกำไรปีก่อน (เทียบรูปทรงฤดูกาล)
     * อ้าง range เซลล์ในชีต "รายเดือน" โดยตรง — แก้ตัวเลขในตาราง กราฟขยับตาม
     */
    private function buildProfitChart(string $sheetTitle, int $lastRow): Chart
    {
        $quotedTitle = "'" . $sheetTitle . "'";
        $pointCount = $lastRow - 1;

        $categories = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                $quotedTitle . '!$A$2:$A$' . $lastRow,
                null,
                $pointCount
            ),
        ];

        $bars = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $quotedTitle . '!$D$1', null, 1)],
            $categories,
            [new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $quotedTitle . '!$D$2:$D$' . $lastRow,
                null,
                $pointCount
            )]
        );
        // แท่งตั้ง — กำไรติดลบจะยื่นลงล่างเองตามค่าจริง
        $bars->setPlotDirection(DataSeries::DIRECTION_COL);

        // เส้นกำไรปีก่อน (คอลัมน์ I) ทับบนแท่ง — เห็นทันทีว่าเดือนไหนเป็นฤดูกาลซ้ำ
        $prevLine = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            [0],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $quotedTitle . '!$I$1', null, 1)],
            $categories,
            [new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $quotedTitle . '!$I$2:$I$' . $lastRow,
                null,
                $pointCount
            )]
        );

        $chart = new Chart(
            'profit-by-month',
            new Title('กำไรรายเดือน (แท่ง = ปีนี้ · เส้น = ปีก่อน)'),
            null,
            new PlotArea(null, [$bars, $prevLine])
        );
        // ต้องพ้นคอลัมน์ K (สะสมปีก่อน) ไม่งั้นทับข้อมูล
        $chart->setTopLeftPosition('M2');
        $chart->setBottomRightPosition('U20');

        return $chart;
    }

    /**
     * กราฟเส้นกำไรสะสม ปีนี้ vs ปีก่อน — เส้นห่างกันมาก = ทิ้งห่าง/ตามหลัง
     */
    private function buildCumulativeChart(string $sheetTitle, int $lastRow): Chart
    {
        $quotedTitle = "'" . $sheetTitle . "'";
        $pointCount = $lastRow - 1;

        $categories = [
            new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_STRING,
                $quotedTitle . '!$A$2:$A$' . $lastRow,
                null,
                $pointCount
            ),
        ];

        $series = new DataSeries(
            DataSeries::TYPE_LINECHART,
            DataSeries::GROUPING_STANDARD,
            [0, 1],
            [
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $quotedTitle . '!$J$1', null, 1),
                new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $quotedTitle . '!$K$1', null, 1),
            ],
            $categories,
            [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    $quotedTitle . '!$J$2:$J$' . $lastRow,
                    null,
                    $pointCount
                ),
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    $quotedTitle . '!$K$2:$K$' . $lastRow,
                    null,
                    $pointCount
                ),
            ]
        );

        $chart = new Chart(
            'cumulative-profit',
            new Title('กำไรสะสม ปีนี้ vs ปีก่อน (ช่วงเดียวกัน)'),
            null,
            new PlotArea(null, [$series])
        );
        $chart->setTopLeftPosition('M22');
        $chart->setBottomRightPosition('U40');

        return $chart;
    }

}
