<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use XlsxReportService;
use ZipArchive;

/**
 * unit test ของเฟส 5B — sheet "เป้าหมาย" และ sheet "ฤดูกาล" (+ conditional formatting)
 */
final class XlsxReportServiceGoalSeasonTest extends TestCase
{
    private const GOAL_GROUP_ROW = 3;
    private const GOAL_HEADER_ROW = 4;
    private const GOAL_FIRST_ROW = 5;
    private const SEASON_HEADER_ROW = 4;
    private const SEASON_FIRST_ROW = 5;

    private function emptySpreadsheet(): Spreadsheet
    {
        return (new XlsxReportService())->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $goalProgress
     */
    private function goalSheet(array $goalProgress): Worksheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptySpreadsheet();
        $service->buildGoalSheet($spreadsheet, $goalProgress, 2026);

        $sheet = $spreadsheet->getSheetByName('เป้าหมาย');
        $this->assertNotNull($sheet);

        return $sheet;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function seasonSheet(array $payload): Worksheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptySpreadsheet();
        $service->buildSeasonSheet($spreadsheet, $payload);

        $sheet = $spreadsheet->getSheetByName('ฤดูกาล');
        $this->assertNotNull($sheet);

        return $sheet;
    }

    /**
     * @return array<string,mixed>
     */
    private function seasonPayload(): array
    {
        return [
            'years' => [2024, 2025, 2026],
            'grid' => [
                2024 => [
                    3 => ['month' => 3, 'profit' => 4000.0, 'has_data' => true],
                    9 => ['month' => 9, 'profit' => -1500.0, 'has_data' => true],
                ],
                2025 => [
                    3 => ['month' => 3, 'profit' => 6000.0, 'has_data' => true],
                    5 => ['month' => 5, 'profit' => 0.0, 'has_data' => true],
                ],
                2026 => [
                    3 => ['month' => 3, 'profit' => 7500.0, 'has_data' => true],
                ],
            ],
        ];
    }

    public function testGoalRowsShowBothTargetsWithProgress(): void
    {
        $sheet = $this->goalSheet([
            [
                'month' => 1,
                'target_revenue' => 10000.0, 'target_profit' => 4000.0,
                'actual_revenue' => 9000.0, 'actual_profit' => 6000.0,
                'revenue_progress' => 90.0, 'profit_progress' => 150.0,
                'revenue_reached' => false, 'profit_reached' => true,
            ],
        ]);

        $row = self::GOAL_FIRST_ROW;
        $this->assertSame('ม.ค.', $sheet->getCell('A' . $row)->getValue());
        $this->assertSame(10000.0, $sheet->getCell('B' . $row)->getValue());
        $this->assertSame(9000.0, $sheet->getCell('C' . $row)->getValue());
        $this->assertSame(90.0, $sheet->getCell('D' . $row)->getValue());
        $this->assertSame('ยังไม่ถึง', $sheet->getCell('E' . $row)->getValue());

        $this->assertSame(4000.0, $sheet->getCell('F' . $row)->getValue());
        $this->assertSame(6000.0, $sheet->getCell('G' . $row)->getValue());
        $this->assertSame(150.0, $sheet->getCell('H' . $row)->getValue());
        $this->assertSame('✓ ถึงเป้า', $sheet->getCell('I' . $row)->getValue());
    }

    public function testReachedAndPendingUseDifferentColors(): void
    {
        $sheet = $this->goalSheet([
            [
                'month' => 1,
                'target_revenue' => 10000.0, 'target_profit' => 4000.0,
                'actual_revenue' => 9000.0, 'actual_profit' => 6000.0,
                'revenue_progress' => 90.0, 'profit_progress' => 150.0,
                'revenue_reached' => false, 'profit_reached' => true,
            ],
        ]);

        $row = self::GOAL_FIRST_ROW;
        $this->assertSame('FFB45309', $sheet->getStyle('E' . $row)->getFont()->getColor()->getARGB());
        $this->assertSame('FF107C41', $sheet->getStyle('I' . $row)->getFont()->getColor()->getARGB());
    }

