<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของกริดฤดูกาล 12 เดือน × 3 ปี
 * today คงที่ = 2026-08-15 (ปีปัจจุบัน = 2026, เดือนปัจจุบัน = ส.ค.)
 */
final class AnnualServiceHeatmapTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     */
    private function makeService(array $monthlyTotals = [], bool $canAccess = true): AnnualService
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
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));
    }

    /**
     * @param array<int,array{0:int,1:float,2:float}> $months [เดือน, รายได้, ค่าแอด]
     * @return array<int,array<string,mixed>>
     */
    private function totalsFor(int $year, array $months): array
    {
        return array_map(
            static fn(array $row): array => [
                'month_key' => sprintf('%04d-%02d', $year, $row[0]),
                'total_revenue' => $row[1],
                'total_ad_cost' => $row[2],
                'days_count' => 3,
            ],
            $months
        );
    }

    public function testGridCoversThreeYearsOldestFirst(): void
    {
        $service = $this->makeService();

        $data = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data'];

        $this->assertSame([2024, 2025, 2026], $data['years']);
        $this->assertCount(3, $data['grid']);
        foreach ($data['years'] as $year) {
            $this->assertCount(12, $data['grid'][$year]);
        }
    }

    public function testProfitPerCellIsRevenueMinusAdCost(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2024, [[3, 5000.0, 1000.0]]),
            $this->totalsFor(2025, [[3, 8000.0, 2000.0]]),
            $this->totalsFor(2026, [[3, 9000.0, 1500.0]])
        ));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        // มี.ค. เขียวติดกัน 3 ปี = ฤดูกาลจริง
        $this->assertSame(4000.0, $grid[2024][3]['profit']);
        $this->assertSame(6000.0, $grid[2025][3]['profit']);
        $this->assertSame(7500.0, $grid[2026][3]['profit']);
        $this->assertTrue($grid[2024][3]['has_data']);
        $this->assertSame(3, $grid[2026][3]['month']);
    }

    public function testMonthWithoutDataIsNullNotZero(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 5000.0, 1000.0]]));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        $this->assertNull($grid[2026][2]['profit']);
        $this->assertFalse($grid[2026][2]['has_data']);
    }

    public function testBreakEvenMonthIsZeroWithData(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[4, 2000.0, 2000.0]]));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        // เท่าทุน = 0.0 + has_data true — ต้องแยกออกจาก "ไม่มีข้อมูล" ที่เป็น null
        $this->assertSame(0.0, $grid[2026][4]['profit']);
        $this->assertTrue($grid[2026][4]['has_data']);
        $this->assertNotNull($grid[2026][4]['profit']);
    }

    public function testLossMonthKeepsNegativeValue(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [[9, 1000.0, 4000.0]]));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        $this->assertSame(-3000.0, $grid[2025][9]['profit']);
        $this->assertTrue($grid[2025][9]['has_data']);
    }

    public function testFutureMonthsOfCurrentYearAreEmpty(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [8, 3000.0, 1000.0],
            // เรคอร์ดลงวันที่ล่วงหน้า — ไม่ควรโผล่เป็นช่องเขียวในเดือนที่ยังไม่ถึง
            [11, 90000.0, 0.0],
        ]));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        $this->assertSame(2000.0, $grid[2026][8]['profit']);   // ส.ค. = เดือนปัจจุบัน ยังนับ
        foreach ([9, 10, 11, 12] as $futureMonth) {
            $this->assertNull($grid[2026][$futureMonth]['profit']);
            $this->assertFalse($grid[2026][$futureMonth]['has_data']);
        }
    }

    public function testPastYearsKeepAllTwelveMonthsAvailable(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [[12, 3000.0, 1000.0]]));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        // ธ.ค. ปีก่อนผ่านมาแล้ว — ต้องไม่โดน cutoff
        $this->assertSame(2000.0, $grid[2025][12]['profit']);
        $this->assertTrue($grid[2025][12]['has_data']);
    }

    public function testYearWithoutAnyDataIsAllNull(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 5000.0, 1000.0]]));

        $grid = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data']['grid'];

        // 2024 ไม่มีข้อมูลเลย → ทั้งแถวว่าง
        foreach ($grid[2024] as $cell) {
            $this->assertNull($cell['profit']);
            $this->assertFalse($cell['has_data']);
        }
    }

    public function testDataOutsideTheThreeYearWindowIsExcluded(): void
    {
        $service = $this->makeService($this->totalsFor(2023, [[6, 90000.0, 0.0]]));

        $data = $service->buildMonthlyHeatmap(1, 1, 2026, self::TODAY)['data'];

        // 2023 อยู่นอกหน้าต่าง 3 ปี — ต้องไม่มีในกริด และไม่ไปโผล่ปีอื่น
        $this->assertArrayNotHasKey(2023, $data['grid']);
        $this->assertNull($data['grid'][2024][6]['profit']);
    }

    public function testViewingPastYearShowsFullGrid(): void
    {
        $service = $this->makeService($this->totalsFor(2023, [[6, 3000.0, 1000.0]]));

        $data = $service->buildMonthlyHeatmap(1, 1, 2025, self::TODAY)['data'];

        $this->assertSame([2023, 2024, 2025], $data['years']);
        $this->assertSame(2000.0, $data['grid'][2023][6]['profit']);
        // ปีอดีตล้วน — ธ.ค. 2025 ผ่านมาแล้ว ไม่โดน cutoff
        $this->assertFalse($data['grid'][2025][12]['has_data']);
        $this->assertArrayHasKey(12, $data['grid'][2025]);
    }

    public function testInvalidYearIsRejected(): void
    {
        $service = $this->makeService();

        foreach ([1999, 2101] as $invalidYear) {
            $result = $service->buildMonthlyHeatmap(1, 1, $invalidYear, self::TODAY);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('ไม่ถูกต้อง', $result['error']);
        }
    }

    public function testForeignShopIsRejected(): void
    {
        $service = $this->makeService([], false);

        $result = $service->buildMonthlyHeatmap(1, 999, 2026, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
