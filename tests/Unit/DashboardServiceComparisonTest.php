<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของแถบ "เทียบเดือนก่อน" บนแดชบอร์ด
 *
 * เมธอดนี้ไม่เคยมีเทสต์ครอบเลย ทั้งที่เป็นตัวเลขบนหน้าแรกที่ผู้ใช้เปิดทุกวัน
 * และ view ย้อมเขียว/แดงตามเครื่องหมายของค่านี้ (dashboard.php:277-283)
 */
final class DashboardServiceComparisonTest extends TestCase
{
    /**
     * @param array<int,array{0:string,1:float,2:float}> $months [เดือน, รายได้, ค่าแอด]
     */
    private function makeService(array $months): DashboardService
    {
        $rows = array_map(
            static fn(array $month): array => [
                'month_key' => $month[0],
                'total_revenue' => $month[1],
                'total_ad_cost' => $month[2],
            ],
            $months
        );

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn($rows);
        $recordRepository->method('getByDateRange')->willReturn([]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('findByShopAndMonth')->willReturn(null);

        return new DashboardService($recordRepository, $shopRepository, $goalRepository);
    }

    /**
     * @param array<int,array{0:string,1:float,2:float}> $months
     * @return array<string,mixed>
     */
    private function changeOf(array $months): array
    {
        $result = $this->makeService($months)
            ->buildDashboard(1, 1, 'month_pick', null, null, '2026-08', '2026-08-20');

        $this->assertTrue($result['success']);

        return (array)$result['data']['comparison']['change'];
    }

    /**
     * ⭐ เดือนก่อนขาดทุน แล้วเดือนนี้ขาดทุนน้อยลง = ดีขึ้น → ต้องเป็นบวก
     *
     * เดิมหารด้วยฐานที่มีเครื่องหมาย ((-50) - (-100)) / (-100) = -50%
     * ทำให้หน้าแรกขึ้นลูกศรลงสีแดงทั้งที่ผลดีขึ้น
     */
    public function testSmallerLossThanLastMonthIsReportedAsImprovement(): void
    {
        $change = $this->changeOf([
            ['2026-07', 100.0, 200.0],  // กำไร -100
            ['2026-08', 150.0, 200.0],  // กำไร  -50 → ขาดทุนน้อยลง
        ]);

        $this->assertSame(50.0, $change['profit']);
    }

    /** เดือนก่อนขาดทุน แล้วเดือนนี้ขาดทุนหนักขึ้น = แย่ลง → ต้องเป็นลบ */
    public function testBiggerLossThanLastMonthIsReportedAsDecline(): void
    {
        $change = $this->changeOf([
            ['2026-07', 100.0, 200.0],  // กำไร -100
            ['2026-08', 100.0, 300.0],  // กำไร -200
        ]);

        $this->assertSame(-100.0, $change['profit']);
    }

    /** พลิกจากขาดทุนเป็นกำไร = ดีที่สุดที่เป็นไปได้ → ต้องเป็นบวก */
    public function testTurningALossIntoProfitIsPositive(): void
    {
        $change = $this->changeOf([
            ['2026-07', 100.0, 200.0],  // กำไร -100
            ['2026-08', 250.0, 200.0],  // กำไร  +50
        ]);

        $this->assertSame(150.0, $change['profit']);
    }

    /** ฐานบวกต้องไม่เปลี่ยนพฤติกรรมเดิม */
    public function testPositiveBaseIsUnchanged(): void
    {
        $change = $this->changeOf([
            ['2026-07', 300.0, 200.0],  // กำไร +100
            ['2026-08', 350.0, 200.0],  // กำไร +150
        ]);

        $this->assertSame(50.0, $change['profit']);
        $this->assertSame(16.7, $change['total_revenue']);
    }

    public function testNullWhenPreviousMonthHasNoBaseline(): void
    {
        $change = $this->changeOf([
            ['2026-08', 350.0, 200.0],
        ]);

        // เดือนก่อนไม่มีข้อมูล → กำไรฐาน 0 → หารไม่ได้
        $this->assertNull($change['profit']);
        $this->assertNull($change['total_revenue']);
    }

    /**
     * เกณฑ์ "ถึงเป้า" ต้องเทียบค่าจริง ไม่ใช่ progress ที่ปัดทศนิยมแล้ว
     *
     * เดิมใช้ round(...,1) >= 100 → 9,999.60 จากเป้า 10,000 ได้ 100.0% แล้วบอกว่าถึงเป้า
     * ขณะที่หน้ารายปี (AnnualService.php:221-222) เทียบค่าจริงจึงบอกว่ายังไม่ถึง
     */
    public function testGoalJustBelowTargetIsNotReportedAsReached(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([]);
        $recordRepository->method('getByDateRange')->willReturn([
            ['record_date' => '2026-08-01', 'revenue' => 9999.60, 'ad_cost' => 0.0],
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('findByShopAndMonth')->willReturn([
            'target_revenue' => 10000.0,
            'target_profit' => null,
        ]);

        $result = (new DashboardService($recordRepository, $shopRepository, $goalRepository))
            ->buildDashboard(1, 1, 'month_pick', null, null, '2026-08', '2026-08-20');

        $goal = (array)$result['data']['goal'];

        $this->assertSame(100.0, $goal['progress_revenue']); // แสดงผลยังปัดได้
        $this->assertFalse($goal['revenue_reached']);        // แต่การตัดสินต้องเทียบค่าจริง
        $this->assertFalse($goal['is_achieved']);
    }

    /**
     * ตัวเลขเดียวกันต้องอ่านได้เหมือนกันทั้งแดชบอร์ดและหน้ารายปี
     *
     * AnnualService::calculateChangePercent มีคอมเมนต์ระบุเจตนาไว้ชัดว่าฐานติดลบ
     * ต้องหารด้วย abs เพื่อให้เครื่องหมายสื่อทิศทางจริง — แดชบอร์ดต้องตรงกัน
     */
    public function testMatchesAnnualServiceForTheSameNumbers(): void
    {
        $change = $this->changeOf([
            ['2026-07', 100.0, 200.0],
            ['2026-08', 150.0, 200.0],
        ]);

        $annualFormula = static fn(float $current, float $previous): float
            => round((($current - $previous) / abs($previous)) * 100, 1);

        $this->assertSame($annualFormula(-50.0, -100.0), $change['profit']);
    }
}