    public function testRevenueOnlyGoalLeavesProfitColumnsEmpty(): void
    {
        $sheet = $this->goalSheet([
            [
                'month' => 2,
                'target_revenue' => 8000.0, 'target_profit' => null,
                'actual_revenue' => 9000.0, 'actual_profit' => 5000.0,
                'revenue_progress' => 112.5, 'profit_progress' => null,
                'revenue_reached' => true, 'profit_reached' => null,
            ],
        ]);

        $row = self::GOAL_FIRST_ROW;
        $this->assertSame(112.5, $sheet->getCell('D' . $row)->getValue());
        $this->assertSame('✓ ถึงเป้า', $sheet->getCell('E' . $row)->getValue());

        // ไม่ได้ตั้งเป้ากำไร → เว้นว่างทั้งชุด ไม่ใช่ 0 ที่อ่านว่า "ตั้งเป้าไว้ศูนย์"
        $this->assertNull($sheet->getCell('F' . $row)->getValue());
        $this->assertNull($sheet->getCell('G' . $row)->getValue());
        $this->assertNull($sheet->getCell('H' . $row)->getValue());
        $this->assertNull($sheet->getCell('I' . $row)->getValue());
    }

    public function testNegativeActualProfitIsRed(): void
    {
        $sheet = $this->goalSheet([
            [
                'month' => 7,
                'target_revenue' => null, 'target_profit' => 3000.0,
                'actual_revenue' => 1000.0, 'actual_profit' => -2500.0,
                'revenue_progress' => null, 'profit_progress' => -83.3,
                'revenue_reached' => null, 'profit_reached' => false,
            ],
        ]);

        $row = self::GOAL_FIRST_ROW;
        $this->assertNull($sheet->getCell('B' . $row)->getValue());
        $this->assertSame(-2500.0, $sheet->getCell('G' . $row)->getValue());
        $this->assertSame('FFC00000', $sheet->getStyle('G' . $row)->getFont()->getColor()->getARGB());
        $this->assertSame('FFC00000', $sheet->getStyle('H' . $row)->getFont()->getColor()->getARGB());
    }

