<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ DashboardService::calculateGoalPace
 * เป็น pure method (ไม่แตะ repo) — stub repository ไว้แค่ให้สร้าง service ได้
 */
final class DashboardServiceGoalPaceTest extends TestCase
{
    private function makeService(): DashboardService
    {
        return new DashboardService(
            $this->createStub(RecordRepository::class),
            $this->createStub(ShopRepository::class),
            $this->createStub(GoalRepository::class)
        );
    }

    public function testCurrentMonthMidMonthComputesDaysAndPerDay(): void
    {
        // ส.ค. มี 31 วัน, วันที่ 10 → เหลือ 31-10+1 = 22 วัน
        $result = $this->makeService()->calculateGoalPace(
            100000.0,
            40000.0,
            null,
            0.0,
            '2026-08',
            '2026-08-10'
        );

        $this->assertTrue($result['pace_applicable']);
        $this->assertSame('current', $result['month_status']);
        $this->assertSame(22, $result['days_remaining']);
        $this->assertSame(60000.0, $result['remaining_revenue']);
        $this->assertSame(round(60000 / 22, 2), $result['required_per_day_revenue']);
    }

    public function testLastDayOfMonthLeavesOneDay(): void
    {
        $result = $this->makeService()->calculateGoalPace(
            100000.0,
            40000.0,
            null,
            0.0,
            '2026-08',
            '2026-08-31'
        );

        $this->assertSame(1, $result['days_remaining']);          // รวมวันนี้
        $this->assertSame(60000.0, $result['required_per_day_revenue']);
    }

    public function testFirstDayOfMonthCountsWholeMonth(): void
    {
        $result = $this->makeService()->calculateGoalPace(
            31000.0,
            0.0,
            null,
            0.0,
            '2026-08',
            '2026-08-01'
        );

        $this->assertSame(31, $result['days_remaining']);
        $this->assertSame(1000.0, $result['required_per_day_revenue']);
    }

    public function testAlreadyExceededTargetGivesZeroRemaining(): void
    {
        $result = $this->makeService()->calculateGoalPace(
            100000.0,
            120000.0,
            null,
            0.0,
            '2026-08',
            '2026-08-10'
        );

        $this->assertSame(0.0, $result['remaining_revenue']);      // ไม่ติดลบ
        $this->assertSame(0.0, $result['required_per_day_revenue']);
    }

    public function testPastMonthIsNotApplicable(): void
    {
        $result = $this->makeService()->calculateGoalPace(
            100000.0,
            40000.0,
            null,
            0.0,
            '2026-07',
            '2026-08-10'
        );

        $this->assertFalse($result['pace_applicable']);
        $this->assertSame('ended', $result['month_status']);
        $this->assertNull($result['days_remaining']);
        $this->assertNull($result['required_per_day_revenue']);
    }

    public function testUpcomingMonthUsesFullMonthLength(): void
    {
        // ก.ย. มี 30 วัน
        $result = $this->makeService()->calculateGoalPace(
            90000.0,
            0.0,
            null,
            0.0,
            '2026-09',
            '2026-08-10'
        );

        $this->assertTrue($result['pace_applicable']);
        $this->assertSame('upcoming', $result['month_status']);
        $this->assertSame(30, $result['days_remaining']);
        $this->assertSame(3000.0, $result['required_per_day_revenue']);
    }

    public function testNullRevenueTargetStillComputesProfitPace(): void
    {
        // ส.ค. วันที่ 16 → เหลือ 31-16+1 = 16 วัน
        $result = $this->makeService()->calculateGoalPace(
            null,
            0.0,
            60000.0,
            15000.0,
            '2026-08',
            '2026-08-16'
        );

        $this->assertNull($result['remaining_revenue']);
        $this->assertNull($result['required_per_day_revenue']);
        $this->assertSame(16, $result['days_remaining']);
        $this->assertSame(45000.0, $result['remaining_profit']);
        $this->assertSame(2812.5, $result['required_per_day_profit']);
    }

    public function testLeapYearFebruaryCurrentMonth(): void
    {
        // ก.พ. 2024 มี 29 วัน, วันที่ 10 → เหลือ 20 วัน
        $result = $this->makeService()->calculateGoalPace(
            29000.0,
            0.0,
            null,
            0.0,
            '2024-02',
            '2024-02-10'
        );

        $this->assertSame(20, $result['days_remaining']);
        $this->assertSame(1450.0, $result['required_per_day_revenue']);
    }

    public function testNonLeapFebruaryCurrentMonth(): void
    {
        $result = $this->makeService()->calculateGoalPace(
            28000.0,
            0.0,
            null,
            0.0,
            '2026-02',
            '2026-02-01'
        );

        $this->assertSame(28, $result['days_remaining']);
        $this->assertSame(1000.0, $result['required_per_day_revenue']);
    }

    public function testInvalidMonthIsNotApplicable(): void
    {
        $result = $this->makeService()->calculateGoalPace(
            100000.0,
            0.0,
            null,
            0.0,
            '2026-13',
            '2026-08-10'
        );

        $this->assertFalse($result['pace_applicable']);
        $this->assertNull($result['days_remaining']);
    }
}
