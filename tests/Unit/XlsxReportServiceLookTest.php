<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use XlsxReportService;

/**
 * unit test ของเฟส 6A — ลุครายงาน: ปิด gridlines, เส้นขอบ, แถบสลับสี, สีแท็บ, ฟอนต์ไทย
 */
final class XlsxReportServiceLookTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function dailyPayload(): array
    {
        return [
            'rows' => [
                ['record_date' => '2026-01-05', 'revenue' => 3000.0, 'ad_cost' => 1000.0, 'profit' => 2000.0, 'roas' => 3.0, 'note' => ''],
                ['record_date' => '2026-02-05', 'revenue' => 2000.0, 'ad_cost' => 500.0, 'profit' => 1500.0, 'roas' => 4.0, 'note' => ''],
                ['record_date' => '2026-07-10', 'revenue' => 1000.0, 'ad_cost' => 3500.0, 'profit' => -2500.0, 'roas' => 0.29, 'note' => ''],
            ],
            'totals' => ['revenue' => 6000.0, 'ad_cost' => 5000.0, 'profit' => 1000.0, 'roas' => 1.2],
            'note_column_index' => 6,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function monthlyPayload(): array
    {
        return [
            'months' => [
                ['month' => 1, 'total_revenue' => 9000.0, 'total_ad_cost' => 3000.0, 'profit' => 6000.0, 'roas' => 3.0, 'days_count' => 3, 'profit_per_day' => 2000.0, 'yoy_change_percent' => 50.0, 'prev_year_profit' => 4000.0],
                ['month' => 2, 'total_revenue' => 1000.0, 'total_ad_cost' => 3500.0, 'profit' => -2500.0, 'roas' => 0.29, 'days_count' => 1, 'profit_per_day' => -2500.0, 'yoy_change_percent' => -80.0, 'prev_year_profit' => 1000.0],
            ],
            'chart' => ['cumulative_profit' => [6000.0, 3500.0], 'prev_cumulative_profit' => [4000.0, 5000.0]],
        ];
    }

    private function fullWorkbook(): Spreadsheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildMonthlySheet($spreadsheet, $this->monthlyPayload());
        $service->buildGoalSheet($spreadsheet, [
            [
                'month' => 1, 'target_revenue' => 10000.0, 'target_profit' => 4000.0,
                'actual_revenue' => 9000.0, 'actual_profit' => 6000.0,
                'revenue_progress' => 90.0, 'profit_progress' => 150.0,
                'revenue_reached' => false, 'profit_reached' => true,
            ],
        ], 2026);
        $service->buildSeasonSheet($spreadsheet, [
            'years' => [2024, 2025, 2026],
            'grid' => [
                2024 => [3 => ['month' => 3, 'profit' => 4000.0, 'has_data' => true]],
                2025 => [3 => ['month' => 3, 'profit' => 6000.0, 'has_data' => true]],
                2026 => [3 => ['month' => 3, 'profit' => 7500.0, 'has_data' => true]],
            ],
        ]);
        $service->buildShopComparisonSheet($spreadsheet, [
            'year' => 2026,
            'shops' => [
                ['shop_name' => 'ร้าน A', 'total_revenue' => 100.0, 'total_ad_cost' => 10.0, 'profit' => 90.0, 'days_count' => 1],
                ['shop_name' => 'ร้าน B', 'total_revenue' => 50.0, 'total_ad_cost' => 10.0, 'profit' => 40.0, 'days_count' => 1],
            ],
            'summary' => ['profit' => 130.0, 'prev_year' => 2025, 'yoy_profit_change_percent' => null],
        ]);
        $service->buildAnnualSheet($spreadsheet, [
            'total_revenue' => 100.0, 'total_ad_cost' => 10.0, 'profit' => 90.0,
            'roas' => 10.0, 'profit_margin' => 90.0,
            'months_with_data' => 1, 'profit_months' => 1, 'loss_months' => 0,
            'best_month' => null, 'worst_month' => null,
            'prev_year' => 2025, 'yoy_profit_change_percent' => null,
            'projection' => ['available' => false],
        ], 2026, 'ร้าน A');

        return $spreadsheet;
    }

    public function testEverySheetHidesGridlines(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $this->assertCount(6, $spreadsheet->getSheetNames());
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $this->assertFalse(
                $sheet->getShowGridlines(),
                'gridlines ต้องปิดในชีต ' . $sheet->getTitle()
            );
        }

        $spreadsheet->disconnectWorksheets();
    }

    public function testEverySheetHasItsOwnTabColor(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $expected = [
            'รายปี' => 'FF1F4E79',
            'รายเดือน' => 'FF2E75B6',
            'รายวัน' => 'FF8EA9DB',
            'เป้าหมาย' => 'FF548235',
            'ฤดูกาล' => 'FFBF8F00',
            'เทียบร้าน' => 'FF7030A0',
        ];

        foreach ($expected as $title => $argb) {
            $sheet = $spreadsheet->getSheetByName($title);
            $this->assertNotNull($sheet);
            $this->assertSame($argb, $sheet->getTabColor()->getARGB());
        }

        // สีต้องไม่ซ้ำกัน — ไม่งั้นแยกแท็บด้วยตาไม่ได้
        $this->assertSame(count($expected), count(array_unique(array_values($expected))));

        $spreadsheet->disconnectWorksheets();
    }

    public function testWorkbookUsesThaiFriendlyFont(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $this->assertSame('Tahoma', $spreadsheet->getDefaultStyle()->getFont()->getName());
        $this->assertEqualsWithDelta(10.0, $spreadsheet->getDefaultStyle()->getFont()->getSize(), 0.01);

        $spreadsheet->disconnectWorksheets();
    }

    public function testHeaderRowIsTallerAndCentered(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $daily = $spreadsheet->getSheetByName('รายวัน');
        $this->assertNotNull($daily);
        $this->assertSame(22.0, $daily->getRowDimension(1)->getRowHeight());
        $this->assertSame('center', $daily->getStyle('A1')->getAlignment()->getVertical());

        // ชีตที่หัวตารางไม่ได้อยู่แถว 1 ก็ต้องสูงเหมือนกัน
        $comparison = $spreadsheet->getSheetByName('เทียบร้าน');
        $this->assertNotNull($comparison);
        $this->assertSame(22.0, $comparison->getRowDimension(3)->getRowHeight());

        $spreadsheet->disconnectWorksheets();
    }

    public function testTableCellsGetThinBorders(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $daily = $spreadsheet->getSheetByName('รายวัน');
        $this->assertNotNull($daily);

        $border = $daily->getStyle('B3')->getBorders()->getLeft();
        $this->assertSame(Border::BORDER_THIN, $border->getBorderStyle());
        $this->assertSame('FFD9D9D9', $border->getColor()->getARGB());

        $spreadsheet->disconnectWorksheets();
    }

    public function testDataRowsAreBanded(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $daily = $spreadsheet->getSheetByName('รายวัน');
        $this->assertNotNull($daily);

        // แถว 2 ไม่ระบาย · แถว 3 ระบาย · แถว 4 ไม่ระบาย
        $this->assertSame('FFFFFFFF', $daily->getStyle('B2')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFF4F7FB', $daily->getStyle('B3')->getFill()->getStartColor()->getARGB());
        $this->assertSame('FFFFFFFF', $daily->getStyle('B4')->getFill()->getStartColor()->getARGB());

        $spreadsheet->disconnectWorksheets();
    }

    public function testBandingDoesNotEraseNegativeRedFont(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $daily = $spreadsheet->getSheetByName('รายวัน');
        $this->assertNotNull($daily);

        // แถว 4 = วันขาดทุน (อยู่ในช่วงที่ทา border/band) — สีแดงต้องยังอยู่
        $this->assertSame(-2500.0, $daily->getCell('D4')->getValue());
        $this->assertSame('FFC00000', $daily->getStyle('D4')->getFont()->getColor()->getARGB());

        $monthly = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($monthly);
        $this->assertSame('FFC00000', $monthly->getStyle('D3')->getFont()->getColor()->getARGB());

        $spreadsheet->disconnectWorksheets();
    }

    public function testSeasonGridIsNotBandedSoConditionalColorsStayClean(): void
    {
        $spreadsheet = $this->fullWorkbook();

        $season = $spreadsheet->getSheetByName('ฤดูกาล');
        $this->assertNotNull($season);

        // กริดนี้ใช้ conditional formatting ระบายสีอยู่แล้ว — ไม่ควรมี band ทับ
        $this->assertSame('FFFFFFFF', $season->getStyle('D6')->getFill()->getStartColor()->getARGB());
        // แต่ต้องมีเส้นขอบ
        $this->assertSame(
            Border::BORDER_THIN,
            $season->getStyle('D6')->getBorders()->getLeft()->getBorderStyle()
        );

        $spreadsheet->disconnectWorksheets();
    }

    public function testEmptyTableSkipsBordersWithoutError(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildMonthlySheet($spreadsheet, ['months' => []]);

        $sheet = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);
        $this->assertFalse($sheet->getShowGridlines());
        $this->assertSame('FF2E75B6', $sheet->getTabColor()->getARGB());

        $spreadsheet->disconnectWorksheets();
    }
}
