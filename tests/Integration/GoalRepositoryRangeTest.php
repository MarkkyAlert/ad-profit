<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use GoalRepository;

/**
 * integration test ของ batch method getByShopAndMonthRange — DB จริง
 * (repository เป็น SQL ล้วน จึงเทสต์ที่ระดับ integration ไม่ใช่ unit)
 */
final class GoalRepositoryRangeTest extends IntegrationTestCase
{
    public function testReturnsGoalsWithinRangeOnly(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createGoal($shopId, '2025-12', 1000.0, 500.0);   // ก่อนช่วง
        $this->createGoal($shopId, '2026-01', 2000.0, 800.0);
        $this->createGoal($shopId, '2026-06', 3000.0, 900.0);
        $this->createGoal($shopId, '2026-12', 4000.0, 1000.0);
        $this->createGoal($shopId, '2027-01', 5000.0, 1100.0);   // หลังช่วง

        $goals = (new GoalRepository($this->pdo))->getByShopAndMonthRange($shopId, '2026-01', '2026-12');

        $months = array_map(static fn(array $row): string => (string)$row['goal_month'], $goals);
        $this->assertSame(['2026-01', '2026-06', '2026-12'], $months);   // เรียงตามเดือน
    }

    public function testMonthsWithoutGoalAreNotReturned(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createGoal($shopId, '2026-03', 2000.0, 800.0);

        $goals = (new GoalRepository($this->pdo))->getByShopAndMonthRange($shopId, '2026-01', '2026-12');

        // ตั้งเป้าเดือนเดียว → คืนแถวเดียว ไม่ใช่ 12 แถวว่าง
        $this->assertCount(1, $goals);
        $this->assertSame('2026-03', $goals[0]['goal_month']);
        $this->assertSame('2000.00', (string)$goals[0]['target_revenue']);
    }

    public function testNullTargetsSurviveTheQuery(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createGoal($shopId, '2026-02', 2000.0, null);
        $this->createGoal($shopId, '2026-04', null, 900.0);

        $goals = (new GoalRepository($this->pdo))->getByShopAndMonthRange($shopId, '2026-01', '2026-12');

        $this->assertNull($goals[0]['target_profit']);
        $this->assertNull($goals[1]['target_revenue']);
    }

    public function testGoalsOfAnotherShopAreNotReturned(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านหลัก');
        $otherShopId = $this->createShop($userId, 'ร้านอื่น');

        $this->createGoal($shopId, '2026-01', 2000.0, 800.0);
        $this->createGoal($otherShopId, '2026-01', 99999.0, 99999.0);

        $goals = (new GoalRepository($this->pdo))->getByShopAndMonthRange($shopId, '2026-01', '2026-12');

        $this->assertCount(1, $goals);
        $this->assertSame('2000.00', (string)$goals[0]['target_revenue']);
    }

    public function testEmptyRangeReturnsEmptyArray(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->assertSame([], (new GoalRepository($this->pdo))->getByShopAndMonthRange($shopId, '2026-01', '2026-12'));
    }
}
