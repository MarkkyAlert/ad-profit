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
}
