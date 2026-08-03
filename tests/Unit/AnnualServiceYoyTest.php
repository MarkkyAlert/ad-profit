<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ YoY (เทียบช่วงเดียวกันปีก่อน) + days_count ต่อเดือน
 * today คงที่ = 2026-08-15 (ปีปัจจุบัน = 2026, cutoff = ส.ค.)
 */
final class AnnualServiceYoyTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals แถวของทุกปีรวมกัน
     */
    private function makeService(array $monthlyTotals = []): AnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use ($monthlyTotals): array {
                // จำลอง BETWEEN — คืนเฉพาะเดือนที่อยู่ในช่วงที่ service ขอ
                // (สำคัญกับเทสต์ same-period: ถ้า service ขอปีก่อนแค่ ม.ค.-ส.ค. ก็จะไม่เห็น ก.ย.-ธ.ค.)
                return array_values(array_filter(
                    $monthlyTotals,
                    static fn(array $row): bool => (string)$row['month_key'] >= $start
                        && (string)$row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new AnnualService($recordRepository, $shopRepository);
    }

    /**
     * @param array<int,array{0:int,1:float,2:float,3?:int}> $months [เดือน, รายได้, ค่าแอด, จำนวนวัน]
     * @return array<int,array<string,mixed>>
     */
    private function totalsFor(int $year, array $months): array
    {
        return array_map(
            static fn(array $row): array => [
                'month_key' => sprintf('%04d-%02d', $year, $row[0]),
                'total_revenue' => $row[1],
                'total_ad_cost' => $row[2],
                'days_count' => $row[3] ?? 30,
            ],
            $months
        );
    }

    public function testDaysCountFlowsIntoMonthRows(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 5000.0, 1000.0, 3],
            [2, 4000.0, 1000.0, 28],
        ]));

        $months = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['months'];

        $this->assertSame(3, $months[0]['days_count']);
        $this->assertSame(28, $months[1]['days_count']);
        // เดือนที่ยังไม่ได้กรอกเลย → 0 ไม่ใช่ null
        $this->assertSame(0, $months[2]['days_count']);
    }

    public function testYearOverYearComparesSamePeriodOnly(): void
    {
        $service = $this->makeService(array_merge(
            // ปีนี้ ม.ค.-ส.ค. → กำไรรวม 8 × 1000 = 8000
            $this->totalsFor(2026, [
                [1, 2000.0, 1000.0], [2, 2000.0, 1000.0], [3, 2000.0, 1000.0], [4, 2000.0, 1000.0],
                [5, 2000.0, 1000.0], [6, 2000.0, 1000.0], [7, 2000.0, 1000.0], [8, 2000.0, 1000.0],
            ]),
            // ปีก่อน ม.ค.-ส.ค. → กำไรรวม 8 × 500 = 4000
            $this->totalsFor(2025, [
                [1, 1500.0, 1000.0], [2, 1500.0, 1000.0], [3, 1500.0, 1000.0], [4, 1500.0, 1000.0],
                [5, 1500.0, 1000.0], [6, 1500.0, 1000.0], [7, 1500.0, 1000.0], [8, 1500.0, 1000.0],
            ]),
            // ปีก่อน ก.ย.-ธ.ค. — จงใจใส่กำไรก้อนใหญ่ ต้องไม่ถูกนับ (พิสูจน์ same-period)
            $this->totalsFor(2025, [
                [9, 100000.0, 0.0], [10, 100000.0, 0.0], [11, 100000.0, 0.0], [12, 100000.0, 0.0],
            ])
        ));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(8000.0, $summary['profit']);
        // ถ้านับปีก่อนเต็ม 12 เดือนจะได้ 404000 — ต้องเป็น 4000 เท่านั้น
        $this->assertSame(4000.0, $summary['prev_year_profit']);
        $this->assertSame(2025, $summary['prev_year']);
        $this->assertSame(4000.0, $summary['yoy_profit_change']);
        $this->assertSame(100.0, $summary['yoy_profit_change_percent']);
    }

    public function testNoPreviousYearDataGivesNullPercent(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 5000.0, 1000.0]]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        // ฐาน 0 หารไม่ได้ → null (ต้องไม่กลายเป็น 0% หรือ inf)
        $this->assertNull($summary['yoy_profit_change_percent']);
        $this->assertSame(4000.0, $summary['yoy_profit_change']);
    }

    public function testPreviousYearBreakEvenGivesNullPercent(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 5000.0, 1000.0]]),
            $this->totalsFor(2025, [[1, 1000.0, 1000.0]])   // กำไรปีก่อน = 0 พอดี
        ));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }

    public function testPreviousYearLossKeepsSignDirection(): void
    {
        $service = $this->makeService(array_merge(
            // ปีนี้กำไร +500
            $this->totalsFor(2026, [[1, 1500.0, 1000.0]]),
            // ปีก่อนขาดทุน -1000
            $this->totalsFor(2025, [[1, 1000.0, 2000.0]])
        ));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(-1000.0, $summary['prev_year_profit']);
        $this->assertSame(1500.0, $summary['yoy_profit_change']);
        // ขาดทุน -1000 → กำไร +500 คือ "ดีขึ้น" ต้องเป็นบวก (หารด้วย abs)
        $this->assertSame(150.0, $summary['yoy_profit_change_percent']);
    }

    public function testWorseThanPreviousYearLossIsNegative(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 1000.0, 3000.0]]),   // -2000
            $this->totalsFor(2025, [[1, 1000.0, 2000.0]])    // -1000
        ));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        // ขาดทุนหนักขึ้น → ต้องติดลบ
        $this->assertSame(-100.0, $summary['yoy_profit_change_percent']);
    }

    public function testPerMonthYoyComparesSameMonthLastYear(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [
                [1, 3000.0, 1000.0],   // +2000
                [2, 1500.0, 1000.0],   // +500
                [3, 2000.0, 1000.0],   // +1000 — ปีก่อนเดือนนี้ไม่มีข้อมูล
            ]),
            $this->totalsFor(2025, [
                [1, 2000.0, 1000.0],   // +1000 → 2000 คือ +100%
                [2, 2000.0, 1000.0],   // +1000 → 500 คือ -50%
            ])
        ));

        $months = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['months'];

        $this->assertSame(1000.0, $months[0]['prev_year_profit']);
        $this->assertSame(100.0, $months[0]['yoy_change_percent']);

        $this->assertSame(1000.0, $months[1]['prev_year_profit']);
        $this->assertSame(-50.0, $months[1]['yoy_change_percent']);

        // เดือนเดียวกันปีก่อนไม่มีข้อมูล → null ไม่ใช่ 0%
        $this->assertSame(0.0, $months[2]['prev_year_profit']);
        $this->assertNull($months[2]['yoy_change_percent']);
    }

    public function testPastYearComparesFullTwelveMonths(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2025, [[12, 3000.0, 1000.0]]),   // +2000 (เดือน ธ.ค.)
            $this->totalsFor(2024, [[12, 2000.0, 1000.0]])    // +1000 (เดือน ธ.ค.)
        ));

        $data = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data'];

        // ปีอดีต — cutoff = 12 → ธ.ค. ของปีก่อนต้องถูกนับด้วย
        $this->assertCount(12, $data['months']);
        $this->assertSame(1000.0, $data['summary']['prev_year_profit']);
        $this->assertSame(100.0, $data['summary']['yoy_profit_change_percent']);
        $this->assertSame(100.0, $data['months'][11]['yoy_change_percent']);
    }

    public function testFutureYearHasNoPreviousYearComparison(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[8, 9999.0, 0.0]]));

        $summary = $service->buildYearlySummary(1, 1, 2027, self::TODAY)['data']['summary'];

        // ปีอนาคต lastMonth = 0 → ไม่ดึงปีก่อนเลย
        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }
}
