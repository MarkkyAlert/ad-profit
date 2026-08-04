<?php

declare(strict_types=1);

namespace Tests\Unit;

use GoalRepository;
use GoalService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

/**
 * เพดานค่าเงินของเป้าหมาย — คู่แฝดของ RecordService::MAX_AMOUNT ที่ตกหล่น
 *
 * monthly_goals.target_revenue/target_profit เป็น DECIMAL(12,2) เท่ากับ daily_records
 * แต่ GoalService ตรวจแค่ "ไม่ติดลบ" → ค่าเกินเพดานหลุดลง MySQL: strict mode = throw
 * แล้วโผล่เป็น "ไม่สามารถบันทึกเป้าหมายได้" ที่ไม่บอกสาเหตุ · non-strict = ตัดค่าเงียบ
 * แล้วรายงานว่าสำเร็จ ทั้งที่เป้าที่เก็บไว้ไม่ใช่ตัวเลขที่ผู้ใช้กรอก
 */
final class GoalServiceAmountCeilingTest extends TestCase
{
    private function makeService(): GoalService
    {
        $goalRepository = $this->createStub(GoalRepository::class);
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'user_id' => 1]);

        return new GoalService($goalRepository, $shopRepository, null);
    }

    /** ⭐ เพดานต้องเป็นตัวเลขเดียวกับฝั่งบันทึกรายวัน — คอลัมน์ชนิดเดียวกัน */
    public function testGoalCeilingIsTheSameNumberAsTheDailyRecordCeiling(): void
    {
        $this->assertSame(
            RecordService::MAX_AMOUNT,
            GoalService::MAX_AMOUNT,
            'เพดานสองฝั่งไม่ตรงกัน ทั้งที่คอลัมน์เป็น DECIMAL(12,2) เหมือนกัน'
        );
    }

    public function testTargetRevenueOverTheCeilingIsRejected(): void
    {
        $result = $this->makeService()->upsertGoal(1, 1, '2026-08', GoalService::MAX_AMOUNT + 1, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่เกิน', $result['error']);
    }

    public function testTargetProfitOverTheCeilingIsRejected(): void
    {
        $result = $this->makeService()->upsertGoal(1, 1, '2026-08', null, 1.0e15);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่เกิน', $result['error']);
    }

    public function testExactlyTheCeilingIsAccepted(): void
    {
        $result = $this->makeService()
            ->upsertGoal(1, 1, '2026-08', GoalService::MAX_AMOUNT, GoalService::MAX_AMOUNT);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** ค่าที่ไม่ใช่ตัวเลขจริง (INF/NAN) ต้องไม่หลุดไปถึง SQL */
    public function testNonFiniteValuesAreRejected(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->upsertGoal(1, 1, '2026-08', INF, null)['success']);
        $this->assertFalse($service->upsertGoal(1, 1, '2026-08', null, NAN)['success']);
    }
}
