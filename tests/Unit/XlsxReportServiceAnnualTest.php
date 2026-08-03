<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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

        $this->assertSame('สรุปรายปี ร้านคอร์สออนไลน์ ปี 2569', $sheet->getCell('A1')->getValue());

        $this->assertSame('รายได้', $sheet->getCell('A4')->getValue());
        $this->assertSame(36000.0, $sheet->getCell('B4')->getValue());
        $this->assertSame('ค่าแอด', $sheet->getCell('A5')->getValue());
        $this->assertSame(26500.0, $sheet->getCell('B5')->getValue());
        $this->assertSame('กำไร', $sheet->getCell('A6')->getValue());
        $this->assertSame(9500.0, $sheet->getCell('B6')->getValue());
        $this->assertSame(1.36, $sheet->getCell('B7')->getValue());
        $this->assertSame(26.4, $sheet->getCell('B8')->getValue());

        $this->assertSame('#,##0.00', $sheet->getStyle('B4')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('B7')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0"%"', $sheet->getStyle('B8')->getNumberFormat()->getFormatCode());
    }

    public function testNegativeYearProfitIsRed(): void
    {
        $sheet = $this->buildSheet($this->summary(['profit' => -3000.0]));

        $this->assertSame(-3000.0, $sheet->getCell('B6')->getValue());
        $this->assertSame('FFC00000', $sheet->getStyle('B6')->getFont()->getColor()->getARGB());
    }

    public function testNullRoasAndMarginShowDash(): void
    {
        $sheet = $this->buildSheet($this->summary(['roas' => null, 'profit_margin' => null]));

        $this->assertSame('–', $sheet->getCell('B7')->getValue());
        $this->assertSame('–', $sheet->getCell('B8')->getValue());
    }

    public function testPositiveYoyIsGreen(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertSame('เทียบ 2568 (ช่วงเดียวกัน)', $sheet->getCell('A10')->getValue());
        $this->assertSame(
            'กำไร ↑375.0% (+7,500.00) · ปีก่อน 2,000.00',
            $sheet->getCell('B10')->getValue()
        );
        $this->assertSame('FF107C41', $sheet->getStyle('B10')->getFont()->getColor()->getARGB());
    }

    public function testNegativeYoyIsRed(): void
    {
        $sheet = $this->buildSheet($this->summary([
            'prev_year_profit' => 20000.0,
            'yoy_profit_change' => -10500.0,
            'yoy_profit_change_percent' => -52.5,
        ]));

        $this->assertSame(
            'กำไร ↓52.5% (-10,500.00) · ปีก่อน 20,000.00',
            $sheet->getCell('B10')->getValue()
        );
        $this->assertSame('FFC00000', $sheet->getStyle('B10')->getFont()->getColor()->getARGB());
    }

    public function testNullYoySaysNoPreviousYear(): void
    {
        $sheet = $this->buildSheet($this->summary(['yoy_profit_change_percent' => null]));

        $this->assertSame('ไม่มีข้อมูลปีก่อน', $sheet->getCell('B10')->getValue());
    }

    public function testBestWorstMonthAndCounts(): void
    {
        $sheet = $this->buildSheet($this->summary());

        $this->assertSame('เดือนกำไรดีสุด', $sheet->getCell('A12')->getValue());
        $this->assertSame('ม.ค. (7,000.00)', $sheet->getCell('B12')->getValue());
        $this->assertSame('เดือนกำไรแย่สุด', $sheet->getCell('A13')->getValue());
        $this->assertSame('ก.ค. (-2,500.00)', $sheet->getCell('B13')->getValue());
        $this->assertSame('เดือนที่มีข้อมูล', $sheet->getCell('A14')->getValue());
        $this->assertSame('4 เดือน · กำไร 3 / ขาดทุน 1', $sheet->getCell('B14')->getValue());
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

        $this->assertSame('–', $sheet->getCell('B12')->getValue());
        $this->assertSame('–', $sheet->getCell('B13')->getValue());
        $this->assertSame('0 เดือน · กำไร 0 / ขาดทุน 0', $sheet->getCell('B14')->getValue());
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
        $this->assertSame(1, $monthly->getChartCount());

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
}
