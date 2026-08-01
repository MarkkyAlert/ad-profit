<?php

declare(strict_types=1);

namespace Tests\Unit;

use GoalRepository;
use GoalService;
use PHPUnit\Framework\TestCase;
use ShopRepository;

final class GoalServiceTest extends TestCase
{
    public function testUpsertGoalFailsWhenNoTargetProvided(): void
    {
        $goalRepository = $this->createStub(GoalRepository::class);

        $shopRepository = $this->createStub(ShopRepository::class);
        // ผ่าน ownership check (คืนร้านที่ผู้ใช้เป็นเจ้าของ)
        $shopRepository->method('findByIdAndUserId')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'name' => 'ร้านของฉัน',
        ]);

        $service = new GoalService($goalRepository, $shopRepository, null);

        // ไม่กรอกเป้าเลย: target_revenue = null และ target_profit = null
        $result = $service->upsertGoal(1, 1, '2024-01', null, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('อย่างน้อย 1', $result['error']);
    }
}
