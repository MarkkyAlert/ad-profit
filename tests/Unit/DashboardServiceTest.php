<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

final class DashboardServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function makeService(array $records): DashboardService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn($records);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([]); // six-month chart

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('findByShopAndMonth')->willReturn(null); // ไม่มีเป้า → has_goal=false

        return new DashboardService($recordRepository, $shopRepository, $goalRepository);
    }

    public function testGetSummaryComputesTotalsProfitAndRoas(): void
    {
        $service = $this->makeService([
            ['record_date' => '2024-01-10', 'revenue' => 1000, 'ad_cost' => 200],
            ['record_date' => '2024-01-11', 'revenue' => 500, 'ad_cost' => 0],
        ]);

        $result = $service->getSummary(1, 1, '2024-01-01', '2024-01-31');

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertSame(1500.0, $data['total_revenue']);
        $this->assertSame(200.0, $data['total_ad_cost']);
        $this->assertSame(1300.0, $data['profit']);          // profit = revenue - ad_cost
        $this->assertSame(7.5, $data['roas']);               // 1500 / 200
        $this->assertSame(2, $data['days_count']);
    }

    public function testGetSummaryRoasIsNullWhenAdCostIsZero(): void
    {
        $service = $this->makeService([
            ['record_date' => '2024-02-10', 'revenue' => 500, 'ad_cost' => 0],
        ]);

        $result = $service->getSummary(1, 1, '2024-02-01', '2024-02-28');

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']['roas']);          // ad_cost=0 → null (ไม่ใช่ 0 / division error)
        $this->assertSame(500.0, $result['data']['profit']);
    }

    public function testBuildDashboardReturnsSuccessAndSummary(): void
    {
        $service = $this->makeService([
            ['record_date' => '2024-01-10', 'revenue' => 1000, 'ad_cost' => 200],
            ['record_date' => '2024-01-11', 'revenue' => 500, 'ad_cost' => 0],
        ]);

        // ใช้ range แบบ custom เพื่อให้ช่วงวันที่ deterministic (ไม่ผูกกับวันนี้)
        $result = $service->buildDashboard(1, 1, 'custom', '2024-01-01', '2024-01-31', null);

        $this->assertTrue($result['success']);
        $data = $result['data'];
        $this->assertSame(1300.0, $data['summary']['profit']);
        $this->assertSame(7.5, $data['summary']['roas']);
        $this->assertFalse($data['goal']['has_goal']);
    }

    /**
     * ⭐ ช่วง "กำหนดเอง" ที่วันเริ่มมากกว่าวันจบ ต้องถูกปฏิเสธ
     *
     * ⚠️ coverage gap จาก logic review 2026-08-07 — กิ่งนี้ไม่เคยถูกเทสต์แตะเลย
     * ทั้งที่เป็นสิ่งที่ผู้ใช้ทำได้ง่าย (เลือกวันจบก่อนวันเริ่มในตัวเลือกวันที่)
     * ถ้าหลุดผ่านไป ทุกการ์ดจะเป็น ฿0 พร้อมป้ายช่วงเวลาที่อ่านแล้วงง — เป็นอาการ
     * เดียวกับที่เคยเกิดตอน `month_pick` เดือนอนาคตทำให้ช่วงกลับหัว
     */
    public function testAReversedCustomRangeIsRejected(): void
    {
        $result = $this->makeService([])->buildDashboard(1, 1, 'custom', '2026-08-31', '2026-08-01', null);

        $this->assertFalse($result['success'], 'ยอมรับช่วงที่วันเริ่มมากกว่าวันจบ');
        $this->assertStringContainsString('วันที่เริ่มต้น', (string)$result['error']);
        $this->assertArrayNotHasKey('data', $result, 'ปฏิเสธแล้วแต่ยังส่งตัวเลขออกมาด้วย');
    }

    /** ⚠️ ช่วงยาวเกิน 366 วัน ต้องบอกเพดานให้ผู้ใช้รู้ ไม่ใช่ตัดเงียบ ๆ */
    public function testACustomRangeLongerThanAYearIsRejected(): void
    {
        $result = $this->makeService([])->buildDashboard(1, 1, 'custom', '2025-01-01', '2026-08-01', null);

        $this->assertFalse($result['success'], 'ยอมรับช่วงที่ยาวเกินเพดาน');
        $this->assertStringContainsString('366', (string)$result['error'], 'ไม่ได้บอกเพดานที่รับได้');
    }

    /** ขอบเขตพอดี 366 วัน (ปีอธิกสุรทิน) ต้องยังผ่าน — ไม่ใช่ปฏิเสธเกินจำเป็น */
    public function testExactlyThreeHundredSixtySixDaysStillPasses(): void
    {
        $result = $this->makeService([])->buildDashboard(1, 1, 'custom', '2024-01-01', '2024-12-31', null);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }
}
