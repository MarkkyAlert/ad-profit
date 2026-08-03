<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewAnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของการตัดเดือนอนาคตในมุมปีของหน้ารวมร้าน
 * (แพตเทิร์นเดียวกับ AnnualService ของร้านเดี่ยว — ก่อนแก้ กราฟดิ่ง ฿0 ตั้งแต่เดือนหน้า)
 * today คงที่ = 2026-08-15 (ปีปัจจุบัน = 2026, cutoff = ส.ค.)
 */
final class OverviewAnnualServiceCutoffTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     */
    private function makeService(array $monthlyTotals = []): OverviewAnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($monthlyTotals): array {
                // จำลอง BETWEEN — คืนเฉพาะเดือนที่อยู่ในช่วงที่ service ขอ
                return array_values(array_filter(
                    $monthlyTotals,
                    static fn(array $row): bool => (string)$row['month_key'] >= $start
                        && (string)$row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        return new OverviewAnnualService($recordRepository, $shopRepository);
    }

    /**
     * @param array<int,array{0:int,1:int,2:float,3:float}> $rows [shopId, เดือน, รายได้, ค่าแอด]
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
            ],
            $rows
        );
    }

    public function testCurrentYearStopsAtCurrentMonth(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 5000.0, 1000.0],
            [2, 8, 3000.0, 1000.0],
        ]));

        $data = $service->buildYearlyOverview(1, 2026, self::TODAY)['data'];

        // ส.ค. = เดือนที่ 8 → ไม่มี ก.ย.–ธ.ค. โผล่มาเป็น ฿0
        $this->assertSame(8, $data['last_month']);
        $this->assertCount(8, $data['months']);
        $this->assertSame('2026-08', end($data['months'])['month_key']);
        $this->assertCount(8, $data['chart']['months']);
        $this->assertNotContains('2026-12', $data['chart']['months']);
    }

    public function testPastYearKeepsAllTwelveMonths(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [[1, 3, 1000.0, 100.0]]));

        $data = $service->buildYearlyOverview(1, 2025, self::TODAY)['data'];

        $this->assertSame(12, $data['last_month']);
        $this->assertCount(12, $data['months']);
        $this->assertSame('2025-12', end($data['months'])['month_key']);
    }

    public function testFutureYearHasNoMonths(): void
    {
        $service = $this->makeService();

        $data = $service->buildYearlyOverview(1, 2027, self::TODAY)['data'];

        $this->assertSame(0, $data['last_month']);
        $this->assertSame([], $data['months']);
        $this->assertSame([], $data['chart']['months']);
        $this->assertSame(0.0, $data['summary']['total_revenue']);
    }

    public function testFutureMonthRecordsDoNotLeakIntoTotals(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 8, 3000.0, 1000.0],
            // เรคอร์ดลงวันที่ล่วงหน้า — ต้องไม่ถูกนับทั้งยอดรวมและยอดต่อร้าน
            [2, 11, 90000.0, 0.0],
        ]));

        $data = $service->buildYearlyOverview(1, 2026, self::TODAY)['data'];

        $this->assertSame(3000.0, $data['summary']['total_revenue']);
        $this->assertSame(2000.0, $data['summary']['profit']);

        $shopById = [];
        foreach ($data['shops'] as $shopRow) {
            $shopById[(int)$shopRow['shop_id']] = $shopRow;
        }
        $this->assertSame(0.0, $shopById[2]['total_revenue']);
    }

    public function testChartProfitSeriesHasNoTrailingZeroMonths(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 5000.0, 1000.0],
            [1, 8, 3000.0, 1000.0],
        ]));

        $chart = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['chart'];

        $this->assertCount(8, $chart['profit']);
        $this->assertCount(8, $chart['revenue']);
        $this->assertCount(8, $chart['ad_cost']);
        $this->assertSame(4000.0, $chart['profit'][0]);
        $this->assertSame(2000.0, $chart['profit'][7]);   // ส.ค. เป็นจุดสุดท้าย ไม่ใช่ ธ.ค. ที่เป็น 0
    }

    public function testShopTotalsStillCoverTheWholeAllowedRange(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 1, 5000.0, 1000.0],
            [1, 8, 3000.0, 1000.0],
            [2, 2, 2000.0, 500.0],
        ]));

        $data = $service->buildYearlyOverview(1, 2026, self::TODAY)['data'];

        $shopById = [];
        foreach ($data['shops'] as $shopRow) {
            $shopById[(int)$shopRow['shop_id']] = $shopRow;
        }

        // cutoff ไม่ควรตัดเดือนที่ผ่านมาแล้วออกจากยอดต่อร้าน
        $this->assertSame(8000.0, $shopById[1]['total_revenue']);
        $this->assertSame(6000.0, $shopById[1]['profit']);
        $this->assertSame(1500.0, $shopById[2]['profit']);
    }
}
