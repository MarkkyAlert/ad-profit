<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของซีรีส์เส้นปีก่อน + กำไรสะสม + กำไรต่อวันที่กรอก
 * today คงที่ = 2026-08-15 (cutoff = ส.ค.)
 */
final class AnnualServiceTrendTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     */
    private function makeService(array $monthlyTotals = []): AnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use ($monthlyTotals): array {
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

    public function testPrevProfitSeriesAlignsWithThisYearAxis(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 3000.0, 1000.0], [3, 2000.0, 1000.0]]),
            $this->totalsFor(2025, [
                [1, 2000.0, 1000.0],   // +1000
                [2, 1800.0, 1000.0],   // +800
                // เดือนที่ปีนี้ไม่มีข้อมูลแต่ปีก่อนมี — ต้องยังโผล่บนเส้นปีก่อน
                [5, 3000.0, 1000.0],   // +2000
            ])
        ));

        $chart = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['chart'];

        // ยาวเท่าแกน x ปีนี้ (8 เดือน) — index ตรงกับ chart['months']
        $this->assertCount(8, $chart['prev_profit']);
        $this->assertCount(8, $chart['months']);
        $this->assertSame(
            [1000.0, 800.0, 0.0, 0.0, 2000.0, 0.0, 0.0, 0.0],
            $chart['prev_profit']
        );
    }

    public function testPrevProfitSeriesStopsAtCutoffMonth(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 3000.0, 1000.0]]),
            // ปีก่อน ก.ย.-ธ.ค. ก้อนใหญ่ — เกิน lastMonth ต้องไม่โผล่บนเส้น
            $this->totalsFor(2025, [[9, 90000.0, 0.0], [12, 90000.0, 0.0]])
        ));

        $chart = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['chart'];

        $this->assertCount(8, $chart['prev_profit']);
        $this->assertSame(0.0, array_sum($chart['prev_profit']));
    }

    public function testCumulativeSeriesIsRunningSum(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 3000.0, 1000.0],   // +2000
            [2, 1500.0, 1000.0],   // +500
            [3, 1000.0, 3000.0],   // -2000  ← เส้นสะสมต้องลดลง
        ]));

        $chart = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['chart'];

        $this->assertSame(
            [2000.0, 2500.0, 500.0, 500.0, 500.0, 500.0, 500.0, 500.0],
            $chart['cumulative_profit']
        );
        // ค่าสุดท้ายของเส้นสะสม = กำไรรวมทั้งช่วง
        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];
        $this->assertSame($summary['profit'], end($chart['cumulative_profit']));
    }

    public function testPrevCumulativeUsesSamePeriodOnly(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 3000.0, 1000.0]]),
            $this->totalsFor(2025, [
                [1, 2000.0, 1000.0],   // +1000
                [4, 2500.0, 1000.0],   // +1500 → สะสม 2500
                // ก.ย.-ธ.ค. ปีก่อน — นอกช่วง ต้องไม่ถูกบวกเข้าเส้นสะสม
                [10, 90000.0, 0.0],
                [12, 90000.0, 0.0],
            ])
        ));

        $data = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];

        $this->assertSame(
            [1000.0, 1000.0, 1000.0, 2500.0, 2500.0, 2500.0, 2500.0, 2500.0],
            $data['chart']['prev_cumulative_profit']
        );
        // ปลายเส้นสะสมปีก่อน = ฐานเทียบ YoY เดิม (ต้องไม่หลุดไปเป็น 182,500)
        $this->assertSame($data['summary']['prev_year_profit'], end($data['chart']['prev_cumulative_profit']));
    }

    public function testProfitPerDayDividesByFilledDays(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            // 3 วัน กำไรรวม 6000 → 2000/วัน
            [1, 9000.0, 3000.0, 3],
            // 1 วัน กำไรรวม 5000 → 5000/วัน (ยอดรวมน้อยกว่า ม.ค. แต่ต่อวันแรงกว่า)
            [2, 9000.0, 4000.0, 1],
        ]));

        $months = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['months'];

        $this->assertSame(2000.0, $months[0]['profit_per_day']);
        $this->assertSame(5000.0, $months[1]['profit_per_day']);
        // เดือนที่กรอกน้อยกว่ากลับแรงกว่าต่อวัน — จุดประสงค์ของคอลัมน์นี้
        $this->assertGreaterThan($months[0]['profit_per_day'], $months[1]['profit_per_day']);
        $this->assertLessThan($months[0]['profit'], $months[1]['profit']);
    }

    public function testProfitPerDayIsNullWhenNoDaysFilled(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 3000.0, 1000.0, 2]]));

        $months = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['months'];

        $this->assertSame(1000.0, $months[0]['profit_per_day']);
        // เดือนที่ยังไม่ได้กรอก → null ไม่ใช่ 0 (หารศูนย์ไม่ได้ และ 0 จะอ่านว่า "เท่าทุน")
        $this->assertNull($months[1]['profit_per_day']);
    }

    public function testProfitPerDayKeepsNegativeSign(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[7, 2000.0, 5000.0, 2]]));

        $months = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['months'];

        $this->assertSame(-1500.0, $months[6]['profit_per_day']);   // -3000 / 2
    }

    public function testFutureYearHasEmptySeries(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 3000.0, 1000.0]]));

        $chart = $service->buildYearlySummary(1, 1, 2027, self::TODAY)['data']['chart'];

        $this->assertSame([], $chart['prev_profit']);
        $this->assertSame([], $chart['cumulative_profit']);
        $this->assertSame([], $chart['prev_cumulative_profit']);
    }
}
