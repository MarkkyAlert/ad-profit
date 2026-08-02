<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use GoalRepository;

final class GoalRepositoryTest extends IntegrationTestCase
{
    public function testUpsertSameShopAndMonthUpdatesInsteadOfInserting(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $repo = new GoalRepository($this->pdo);
        $repo->upsert($shopId, '2024-03', 1000.0, 500.0);
        $repo->upsert($shopId, '2024-03', 2000.0, 900.0); // shop + month เดิม → ต้องเป็น UPDATE

        // unique(shop_id, goal_month) → ยังมีแถวเดียว
        $this->assertSame(1, $this->countRows('monthly_goals'));

        $goal = $repo->findByShopAndMonth($shopId, '2024-03');
        $this->assertNotNull($goal);
        $this->assertSame(2000.0, (float)$goal['target_revenue']); // ค่าถูกอัปเดต
        $this->assertSame(900.0, (float)$goal['target_profit']);
    }
}
