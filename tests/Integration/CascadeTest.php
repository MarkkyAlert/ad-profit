<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use ShopRepository;

final class CascadeTest extends IntegrationTestCase
{
    public function testDeletingShopCascadesRecordsAndGoals(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2024-01-01', 100.0, 10.0);
        $this->createGoal($shopId, '2024-01', 1000.0, 500.0);

        $this->assertSame(1, $this->countRows('daily_records'));
        $this->assertSame(1, $this->countRows('monthly_goals'));

        (new ShopRepository($this->pdo))->deleteByIdAndUserId($shopId, $userId);

        // FK ON DELETE CASCADE: ลบร้าน → รายวัน + เป้าหมายของร้านหายหมด
        $this->assertSame(0, $this->countRows('shops'));
        $this->assertSame(0, $this->countRows('daily_records'));
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    public function testDeletingUserCascadesShopsRecordsAndGoals(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2024-02-01', 200.0, 20.0);
        $this->createGoal($shopId, '2024-02', 2000.0, 800.0);

        // ไม่มี UserRepository::delete → ลบผ่าน SQL ตรงในเทสต์
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        // FK ON DELETE CASCADE: ลบ user → ร้าน + รายวัน + เป้าหมายหายหมด
        $this->assertSame(0, $this->countRows('users'));
        $this->assertSame(0, $this->countRows('shops'));
        $this->assertSame(0, $this->countRows('daily_records'));
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }
}
