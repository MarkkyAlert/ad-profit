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
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
    private const EMPHASIS_FILL_ARGB = 'FFEAF1F8';
    private const TOTALS_FILL_ARGB = 'FFDCE6F1';
    private const HEADER_ROW_HEIGHT = 24;

    /** ฟอนต์ทั้ง workbook — Tahoma เรนเดอร์ไทยคมกว่า Calibri (ที่ต้อง fallback) */
    private const BASE_FONT = 'Tahoma';
    private const BASE_FONT_SIZE = 11;
    private const BASE_ROW_HEIGHT = 18;

    /** สีแท็บแยกกลุ่ม: ร้านเดี่ยว = น้ำเงินไล่เฉด · เป้าหมาย/ฤดูกาล/พอร์ต = คนละโทน */
    private const TAB_COLORS = [
        'รายปี' => 'FF1F4E79',
        'รายเดือน' => 'FF2E75B6',
        'รายวัน' => 'FF8EA9DB',
        'เป้าหมาย' => 'FF548235',
        'ฤดูกาล' => 'FFBF8F00',
        'เทียบร้าน' => 'FF7030A0',
    ];

    /** สีกราฟให้ตรงกับสัญญาณสีในตาราง: กำไร = เขียว · ปีก่อน = เทา · สะสม = น้ำเงิน */
    private const CHART_PROFIT_ARGB = '2E9E5B';
    private const CHART_PREV_ARGB = '9E9E9E';
    private const CHART_CUMULATIVE_ARGB = '2E75B6';

    private const PERCENT_FORMAT = '0.0"%"';
    /* ⚠️⚠️ สัดส่วนกำไรต้องแสดง **สองตำแหน่ง** ต่างจาก % อื่น ๆ
       · ตัวเลขที่คำนวณไว้รวมกันได้ 100.00 พอดี (`distribute_profit_share()` แจกเศษให้)
         แต่ถ้าแสดงตำแหน่งเดียว 3 ร้านเท่ากันจะกลายเป็น 33.3 × 3 = **99.9%** บนหน้าจอ Excel
         ทั้งที่ค่าจริงในเซลล์ถูกต้อง — คนอ่านบวกตามที่เห็นแล้วไม่ครบ
       · ร้านที่สัดส่วนเล็กมาก (0.01%) ก็ถูกแสดงเป็น 0.0% ขณะที่หน้าจอเขียน `<0.1%` */
    private const SHARE_FORMAT = '0.00"%"';
    /**
     * ทุกช่องเงินในไฟล์ใช้รูปแบบเดียวกัน — ตรงถึงสตางค์
     *
     * ⚠️ เดิมชีตสรุป/รายเดือน/เทียบร้านใช้ `"฿"#,##0` (ตัดสตางค์ทิ้ง) ขณะที่ชีตรายวัน
     * และหน้าเว็บแสดงสตางค์ → ค่าเดียวกันอ่านได้ ฿100 ในแท็บหนึ่งและ ฿100.40 ในอีกแท็บ
     * ของไฟล์เดียวกัน และ 3 แถวที่เห็น (฿100 ×3) บวกไม่เท่าแถวรวม (฿301)
     */
    private const MONEY_FORMAT = '"฿"#,##0.00';
    private const MONEY_DETAIL_FORMAT = '"฿"#,##0.00';
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
        $spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('รายวัน');
        $this->applyReportLook($sheet, self::TAB_COLORS['รายวัน']);

        $sheet->fromArray(['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'โน้ต'], null, 'A1');

        $headerRange = 'A1:' . $noteColumn . '1';
        $this->styleHeaderRow($sheet, $headerRange);

        // ตรึงแถวหัว — เลื่อนดูทั้งปีแล้วยังเห็นชื่อคอลัมน์
        $sheet->freezePane('A2');
        $this->repeatHeaderOnPrint($sheet, 1);

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

        /* ⚠️ ไม่มีแถวข้อมูลสักแถว → แถวรวมต้องเว้นว่าง ไม่ใช่ 0/0/0
           (กติกาเดียวกับแถวรวมของตารางบนหน้าจอ ซึ่งเป็นขีดเมื่อไม่มีข้อมูล) */
        if ($rowNumber > 2) {
            $sheet->setCellValue('B' . $totalsRowNumber, (float)($totals['revenue'] ?? 0));
            $sheet->setCellValue('C' . $totalsRowNumber, (float)($totals['ad_cost'] ?? 0));
            $sheet->setCellValue('D' . $totalsRowNumber, (float)($totals['profit'] ?? 0));
        }
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

        $sheet->getStyle('B2:D' . $totalsRowNumber)->getNumberFormat()->setFormatCode(self::MONEY_DETAIL_FORMAT);
        $sheet->getStyle('E2:E' . $totalsRowNumber)->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);

        if ($lastDataRow >= 2) {
            $this->styleTableBody($sheet, $headerRange, 'A2:' . $noteColumn . $lastDataRow);
            $this->emphasizeColumn($sheet, 'D', 2, $lastDataRow);
        }

        // แถวรวมต้องหนักกว่าแถวข้อมูล ไม่ใช่แค่เส้นบาง ๆ
        $sheet->getStyle($totalsRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::TOTALS_FILL_ARGB);
        $sheet->getRowDimension($totalsRowNumber)->setRowHeight(self::BASE_ROW_HEIGHT + 2);

        /* ⭐⭐ บอกวันตัดของไฟล์เอง — ปีปัจจุบันตัดที่วันนี้เพราะ `daily_records`
           เป็นยอดจริงเท่านั้น · หน้าประวัติไม่ตัด (ต้องแสดงแถวเก่าที่ลงวันล่วงหน้า
           ไว้ให้ลบได้) ร้านที่มีแถวแบบนั้นจึงเห็นจำนวนแถวสองที่ไม่เท่ากัน
           **[เจ้าของระบบตัดสิน 2026-08-12] คงพฤติกรรมไว้ แต่ให้ไฟล์อธิบายตัวเอง**
           ⚠️ ข้อความอยู่ที่ `export_coverage_note()` ที่เดียว และคืน null เมื่อไม่ได้ตัด
              (ปีที่จบแล้ว) — เขียนกำกับทุกปีคือเสียงรบกวนที่ไม่บอกอะไรใหม่ */
        $coverageNote = export_coverage_note(
            isset($payload['covered_through']) ? (string)$payload['covered_through'] : null,
            (bool)($payload['covered_through_is_trimmed'] ?? false)
        );

        if ($coverageNote !== null) {
            $noteRowNumber = $totalsRowNumber + 2;
            $sheet->setCellValueExplicit('A' . $noteRowNumber, $coverageNote, DataType::TYPE_STRING);
            $sheet->mergeCells('A' . $noteRowNumber . ':' . $noteColumn . $noteRowNumber);
            $sheet->getStyle('A' . $noteRowNumber)->getFont()->setItalic(true)->setSize(self::BASE_FONT_SIZE - 1);
        }

        $this->setColumnWidths($sheet, [
            'A' => 14, 'B' => 15, 'C' => 15, 'D' => 15, 'E' => 11, 'F' => 30,
        ]);

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

        /* "ทั้งปีมีข้อมูลไหม" — ตัวตัดสินของเส้นสะสม (ดูเหตุผลตรงจุดที่ใช้)
           ⚠️ นับจาก **จำนวนวันที่กรอก** ไม่ใช่จากยอดเงิน — ปีที่กรอกครบแต่เท่าทุนพอดี
              ยังต้องเขียน 0 เพราะนั่นคือความจริง (กติกาเดียวกับทั้งระบบ) */
        $yearHasAnyData = false;
        $previousYearHasAnyData = false;
        foreach ($months as $month) {
            if ((int)($month['days_count'] ?? 0) > 0) {
                $yearHasAnyData = true;
            }
            /* ⚠️ ต้องดู "จำนวนวันที่ปีก่อนกรอก" ไม่ใช่ดูว่ากำไรปีก่อนเป็น 0 ไหม —
               0 เกิดได้ทั้งตอนไม่มีข้อมูลและตอนเท่าทุนพอดี · ผู้เรียกที่ไม่ส่งคีย์นี้มา
               (ชุดทดสอบของกราฟ) ได้พฤติกรรมเดิม */
            if (!array_key_exists('prev_year_days_count', $month)
                || (int)$month['prev_year_days_count'] > 0
            ) {
                $previousYearHasAnyData = true;
            }
        }

        /* ⚠️ ผู้เรียกที่ไม่ส่ง `days_count` มาเลย (ชุดทดสอบของกราฟ) ต้องได้พฤติกรรมเดิม
           — เงื่อนไขเดียวกับ `$monthHasData` ข้างล่าง */
        foreach ($months as $month) {
            if (!array_key_exists('days_count', $month)) {
                $yearHasAnyData = true;
                break;
            }
        }

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
        $this->repeatHeaderOnPrint($sheet, 1);

        $rowNumber = 2;
        foreach ($months as $month) {
            $profit = (float)($month['profit'] ?? 0);
            $label = (string)($month['month_label'] ?? (self::THAI_MONTHS[(int)($month['month'] ?? 0)] ?? ''));

            $sheet->setCellValueExplicit('A' . $rowNumber, $label, DataType::TYPE_STRING);

            /* ⚠️⚠️ เดือนที่ยังไม่ได้กรอกเลย ≠ เดือนที่ทำได้ ฿0 — กติกานี้ต้องลงถึงไฟล์ด้วย
               หน้าจอ (`annual.php`) เว้นเป็นขีดให้เดือนที่ `days_count === 0` มานานแล้ว
               และในไฟล์เอง คอลัมน์ ROAS/กำไรต่อวัน/เทียบปีก่อน ก็เว้นว่างถูกต้องอยู่แล้ว
               — เหลือแค่ ยอดขาย/ค่าแอด/กำไร ที่ยังเขียน 0 ลงไป
               · **แถวเดียวกันในไฟล์จึงใช้กติกาสองแบบ และไม่ตรงกับจอ**
               · คนที่เปิดไฟล์แล้วลากกราฟจากคอลัมน์กำไร เห็นเส้นดิ่งลงศูนย์ตั้งแต่เดือนที่
                 ยังไม่ได้กรอก ซึ่งอ่านว่า "กำไรหายไป" ไม่ใช่ "ยังไม่ได้บันทึก"
               · เว้นเซลล์ว่างยังทำให้กราฟใน Excel ขาดช่วงเอง (หลักเดียวกับที่ส่ง null
                 ให้ Chart.js บนหน้าเว็บ)
               ⚠️ คอลัมน์ J (กำไรสะสม) ไม่เว้น — ยอดสะสมของเดือนที่ไม่ได้กรอกคือยอดเดิม
                  ที่สะสมมา ไม่ใช่ "ไม่รู้" */
            /* ⚠️ "ไม่มีคีย์ days_count" ≠ "days_count = 0" — ผู้เรียกที่ส่งแถวมาเองโดยไม่ระบุ
               จำนวนวัน (เช่นชุดทดสอบของกราฟ) ต้องได้พฤติกรรมเดิม ไม่ใช่ถูกนับว่าเดือนว่าง
               · `AnnualService` ส่ง `days_count` มาเสมอ ทางที่ผู้ใช้เดินจริงจึงถูกคุมครบ */
            $monthHasData = !array_key_exists('days_count', $month)
                || (int)$month['days_count'] > 0;

            if ($monthHasData) {
                $sheet->setCellValue('B' . $rowNumber, (float)($month['revenue'] ?? $month['total_revenue'] ?? 0));
                $sheet->setCellValue('C' . $rowNumber, (float)($month['ad_cost'] ?? $month['total_ad_cost'] ?? 0));
                $sheet->setCellValue('D' . $rowNumber, $profit);
            }

            if (isset($month['roas']) && $month['roas'] !== null) {
                $sheet->setCellValue('E' . $rowNumber, (float)$month['roas']);
            }

            /* ⚠️⚠️ คอลัมน์ "วันที่กรอก" คือ **หลักฐาน** ไม่ใช่ผลงาน — เขียน 0 ต่อไปโดยตั้งใจ
               · "กรอกไป 0 วัน" เป็นความจริงที่ตรวจสอบได้ ไม่ใช่การเดาแทนข้อมูล
                 ต่างจากช่องเงิน/ROAS/กำไรต่อวัน ที่ 0 อ่านว่า "ทำได้เท่านี้"
               · มันคือคำอธิบายว่าทำไมช่องอื่นในแถวนั้นถึงว่าง
               · ชีต "เทียบร้าน" และหน้าจอของมัน (`overview.php`) ก็เขียน "0 วัน" เหมือนกัน
               ⚠️ หน้าจอของชีตนี้ (`annual.php`) เว้นเป็นขีดทั้งแถวรวมช่องนี้ด้วย —
                  เป็นความต่างที่ **ตั้งใจและถูกล็อกไว้ทั้งสองฝั่ง** โดย
                  `XlsxReportServiceUsabilityTest` (ฝั่งไฟล์) และ `AnnualMetricParityTest`
                  (ตรึงว่าทั้งสองฝั่งยังเป็นแบบนี้อยู่) — ใครเปลี่ยนข้างเดียวจะแดงทันที */
            $sheet->setCellValue('F' . $rowNumber, (int)($month['days_count'] ?? 0));

            if (isset($month['profit_per_day']) && $month['profit_per_day'] !== null) {
                $sheet->setCellValue('G' . $rowNumber, (float)$month['profit_per_day']);
            }

            /* ⚠️⚠️ เดือนที่ปีนี้ยังไม่ได้กรอก **ห้ามเขียน −100%** — `change_percent(0, ปีก่อน)`
               ให้ −100 ซึ่งอ่านว่า "ยอดหายไปหมด" ทั้งที่แค่ยังไม่ได้บันทึก
               · หน้าจอเว้นเป็นขีดอยู่แล้ว (`annual.php` · `$rowHasData ? … : $blank`)
               · ร้านที่เริ่มใช้ระบบกลางปีจะเห็นครึ่งปีแรกในไฟล์เป็น "ตก 100%" ทุกเดือน */
            if ($monthHasData
                && isset($month['yoy_change_percent'])
                && $month['yoy_change_percent'] !== null
            ) {
                $sheet->setCellValue('H' . $rowNumber, (float)$month['yoy_change_percent']);
            }

            /* กำไรปีก่อนเดือนเดียวกัน + เส้นสะสม — คอลัมน์พวกนี้เป็นแหล่งข้อมูลของกราฟด้วย
               ⚠️⚠️ กติกา "ยังไม่เคยกรอก ≠ ทำได้ ฿0" ใช้กับสามคอลัมน์นี้ด้วย —
                    เดิมเขียน 0 เสมอ ทั้งที่ B/C/D ข้าง ๆ เว้นว่างไปแล้ว
                    → **แถวเดียวกันยังใช้กติกาสองแบบอยู่** และกราฟที่ลากจากคอลัมน์นี้
                      จะดิ่งลงศูนย์ตั้งแต่เดือนที่ยังไม่ได้กรอก
               ⚠️ ตัวตัดสินของ I คือ "**ปีก่อน**เดือนนั้นมีข้อมูลไหม" ไม่ใช่ "ปีนี้เดือนนั้น
                  มีข้อมูลไหม" — คนละคำถามกัน · และต้องดู **จำนวนวันที่กรอก** ไม่ใช่ดูว่ากำไร
                  เป็น 0 ไหม เพราะ 0 เกิดได้ทั้งตอนไม่มีข้อมูลและตอนเท่าทุนพอดี
               ⚠️ ผู้เรียกที่ไม่ส่ง `prev_year_days_count` มา (ชุดทดสอบของกราฟ) ได้พฤติกรรมเดิม */
            $prevMonthHasData = !array_key_exists('prev_year_days_count', $month)
                || (int)$month['prev_year_days_count'] > 0;

            if ($prevMonthHasData && ($month['prev_year_profit'] ?? null) !== null) {
                $prevProfit = (float)$month['prev_year_profit'];
                $sheet->setCellValue('I' . $rowNumber, $prevProfit);
                $this->paintNegative($sheet, 'I' . $rowNumber, $prevProfit);
            }

            /* ⚠️ เส้นสะสมเว้นว่างเฉพาะเมื่อ **ทั้งปีไม่มีข้อมูลเลย** ไม่ใช่รายเดือน —
               ยอดสะสมของเดือนที่ไม่ได้กรอกคือยอดเดิมที่สะสมมา ซึ่งเป็นความจริง ไม่ใช่ "ไม่รู้"
               (ต่างจากคอลัมน์อื่นในแถวโดยตั้งใจ) */
            $index = $rowNumber - 2;
            if ($yearHasAnyData && array_key_exists($index, $cumulative)) {
                $sheet->setCellValue('J' . $rowNumber, (float)$cumulative[$index]);
                $this->paintNegative($sheet, 'J' . $rowNumber, (float)$cumulative[$index]);
            }
            if ($previousYearHasAnyData && array_key_exists($index, $prevCumulative)) {
                $sheet->setCellValue('K' . $rowNumber, (float)$prevCumulative[$index]);
                $this->paintNegative($sheet, 'K' . $rowNumber, (float)$prevCumulative[$index]);
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
            $sheet->getStyle('I2:K' . $lastRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->setAutoFilter('A1:K' . $lastRow);
            $this->styleTableBody($sheet, $headerRange, 'A2:K' . $lastRow);
            $this->emphasizeColumn($sheet, 'D', 2, $lastRow);
        }

        $this->setColumnWidths($sheet, [
            'A' => 10, 'B' => 14, 'C' => 14, 'D' => 14, 'E' => 9,
            'F' => 11, 'G' => 14, 'H' => 12, 'I' => 14, 'J' => 14, 'K' => 14,
        ]);

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

        /* ⚠️⚠️ ร้านที่ยังไม่เคยกรอกอะไรเลย → เว้นเซลล์ว่าง ห้ามเขียน 0
           หน้าจอซ่อนการ์ดทั้งหมดในสถานะนี้ (`annual.php` · `$showEmptyShopInvite`)
           แต่ไฟล์เคยเขียน "กำไรทั้งปี 0 · รายได้ 0 · ค่าแอด 0" คู่กับ "อัตรากำไร –"
           ในแถวเดียวกัน — สองกติกาในบรรทัดเดียว และไม่ตรงกับจอ
           · คนที่ได้ไฟล์ต่อไปอ่านว่า "ทำมาทั้งปีได้ศูนย์" ไม่ใช่ "ยังไม่ได้เริ่ม"
           ⚠️ เกณฑ์คือ "ทั้งปีไม่มีเดือนไหนมีข้อมูลเลย" ไม่ใช่ "กำไรเป็นศูนย์" —
              ปีที่กรอกครบแต่เท่าทุนพอดีต้องยังเขียน 0 เพราะนั่นคือความจริง */
        /* ⚠️⚠️ เกณฑ์คือ "**ร้านนี้ไม่เคยกรอกอะไรเลย**" ไม่ใช่ "ปีที่เลือกไม่มีข้อมูล" —
           หน้าจอแยกสองอย่างนี้ไว้แล้ว (`annual.php` · `$showEmptyShopInvite`)
           · ร้านที่มีข้อมูลปี 2569 แล้วผู้ใช้เลือกดูปี 2568 → **ต้องเห็น ฿0** เพราะนั่นคือ
             คำตอบของคำถามที่เขาถามด้วยการเลือกปีนั้น · ไฟล์เคยเขียนขีด = ไม่ตอบคำถามเลย
           · ผู้เรียกที่ไม่ส่ง `shop_has_ever_recorded` มา ให้ถอยไปใช้เกณฑ์ของปีที่เลือก
             (พฤติกรรมเดิม) เพื่อไม่ให้ชุดทดสอบเก่าเปลี่ยนความหมาย */
        $hasAnyMonthWithData = array_key_exists('shop_has_ever_recorded', $summary)
            ? (bool)$summary['shop_has_ever_recorded']
            : (int)($summary['months_with_data'] ?? 0) > 0;
        $money = static fn(float $value): ?float => $hasAnyMonthWithData ? $value : null;

        // ── การ์ดตัวเลขหลัก ────────────────────────────────────────
        $profit = (float)($summary['profit'] ?? 0);
        $cards = [
            ['A', 'B', 'กำไรทั้งปี', $money($profit), self::MONEY_FORMAT],
            ['C', 'D', 'รายได้', $money((float)($summary['total_revenue'] ?? 0)), self::MONEY_FORMAT],
            ['E', 'F', 'ค่าแอด', $money((float)($summary['total_ad_cost'] ?? 0)), self::MONEY_FORMAT],
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
            // ⚠️ null = เทียบเป็น % ไม่ได้ ไม่ใช่ "ไม่มีข้อมูล" — ปีก่อนที่เท่าทุนพอดี
            // ก็ได้ null เหมือนกัน ต้องบอกให้ตรงกับหน้าเว็บ (ดูคอมเมนต์ใน AnnualService)
            $sheet->setCellValueExplicit(
                'A8',
                $this->describeMissingYoy($summary),
                DataType::TYPE_STRING
            );
        } else {
            $percentValue = (float)$percent;
            $change = (float)($summary['yoy_profit_change'] ?? 0);
            $sheet->setCellValueExplicit(
                'A8',
                $this->withComparisonLengthNote(
                    sprintf(
                        'กำไร %s%s%% (%s%s) · ปีก่อน %s',
                        self::changeArrow($percentValue),
                        number_format(abs($percentValue), 1),
                        $change >= 0 ? '+' : '-',
                        formatMoney(abs($change)),
                        formatMoney((float)($summary['prev_year_profit'] ?? 0))
                    ),
                    $summary
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

        [$bestMonthText, $worstMonthText] = $this->describeMonthExtremes(
            is_array($summary['best_month'] ?? null) ? $summary['best_month'] : null,
            is_array($summary['worst_month'] ?? null) ? $summary['worst_month'] : null
        );

        $facts = [
            ['เดือนกำไรดีสุด', $bestMonthText],
            ['เดือนกำไรแย่สุด', $worstMonthText],
            [
                'เดือนที่มีข้อมูล',
                // นับด้วย helper ตัวเดียวกับหน้าสรุปประจำปี — เดือนที่กำไรเป็น 0 พอดี
                // ไม่เข้าทั้งกำไรและขาดทุน เดิมจึงหายไปจากไฟล์ Excel เฉย ๆ
                (static function (array $counts): string {
                    $text = sprintf(
                        '%d เดือน · กำไร %d / ขาดทุน %d',
                        $counts['with_data'],
                        $counts['profit'],
                        $counts['loss']
                    );

                    return $counts['break_even'] > 0
                        ? $text . sprintf(' · เท่าทุน %d', $counts['break_even'])
                        : $text;
                })(annual_month_outcome_counts($summary)),
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
        $this->writeSectionHeader($sheet, $rowNumber, 'ประมาณการสิ้นปี (ไม่ใช่ตัวเลขจริง)');
        $this->writeProjection($sheet, (array)($summary['projection'] ?? []), $rowNumber + 1);

        // การ์ด 4 ใบ × 2 คอลัมน์ — กว้างพอให้แถบหัวด้านบนดูเต็ม ไม่ใช่แถบสั้น ๆ
        $this->setColumnWidths($sheet, [
            'A' => 22, 'B' => 16, 'C' => 19, 'D' => 16,
            'E' => 19, 'F' => 16, 'G' => 19, 'H' => 16,
        ]);

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

        // หัว 2 ชั้น — ไม่งั้น "ทำได้"/"ถึงเป้า" โผล่อย่างละ 2 ครั้งโดยไม่รู้ว่าของฝั่งไหน
        $groupRow = 3;
        $headerRow = 4;

        $sheet->mergeCells('A' . $groupRow . ':A' . $headerRow);
        $sheet->setCellValueExplicit('A' . $groupRow, 'เดือน', DataType::TYPE_STRING);
        $sheet->mergeCells('B' . $groupRow . ':E' . $groupRow);
        $sheet->setCellValueExplicit('B' . $groupRow, 'รายได้', DataType::TYPE_STRING);
        $sheet->mergeCells('F' . $groupRow . ':I' . $groupRow);
        $sheet->setCellValueExplicit('F' . $groupRow, 'กำไร', DataType::TYPE_STRING);

        $sheet->fromArray(
            ['', 'เป้า', 'ทำได้จริง', 'คิดเป็น', 'สถานะ', 'เป้า', 'ทำได้จริง', 'คิดเป็น', 'สถานะ'],
            null,
            'A' . $headerRow
        );

        $this->styleHeaderRow($sheet, 'A' . $groupRow . ':I' . $groupRow);
        $this->styleHeaderRow($sheet, 'A' . $headerRow . ':I' . $headerRow);
        $sheet->freezePane('A' . ($headerRow + 1));
        $this->repeatHeaderOnPrint($sheet, $groupRow);

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
                'A' . $groupRow . ':I' . $groupRow,
                'A' . ($headerRow + 1) . ':I' . $lastRow
            );
        }

        $this->setColumnWidths($sheet, [
            'A' => 10, 'B' => 14, 'C' => 14, 'D' => 11, 'E' => 13,
            'F' => 14, 'G' => 14, 'H' => 11, 'I' => 13,
        ]);
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
        $this->repeatHeaderOnPrint($sheet, $headerRow);

        $rowNumber = $headerRow + 1;
        /** @var array<int,int> $unfinishedByRow แถว → หมายเลขคอลัมน์ของเดือนที่ยังไม่จบ */
        $unfinishedByRow = [];
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

                // ⚠️⚠️ เดือนที่ยังไม่จบต้องไม่ถูกระบายสีเหมือนเดือนที่จบแล้ว
                //
                // แท็บนี้มีไว้ตอบว่า "เดือนไหนขายดีซ้ำ ๆ ทุกปี" ผู้ใช้จึงอ่านคอลัมน์
                // เดือนเดียวกันข้ามปี · ร้านที่ทำกำไรวันละเท่ากันเป๊ะทุกวันจะเห็น
                // 31,000 → 31,000 → 7,000 (เดือนนี้เพิ่งผ่าน 7 จาก 31 วัน) แล้วสรุปว่า
                // เดือนนี้ตกไป 77% · หน้าเว็บระบายเทาไว้แล้วเพื่อบอกว่า "ยังตัดสินไม่ได้"
                // แต่ไฟล์ยังเขียวเหมือนอีกสองปีทุกอย่าง — กติกาลงถึงหน้าจอแต่ไม่ถึงไฟล์
                if (($cell['is_unfinished'] ?? false) === true) {
                    $unfinishedByRow[$rowNumber] = $month + 1;
                }
            }

            $rowNumber++;
        }

        $lastRow = $rowNumber - 1;
        if ($lastRow >= $headerRow + 1) {
            $dataRange = 'B' . ($headerRow + 1) . ':M' . $lastRow;
            $sheet->getStyle($dataRange)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            // ⚠️⚠️ กฎสีอัตโนมัติต้อง **ไม่ครอบ** ช่องของเดือนที่ยังไม่จบตั้งแต่แรก
            // (ระบายทับทีหลังไม่ได้ผล — ใน Excel กฎอัตโนมัติชนะสีพื้นที่ตั้งไว้ตรง ๆ)
            // จึงใส่กฎทีละแถว และแถวที่มีเดือนยังไม่จบก็ตัดช่องนั้นออกจากช่วง
            $conditionals = $this->buildProfitConditionals();
            for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
                $skipColumn = $unfinishedByRow[$row] ?? null;

                if ($skipColumn === null) {
                    $sheet->setConditionalStyles('B' . $row . ':M' . $row, $conditionals);
                    continue;
                }

                if ($skipColumn > 2) {
                    $sheet->setConditionalStyles(
                        'B' . $row . ':' . Coordinate::stringFromColumnIndex($skipColumn - 1) . $row,
                        $conditionals
                    );
                }
                if ($skipColumn < 13) {
                    $sheet->setConditionalStyles(
                        Coordinate::stringFromColumnIndex($skipColumn + 1) . $row . ':M' . $row,
                        $conditionals
                    );
                }

                // สีกลาง ๆ เหมือนบนหน้าเว็บ = "ยังตัดสินไม่ได้" ไม่ใช่เขียว/แดง
                $sheet->getStyle(Coordinate::stringFromColumnIndex($skipColumn) . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE2E8F0');
            }
            // ไม่ band — กริดนี้ใช้ conditional formatting ระบายสีอยู่แล้ว จะตีกัน
            $this->styleTableBody(
                $sheet,
                'A' . $headerRow . ':M' . $headerRow,
                'A' . ($headerRow + 1) . ':M' . $lastRow,
                false
            );
        }

        $widths = ['A' => 9];
        foreach (range('B', 'M') as $column) {
            $widths[$column] = 12;
        }
        $this->setColumnWidths($sheet, $widths);
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

        // สั่งพิมพ์แล้วต้องอ่านได้ ไม่ใช่ตารางขาดกลางหน้า
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $sheet->getHeaderFooter()->setOddFooter('&L&"Tahoma"&8&A&R&"Tahoma"&8หน้า &P / &N');
    }

    /** ซ้ำแถวหัวตารางทุกหน้าเวลาพิมพ์ — ตารางยาว ๆ จะได้ไม่ต้องเดาว่าคอลัมน์ไหนคืออะไร */
    private function repeatHeaderOnPrint(Worksheet $sheet, int $headerRowNumber): void
    {
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRowNumber, $headerRowNumber);
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
        $this->repeatHeaderOnPrint($sheet, $headerRow);

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
            /* ⚠️⚠️ ร้านที่ยังไม่เคยกรอก (`days_count = 0`) ต้องเว้นว่างทั้งแถว ไม่ใช่ 0/0/0
               หน้าจอแก้ไปแล้ว (`overview.php` แสดงขีดทั้งแถว) แต่ไฟล์ยังเขียนศูนย์
               · ชีตนี้คือตารางที่ใช้ตัดสินว่า "ร้านไหนคุ้ม" — คนอ่านเทียบ "ร้าน C กำไร 0"
                 กับ "ร้าน D ขาดทุน -5,000" แล้วสรุปว่า C ดีกว่า ทั้งที่ C แค่ยังไม่มีข้อมูล
               · คอลัมน์ ROAS/อัตรากำไร/สัดส่วน เว้นว่างถูกอยู่แล้ว — เหลือสามช่องนี้ */
            $shopHasData = (int)($shop['days_count'] ?? 0) > 0;
            if ($shopHasData) {
                $sheet->setCellValue('B' . $rowNumber, (float)($shop['total_revenue'] ?? 0));
                $sheet->setCellValue('C' . $rowNumber, (float)($shop['total_ad_cost'] ?? 0));
                $sheet->setCellValue('D' . $rowNumber, $profit);
            }

            /* ⚠️⚠️ E/F/G ต้องอยู่ใต้ guard เดียวกับ B/C/D — ไม่งั้นแถวเดียวกันใช้กติกาสองแบบ
               · วัดจริง: ร้านที่ไม่เคยกรอก ช่องเงินว่างแล้ว แต่ **สัดส่วนกำไรยังเขียน 0%**
                 (กำไร 0 หารด้วยยอดรวมที่เป็นบวก = 0.0 ซึ่งไม่ใช่ null จึงลอดกิ่งเดิมมาได้)
               · หน้าจอ (`overview.php`) เว้นขีดทั้งแถวอยู่แล้ว */
            if ($shopHasData && isset($shop['roas']) && $shop['roas'] !== null) {
                $sheet->setCellValue('E' . $rowNumber, (float)$shop['roas']);
            }

            if ($shopHasData && isset($shop['profit_margin']) && $shop['profit_margin'] !== null) {
                $sheet->setCellValue('F' . $rowNumber, (float)$shop['profit_margin']);
            }

            if ($shopHasData && $share !== null) {
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

        // ⚠️⚠️ แถวรวมท้ายตาราง — ตารางเดียวกันบนหน้าเว็บมี แต่ไฟล์ไม่มี
        //
        // ผลคือ **ยอดขายรวมและค่าแอดรวมของทุกร้านไม่ปรากฏที่ไหนเลยทั้งไฟล์**
        // (บล็อกสรุปใต้ตารางมีแต่กำไรรวม) ทั้งที่หน้าเว็บแสดงครบทั้ง 5 ค่า
        $totalRow = null;
        if ($rowNumber > $headerRow + 1) {
            $totalRow = $rowNumber;
            $totalRevenue = 0.0;
            $totalAdCost = 0.0;
            $totalDays = 0;
            foreach ($shops as $shop) {
                $totalRevenue += (float)($shop['total_revenue'] ?? 0);
                $totalAdCost += (float)($shop['total_ad_cost'] ?? 0);
                $totalDays = max($totalDays, (int)($shop['days_count'] ?? 0));
            }
            // ⭐ ปัดเป็นสตางค์ก่อนใช้ต่อ — ให้ตรงกับ `SUM()` ของฐานข้อมูลที่หน้าอื่นใช้
            $totalRevenue = money_total($totalRevenue);
            $totalAdCost = money_total($totalAdCost);
            $totalProfit = money_total($totalRevenue - $totalAdCost);

            $sheet->setCellValueExplicit('A' . $totalRow, 'รวมทุกร้าน', DataType::TYPE_STRING);
            /* ⚠️⚠️ ไม่มีร้านไหนกรอกเลยสักร้าน → แถวรวมต้องเว้นว่างเหมือนทุกแถวเหนือมัน
               เดิมเขียน 0/0/0 ทั้งที่แถวร้านทุกแถวว่าง = ตารางเดียวกันใช้กติกาสองแบบ */
            if ($totalDays > 0) {
                $sheet->setCellValue('B' . $totalRow, $totalRevenue);
                $sheet->setCellValue('C' . $totalRow, $totalAdCost);
                $sheet->setCellValue('D' . $totalRow, $totalProfit);
            }
            if ($totalAdCost > 0) {
                $sheet->setCellValue('E' . $totalRow, round($totalRevenue / $totalAdCost, 2));
            }
            if ($totalRevenue > 0) {
                $sheet->setCellValue('F' . $totalRow, round(($totalProfit / $totalRevenue) * 100, 1));
            }
            // ⚠️⚠️ สัดส่วนกำไรของแถวรวม = 100% **เฉพาะเมื่อคำนวณสัดส่วนได้**
            //
            // กำไรรวม ≤ 0 → หารไม่ได้ ทุกแถวจึงเป็นช่องว่าง · หน้าเว็บเว้นว่างตามไปด้วย
            // แต่ไฟล์เคยเขียน 100.0% ตายตัว → แถวรวมบอก 100% ขณะที่ทุกแถวเหนือมันว่าง
            // บวกกันไม่ได้ 100% (วัดจริงตอนทุกร้านขาดทุน: กำไรรวมทั้งปี ฿-32,500)
            $hasProfitShare = false;
            foreach ($shops as $shop) {
                if (($shop['profit_share'] ?? null) !== null) {
                    $hasProfitShare = true;
                    break;
                }
            }
            if ($hasProfitShare) {
                $sheet->setCellValue('G' . $totalRow, 100.0);
            }
            // ⚠️ เขียนเป็นข้อความ "สูงสุด N วัน" เหมือนหน้าเว็บ — ตัวเลขเปล่าในคอลัมน์นี้
            // อ่านเป็นผลบวกของแถวบน ซึ่งบวกไม่ลง (3 ร้าน × 31 วัน ≠ 93 วันในเดือนที่มี 31 วัน)
            $sheet->setCellValueExplicit('H' . $totalRow, 'สูงสุด ' . $totalDays . ' วัน', DataType::TYPE_STRING);
            $sheet->getStyle('A' . $totalRow . ':H' . $totalRow)->getFont()->setBold(true);
            if ($totalProfit < 0) {
                $sheet->getStyle('D' . $totalRow)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
            }
            $rowNumber++;
        }

        $lastShopRow = $rowNumber - 1;
        if ($lastShopRow >= $headerRow + 1) {
            $sheet->getStyle('B' . ($headerRow + 1) . ':D' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle('E' . ($headerRow + 1) . ':E' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::RATIO_FORMAT);
            $sheet->getStyle('F' . ($headerRow + 1) . ':F' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
            $sheet->getStyle('G' . ($headerRow + 1) . ':G' . $lastShopRow)
                ->getNumberFormat()->setFormatCode(self::SHARE_FORMAT);
            // ⚠️ ตัวกรองต้องไม่คลุมแถวรวม ไม่งั้นกรองแล้วแถวรวมหายหรือถูกจัดเรียงปนไปด้วย
            $sheet->setAutoFilter('A' . $headerRow . ':H' . ($totalRow !== null ? $totalRow - 1 : $lastShopRow));
            $this->styleTableBody($sheet, $headerRange, 'A' . ($headerRow + 1) . ':H' . $lastShopRow);
        }

        $this->emphasizeColumn($sheet, 'D', $headerRow + 1, $lastShopRow);
        /* ⚠️ ส่ง "มีร้านไหนกรอกแล้วบ้างไหม" เข้าไปด้วย — บล็อกสรุปต้องเงียบเหมือนตาราง
           ข้างบนเมื่อยังไม่มีใครกรอกอะไรเลย */
        $anyShopHasData = false;
        foreach ($shops as $shop) {
            if ((int)($shop['days_count'] ?? 0) > 0) {
                $anyShopHasData = true;
                break;
            }
        }

        $this->writeComparisonSummary($sheet, $summary, $lastShopRow + 2, $anyShopHasData);

        $this->setColumnWidths($sheet, [
            'A' => 24, 'B' => 15, 'C' => 15, 'D' => 15,
            'E' => 9, 'F' => 12, 'G' => 13, 'H' => 11,
        ]);
    }

    /**
     * แถบสรุปใต้ตาราง — เดือนดี/แย่สุด + YoY รวมร้าน (same-period)
     *
     * @param array<string,mixed> $summary
     * @param bool $anyShopHasData มีร้านไหนกรอกข้อมูลแล้วบ้างไหม (ผู้เรียกคำนวณมาให้)
     */
    private function writeComparisonSummary(
        Worksheet $sheet,
        array $summary,
        int $startRow,
        bool $anyShopHasData = true
    ): void {
        $rowNumber = $startRow;

        $sheet->getStyle('A' . $rowNumber . ':A' . ($rowNumber + 3))->getFont()->setBold(true);

        $sheet->setCellValueExplicit('A' . $rowNumber, 'กำไรรวมทุกร้าน', DataType::TYPE_STRING);
        // ⚠️ กติกาเดียวกับแถวรวม — ไม่มีร้านไหนกรอกเลย = เว้นว่าง ไม่ใช่ ฿0
        if ($anyShopHasData) {
            $sheet->setCellValue('B' . $rowNumber, (float)($summary['profit'] ?? 0));
        }
        $sheet->getStyle('B' . $rowNumber)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        if ((float)($summary['profit'] ?? 0) < 0) {
            $sheet->getStyle('B' . $rowNumber)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
        }
        $rowNumber++;

        // ⚠️ กติกาเดียวกับหน้าจอ — เดือนเดียวกันเป็นทั้งดีสุดและแย่สุดไม่ได้
        [$bestMonthText, $worstMonthText] = $this->describeMonthExtremes(
            is_array($summary['best_month'] ?? null) ? $summary['best_month'] : null,
            is_array($summary['worst_month'] ?? null) ? $summary['worst_month'] : null
        );

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนกำไรดีสุด', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $bestMonthText,
            DataType::TYPE_STRING
        );
        $rowNumber++;

        $sheet->setCellValueExplicit('A' . $rowNumber, 'เดือนกำไรแย่สุด', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $worstMonthText,
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
            // ⚠️ null = เทียบเป็น % ไม่ได้ ไม่ใช่ "ไม่มีข้อมูล" (ดูคอมเมนต์ใน AnnualService)
            $sheet->setCellValueExplicit(
                'B' . $rowNumber,
                $this->describeMissingYoy($summary),
                DataType::TYPE_STRING
            );
            return;
        }

        $percentValue = (float)$percent;
        $change = (float)($summary['yoy_profit_change'] ?? 0);
        $sheet->setCellValueExplicit(
            'B' . $rowNumber,
            $this->withComparisonLengthNote(
                sprintf(
                    '%s%s%% (%s%s) · ปีก่อน %s',
                    self::changeArrow($percentValue),
                    number_format(abs($percentValue), 1),
                    $change >= 0 ? '+' : '-',
                    formatMoney(abs($change)),
                    formatMoney((float)($summary['prev_year_profit'] ?? 0))
                ),
                $summary
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
                formatMoney((float)($projection['projection_low'] ?? 0)),
                formatMoney((float)($projection['projection_high'] ?? 0)),
                formatMoney((float)($projection['projection_mid'] ?? 0))
            ),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A' . $startRow)->getFont()->setItalic(true)->setBold(true)->setSize(12);

        $sheet->mergeCells('A' . ($startRow + 1) . ':H' . ($startRow + 1));
        $sheet->getStyle('A' . ($startRow + 1))->getAlignment()->setIndent(1);
        // ข้อความจาก helper ตัวเดียวกับ annual.php — เดิมคัดลอกไว้แล้วเพี้ยนกันจริง
        $sheet->setCellValueExplicit(
            'A' . ($startRow + 1),
            projection_footnote_text($projection),
            DataType::TYPE_STRING
        );
        $sheet->getStyle('A' . ($startRow + 1))->getFont()->setItalic(true)->setSize(9)
            ->getColor()->setARGB('FF808080');
    }

    /**
     * ตั้งความกว้างคอลัมน์เอง — ไม่ใช้ autoSize เพราะมันไปคิดจากเซลล์ merge หัวเรื่อง
     * แล้วยืดคอลัมน์เดียวจนตารางเบี้ยว
     *
     * @param array<string,int> $widths
     */
    private function setColumnWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /** เน้นคอลัมน์ที่เป็นพระเอกของตาราง (กำไร) ด้วยพื้นอ่อน + ตัวหนา */
    private function emphasizeColumn(Worksheet $sheet, string $column, int $firstRow, int $lastRow): void
    {
        if ($lastRow < $firstRow) {
            return;
        }

        $range = $column . $firstRow . ':' . $column . $lastRow;
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::EMPHASIS_FILL_ARGB);
        $sheet->getStyle($range)->getFont()->setBold(true);
    }

    /** ทาแดงเฉพาะค่าติดลบ — ใช้ร่วมทุกชีตให้สัญญาณสีสม่ำเสมอ */
    private function paintNegative(Worksheet $sheet, string $coordinate, float $value): void
    {
        if ($value < 0) {
            $sheet->getStyle($coordinate)->getFont()->getColor()->setARGB(self::NEGATIVE_FONT_ARGB);
        }
    }

    /**
     * ⚠️⚠️ ทำไม % เทียบปีก่อนถึงไม่มี — มี 3 สาเหตุที่ต้องบอกคนละอย่าง
     *
     * ไฟล์ต้องพูดตรงกับหน้าจอ · เดิมไฟล์รู้จักแค่ 2 สาเหตุ (มีข้อมูล/ไม่มีข้อมูล)
     * ส่วน "อ่านข้อมูลปีก่อนไม่สำเร็จ" ถูกยุบไปรวมกับ "ไม่มีข้อมูล" ทั้งที่หน้าเว็บ
     * แยกไว้แล้ว — คนเปิดไฟล์จะเข้าใจว่าปีก่อนไม่มีข้อมูลจริง ๆ
     *
     * @param array<string,mixed> $summary
     */
    /**
     * ⚠️⚠️ ต่อท้ายข้อความเทียบปีก่อนด้วยความยาวของสองฝั่ง เมื่อยาวไม่เท่ากัน
     *
     * [เจ้าของระบบตัดสิน 2026-08-11] ปีอธิกสุรทินมี 366 วัน ปีก่อนหน้ามี 365
     * ตัวเลข % ไม่ได้ผิด แต่คำว่า "ช่วงเดียวกัน" ทำให้อ่านว่าเทียบกันได้ตรง ๆ
     * · ไฟล์ต้องพูดเหมือนหน้าจอ — กติกาอยู่ที่ `comparison_length_note()` ที่เดียว
     *
     * @param array<string,mixed> $summary
     */
    private function withComparisonLengthNote(string $text, array $summary): string
    {
        $note = comparison_length_note(
            isset($summary['compared_days']) ? (int)$summary['compared_days'] : null,
            isset($summary['prev_compared_days']) ? (int)$summary['prev_compared_days'] : null
        );

        return $note === null ? $text : $text . ' · ' . $note;
    }

    private function describeMissingYoy(array $summary): string
    {
        if (($summary['prev_year_unavailable'] ?? false) === true) {
            return 'โหลดข้อมูลปีก่อนไม่สำเร็จ — เทียบให้ไม่ได้ตอนนี้';
        }

        return ($summary['prev_year_has_data'] ?? false) === true
            ? 'ปีก่อนเท่าทุนพอดี เทียบเป็น % ไม่ได้'
            : 'ไม่มีข้อมูลปีก่อน';
    }

    /**
     * ⚠️⚠️ ไฟล์ต้องใช้กติกาเดียวกับหน้าจอ — `extremes_are_comparable()`
     *
     * วัดจริง: ร้านที่มีเดือนที่จบแล้วเดือนเดียว หน้าเว็บขึ้น "ยังเทียบไม่ได้" ทั้งสองการ์ด
     * แต่ไฟล์ Excel เขียน "ก.ค. (฿30,000)" เป็นทั้งเดือนดีสุดและแย่สุดของปีเดียวกัน
     * (เกิดกับทุกร้านใน 1–2 เดือนแรกที่เริ่มใช้ระบบ)
     *
     * @param array<string,mixed>|null $best
     * @param array<string,mixed>|null $worst
     * @return array{0:string,1:string} [ข้อความดีสุด, ข้อความแย่สุด]
     */
    private function describeMonthExtremes(?array $best, ?array $worst): array
    {
        if (!extremes_are_comparable($best, $worst, 'month')) {
            $text = ($best === null && $worst === null)
                ? '–'
                : extremes_not_comparable_text();

            return [$text, $text];
        }

        return [$this->describeMonth($best), $this->describeMonth($worst)];
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

        // ⚠️ ช่องที่เป็น "ประโยค" ต้องใช้ `formatMoney()` เหมือนหน้าเว็บ — ไม่งั้นในแผ่น
        // เดียวกันจะมี "฿219,000.00" (ช่องตัวเลข) อยู่ไม่กี่บรรทัดเหนือ "219,000.00"
        // (ช่องข้อความ) ซึ่งไม่มี ฿ และบังคับ 2 ตำแหน่งเสมอ ต่างจากทั้งไฟล์และหน้าจอ
        return sprintf('%s (%s)', $label, formatMoney((float)($month['profit'] ?? 0)));
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
            [(new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $quotedTitle . '!$D$2:$D$' . $lastRow,
                null,
                $pointCount
            ))->setFillColor(self::CHART_PROFIT_ARGB)]
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
            [(new DataSeriesValues(
                DataSeriesValues::DATASERIES_TYPE_NUMBER,
                $quotedTitle . '!$I$2:$I$' . $lastRow,
                null,
                $pointCount
            ))->setFillColor(self::CHART_PREV_ARGB)->setLineWidth(20000)]
        );

        $chart = new Chart(
            'profit-by-month',
            new Title('กำไรรายเดือน (แท่ง = ปีนี้ · เส้น = ปีก่อน)'),
            null,
            new PlotArea(null, [$bars, $prevLine])
        );
        // ไม่มีกรอบ/มุมมน — ให้กลืนกับพื้นรายงาน
        $chart->setRoundedCorners(false);
        $chart->getBorderLines()->setLineColorProperties('FFFFFF');
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
                (new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    $quotedTitle . '!$J$2:$J$' . $lastRow,
                    null,
                    $pointCount
                ))->setFillColor(self::CHART_CUMULATIVE_ARGB)->setLineWidth(24000),
                (new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    $quotedTitle . '!$K$2:$K$' . $lastRow,
                    null,
                    $pointCount
                ))->setFillColor(self::CHART_PREV_ARGB)->setLineWidth(20000),
            ]
        );

        $chart = new Chart(
            'cumulative-profit',
            new Title('กำไรสะสม ปีนี้ vs ปีก่อน (ช่วงเดียวกัน)'),
            null,
            new PlotArea(null, [$series])
        );
        // ไม่มีกรอบ/มุมมน — ให้กลืนกับพื้นรายงาน
        $chart->setRoundedCorners(false);
        $chart->getBorderLines()->setLineColorProperties('FFFFFF');
        $chart->setTopLeftPosition('M22');
        $chart->setBottomRightPosition('U40');

        return $chart;
    }


    /**
     * ลูกศรของป้ายเปลี่ยนแปลง — ต้องตัดสินจาก **ค่าที่ปัดแล้ว** เหมือนหน้าเว็บ
     *
     * ⚠️ เดิมตัดสินจากค่าดิบ แล้วค่อยปัดตอนพิมพ์ตัวเลข ผลคือลูกศรขัดกับเลขที่เห็น:
     *   % จริง +0.04 → หน้าเว็บ "0.0%" (เทา ไม่มีลูกศร) · Excel "↑0.0%"
     * `format_change_badge()` ถูกสร้างมาปิดความไม่ตรงกันแบบนี้พอดี แต่ไฟล์ Excel
     * เขียนลูกศรเอง จึงหลุดออกไปอยู่ในไฟล์ที่ผู้ใช้เปิดวางข้างหน้าจอ
     */
    private static function changeArrow(float $percent): string
    {
        return match (format_change_badge($percent)['direction']) {
            1 => '↑',
            -1 => '↓',
            default => '',
        };
    }
}
