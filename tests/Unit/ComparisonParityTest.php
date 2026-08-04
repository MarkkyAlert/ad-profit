<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ตัวเลขเดียวกันบนสองหน้าต้องตรงกัน
 *
 * แต่ละ service มีเทสต์ของตัวเองครบ แต่ไม่เคยมีใครเทียบกันเอง — จึงเกิดกรณีที่
 * แดชบอร์ดบอก "เท่าเดิม 0%" ขณะที่หน้ารวมร้านบอก "↓ 87.1%" สำหรับข้อมูลชุดเดียวกัน
 * เพราะแดชบอร์ดตัดที่วันเดียวกัน แต่หน้ารวมร้านเทียบกับเดือนก่อนทั้งเดือน
 */
final class ComparisonParityTest extends TestCase
{
    private const TODAY = '2026-08-04';

    /**
     * ก.ค. 31 วัน กำไรวันละ 1,000 · ส.ค. 1–4 กำไรวันละ 1,000 (เท่ากันเป๊ะต่อวัน)
     *
     * @return array<int,array<string,mixed>>
     */
    private function records(): array
    {
        $rows = [];
        for ($day = 1; $day <= 31; $day++) {
            $rows[] = ['record_date' => sprintf('2026-07-%02d', $day), 'revenue' => 2000.0, 'ad_cost' => 1000.0];
        }
        for ($day = 1; $day <= 4; $day++) {
            $rows[] = ['record_date' => sprintf('2026-08-%02d', $day), 'revenue' => 2000.0, 'ad_cost' => 1000.0];
        }

        return $rows;
    }

    private function recordRepository(): RecordRepository
    {
        $rows = $this->records();

        $repository = $this->createStub(RecordRepository::class);
        $repository->method('getByDateRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['record_date'] >= $start && $row['record_date'] <= $end
            ))
        );
        $repository->method('getTotalsByShopIdsAndDateRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($rows): array {
                $revenue = 0.0;
                $adCost = 0.0;
                $days = 0;
                foreach ($rows as $row) {
                    if ($row['record_date'] >= $start && $row['record_date'] <= $end) {
                        $revenue += $row['revenue'];
                        $adCost += $row['ad_cost'];
                        $days++;
                    }
                }

                return $days === 0
                    ? []
                    : [['shop_id' => 1, 'total_revenue' => $revenue, 'total_ad_cost' => $adCost, 'days_count' => $days]];
            }
        );
        $repository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturn([]);
        $repository->method('getMonthlyTotalsByMonthRange')->willReturn([]);

        return $repository;
    }

    private function shopRepository(): ShopRepository
    {
        $repository = $this->createStub(ShopRepository::class);
        $repository->method('userCanAccessShop')->willReturn(true);
        $repository->method('listByUserId')->willReturn([['id' => 1, 'name' => 'ร้านเดียว']]);

        return $repository;
    }

    /** ⭐ กำไรต่อวันเท่ากันเป๊ะ → ทั้งสองหน้าต้องบอก "เท่าเดิม" */
    public function testBothPagesReportTheSameMonthOverMonthChange(): void
    {
        $dashboard = (new DashboardService(
            $this->recordRepository(),
            $this->shopRepository(),
            $this->createStub(GoalRepository::class)
        ))->buildDashboard(1, 1, 'month_this', null, null, null, self::TODAY);

        $overview = (new OverviewService($this->recordRepository(), $this->shopRepository()))
            ->buildOverview(1, '2026-08', self::TODAY);

        $dashboardChange = $dashboard['data']['comparison']['change']['profit'];
        $overviewChange = $overview['data']['comparison']['rows'][0]['profit_change_percent'];

        $this->assertSame(0.0, (float)$dashboardChange, 'แดชบอร์ดคิดผิด');
        $this->assertSame(
            (float)$dashboardChange,
            (float)$overviewChange,
            'สองหน้าบอกคนละตัวเลขสำหรับข้อมูลชุดเดียวกัน'
        );
    }

    /** เดือนที่จบไปแล้วยังเทียบทั้งเดือนกับทั้งเดือนเหมือนเดิม */
    public function testAPastMonthStillComparesWholeMonths(): void
    {
        $overview = (new OverviewService($this->recordRepository(), $this->shopRepository()))
            ->buildOverview(1, '2026-07', self::TODAY);

        // ก.ค. = 31,000 · มิ.ย. = ไม่มีข้อมูล → เทียบไม่ได้
        $this->assertSame(31000.0, (float)$overview['data']['comparison']['rows'][0]['profit']);
        $this->assertNull($overview['data']['comparison']['rows'][0]['profit_change_percent']);
    }

    /** ตารางหลักยังโชว์กำไรทั้งเดือนตามเดิม — ตัดวันเฉพาะตัวเลขที่เอาไปเทียบ */
    public function testTheTableStillShowsTheWholeMonthProfit(): void
    {
        $overview = (new OverviewService($this->recordRepository(), $this->shopRepository()))
            ->buildOverview(1, '2026-08', self::TODAY);

        $this->assertSame(4000.0, (float)$overview['data']['comparison']['rows'][0]['profit']);
    }
}
