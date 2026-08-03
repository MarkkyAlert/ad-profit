<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use XlsxReportService;

/**
 * unit test ของเฟส 5A — AutoFilter, คอลัมน์วิเคราะห์ในชีตรายเดือน,
 * สีแดงค่าติดลบ และแถบประมาณการสิ้นปี
 */
final class XlsxReportServiceUsabilityTest extends TestCase
{
    /**
     * แถวเดือนแบบที่ AnnualService คืนมาจริง (มี days_count / profit_per_day / yoy)
     *
     * @return array<string,mixed>
     */
    private function annualMonthsPayload(): array
    {
        return [
            'months' => [
                [
                    'month' => 1, 'total_revenue' => 9000.0, 'total_ad_cost' => 3000.0,
                    'profit' => 6000.0, 'roas' => 3.0, 'days_count' => 3,
                    'profit_per_day' => 2000.0, 'yoy_change_percent' => 50.0,
                ],
                [
                    'month' => 2, 'total_revenue' => 0.0, 'total_ad_cost' => 0.0,
                    'profit' => 0.0, 'roas' => null, 'days_count' => 0,
                    'profit_per_day' => null, 'yoy_change_percent' => null,
                ],
                [
                    'month' => 7, 'total_revenue' => 1000.0, 'total_ad_cost' => 3500.0,
                    'profit' => -2500.0, 'roas' => 0.29, 'days_count' => 2,
                    'profit_per_day' => -1250.0, 'yoy_change_percent' => -80.0,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dailyPayload(): array
    {
        return [
            // มีคีย์ profit เหมือน buildYearlyDailyPayload คืนมาจริง
            'rows' => [
                ['record_date' => '2026-01-05', 'revenue' => 3000.0, 'ad_cost' => 1000.0, 'profit' => 2000.0, 'roas' => 3.0, 'note' => ''],
                ['record_date' => '2026-07-10', 'revenue' => 1000.0, 'ad_cost' => 3500.0, 'profit' => -2500.0, 'roas' => 0.29, 'note' => ''],
            ],
            'totals' => ['revenue' => 4000.0, 'ad_cost' => 4500.0, 'profit' => -500.0, 'roas' => 0.89],
            'note_column_index' => 6,
        ];
    }

    private function buildWorkbook(): Spreadsheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildMonthlySheet($spreadsheet, $this->annualMonthsPayload());

        return $spreadsheet;
    }

    public function testMonthlySheetGainsAnalysisColumns(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $this->assertSame('วันที่กรอก', $sheet->getCell('F1')->getValue());
        $this->assertSame('กำไร/วัน', $sheet->getCell('G1')->getValue());
        $this->assertSame('เทียบปีก่อน', $sheet->getCell('H1')->getValue());

        // ม.ค. — ชื่อเดือนไทยมาจาก month (payload ของ AnnualService ไม่มี month_label)
        $this->assertSame('ม.ค.', $sheet->getCell('A2')->getValue());
        $this->assertSame(9000.0, $sheet->getCell('B2')->getValue());
        $this->assertSame(3, $sheet->getCell('F2')->getValue());
        $this->assertSame(2000.0, $sheet->getCell('G2')->getValue());
        $this->assertSame(50.0, $sheet->getCell('H2')->getValue());

        $this->assertSame('"฿"#,##0', $sheet->getStyle('G2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0"%"', $sheet->getStyle('H2')->getNumberFormat()->getFormatCode());
    }

    public function testUnfilledMonthLeavesAnalysisCellsEmpty(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        // ก.พ. ยังไม่กรอก — วันที่กรอก 0 แต่ กำไร/วัน กับ เทียบปีก่อน ต้องว่าง ไม่ใช่ 0
        $this->assertSame(0, $sheet->getCell('F3')->getValue());
        $this->assertNull($sheet->getCell('G3')->getValue());
        $this->assertNull($sheet->getCell('H3')->getValue());
    }

    public function testNegativeMonthlyValuesAreRed(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        // ก.ค. ขาดทุน → กำไร, กำไร/วัน, เทียบปีก่อน แดงหมด
        $this->assertSame('FFC00000', $sheet->getStyle('D4')->getFont()->getColor()->getARGB());
        $this->assertSame('FFC00000', $sheet->getStyle('G4')->getFont()->getColor()->getARGB());
        $this->assertSame('FFC00000', $sheet->getStyle('H4')->getFont()->getColor()->getARGB());
        // เดือนกำไรบวกต้องไม่แดง
        $this->assertNotSame('FFC00000', $sheet->getStyle('D2')->getFont()->getColor()->getARGB());
    }

    public function testNegativeDailyProfitIsRedIncludingTotals(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายวัน');
        $this->assertNotNull($sheet);

        $this->assertSame('FFC00000', $sheet->getStyle('D3')->getFont()->getColor()->getARGB());
        // แถวรวม (เว้น 1 บรรทัด → แถว 5) ก็ติดลบ
        $this->assertSame(-500.0, $sheet->getCell('D5')->getValue());
        $this->assertSame('FFC00000', $sheet->getStyle('D5')->getFont()->getColor()->getARGB());
    }

    public function testAutoFilterIsOnDataRowsOnly(): void
    {
        $spreadsheet = $this->buildWorkbook();

        $daily = $spreadsheet->getSheetByName('รายวัน');
        $this->assertNotNull($daily);
        // 2 แถวข้อมูล → A1:F3 · ต้องไม่คลุมแถวรวม (แถว 5) ไม่งั้นกรองแล้วยอดรวมหาย
        $this->assertSame('A1:F3', $daily->getAutoFilter()->getRange());

        $monthly = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($monthly);
        $this->assertSame('A1:K4', $monthly->getAutoFilter()->getRange());
    }

    public function testComparisonSheetHasAutoFilter(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildShopComparisonSheet($spreadsheet, [
            'year' => 2026,
            'shops' => [
                ['shop_name' => 'ร้าน A', 'total_revenue' => 100.0, 'total_ad_cost' => 10.0, 'profit' => 90.0, 'days_count' => 1],
                ['shop_name' => 'ร้าน B', 'total_revenue' => 50.0, 'total_ad_cost' => 10.0, 'profit' => 40.0, 'days_count' => 1],
            ],
            'summary' => ['profit' => 130.0, 'prev_year' => 2025, 'yoy_profit_change_percent' => null],
        ]);

        $sheet = $spreadsheet->getSheetByName('เทียบร้าน');
        $this->assertNotNull($sheet);
        // หัวตารางอยู่แถว 3 · 2 ร้าน → A3:H5
        $this->assertSame('A3:H5', $sheet->getAutoFilter()->getRange());

        $spreadsheet->disconnectWorksheets();
    }

    public function testEmptyMonthlyPayloadHasNoAutoFilter(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildMonthlySheet($spreadsheet, ['months' => []]);

        $sheet = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);
        $this->assertSame('', $sheet->getAutoFilter()->getRange());

        $spreadsheet->disconnectWorksheets();
    }

    public function testProjectionStripIsWrittenWithDisclaimer(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildAnnualSheet($spreadsheet, [
            'total_revenue' => 100.0, 'total_ad_cost' => 10.0, 'profit' => 90.0,
            'roas' => 10.0, 'profit_margin' => 90.0,
            'months_with_data' => 2, 'profit_months' => 2, 'loss_months' => 0,
            'best_month' => null, 'worst_month' => null,
            'prev_year' => 2025, 'yoy_profit_change_percent' => null,
            'projection' => [
                'available' => true,
                'months_remaining' => 4,
                'basis_month_count' => 3,
                'projection_low' => 21000.0,
                'projection_mid' => 25000.0,
                'projection_high' => 29000.0,
            ],
        ], 2026, 'ร้านคอร์ส');

        $sheet = $spreadsheet->getSheetByName('รายปี');
        $this->assertNotNull($sheet);

        $this->assertSame('ประมาณการสิ้นปี (ไม่ใช่ตัวเลขจริง)', $sheet->getCell('A15')->getValue());
        $this->assertSame('21,000.00 – 29,000.00 (กลาง 25,000.00)', $sheet->getCell('A16')->getValue());
        $this->assertStringContainsString('ไม่ใช่ตัวเลขที่เกิดขึ้นจริง', (string)$sheet->getCell('A17')->getValue());
        // ตัวเอียง = สัญญาณสายตาว่าไม่ใช่ข้อมูลจริง
        $this->assertTrue($sheet->getStyle('A16')->getFont()->getItalic());

        $spreadsheet->disconnectWorksheets();
    }

    public function testUnavailableProjectionSaysNotEnoughData(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildAnnualSheet($spreadsheet, [
            'profit' => 0.0,
            'prev_year' => 2025,
            'yoy_profit_change_percent' => null,
            'projection' => ['available' => false, 'reason' => 'insufficient_data'],
        ], 2026, 'ร้านใหม่');

        $sheet = $spreadsheet->getSheetByName('รายปี');
        $this->assertNotNull($sheet);
        $this->assertSame('ข้อมูลยังไม่พอประมาณการ', $sheet->getCell('A16')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function testChartsSitClearOfTheDataColumns(): void
    {
        $spreadsheet = $this->buildWorkbook();
        $sheet = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        // ตารางกินถึงคอลัมน์ K → กราฟต้องเริ่มที่ M ไม่งั้นทับข้อมูล
        $this->assertSame(2, $sheet->getChartCount());
        $this->assertSame('M2', $sheet->getChartByIndex(0)->getTopLeftPosition()['cell']);
        $this->assertSame('M22', $sheet->getChartByIndex(1)->getTopLeftPosition()['cell']);

        $spreadsheet->disconnectWorksheets();
    }
}
