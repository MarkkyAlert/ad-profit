<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของกริดฤดูกาล 3 ปี — seed 3 ปีลง DB จริง
 */
final class AnnualServiceHeatmapTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): AnnualService
    {
        return new AnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        );
    }

    public function testSeasonalPatternAcrossThreeYears(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // มี.ค. กำไรดีทุกปี = ฤดูกาลขายคอร์ส
        $this->createRecord($shopId, '2024-03-10', 5000.0, 1000.0);   // +4000
        $this->createRecord($shopId, '2025-03-10', 8000.0, 2000.0);   // +6000
        $this->createRecord($shopId, '2026-03-10', 9000.0, 1500.0);   // +7500

        // ก.ย. ขาดทุนปี 2025
        $this->createRecord($shopId, '2025-09-10', 1000.0, 4000.0);   // -3000

        $data = $this->makeService()->buildMonthlyHeatmap($userId, $shopId, 2026, self::TODAY)['data'];

        $this->assertSame([2024, 2025, 2026], $data['years']);

        $grid = $data['grid'];
        $this->assertSame(4000.0, $grid[2024][3]['profit']);
        $this->assertSame(6000.0, $grid[2025][3]['profit']);
        $this->assertSame(7500.0, $grid[2026][3]['profit']);
        $this->assertSame(-3000.0, $grid[2025][9]['profit']);

        // เดือนที่ไม่มีข้อมูล → null ไม่ใช่ 0
        $this->assertNull($grid[2024][1]['profit']);
        $this->assertFalse($grid[2024][1]['has_data']);
    }

    public function testBreakEvenIsDistinctFromNoData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2025-05-10', 2000.0, 2000.0);   // เท่าทุนพอดี

        $grid = $this->makeService()->buildMonthlyHeatmap($userId, $shopId, 2026, self::TODAY)['data']['grid'];

        $this->assertSame(0.0, $grid[2025][5]['profit']);
        $this->assertTrue($grid[2025][5]['has_data']);

        $this->assertNull($grid[2025][6]['profit']);
        $this->assertFalse($grid[2025][6]['has_data']);
    }

    public function testFutureMonthsOfCurrentYearStayEmpty(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-10', 3000.0, 1000.0);
        // เรคอร์ดล่วงหน้า — ต้องไม่โผล่ในกริด
        $this->createRecord($shopId, '2026-11-10', 90000.0, 0.0);

        $grid = $this->makeService()->buildMonthlyHeatmap($userId, $shopId, 2026, self::TODAY)['data']['grid'];

        $this->assertSame(2000.0, $grid[2026][8]['profit']);
        $this->assertNull($grid[2026][11]['profit']);
        $this->assertFalse($grid[2026][11]['has_data']);
    }

    public function testAnotherShopDataDoesNotLeakIn(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านหลัก');
        $otherShopId = $this->createShop($userId, 'ร้านอื่น');

        $this->createRecord($shopId, '2025-03-10', 3000.0, 1000.0);
        $this->createRecord($otherShopId, '2025-03-10', 99999.0, 0.0);

        $grid = $this->makeService()->buildMonthlyHeatmap($userId, $shopId, 2026, self::TODAY)['data']['grid'];

        $this->assertSame(2000.0, $grid[2025][3]['profit']);
    }

    public function testForeignShopIsRejected(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($ownerId);

        $intruderId = $this->createUser('intruder@example.com');

        $result = $this->makeService()->buildMonthlyHeatmap($intruderId, $shopId, 2026, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testEmptyShopGivesFullyEmptyGrid(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $data = $this->makeService()->buildMonthlyHeatmap($userId, $shopId, 2026, self::TODAY)['data'];

        $this->assertCount(3, $data['grid']);
        foreach ($data['grid'] as $row) {
            $this->assertCount(12, $row);
            foreach ($row as $cell) {
                $this->assertNull($cell['profit']);
            }
        }
    }
}
