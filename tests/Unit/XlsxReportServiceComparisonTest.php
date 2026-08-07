<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use XlsxReportService;

/**
 * unit test ของ sheet "เทียบร้าน" (portfolio)
 */
final class XlsxReportServiceComparisonTest extends TestCase
{
    private const HEADER_ROW = 3;
    private const FIRST_SHOP_ROW = 4;

    /**
     * @param array<int,array<string,mixed>> $shops
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function payload(array $shops, array $summary = []): array
    {
        return [
            'year' => 2026,
            'shops_count' => count($shops),
            'can_view' => true,
            'shops' => $shops,
            'summary' => array_merge([
                'profit' => 0.0,
                'best_month' => null,
                'worst_month' => null,
                'prev_year' => 2025,
                'prev_year_profit' => 0.0,
                'yoy_profit_change' => null,
                'yoy_profit_change_percent' => null,
            ], $summary),
        ];
    }

    /**
     * @param array{0:string,1:float,2:float,3:float|null,4:int} $row [ชื่อ, รายได้, ค่าแอด, สัดส่วน, วัน]
     * @return array<string,mixed>
     */
    /**
     * หาแถวของบล็อกสรุปจาก "ป้ายกำกับ" ไม่ใช่เลขแถวตายตัว
     *
     * ⚠️ เดิมเทสต์ล็อก A7/A8/… ไว้ พอเพิ่มแถวรวมท้ายตาราง บล็อกสรุปเลื่อนลง 1 แถว
     * เทสต์ก็แดงทั้งที่พฤติกรรมที่ผู้ใช้เห็นถูกต้อง — ล็อกเลขแถวคือล็อกการจัดวาง
     * ไม่ใช่ล็อกสิ่งที่ต้องเป็นจริง
     */
    private function summaryValue(Worksheet $sheet, string $label): ?string
    {
        foreach ($sheet->getRowIterator() as $row) {
            $index = $row->getRowIndex();
            if (trim((string)$sheet->getCell('A' . $index)->getValue()) === $label) {
                $value = $sheet->getCell('B' . $index)->getValue();

                return $value === null ? null : (string)$value;
            }
        }

        return null;
    }

