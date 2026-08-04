<?php

declare(strict_types=1);

namespace Tests\Unit;

use GoalRepository;
use GoalService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ตัวเลขที่มีทศนิยมเกิน 2 ตำแหน่งต้องถูกปฏิเสธ ไม่ใช่ปัดให้เงียบ ๆ
 *
 * คอลัมน์เป็น DECIMAL(12,2) — พิมพ์ 1,234.567 แล้วระบบตอบ "บันทึกเรียบร้อยแล้ว"
 * แต่เก็บจริง 1,234.57 · `step="0.01"` เป็นแค่ฝั่งเบราว์เซอร์ ทาง CSV ไม่มีอะไรกันเลย
 * และถ้า MySQL ไม่ได้เปิด strict mode ค่าที่เกินขอบเขตก็ถูกตัดเงียบเช่นกัน
 */
final class AmountPrecisionTest extends TestCase
{
    private function makeRecordService(): RecordService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new RecordService($this->createStub(RecordRepository::class), $shopRepository, null);
    }

    private function makeGoalService(): GoalService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'user_id' => 1]);

        return new GoalService($this->createStub(GoalRepository::class), $shopRepository, null);
    }

    /** ⭐ รายได้ทศนิยม 3 ตำแหน่ง → ปฏิเสธพร้อมบอกเหตุผล */
    public function testRevenueWithMoreThanTwoDecimalsIsRejected(): void
    {
        $result = $this->makeRecordService()->upsertRecord(1, 1, '2026-08-01', 1234.567, 100.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ทศนิยม', $result['error']);
    }

    public function testAdCostWithMoreThanTwoDecimalsIsRejected(): void
    {
        $result = $this->makeRecordService()->upsertRecord(1, 1, '2026-08-01', 100.0, 100.999, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ทศนิยม', $result['error']);
    }

    /** ทศนิยม 2 ตำแหน่งพอดี และจำนวนเต็ม ต้องผ่านตามปกติ */
    public function testTwoDecimalsAndWholeNumbersAreAccepted(): void
    {
        $service = $this->makeRecordService();

        foreach ([1234.56, 1234.5, 1234.0, 0.0, 0.01] as $amount) {
            $result = $service->upsertRecord(1, 1, '2026-08-01', $amount, 0.0, null);
            $this->assertTrue($result['success'], 'ปฏิเสธค่าที่ควรผ่าน: ' . $amount);
        }
    }

    /** ⚠️ ค่าที่ทศนิยมยาวเพราะ floating point ล้วน ๆ ต้องไม่ถูกปฏิเสธ */
    public function testFloatingPointNoiseIsNotTreatedAsExtraPrecision(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004 ในเลขทศนิยมฐานสอง
        $result = $this->makeRecordService()->upsertRecord(1, 1, '2026-08-01', 0.1 + 0.2, 0.0, null);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** เป้าหมายใช้คอลัมน์ชนิดเดียวกัน — เกณฑ์ต้องตรงกัน */
    public function testGoalAmountsUseTheSameRule(): void
    {
        $rejected = $this->makeGoalService()->upsertGoal(1, 1, '2026-08', 20000.555, null);
        $this->assertFalse($rejected['success']);
        $this->assertStringContainsString('ทศนิยม', $rejected['error']);

        $accepted = $this->makeGoalService()->upsertGoal(1, 1, '2026-08', 20000.55, null);
        $this->assertTrue($accepted['success'], (string)($accepted['error'] ?? ''));
    }
}
