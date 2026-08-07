<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use GoalService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ ยอดที่แดชบอร์ดบวกเอง ต้องตรงกับ `SUM()` ของฐานข้อมูลที่หน้าอื่นใช้
 *
 * เงินทุกช่องเป็น `DECIMAL(12,2)` แต่ PHP เก็บเป็นทศนิยมฐานสอง การบวกทีละวันจึง
 * เพี้ยนในหลักที่ 10 กว่า ๆ ขณะที่ `SUM()` ของ MySQL ได้ค่าเป๊ะเสมอ
 *
 * ⚠️ อาการที่วัดได้จริง (ส.ค. 2569 กรอก 7 วัน · เป้ารายได้ ฿500,000 ซึ่งลงตัวพอดี):
 *   แดชบอร์ด                     | หน้ารายปี + ไฟล์ Excel
 *   ----------------------------|------------------------
 *   เป้ารายได้ **99.9%**          | **✓ ถึงเป้า (100.0%)**
 *   ไม่ขึ้น "🎉 ถึงเป้าแล้ว"        |
 *   "ต้องได้อีกวันละ ฿0"          |
 *
 * การ์ดใบเดียวขัดกันเอง 3 บรรทัด: ทำได้เท่าเป้าเป๊ะ · 99.9% · ต้องหาอีกวันละ ฿0
 * และเกิดกับพฤติกรรมที่ตั้งใจทำ (เล็งเป้ากลม ๆ แล้วกรอกวันสุดท้ายให้ครบพอดี)
 * ซึ่งเป็นเส้นแบ่งที่สำคัญที่สุดของฟีเจอร์เป้าหมายพอดี
 */
final class MoneyTotalPrecisionTest extends TestCase
{
    private const TODAY = '2026-08-07';
    private const TARGET = 500000.00;

    /**
     * ยอดจริงระดับร้านทั่วไป ไม่ใช่ค่าสุดขั้ว — บวกกันแล้วได้ ฿500,000.00 พอดี
     * แต่การบวกแบบ float ได้ 499999.99999999994
     *
     * @return array<int,array<string,mixed>>
     */
    private function daysThatSumToExactlyTheTarget(): array
    {
        $amounts = [72328.46, 63411.33, 81239.59, 60534.09, 80981.37, 77855.05, 63650.11];
        $rows = [];

        foreach ($amounts as $index => $revenue) {
            $rows[] = [
                'record_date' => sprintf('2026-08-%02d', $index + 1),
                'revenue' => $revenue,
                'ad_cost' => 0.0,
            ];
        }

        return $rows;
    }

    public function testTheHandAddedTotalIsNotBelowTheDatabaseTotal(): void
    {
        $naive = 0.0;
        foreach ($this->daysThatSumToExactlyTheTarget() as $row) {
            $naive += (float)$row['revenue'];
        }

        // ยืนยันว่าข้อมูลชุดนี้ทำให้เกิดปัญหาจริง ไม่งั้นเทสต์ข้างล่างจะไม่ได้ตรวจอะไรเลย
        $this->assertNotSame(self::TARGET, $naive, 'ข้อมูลทดสอบไม่ทำให้เกิดเศษ float — เทสต์นี้พิสูจน์อะไรไม่ได้');

        $this->assertSame(
            self::TARGET,
            money_total($naive),
            'ปัดเป็นสตางค์แล้วยังไม่ตรงกับยอดที่ฐานข้อมูลคำนวณให้'
        );
    }

    /** ⚠️ ตัวเลขบนการ์ดเป้าต้องเป็นตัวเดียวกับที่หน้ารายปี/Excel ใช้ */
    public function testTheGoalCardAgreesWithTheAnnualPageOnTheSameData(): void
    {
        $goal = $this->buildGoalProgress();

        $this->assertSame(
            100.0,
            $goal['progress_revenue'],
            'การ์ดเป้าบนแดชบอร์ดขึ้น 99.9% ขณะที่หน้ารายปีบอก 100.0% จากข้อมูลชุดเดียวกัน'
        );
        $this->assertTrue(
            (bool)$goal['revenue_reached'],
            'ทำได้เท่าเป้าเป๊ะแล้วแต่การ์ดยังบอกว่ายังไม่ถึงเป้า'
        );
        $this->assertTrue((bool)$goal['is_achieved'], 'ไม่ขึ้น "🎉 ถึงเป้าแล้ว" ทั้งที่ถึงแล้ว');
    }

    /** ยอดที่แสดงบนการ์ดต้องเป็นค่าเป๊ะ ไม่ใช่ค่าที่ขาดไปเศษเสี้ยวสตางค์ */
    public function testTheDisplayedTotalIsExact(): void
    {
        $goal = $this->buildGoalProgress();

        $this->assertSame(self::TARGET, (float)$goal['actual_revenue']);
    }

    /** ยังไม่ถึงเป้าจริง ๆ ต้องยังตอบว่ายังไม่ถึงเหมือนเดิม — ห้ามปัดจนถึงเป้าฟรี ๆ */
    public function testBeingOneSatangShortIsStillShort(): void
    {
        $this->assertFalse(GoalService::isReached(money_total(499999.99), self::TARGET));
        $this->assertSame(99.9, GoalService::progressPercent(money_total(499999.99), self::TARGET));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildGoalProgress(): array
    {
        $records = $this->daysThatSumToExactlyTheTarget();

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn($records);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('findByShopAndMonth')->willReturn([
            'goal_month' => '2026-08',
            'target_revenue' => self::TARGET,
            'target_profit' => null,
        ]);

        $service = new DashboardService($recordRepository, $shopRepository, $goalRepository);
        $result = $service->buildDashboard(1, 1, 'month_this', null, null, null, self::TODAY);

        $goal = (array)($result['data']['goal'] ?? []);
        $this->assertTrue((bool)($goal['has_goal'] ?? false), 'อ่านการ์ดเป้าไม่ได้ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');

        return $goal;
    }
}
