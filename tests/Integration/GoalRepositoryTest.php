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

    /**
     * upsert เขียนทับทั้ง 2 ฟิลด์เสมอ — ส่ง null มาแปลว่า "ลบเป้านั้น" ไม่ใช่ "ไม่เปลี่ยน"
     *
     * ล็อกไว้เป็นพฤติกรรมที่ตั้งใจ: ฟอร์มตั้งเป้าส่งครบทั้งสองช่องทุกครั้ง การล้างช่องหนึ่ง
     * ให้ว่างจึงต้องกลายเป็น NULL จริง ถ้าวันหนึ่งเปลี่ยนเป็น partial update เทสต์นี้จะแดง
     * และต้องตามไปแก้ contract ของ controller ด้วย
     */
    public function testUpsertClearsTheFieldThatIsPassedAsNull(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $repo = new GoalRepository($this->pdo);
        $repo->upsert($shopId, '2024-03', 1000.0, 500.0);
        $repo->upsert($shopId, '2024-03', 1000.0, null); // ผู้ใช้ล้างช่อง "เป้ากำไร"

        $goal = $repo->findByShopAndMonth($shopId, '2024-03');
        $this->assertNotNull($goal);
        $this->assertSame(1000.0, (float)$goal['target_revenue']);
        $this->assertNull($goal['target_profit']);
    }
}
