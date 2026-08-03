<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของ goal_progress — seed เป้า + ยอดจริงหลายเดือนลง DB จริง
 */
final class AnnualServiceGoalTest extends IntegrationTestCase
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

    public function testGoalProgressMatchesRealRecords(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ม.ค. กรอก 2 วัน → รายได้ 8000 · กำไร 5000
        $this->createRecord($shopId, '2026-01-10', 5000.0, 2000.0);
        $this->createRecord($shopId, '2026-01-11', 3000.0, 1000.0);
        $this->createGoal($shopId, '2026-01', 10000.0, 4000.0);

        // มี.ค. ไม่ได้ตั้งเป้า — ต้องไม่โผล่
        $this->createRecord($shopId, '2026-03-10', 9000.0, 1000.0);

        $progress = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertCount(1, $progress);
        $this->assertSame(1, $progress[0]['month']);
        $this->assertSame(8000.0, $progress[0]['actual_revenue']);
        $this->assertSame(5000.0, $progress[0]['actual_profit']);
        $this->assertSame(80.0, $progress[0]['revenue_progress']);
        $this->assertSame(125.0, $progress[0]['profit_progress']);
        $this->assertFalse($progress[0]['revenue_reached']);
        $this->assertTrue($progress[0]['profit_reached']);
    }

    public function testFutureMonthGoalIsCutOff(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-02-10', 5000.0, 1000.0);
        $this->createGoal($shopId, '2026-02', 4000.0, 2000.0);
        // ตั้งเป้า ธ.ค. ล่วงหน้า (today = ส.ค.) — ไม่ควรโชว์ว่าทำได้ 0%
        $this->createGoal($shopId, '2026-12', 50000.0, 20000.0);

        $progress = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertCount(1, $progress);
        $this->assertSame(2, $progress[0]['month']);
        $this->assertTrue($progress[0]['revenue_reached']);
    }

    public function testGoalsOfAnotherShopDoNotLeakIn(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านหลัก');
        $otherShopId = $this->createShop($userId, 'ร้านอื่น');

        $this->createRecord($shopId, '2026-01-10', 5000.0, 1000.0);
        $this->createGoal($otherShopId, '2026-01', 99999.0, 99999.0);

        $progress = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertSame([], $progress);
    }

    public function testGoalWithoutRecordsShowsZeroProgress(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ตั้งเป้าเดือนที่ผ่านมาแล้วแต่ยังไม่ได้กรอกยอดเลย
        $this->createGoal($shopId, '2026-05', 10000.0, 5000.0);

        $progress = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertCount(1, $progress);
        $this->assertSame(0.0, $progress[0]['actual_revenue']);
        $this->assertSame(0.0, $progress[0]['revenue_progress']);
        $this->assertFalse($progress[0]['revenue_reached']);
    }

    public function testNoGoalsGivesEmptyProgress(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-01-10', 5000.0, 1000.0);

        $data = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data'];

        $this->assertSame([], $data['goal_progress']);
        // ของเดิมยังทำงานปกติ
        $this->assertCount(8, $data['months']);
        $this->assertSame(4000.0, $data['summary']['profit']);
    }
}