    public function testGoalSheetHasHeaderFilterAndFormats(): void
    {
        $sheet = $this->goalSheet([
            [
                'month' => 1, 'target_revenue' => 100.0, 'target_profit' => 50.0,
                'actual_revenue' => 90.0, 'actual_profit' => 60.0,
                'revenue_progress' => 90.0, 'profit_progress' => 120.0,
                'revenue_reached' => false, 'profit_reached' => true,
            ],
        ]);

        // หัวชั้นบน = กลุ่ม · ชั้นล่าง = ชื่อคอลัมน์ (แก้ปัญหา "ทำได้/ถึงเป้า" ซ้ำโดยไม่รู้ฝั่ง)
        $this->assertSame('เดือน', $sheet->getCell('A' . self::GOAL_GROUP_ROW)->getValue());
        $this->assertSame('รายได้', $sheet->getCell('B' . self::GOAL_GROUP_ROW)->getValue());
        $this->assertSame('กำไร', $sheet->getCell('F' . self::GOAL_GROUP_ROW)->getValue());
        $this->assertContains('B3:E3', array_keys($sheet->getMergeCells()));
        $this->assertContains('F3:I3', array_keys($sheet->getMergeCells()));

        $this->assertSame('เป้า', $sheet->getCell('B' . self::GOAL_HEADER_ROW)->getValue());
        $this->assertSame('ทำได้จริง', $sheet->getCell('C' . self::GOAL_HEADER_ROW)->getValue());
        $this->assertSame('คิดเป็น', $sheet->getCell('D' . self::GOAL_HEADER_ROW)->getValue());
        $this->assertSame('สถานะ', $sheet->getCell('E' . self::GOAL_HEADER_ROW)->getValue());
        $this->assertTrue($sheet->getStyle('A' . self::GOAL_GROUP_ROW)->getFont()->getBold());

        $this->assertSame('A4:I5', $sheet->getAutoFilter()->getRange());
        $this->assertSame('"฿"#,##0.00', $sheet->getStyle('B5')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0"%"', $sheet->getStyle('D5')->getNumberFormat()->getFormatCode());
    }

    public function testSeasonGridPlacesProfitByYearAndMonth(): void
    {
        $sheet = $this->seasonSheet($this->seasonPayload());

        $this->assertSame('ปี', $sheet->getCell('A' . self::SEASON_HEADER_ROW)->getValue());
        $this->assertSame('ม.ค.', $sheet->getCell('B' . self::SEASON_HEADER_ROW)->getValue());
        $this->assertSame('ธ.ค.', $sheet->getCell('M' . self::SEASON_HEADER_ROW)->getValue());

        // ปีเป็น พ.ศ. · มี.ค. = คอลัมน์ D (เดือน 3 → index 4)
        $this->assertSame('2567', $sheet->getCell('A5')->getValue());
        $this->assertSame(4000.0, $sheet->getCell('D5')->getValue());
        $this->assertSame('2568', $sheet->getCell('A6')->getValue());
        $this->assertSame(6000.0, $sheet->getCell('D6')->getValue());
        $this->assertSame('2569', $sheet->getCell('A7')->getValue());
        $this->assertSame(7500.0, $sheet->getCell('D7')->getValue());

        $this->assertSame(-1500.0, $sheet->getCell('J5')->getValue());   // ก.ย. 2567
    }

    public function testBreakEvenIsZeroButNoDataIsBlank(): void
    {
        $sheet = $this->seasonSheet($this->seasonPayload());

        // พ.ค. 2568 เท่าทุน → 0.0 (มีค่า) · พ.ค. ปีอื่นไม่มีข้อมูล → ว่าง
        $this->assertSame(0.0, $sheet->getCell('F6')->getValue());
        $this->assertNull($sheet->getCell('F5')->getValue());
        $this->assertNull($sheet->getCell('F7')->getValue());
        $this->assertNull($sheet->getCell('B5')->getValue());
    }

    public function testConditionalFormattingCoversTheGrid(): void
    {
        $sheet = $this->seasonSheet($this->seasonPayload());

        // ⚠️ ตรวจ "ทุกแถวของกริดมีกฎสี" ไม่ใช่ "กฎอยู่ในช่วงชื่อนี้" — ช่วงถูกแบ่งเป็น
        // รายแถว (และตัดช่องของเดือนที่ยังไม่จบออก) การล็อกชื่อช่วงจึงล็อกวิธีเขียนโค้ด
        // ไม่ใช่พฤติกรรมที่ผู้ใช้เห็น
        $conditionals = [];
        foreach ([5, 6, 7] as $row) {
            $rowConditionals = $sheet->getConditionalStyles('B' . $row . ':M' . $row);
            $this->assertCount(2, $rowConditionals, 'แถวที่ ' . $row . ' ไม่มีกฎสี');
            $conditionals = $rowConditionals;
        }

        $operators = array_map(
            static fn(Conditional $c): string => $c->getOperatorType(),
            $conditionals
        );
        $this->assertSame(
            [Conditional::OPERATOR_GREATERTHAN, Conditional::OPERATOR_LESSTHAN],
            $operators
        );

        foreach ($conditionals as $conditional) {
            $this->assertSame(Conditional::CONDITION_CELLIS, $conditional->getConditionType());
            $this->assertSame(['0'], $conditional->getConditions());
        }

        $this->assertSame(
            'FFC6EFCE',
            $conditionals[0]->getStyle()->getFill()->getStartColor()->getARGB()
        );
        $this->assertSame(
            'FFFFC7CE',
            $conditionals[1]->getStyle()->getFill()->getStartColor()->getARGB()
        );
    }

    public function testConditionalFormattingSurvivesToTheSavedFile(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptySpreadsheet();
        $service->buildSeasonSheet($spreadsheet, $this->seasonPayload());

        $file = tempnam(sys_get_temp_dir(), 'xlsx-season-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $xml = (string)$zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        unlink($file);

        // เขียนลงไฟล์จริงแล้วต้องมี conditionalFormatting — ไม่ใช่แค่อยู่ในหน่วยความจำ
        // ⚠️ ตรวจว่าทุกแถวของกริดถูกครอบ ไม่ใช่ล็อกว่าเป็นช่วงเดียวชื่อ B5:M7
        foreach ([5, 6, 7] as $row) {
            $this->assertStringContainsString(
                '<conditionalFormatting sqref="B' . $row . ':M' . $row . '">',
                $xml,
                'แถวที่ ' . $row . ' ไม่มีกฎสีในไฟล์ที่บันทึกจริง'
            );
        }
        $this->assertStringContainsString('operator="greaterThan"', $xml);
        $this->assertStringContainsString('operator="lessThan"', $xml);
    }

    public function testEmptySeasonGridSkipsConditionalFormatting(): void
    {
        $sheet = $this->seasonSheet(['years' => [], 'grid' => []]);

        $this->assertSame([], $sheet->getConditionalStyles('B5:M7'));
        $this->assertSame('ฤดูกาลกำไร (3 ปี)', $sheet->getCell('A1')->getValue());
    }

    public function testFullWorkbookTabOrderWithGoalAndSeason(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptySpreadsheet();
        $service->buildMonthlySheet($spreadsheet, [
            'months' => [
                ['month' => 1, 'total_revenue' => 100.0, 'total_ad_cost' => 10.0, 'profit' => 90.0, 'roas' => 10.0, 'days_count' => 1],
            ],
        ]);
        $service->buildGoalSheet($spreadsheet, [
            [
                'month' => 1, 'target_revenue' => 100.0, 'target_profit' => null,
                'actual_revenue' => 90.0, 'actual_profit' => 60.0,
                'revenue_progress' => 90.0, 'profit_progress' => null,
                'revenue_reached' => false, 'profit_reached' => null,
            ],
        ], 2026);
        $service->buildSeasonSheet($spreadsheet, $this->seasonPayload());
        $service->buildShopComparisonSheet($spreadsheet, [
            'year' => 2026,
            'shops' => [['shop_name' => 'ร้าน A', 'profit' => 90.0, 'days_count' => 1]],
            'summary' => ['profit' => 90.0, 'prev_year' => 2025, 'yoy_profit_change_percent' => null],
        ]);
        $service->buildAnnualSheet($spreadsheet, [
            'profit' => 90.0, 'prev_year' => 2025, 'yoy_profit_change_percent' => null,
            'projection' => ['available' => false],
        ], 2026, 'ร้าน A');

        $this->assertSame(
            ['รายปี', 'รายเดือน', 'รายวัน', 'เป้าหมาย', 'ฤดูกาล', 'เทียบร้าน'],
            $spreadsheet->getSheetNames()
        );
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());

        $spreadsheet->disconnectWorksheets();
    }

    /**
     * ⭐⭐ แท็บ "ฤดูกาล" ต้องไม่ระบายสีเดือนที่ยังไม่จบ เหมือนที่หน้าเว็บไม่ระบาย
     *
     * ⚠️ แท็บนี้มีไว้ตอบว่า "เดือนไหนขายดีซ้ำ ๆ ทุกปี" ผู้ใช้จึงอ่านคอลัมน์เดือนเดียวกัน
     * ข้ามปี · ร้านที่ทำกำไรวันละเท่ากันเป๊ะทุกวันจะเห็น 31,000 → 31,000 → 7,000
     * (เดือนนี้เพิ่งผ่าน 7 จาก 31 วัน) แล้วสรุปว่าเดือนนี้ตกไป 77%
     *
     * ⚠️⚠️ คอมมิตที่แก้เรื่องนี้บนหน้าเว็บ (`annual.php` + `AnnualService`) **ไม่ได้แตะ
     * ไฟล์ Excel** — กติกาลงถึงหน้าจอแต่ไม่ถึงไฟล์ที่ผู้ใช้ดาวน์โหลดไปเปิดดู
     * เป็นรูปแบบเดิมที่เจอซ้ำ ๆ: กติกาถูกบังคับใช้แค่บางที่
     */
    public function testTheSeasonSheetDoesNotColourTheUnfinishedMonth(): void
    {
        $grid = [];
        $years = [2024, 2025, 2026];
        foreach ($years as $year) {
            for ($month = 1; $month <= 12; $month++) {
                $isFuture = $year === 2026 && $month > 8;
                $isUnfinished = $year === 2026 && $month === 8;
                $grid[$year][$month] = [
                    'month' => $month,
                    'profit' => $isFuture ? null : ($isUnfinished ? 7000.0 : 31000.0),
                    'has_data' => !$isFuture,
                    'is_unfinished' => $isUnfinished,
                ];
            }
        }

        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildSeasonSheet($spreadsheet, ['years' => $years, 'grid' => $grid, 'comparable' => true]);

        $sheet = $spreadsheet->getSheetByName('ฤดูกาล');
        $this->assertNotNull($sheet);

        // หาแถวของแต่ละปีจากคอลัมน์ A (ปี พ.ศ.)
        $rowByYear = [];
        foreach ($sheet->getRowIterator() as $row) {
            $label = trim((string)$sheet->getCell('A' . $row->getRowIndex())->getValue());
            if ($label !== '' && ctype_digit($label)) {
                $rowByYear[(int)$label - 543] = $row->getRowIndex();
            }
        }

        $this->assertArrayHasKey(2026, $rowByYear, 'ไม่มีแถวของปีปัจจุบันในแท็บฤดูกาล');

        // คอลัมน์ I = เดือนที่ 8
        $this->assertCount(
            0,
            $sheet->getConditionalStyles('I' . $rowByYear[2026]),
            'ช่องของเดือนที่ยังไม่จบยังถูกระบายสีเทียบกับปีอื่น'
        );
        $this->assertNotCount(
            0,
            $sheet->getConditionalStyles('I' . $rowByYear[2025]),
            'เดือนที่จบแล้วกลับไม่ถูกระบายสี — ตัดออกเกินไป'
        );

        $spreadsheet->disconnectWorksheets();
    }
}
