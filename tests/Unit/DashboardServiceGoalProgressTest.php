<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ReflectionMethod;
use ShopRepository;

/**
 * แถบความคืบหน้าต้องไม่ขึ้น 100.0% ตอนที่ป้ายยังบอก "ยังไม่ถึงเป้า"
 *
 * ป้าย reached เทียบค่าจริง (แก้ไปแล้วรอบก่อน) แต่ progress ยังใช้ round(…, 1)
 * → 9,999.60 จากเป้า 10,000 = 99.996% → ปัดเป็น 100.0% · ผู้ใช้เห็นแถบเต็ม
 * คู่กับป้ายสีส้ม "ยังไม่ถึงเป้า" ในการ์ดเดียวกัน
 */
final class DashboardServiceGoalProgressTest extends TestCase
{
    private function percent(float $actual, ?float $target): ?float
    {
        $service = new DashboardService(
            $this->createStub(RecordRepository::class),
            $this->createStub(ShopRepository::class),
            $this->createStub(GoalRepository::class)
        );

        $method = new ReflectionMethod(DashboardService::class, 'calculateGoalPercent');

        /** @var float|null $value */
        $value = $method->invoke($service, $actual, $target);

        return $value;
    }

    /** ⭐ เกือบถึงต้องไม่แสดงเป็นถึง */
    public function testAlmostReachedNeverRoundsUpToOneHundred(): void
    {
        $this->assertLessThan(100.0, $this->percent(9999.60, 10000.0));
    }

    public function testExactlyOnTargetIsOneHundred(): void
    {
        $this->assertSame(100.0, $this->percent(10000.0, 10000.0));
    }

    public function testOverTargetStaysAtOrAboveOneHundred(): void
    {
        $this->assertGreaterThanOrEqual(100.0, $this->percent(10000.01, 10000.0));
    }

    /** ค่าปกติยังคงอ่านง่ายเหมือนเดิม (ทศนิยม 1 ตำแหน่ง) */
    public function testOrdinaryValuesKeepOneDecimal(): void
    {
        $this->assertSame(45.6, $this->percent(4560.0, 10000.0));
        $this->assertSame(0.0, $this->percent(0.0, 10000.0));
    }

    public function testNoTargetGivesNoPercent(): void
    {
        $this->assertNull($this->percent(1000.0, null));
        $this->assertNull($this->percent(1000.0, 0.0));
    }
}