    private function shop(array $row): array
    {
        $profit = $row[1] - $row[2];

        return [
            'shop_name' => $row[0],
            'total_revenue' => $row[1],
            'total_ad_cost' => $row[2],
            'profit' => $profit,
            'roas' => $row[2] > 0 ? round($row[1] / $row[2], 2) : null,
            'profit_margin' => $row[1] > 0 ? round(($profit / $row[1]) * 100, 1) : null,
            'profit_share' => $row[3],
            'days_count' => $row[4],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function buildSheet(array $payload): Worksheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildShopComparisonSheet($spreadsheet, $payload);

        $sheet = $spreadsheet->getSheetByName('เทียบร้าน');
        $this->assertNotNull($sheet);

        return $sheet;
    }

    public function testShopRowsKeepPayloadOrderAndColumns(): void
    {
        $sheet = $this->buildSheet($this->payload([
            $this->shop(['ร้าน A', 9000.0, 3000.0, 120.0, 6]),
            $this->shop(['ร้าน B', 1000.0, 2000.0, -20.0, 1]),
        ]));

        // payload เรียงกำไรมาแล้ว — sheet ต้องไม่สลับ
        $this->assertSame('ร้าน A', $sheet->getCell('A' . self::FIRST_SHOP_ROW)->getValue());
        $this->assertSame(6000.0, $sheet->getCell('D' . self::FIRST_SHOP_ROW)->getValue());
        $this->assertSame(3.0, $sheet->getCell('E' . self::FIRST_SHOP_ROW)->getValue());
        $this->assertSame(120.0, $sheet->getCell('G' . self::FIRST_SHOP_ROW)->getValue());
        $this->assertSame(6, $sheet->getCell('H' . self::FIRST_SHOP_ROW)->getValue());

        $this->assertSame('ร้าน B', $sheet->getCell('A5')->getValue());
        $this->assertSame(-1000.0, $sheet->getCell('D5')->getValue());
        $this->assertSame(-20.0, $sheet->getCell('G5')->getValue());
    }

    public function testLossMakingShopIsFlaggedRed(): void
    {
        $sheet = $this->buildSheet($this->payload([
            $this->shop(['ร้าน A', 9000.0, 3000.0, 120.0, 6]),
            $this->shop(['ร้าน B', 1000.0, 2000.0, -20.0, 1]),
        ]));

        $this->assertSame('FFC00000', $sheet->getStyle('D5')->getFont()->getColor()->getARGB());
        $this->assertSame('FFC00000', $sheet->getStyle('G5')->getFont()->getColor()->getARGB());
        // ร้านที่กำไรบวกต้องไม่ถูกทาแดง
        $this->assertNotSame('FFC00000', $sheet->getStyle('D4')->getFont()->getColor()->getARGB());
    }

    public function testNullShareLeavesCellEmpty(): void
    {
        // กำไรรวม ≤ 0 → service คืน profit_share = null ทุกแถว
        $sheet = $this->buildSheet($this->payload([
            $this->shop(['ร้าน A', 1000.0, 2000.0, null, 2]),
            $this->shop(['ร้าน B', 500.0, 1500.0, null, 1]),
        ]));

        $this->assertNull($sheet->getCell('G4')->getValue());
        $this->assertNull($sheet->getCell('G5')->getValue());
    }

    public function testHeaderIsStyledFrozenAndTitled(): void
    {
        $sheet = $this->buildSheet($this->payload([$this->shop(['ร้าน A', 100.0, 10.0, 100.0, 1])]));

        $this->assertSame('เทียบทุกร้าน ปี 2569', $sheet->getCell('A1')->getValue());
        $this->assertSame('ร้าน', $sheet->getCell('A' . self::HEADER_ROW)->getValue());
        $this->assertSame('สัดส่วนกำไร', $sheet->getCell('G' . self::HEADER_ROW)->getValue());
        $this->assertSame('วันที่กรอก', $sheet->getCell('H' . self::HEADER_ROW)->getValue());
        $this->assertTrue($sheet->getStyle('A' . self::HEADER_ROW)->getFont()->getBold());
        $this->assertSame(
            'FF1F4E79',
            $sheet->getStyle('A' . self::HEADER_ROW)->getFill()->getStartColor()->getARGB()
        );
        $this->assertSame('A' . self::FIRST_SHOP_ROW, $sheet->getFreezePane());
    }

    public function testNumberFormatsAreApplied(): void
    {
        $sheet = $this->buildSheet($this->payload([$this->shop(['ร้าน A', 9000.0, 3000.0, 100.0, 6])]));

        $this->assertSame('"฿"#,##0.00', $sheet->getStyle('D4')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('E4')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.0"%"', $sheet->getStyle('G4')->getNumberFormat()->getFormatCode());
    }

    public function testSummaryShowsBestWorstMonthAndYoy(): void
    {
        $sheet = $this->buildSheet($this->payload(
            [
                $this->shop(['ร้าน A', 9000.0, 3000.0, 120.0, 6]),
                $this->shop(['ร้าน B', 1000.0, 2000.0, -20.0, 1]),
            ],
            [
                'profit' => 5000.0,
                'best_month' => ['month' => 1, 'profit' => 6000.0],
                'worst_month' => ['month' => 7, 'profit' => -1000.0],
                'prev_year_profit' => 2500.0,
                'yoy_profit_change' => 2500.0,
                'yoy_profit_change_percent' => 100.0,
            ]
        ));

        // ใต้ตาราง (2 ร้าน → แถว 4-5) เว้น 1 บรรทัด → เริ่มแถว 7
        $this->assertSame('5000', $this->summaryValue($sheet, 'กำไรรวมทุกร้าน'));
        $this->assertSame('ม.ค. (฿6,000)', $this->summaryValue($sheet, 'เดือนกำไรดีสุด'));
        $this->assertSame('ก.ค. (฿-1,000)', $this->summaryValue($sheet, 'เดือนกำไรแย่สุด'));
        $this->assertSame('↑100.0% (+฿2,500) · ปีก่อน ฿2,500', $this->summaryValue($sheet, 'เทียบ 2568 (ช่วงเดียวกัน)'));
    }

    public function testNullYoySaysNoPreviousYear(): void
    {
        $sheet = $this->buildSheet($this->payload(
            [$this->shop(['ร้าน A', 9000.0, 3000.0, 100.0, 6])],
            ['profit' => 6000.0, 'yoy_profit_change_percent' => null]
        ));

        $this->assertSame('ไม่มีข้อมูลปีก่อน', $this->summaryValue($sheet, 'เทียบ 2568 (ช่วงเดียวกัน)'));
    }

    public function testNegativeYoyIsFlaggedRed(): void
    {
        $sheet = $this->buildSheet($this->payload(
            [$this->shop(['ร้าน A', 1000.0, 500.0, 100.0, 2])],
            [
                'profit' => 500.0,
                'prev_year_profit' => 2000.0,
                'yoy_profit_change' => -1500.0,
                'yoy_profit_change_percent' => -75.0,
            ]
        ));

        $this->assertSame('↓75.0% (-฿1,500) · ปีก่อน ฿2,000', $this->summaryValue($sheet, 'เทียบ 2568 (ช่วงเดียวกัน)'));
        $yoyRow = null;
        foreach ($sheet->getRowIterator() as $row) {
            if (str_starts_with(trim((string)$sheet->getCell('A' . $row->getRowIndex())->getValue()), 'เทียบ 2568')) {
                $yoyRow = $row->getRowIndex();
                break;
            }
        }
        $this->assertNotNull($yoyRow);
        $this->assertSame('FFC00000', $sheet->getStyle('B' . $yoyRow)->getFont()->getColor()->getARGB());
    }

    public function testMissingBestWorstMonthShowsDash(): void
    {
        $sheet = $this->buildSheet($this->payload([$this->shop(['ร้าน A', 0.0, 0.0, null, 0])]));

        // 1 ร้าน → ตารางจบแถว 4 · สรุปเริ่มแถว 6 (รวม/ดีสุด/แย่สุด/YoY)
        $this->assertSame('–', $this->summaryValue($sheet, 'เดือนกำไรดีสุด'));
        $this->assertSame('–', $this->summaryValue($sheet, 'เดือนกำไรแย่สุด'));
    }

    public function testComparisonSheetHasNoChartAndSitsLast(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildMonthlySheet($spreadsheet, [
            'year' => 2026,
            'last_month' => 1,
            'months' => [
                ['month' => 1, 'month_label' => 'ม.ค.', 'revenue' => 100.0, 'ad_cost' => 10.0, 'profit' => 90.0, 'roas' => 10.0],
            ],
        ]);
        $service->buildShopComparisonSheet(
            $spreadsheet,
            $this->payload([$this->shop(['ร้าน A', 100.0, 10.0, 100.0, 1])])
        );

        // ยังไม่เรียก buildAnnualSheet — ลำดับสุดท้าย (รายปีมาก่อน) ดู XlsxReportServiceAnnualTest
        $this->assertSame(['รายเดือน', 'รายวัน', 'เทียบร้าน'], $spreadsheet->getSheetNames());

        $sheet = $spreadsheet->getSheetByName('เทียบร้าน');
        $this->assertNotNull($sheet);
        $this->assertSame(0, $sheet->getChartCount());

        // เฟส 2 ไม่ถูกกระทบ — กราฟรายเดือนยังอยู่
        $monthly = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($monthly);
        // แท่งกำไร + เส้นสะสม (เฟส 5C)
        $this->assertSame(2, $monthly->getChartCount());

        $spreadsheet->disconnectWorksheets();
    }

    public function testWorkbookWithoutComparisonKeepsTwoTabs(): void
    {
        // จำลองสิ่งที่ controller ทำเมื่อ can_view = false — ไม่เรียก buildShopComparisonSheet
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildMonthlySheet($spreadsheet, ['year' => 2026, 'last_month' => 0, 'months' => []]);

        $this->assertSame(['รายเดือน', 'รายวัน'], $spreadsheet->getSheetNames());
        $this->assertNull($spreadsheet->getSheetByName('เทียบร้าน'));

        $spreadsheet->disconnectWorksheets();
    }

    /**
     * ⭐⭐ ลูกศรในไฟล์ Excel ต้องตรงกับตัวเลขที่พิมพ์ข้าง ๆ (เหมือนหน้าเว็บ)
     *
     * ⚠️ เดิม Excel ตัดสินลูกศรจาก **ค่าดิบ** แล้วค่อยปัดตอนพิมพ์ตัวเลข ผลคือ
     *   % จริง +0.04 → หน้าเว็บ "0.0%" (เทา ไม่มีลูกศร) · Excel "↑0.0%"
     * ลูกศรขัดกับเลขที่เห็นในไฟล์เดียวกัน · `format_change_badge()` ถูกสร้างมา
     * ปิดความไม่ตรงกันแบบนี้พอดี แต่ไฟล์ Excel เขียนลูกศรเอง จึงหลุดออกไป
     *
     * @return array<string,array{0:float,1:string}>
     */
    public static function nearZeroChangeProvider(): array
    {
        return [
            'บวกน้อยจนปัดแล้วเป็น 0' => [0.04, ''],
            'ลบน้อยจนปัดแล้วเป็น 0' => [-0.04, ''],
            'บวกพอให้ปัดขึ้น 0.1' => [0.06, '↑'],
            'ลบพอให้ปัดลง 0.1' => [-0.06, '↓'],
            'ศูนย์พอดี' => [0.0, ''],
            'บวกชัดเจน' => [12.3, '↑'],
            'ลบชัดเจน' => [-5.0, '↓'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nearZeroChangeProvider')]
    public function testTheExcelArrowMatchesTheNumberBesideIt(float $percent, string $expectedArrow): void
    {
        $arrow = new \ReflectionMethod(XlsxReportService::class, 'changeArrow');

        $this->assertSame(
            $expectedArrow,
            $arrow->invoke(null, $percent),
            'ลูกศรใน Excel ขัดกับเลขที่พิมพ์ข้าง ๆ และขัดกับหน้าเว็บ'
        );

        // ต้องตรงกับกติกาของหน้าเว็บเสมอ ไม่ใช่แค่ตรงกับค่าที่คาดไว้ในเทสต์
        $webDirection = format_change_badge($percent)['direction'];
        $webArrow = $webDirection > 0 ? '↑' : ($webDirection < 0 ? '↓' : '');
        $this->assertSame($webArrow, $arrow->invoke(null, $percent), 'Excel กับหน้าเว็บใช้กติกาคนละอย่าง');
    }

    /**
     * ⭐⭐ แท็บ "เทียบร้าน" ต้องมีแถวรวม เหมือนตารางเดียวกันบนหน้าเว็บ
     *
     * ⚠️ เดิมไฟล์จบตารางที่แถวร้านสุดท้าย แล้วข้ามไปบล็อกสรุปที่มี **กำไรอย่างเดียว**
     * → ยอดขายรวมและค่าแอดรวมของทุกร้าน **ไม่ปรากฏที่ไหนเลยทั้งไฟล์**
     * (แท็บอื่นเป็นข้อมูลร้านเดียว) ทั้งที่หน้าเว็บแสดงครบทั้ง 5 ค่าในแถว tfoot
     */
    public function testTheShopSheetHasATotalsRow(): void
    {
        $sheet = $this->buildSheet($this->payload([
            $this->shop(['ร้าน A', 500000.0, 400000.0, 57.1, 31]),
            $this->shop(['ร้าน B', 376000.0, 300800.0, 42.9, 31]),
        ]));

        $totalRow = null;
        foreach ($sheet->getRowIterator() as $row) {
            if (trim((string)$sheet->getCell('A' . $row->getRowIndex())->getValue()) === 'รวมทุกร้าน') {
                $totalRow = $row->getRowIndex();
                break;
            }
        }

        $this->assertNotNull($totalRow, 'ไม่มีแถวรวมในแท็บเทียบร้าน — ยอดขายรวม/ค่าแอดรวมหายไปทั้งไฟล์');
        $this->assertSame(876000.0, (float)$sheet->getCell('B' . $totalRow)->getValue(), 'ยอดขายรวมผิด');
        $this->assertSame(700800.0, (float)$sheet->getCell('C' . $totalRow)->getValue(), 'ค่าแอดรวมผิด');
        $this->assertSame(175200.0, (float)$sheet->getCell('D' . $totalRow)->getValue(), 'กำไรรวมผิด');
        // ⚠️ สัดส่วนกำไรของแถวรวม = 100% ตามนิยาม · "วันที่กรอก" ใช้ค่ามากสุด ไม่ใช่ผลบวก
        $this->assertSame(100.0, (float)$sheet->getCell('G' . $totalRow)->getValue());
        // ⚠️ เขียนเป็นข้อความเหมือนหน้าเว็บ — ตัวเลขเปล่าอ่านเป็นผลบวกที่บวกไม่ลง
        $this->assertSame('สูงสุด 31 วัน', (string)$sheet->getCell('H' . $totalRow)->getValue(), 'วันที่กรอกของแถวรวมถูกบวกข้ามร้าน');

        $sheet->getParent()->disconnectWorksheets();
    }
}
