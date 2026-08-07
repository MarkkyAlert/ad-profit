<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use XlsxReportService;

/**
 * unit test ของ sheet "รายปี" (สรุป + YoY ร้านเดี่ยว) และลำดับ tab สุดท้ายของ workbook
 */
final class XlsxReportServiceAnnualTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function summary(array $overrides = []): array
    {
        return array_merge([
            'total_revenue' => 36000.0,
            'total_ad_cost' => 26500.0,
            'profit' => 9500.0,
            'roas' => 1.36,
            'profit_margin' => 26.4,
            'months_with_data' => 4,
            'profit_months' => 3,
            'loss_months' => 1,
            'best_month' => ['month' => 1, 'profit' => 7000.0],
            'worst_month' => ['month' => 7, 'profit' => -2500.0],
            'prev_year' => 2025,
            'prev_year_profit' => 2000.0,
            'yoy_profit_change' => 7500.0,
            'yoy_profit_change_percent' => 375.0,
        ], $overrides);
    }

    private function emptyDailySpreadsheet(): Spreadsheet
    {
        return (new XlsxReportService())->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function buildSheet(array $summary, string $shopName = 'ร้านคอร์สออนไลน์'): Worksheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptyDailySpreadsheet();
        $service->buildAnnualSheet($spreadsheet, $summary, 2026, $shopName);

        $sheet = $spreadsheet->getSheetByName('รายปี');
        $this->assertNotNull($sheet);

        return $sheet;
    }

    public function testTitleAndYearTotals(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertSame('สรุปรายปี 2569', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString('ร้านคอร์สออนไลน์', (string)$sheet->getCell('A2')->getValue());

        // การ์ด 4 ใบ: label แถว 4 · ค่าแถว 5
        $this->assertSame('กำไรทั้งปี', $sheet->getCell('A4')->getValue());
        $this->assertSame(9500.0, $sheet->getCell('A5')->getValue());
        $this->assertSame('รายได้', $sheet->getCell('C4')->getValue());
        $this->assertSame(36000.0, $sheet->getCell('C5')->getValue());
        $this->assertSame('ค่าแอด', $sheet->getCell('E4')->getValue());
        $this->assertSame(26500.0, $sheet->getCell('E5')->getValue());
        $this->assertSame('อัตรากำไร', $sheet->getCell('G4')->getValue());
        $this->assertSame(26.4, $sheet->getCell('G5')->getValue());

        $this->assertSame('"฿"#,##0.00', $sheet->getStyle('A5')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0"%"', $sheet->getStyle('G5')->getNumberFormat()->getFormatCode());
        // ตัวเลขการ์ดต้องเด่นกว่าเนื้อความทั่วไป
        $this->assertSame(16.0, $sheet->getStyle('A5')->getFont()->getSize());
    }

    public function testNegativeYearProfitIsRed(): void
    {
        $sheet = $this->buildSheet($this->summary(['profit' => -3000.0]));

        $this->assertSame(-3000.0, $sheet->getCell('A5')->getValue());
        $this->assertSame('FFC00000', $sheet->getStyle('A5')->getFont()->getColor()->getARGB());
    }

    public function testNullRoasAndMarginShowDash(): void
    {
        $sheet = $this->buildSheet($this->summary(['profit_margin' => null]));

        // ไม่มีอัตรากำไร → การ์ดโชว์ขีด ไม่ใช่ 0
        $this->assertSame('–', $sheet->getCell('G5')->getValue());
    }

    public function testPositiveYoyIsGreen(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertSame('เทียบ 2568 (ช่วงเดียวกัน)', $sheet->getCell('A7')->getValue());
        $this->assertSame(
            'กำไร ↑375.0% (+฿7,500) · ปีก่อน ฿2,000',
            $sheet->getCell('A8')->getValue()
        );
        $this->assertSame('FF107C41', $sheet->getStyle('A8')->getFont()->getColor()->getARGB());
    }

    public function testNegativeYoyIsRed(): void
    {
        $sheet = $this->buildSheet($this->summary([
            'prev_year_profit' => 20000.0,
            'yoy_profit_change' => -10500.0,
            'yoy_profit_change_percent' => -52.5,
        ]));

        $this->assertSame(
            'กำไร ↓52.5% (-฿10,500) · ปีก่อน ฿20,000',
            $sheet->getCell('A8')->getValue()
        );
        $this->assertSame('FFC00000', $sheet->getStyle('A8')->getFont()->getColor()->getARGB());
    }

    public function testNullYoySaysNoPreviousYear(): void
    {
        $sheet = $this->buildSheet($this->summary(['yoy_profit_change_percent' => null]));

        $this->assertSame('ไม่มีข้อมูลปีก่อน', $sheet->getCell('A8')->getValue());
    }

    public function testBestWorstMonthAndCounts(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertSame('เดือนที่โดดเด่น', $sheet->getCell('A10')->getValue());
        $this->assertSame('เดือนกำไรดีสุด', $sheet->getCell('A11')->getValue());
        $this->assertSame('ม.ค. (฿7,000)', $sheet->getCell('C11')->getValue());
        $this->assertSame('เดือนกำไรแย่สุด', $sheet->getCell('A12')->getValue());
        $this->assertSame('ก.ค. (฿-2,500)', $sheet->getCell('C12')->getValue());
        $this->assertSame('เดือนที่มีข้อมูล', $sheet->getCell('A13')->getValue());
        $this->assertSame('4 เดือน · กำไร 3 / ขาดทุน 1', $sheet->getCell('C13')->getValue());
    }

    public function testMissingBestWorstShowDash(): void
    {
        $sheet = $this->buildSheet($this->summary([
            'best_month' => null,
            'worst_month' => null,
            'months_with_data' => 0,
            'profit_months' => 0,
            'loss_months' => 0,
        ]));

        $this->assertSame('–', $sheet->getCell('C11')->getValue());
        $this->assertSame('–', $sheet->getCell('C12')->getValue());
        $this->assertSame('0 เดือน · กำไร 0 / ขาดทุน 0', $sheet->getCell('C13')->getValue());
    }

    public function testCoverHeaderIsBrandedAndDated(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptyDailySpreadsheet();
        $service->buildAnnualSheet($spreadsheet, $this->summary(), 2026, 'ร้านคอร์ส', '2026-08-03');

        $sheet = $spreadsheet->getSheetByName('รายปี');
        $this->assertNotNull($sheet);

        // แถบหัวเต็มความกว้าง พื้นน้ำเงินเข้ม ตัวอักษรขาว
        $this->assertContains('A1:H1', array_keys($sheet->getMergeCells()));
        $this->assertSame(20.0, $sheet->getStyle('A1')->getFont()->getSize());
        $this->assertSame('FFFFFFFF', $sheet->getStyle('A1')->getFont()->getColor()->getARGB());
        $this->assertSame('FF1F4E79', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertSame(38.0, $sheet->getRowDimension(1)->getRowHeight());

        // บรรทัดรอง: ชื่อร้าน + วันที่ออกรายงาน (seam ส่งเข้ามาได้)
        $this->assertSame('ร้านคอร์ส · ออกรายงาน 2026-08-03', $sheet->getCell('A2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function testGeneratedDateFallsBackToToday(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertStringContainsString(date('Y-m-d'), (string)$sheet->getCell('A2')->getValue());
    }

    public function testKpiCardsHaveFillAndBorder(): void
    {
        $sheet = $this->buildSheet($this->summary());

        foreach (['A', 'C', 'E', 'G'] as $column) {
            $this->assertSame(
                'FFF4F7FB',
                $sheet->getStyle($column . '5')->getFill()->getStartColor()->getARGB(),
                'การ์ด ' . $column . ' ต้องมีพื้นหลัง'
            );
            $this->assertSame(
                Border::BORDER_THIN,
                $sheet->getStyle($column . '5')->getBorders()->getTop()->getBorderStyle()
            );
            $this->assertSame(
                'center',
                $sheet->getStyle($column . '5')->getAlignment()->getHorizontal()
            );
        }
    }

    public function testSectionHeadersAreUnderlinedAndColored(): void
    {
        $sheet = $this->buildSheet($this->summary());

        foreach ([7, 10, 15] as $row) {
            $this->assertSame(
                'FF1F4E79',
                $sheet->getStyle('A' . $row)->getFont()->getColor()->getARGB(),
                'หัวข้อบล็อกแถว ' . $row
            );
            $this->assertTrue($sheet->getStyle('A' . $row)->getFont()->getBold());
            $this->assertSame(
                Border::BORDER_THIN,
                $sheet->getStyle('A' . $row)->getBorders()->getBottom()->getBorderStyle()
            );
        }
    }

    public function testAnnualSheetHasNoChart(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertSame(0, $sheet->getChartCount());
    }

    public function testFinalTabOrderPutsAnnualFirstWithPortfolio(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptyDailySpreadsheet();
        $service->buildMonthlySheet($spreadsheet, [
            'year' => 2026,
            'last_month' => 1,
            'months' => [
                ['month' => 1, 'month_label' => 'ม.ค.', 'revenue' => 100.0, 'ad_cost' => 10.0, 'profit' => 90.0, 'roas' => 10.0],
            ],
        ]);
        $service->buildShopComparisonSheet($spreadsheet, [
            'year' => 2026,
            'shops' => [['shop_name' => 'ร้าน A', 'profit' => 90.0, 'days_count' => 1]],
            'summary' => ['profit' => 90.0, 'prev_year' => 2025, 'yoy_profit_change_percent' => null],
        ]);
        $service->buildAnnualSheet($spreadsheet, $this->summary(), 2026, 'ร้าน A');

        $this->assertSame(
            ['รายปี', 'รายเดือน', 'รายวัน', 'เทียบร้าน'],
            $spreadsheet->getSheetNames()
        );
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());
        $this->assertSame('รายปี', $spreadsheet->getActiveSheet()->getTitle());

        // เฟส 2 ไม่ถูกกระทบ — กราฟรายเดือนยังอยู่
        $monthly = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($monthly);
        // เฟส 5C เพิ่มกราฟสะสม → ชีตรายเดือนมี 2 กราฟ
        $this->assertSame(2, $monthly->getChartCount());

        $spreadsheet->disconnectWorksheets();
    }

    public function testFinalTabOrderWithoutPortfolio(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $this->emptyDailySpreadsheet();
        $service->buildMonthlySheet($spreadsheet, ['year' => 2026, 'last_month' => 0, 'months' => []]);
        $service->buildAnnualSheet($spreadsheet, $this->summary(), 2026, 'ร้านเดียว');

        // ร้านเดียว → ไม่มีแท็บเทียบร้าน แต่รายปียังมาก่อน
        $this->assertSame(['รายปี', 'รายเดือน', 'รายวัน'], $spreadsheet->getSheetNames());
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());

        $spreadsheet->disconnectWorksheets();
    }

    /**
     * ⭐⭐ ไฟล์ Excel ต้องใช้กติกา "ดีสุด/แย่สุดต้องคนละเดือน" เหมือนหน้าจอ
     *
     * ⚠️ หน้าเว็บขึ้น "ยังเทียบไม่ได้" ทั้งสองการ์ดเมื่อเดือนที่จบแล้วให้ผลเท่ากันหมด
     * แต่ไฟล์เขียน "ก.ค. (฿30,000)" เป็นทั้งเดือนดีสุดและแย่สุดของปีเดียวกัน
     * — เกิดกับทุกร้านใน 1–2 เดือนแรกที่เริ่มใช้ระบบ
     *
     * เป็นรูปแบบเดิมที่เจอซ้ำ ๆ: กติกาถูกบังคับใช้ที่หน้าจอแล้วแต่ไปไม่ถึงไฟล์
     */
    public function testTheAnnualSheetDoesNotCallOneMonthBothBestAndWorst(): void
    {
        $sheet = $this->buildSheet($this->summary([
            'best_month' => ['month' => 7, 'profit' => 30000.0],
            'worst_month' => ['month' => 7, 'profit' => 30000.0],
        ]));

        $best = $this->factValue($sheet, 'เดือนกำไรดีสุด');
        $worst = $this->factValue($sheet, 'เดือนกำไรแย่สุด');

        $this->assertNotNull($best, 'ไม่มีแถวเดือนกำไรดีสุดในไฟล์ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');
        $this->assertStringContainsString(
            'ยังเทียบไม่ได้',
            (string)$best,
            'ไฟล์ประกาศว่าเดือนเดียวกันเป็นทั้งเดือนดีสุดและแย่สุดของปี'
        );
        $this->assertSame($best, $worst);

        $sheet->getParent()?->disconnectWorksheets();
    }

    /** คนละเดือนจริง ๆ ต้องยังแสดงตามปกติ */
    public function testTwoDifferentMonthsAreStillShown(): void
    {
        $sheet = $this->buildSheet($this->summary([
            'best_month' => ['month' => 1, 'profit' => 30000.0],
            'worst_month' => ['month' => 7, 'profit' => -5000.0],
        ]));

        $this->assertStringContainsString('ม.ค.', (string)$this->factValue($sheet, 'เดือนกำไรดีสุด'));
        $this->assertStringContainsString('ก.ค.', (string)$this->factValue($sheet, 'เดือนกำไรแย่สุด'));

        $sheet->getParent()?->disconnectWorksheets();
    }

    /** ค้นค่าจากป้ายชื่อ ไม่ใช่ตำแหน่งเซลล์ตายตัว — จัดหน้าใหม่แล้วเทสต์ต้องไม่พังฟรี ๆ */
    private function factValue(Worksheet $sheet, string $label): ?string
    {
        foreach ($sheet->getRowIterator() as $row) {
            $index = $row->getRowIndex();
            if (trim((string)$sheet->getCell('A' . $index)->getValue()) === $label) {
                return (string)$sheet->getCell('C' . $index)->getValue();
            }
        }

        return null;
    }
}
