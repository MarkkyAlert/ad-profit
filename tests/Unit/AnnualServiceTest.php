<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

final class AnnualServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     */
    private function makeService(array $monthlyTotals = []): AnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn($monthlyTotals);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));
    }

    public function testValidYearsWithinRangeAreAccepted(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->buildYearlySummary(1, 1, 2000)['success']);
        $this->assertTrue($service->buildYearlySummary(1, 1, 2100)['success']);
    }

    public function testYearsOutsideRangeAreRejected(): void
    {
        $service = $this->makeService();

        foreach ([1999, 2101] as $invalidYear) {
            $result = $service->buildYearlySummary(1, 1, $invalidYear);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('ไม่ถูกต้อง', $result['error']);
        }
    }

    public function testYearlySummaryTotalsBestAndWorstMonth(): void
    {
        // มี.ค. รายได้สูงสุด, ก.ค. มีข้อมูล, เดือนอื่นเป็น 0
        $service = $this->makeService([
            ['month_key' => '2024-03', 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 5],
            ['month_key' => '2024-07', 'total_revenue' => 500, 'total_ad_cost' => 100, 'days_count' => 3],
        ]);

        $result = $service->buildYearlySummary(1, 1, 2024);

        $this->assertTrue($result['success']);
        $data = $result['data'];

        // ยอดรวมทั้งปี
        $summary = $data['summary'];
        $this->assertSame(1500.0, $summary['total_revenue']);
        $this->assertSame(300.0, $summary['total_ad_cost']);
        $this->assertSame(1200.0, $summary['profit']);
        $this->assertSame(5.0, $summary['roas']);            // 1500 / 300

        // best/worst month by revenue
        $this->assertSame(3, $summary['best_month']['month']);
        $this->assertSame(800.0, $summary['best_month']['profit']);   // 1000 - 200
        // worst = เดือนที่ "มีข้อมูล" และกำไรน้อยสุด (ก.ค. 500-100) ไม่ใช่เดือนว่าง
        $this->assertSame(7, $summary['worst_month']['month']);
        $this->assertSame(400.0, $summary['worst_month']['profit']);

        // 12 เดือนครบ + เดือน มี.ค. (index 2) คำนวณถูก
        $this->assertCount(12, $data['months']);
        $this->assertSame(800.0, $data['months'][2]['profit']);
        $this->assertSame(5.0, $data['months'][2]['roas']);
    }
}
