<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewAnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของสัดส่วนกำไร + วันที่กรอกต่อร้าน + เดือนดี/แย่สุด (มุมปีรวมร้าน)
 * today คงที่ = 2026-08-15 (cutoff = ส.ค.)
 */
final class OverviewAnnualServiceAnalysisTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     * @param array<int,array<string,mixed>> $shops
     */
    private function makeService(array $monthlyTotals = [], ?array $shops = null): OverviewAnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($monthlyTotals): array {
                return array_values(array_filter(
                    $monthlyTotals,
                    static fn(array $row): bool => (string)$row['month_key'] >= $start
                        && (string)$row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops ?? [
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        return new OverviewAnnualService($recordRepository, $shopRepository);
    }

    /**
     * @param array<int,array{0:int,1:int,2:float,3:float,4?:int}> $rows [shopId, เดือน, รายได้, ค่าแอด, จำนวนวัน]
     * @return array<int,array<string,mixed>>
     */
    private function totalsFor(int $year, array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'shop_id' => $row[0],
                'month_key' => sprintf('%04d-%02d', $year, $row[1]),
                'total_revenue' => $row[2],
                'total_ad_cost' => $row[3],
                'days_count' => $row[4] ?? 1,
            ],
            $rows
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function byShopName(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['shop_name']] = $row;
        }

        return $result;
    }

    public function testProfitSharesSumToOneHundred(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 5000.0, 1000.0],   // A +4000
            [2, 1, 2000.0, 1000.0],   // B +1000
        ]));

        $shops = $this->byShopName($service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops']);

        $this->assertSame(80.0, $shops['ร้าน A']['profit_share']);
        $this->assertSame(20.0, $shops['ร้าน B']['profit_share']);
        $this->assertSame(100.0, $shops['ร้าน A']['profit_share'] + $shops['ร้าน B']['profit_share']);
    }

    public function testNegativeShopKeepsNegativeShare(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 6000.0, 1000.0],   // A +5000
            [2, 1, 1000.0, 2000.0],   // B -1000 (ตัวถ่วง) → รวม 4000
        ]));

        $shops = $this->byShopName($service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops']);

        // เกิน 100% ได้ และร้านตัวถ่วงติดลบ — คงค่าจริงไม่ clamp
        $this->assertSame(125.0, $shops['ร้าน A']['profit_share']);
        $this->assertSame(-25.0, $shops['ร้าน B']['profit_share']);
    }

    public function testTotalProfitNotPositiveGivesNullShares(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 1000.0, 2000.0],   // A -1000
            [2, 1, 1000.0, 3000.0],   // B -2000 → รวม -3000
        ]));

        $rows = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops'];

        // ฐานติดลบ → สัดส่วนไม่มีความหมาย ต้อง null ทุกแถว
        foreach ($rows as $row) {
            $this->assertNull($row['profit_share']);
        }
    }

    public function testBreakEvenTotalGivesNullShares(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 3000.0, 1000.0],   // A +2000
            [2, 1, 1000.0, 3000.0],   // B -2000 → รวม 0 พอดี
        ]));

        $rows = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops'];

        foreach ($rows as $row) {
            $this->assertNull($row['profit_share']);
        }
    }

    public function testDayCountsSumAcrossMonths(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 1000.0, 100.0, 5],
            [1, 3, 1000.0, 100.0, 12],
            [1, 8, 1000.0, 100.0, 3],
            [2, 8, 1000.0, 100.0, 1],
        ]));

        $shops = $this->byShopName($service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops']);

        $this->assertSame(20, $shops['ร้าน A']['days_count']);   // 5 + 12 + 3
        $this->assertSame(1, $shops['ร้าน B']['days_count']);
    }

    public function testShopWithoutRecordsHasZeroDays(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 1, 1000.0, 100.0, 4]]));

        $shops = $this->byShopName($service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops']);

        $this->assertSame(4, $shops['ร้าน A']['days_count']);
        $this->assertSame(0, $shops['ร้าน B']['days_count']);
    }

    public function testFutureMonthDaysAreNotCounted(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 8, 1000.0, 100.0, 3],
            [1, 11, 1000.0, 100.0, 30],   // เกิน cutoff
        ]));

        $shops = $this->byShopName($service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops']);

        $this->assertSame(3, $shops['ร้าน A']['days_count']);
    }

    public function testBestMonthRanksByProfitNotRevenue(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 3, 20000.0, 19500.0],   // มี.ค. รายได้สูงสุด แต่กำไรแค่ 500
            [1, 5, 4000.0, 1000.0],     // พ.ค. กำไร 3000 ← ต้องเป็น best
        ]));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(5, $summary['best_month']['month']);
        $this->assertSame(3000.0, $summary['best_month']['profit']);
        $this->assertSame(3, $summary['worst_month']['month']);
        $this->assertSame(500.0, $summary['worst_month']['profit']);
    }

    public function testWorstMonthSkipsMonthsWithoutRecords(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 2, 3000.0, 1000.0],   // +2000
            [1, 7, 1000.0, 2000.0],   // -1000 ← ขาดทุนจริง ต้องเป็น worst
            // เดือนอื่นไม่มีข้อมูล (กำไร 0) — ต้องไม่ถูกเลือก
        ]));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(7, $summary['worst_month']['month']);
        $this->assertSame(-1000.0, $summary['worst_month']['profit']);
        $this->assertSame(2, $summary['best_month']['month']);
    }

    public function testBestWorstAreNullWithoutAnyRecords(): void
    {
        $service = $this->makeService();

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertNull($summary['best_month']);
        $this->assertNull($summary['worst_month']);
    }

    public function testFutureMonthIsNeverBestOrWorst(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 8, 3000.0, 1000.0],
            // ธ.ค. เกิน cutoff แม้มีเรคอร์ดล่วงหน้า
            [1, 12, 90000.0, 0.0],
        ]));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(8, $summary['best_month']['month']);
        $this->assertSame(8, $summary['worst_month']['month']);
    }

    public function testMonthsAreStillRankedWithinCutoffForPastYear(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [
            [1, 2, 1000.0, 200.0],     // +800
            [1, 11, 500.0, 1500.0],    // -1000
        ]));

        $data = $service->buildYearlyOverview(1, 2025, self::TODAY)['data'];

        $this->assertCount(12, $data['months']);
        $this->assertSame(2, $data['summary']['best_month']['month']);
        $this->assertSame(11, $data['summary']['worst_month']['month']);
    }
}
