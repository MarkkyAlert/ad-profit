<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของสถิติในการ์ดสรุป — best_day / worst_day / avg_profit_per_day
 * ต้องวัดจาก "กำไร" ไม่ใช่ "รายได้"
 */
final class DashboardServiceProfitStatsTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function makeService(array $records): DashboardService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn($records);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('findByShopAndMonth')->willReturn(null);

        return new DashboardService($recordRepository, $shopRepository, $goalRepository);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function statisticsOf(array $records): array
    {
        $result = $this->makeService($records)
            ->buildDashboard(1, 1, 'custom', '2026-01-01', '2026-01-31', null);

        $this->assertTrue($result['success']);

        return (array)$result['data']['statistics'];
    }

    public function testBestDayRanksByProfitNotRevenue(): void
    {
        // จงใจให้ "วันรายได้สูงสุด" กับ "วันกำไรสูงสุด" เป็นคนละวัน
        $statistics = $this->statisticsOf([
            // รายได้สูงสุด (9000) แต่ค่าแอดแพงมาก → กำไรแค่ 500
            ['record_date' => '2026-01-10', 'revenue' => 9000, 'ad_cost' => 8500],
            // รายได้น้อยกว่า (3000) แต่กำไรสูงสุด (2800)
            ['record_date' => '2026-01-11', 'revenue' => 3000, 'ad_cost' => 200],
        ]);

        $this->assertSame('2026-01-11', $statistics['best_day']['record_date']);
        $this->assertSame(2800.0, $statistics['best_day']['profit']);
        // ต้องไม่มี key 'revenue' แล้ว (การ์ดอ่าน 'profit')
        $this->assertArrayNotHasKey('revenue', $statistics['best_day']);
    }

    public function testWorstDayRanksByProfitIncludingLoss(): void
    {
        $statistics = $this->statisticsOf([
            ['record_date' => '2026-01-10', 'revenue' => 3000, 'ad_cost' => 200],   // +2800
            // รายได้ต่ำสุด (100) แต่กำไร -100 ยังไม่ใช่แย่สุด
            ['record_date' => '2026-01-11', 'revenue' => 100, 'ad_cost' => 200],    // -100
            // รายได้สูงกว่า แต่ขาดทุนหนักสุด
            ['record_date' => '2026-01-12', 'revenue' => 1000, 'ad_cost' => 4000],  // -3000
        ]);

        $this->assertSame('2026-01-12', $statistics['worst_day']['record_date']);
        $this->assertSame(-3000.0, $statistics['worst_day']['profit']);   // ติดลบได้
        $this->assertSame('2026-01-10', $statistics['best_day']['record_date']);
    }

    public function testAverageProfitPerDayIsComputed(): void
    {
        $statistics = $this->statisticsOf([
            ['record_date' => '2026-01-10', 'revenue' => 1000, 'ad_cost' => 200],   // +800
            ['record_date' => '2026-01-11', 'revenue' => 2000, 'ad_cost' => 400],   // +1600
            ['record_date' => '2026-01-12', 'revenue' => 600, 'ad_cost' => 1200],   // -600
        ]);

        // (800 + 1600 - 600) / 3 = 600
        $this->assertSame(600.0, $statistics['avg_profit_per_day']);
        $this->assertSame(3, $statistics['days_count']);
        // คงของเดิมไว้ด้วย: (1000+2000+600)/3 = 1200
        $this->assertSame(1200.0, $statistics['avg_revenue_per_day']);
    }

    public function testAverageProfitPerDayCanBeNegative(): void
    {
        $statistics = $this->statisticsOf([
            ['record_date' => '2026-01-10', 'revenue' => 100, 'ad_cost' => 900],    // -800
            ['record_date' => '2026-01-11', 'revenue' => 100, 'ad_cost' => 700],    // -600
        ]);

        $this->assertSame(-700.0, $statistics['avg_profit_per_day']);
    }

    public function testEmptyRecordsGiveNullStatistics(): void
    {
        $statistics = $this->statisticsOf([]);

        $this->assertNull($statistics['best_day']);
        $this->assertNull($statistics['worst_day']);
        $this->assertNull($statistics['avg_profit_per_day']);
        $this->assertNull($statistics['avg_revenue_per_day']);
        $this->assertSame(0, $statistics['days_count']);
    }

    public function testSingleRecordIsBothBestAndWorst(): void
    {
        $statistics = $this->statisticsOf([
            ['record_date' => '2026-01-10', 'revenue' => 1000, 'ad_cost' => 200],
        ]);

        $this->assertSame('2026-01-10', $statistics['best_day']['record_date']);
        $this->assertSame('2026-01-10', $statistics['worst_day']['record_date']);
        $this->assertSame(800.0, $statistics['best_day']['profit']);
    }
}
