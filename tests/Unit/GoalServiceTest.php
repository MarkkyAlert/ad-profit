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

    /**
     * ⭐⭐⭐ **นโยบายการปัด % เป้าหมาย — ปัดเข้าหาศูนย์ทั้งสองฝั่ง**
     *
     * ✅ **[เจ้าของระบบตัดสิน 2026-08-10]** เลือก "ปัดเข้าหาศูนย์เมื่อติดลบ"
     * จากสามทางเลือก (ปัดลงเสมอ · ปัดปกติ · ปัดเข้าหาศูนย์)
     *
     * ⚠️ **ทำไมไม่ใช้ `floor()` เสมอ**: ฝั่งบวก `floor()` = ปัดเข้าหาศูนย์อยู่แล้ว
     * แต่ฝั่งลบมันปัด **ออกจาก** ศูนย์ → ขาดทุน 1 สตางค์จากเป้า ฿100 (ค่าจริง −0.01%)
     * ถูกแสดงเป็น **−0.1%** คือขยายความติดลบ 10 เท่า
     *
     * ⚠️ **ทำไมไม่ใช้ `round()`**: 99.996% จะขึ้นเป็น 100.0% ทั้งที่ยังไม่ถึงเป้า
     * แล้วขัดกับป้าย "ยังไม่ถึงเป้า" ในการ์ดเดียวกัน (ป้ายเทียบค่าจริง ไม่ใช่ % ที่ปัดแล้ว)
     *
     * @return array<string,array{0:float,1:float,2:?float}>
     */
    public static function progressRoundingProvider(): array
    {
        return [
            'เกือบถึงเป้า — ห้ามขึ้นเป็น 100' => [99.996, 100.0, 99.9],
            'ถึงเป้าพอดี' => [100.0, 100.0, 100.0],
            'เกินเป้า' => [150.5, 100.0, 150.5],
            'ขาดทุน 1 สตางค์ — ห้ามขยายเป็น -0.1' => [-0.01, 100.0, -0.0],
            'ขาดทุนที่ปัดเข้าหาศูนย์แล้วยังเห็น' => [-0.19, 100.0, -0.1],
            'ขาดทุนหนัก' => [-50.0, 100.0, -50.0],
            'เป้าเป็นศูนย์ — ยังไม่ได้ตั้งเป้าจริง' => [50.0, 0.0, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('progressRoundingProvider')]
    public function testProgressPercentRoundsTowardsZero(
        float $actual,
        float $target,
        ?float $expected
    ): void {
        $this->assertSame(
            $expected,
            GoalService::progressPercent($actual, $target),
            '% ความคืบหน้าเป้าไม่ตรงกับนโยบายการปัดที่เจ้าของระบบเลือกไว้'
        );
    }
}
