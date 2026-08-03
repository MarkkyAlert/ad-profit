<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของเส้นปีก่อน + กำไรสะสม + กำไรต่อวัน — DB จริง 2 ปี
 */
final class AnnualServiceTrendTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): AnnualService
    {
        return new AnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        );
    }

    public function testPrevLineAndCumulativeFromRealData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ปีนี้: ม.ค. +2000 · มี.ค. -500
        $this->createRecord($shopId, '2026-01-10', 3000.0, 1000.0);
        $this->createRecord($shopId, '2026-03-10', 1000.0, 1500.0);

        // ปีก่อน: ม.ค. +1000 · เม.ย. +1500 (ในช่วงเทียบ)
        $this->createRecord($shopId, '2025-01-10', 2000.0, 1000.0);
        $this->createRecord($shopId, '2025-04-10', 2500.0, 1000.0);
        // ปีก่อน ต.ค. — นอกช่วง lastMonth ต้องไม่โผล่ทั้งเส้นปีก่อนและเส้นสะสม
        $this->createRecord($shopId, '2025-10-10', 90000.0, 0.0);

        $data = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data'];
        $chart = $data['chart'];

        // เส้นปีก่อน align กับแกน x ปีนี้ (8 เดือน)
        $this->assertCount(8, $chart['prev_profit']);
        $this->assertSame([1000.0, 0.0, 0.0, 1500.0, 0.0, 0.0, 0.0, 0.0], $chart['prev_profit']);

        // สะสมปีนี้: ม.ค. 2000 → มี.ค. 1500 (ลดลงเพราะเดือนขาดทุน)
        $this->assertSame(2000.0, $chart['cumulative_profit'][0]);
        $this->assertSame(2000.0, $chart['cumulative_profit'][1]);
        $this->assertSame(1500.0, $chart['cumulative_profit'][2]);
        $this->assertSame(1500.0, end($chart['cumulative_profit']));

        // สะสมปีก่อน same-period — ต.ค. ฿90,000 ต้องไม่ถูกนับ
        $this->assertSame(2500.0, end($chart['prev_cumulative_profit']));
        $this->assertSame($data['summary']['prev_year_profit'], end($chart['prev_cumulative_profit']));
    }

    public function testProfitPerDayFromRealDayCounts(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ม.ค. กรอก 3 วัน กำไรรวม 6000 → 2000/วัน
        $this->createRecord($shopId, '2026-01-01', 3000.0, 1000.0);
        $this->createRecord($shopId, '2026-01-02', 3000.0, 1000.0);
        $this->createRecord($shopId, '2026-01-03', 3000.0, 1000.0);

        // ก.พ. กรอกวันเดียว กำไร 5000 → 5000/วัน (ยอดรวมน้อยกว่า ม.ค. แต่ต่อวันแรงกว่า)
        $this->createRecord($shopId, '2026-02-01', 9000.0, 4000.0);

        // ก.ค. ขาดทุน 2 วัน → -1500/วัน
        $this->createRecord($shopId, '2026-07-01', 1000.0, 2500.0);
        $this->createRecord($shopId, '2026-07-02', 1000.0, 2500.0);

        $months = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['months'];

        $this->assertSame(2000.0, $months[0]['profit_per_day']);
        $this->assertSame(5000.0, $months[1]['profit_per_day']);
        $this->assertNull($months[2]['profit_per_day']);          // มี.ค. ยังไม่ได้กรอก
        $this->assertSame(-1500.0, $months[6]['profit_per_day']);   // ก.ค. ขาดทุน

        // ม.ค. กำไรรวมมากกว่า ก.พ. แต่ต่อวันน้อยกว่า
        $this->assertGreaterThan($months[1]['profit'], $months[0]['profit']);
        $this->assertLessThan($months[1]['profit_per_day'], $months[0]['profit_per_day']);
    }

    public function testShopWithoutHistoryHasFlatPrevLine(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-05-10', 5000.0, 1000.0);

        $chart = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['chart'];

        $this->assertCount(8, $chart['prev_profit']);
        $this->assertSame(0.0, array_sum($chart['prev_profit']));
        $this->assertSame(0.0, end($chart['prev_cumulative_profit']));
        $this->assertSame(4000.0, end($chart['cumulative_profit']));
    }

    public function testPastYearSeriesCoverTwelveMonths(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2025-12-10', 3000.0, 1000.0);
        $this->createRecord($shopId, '2024-12-10', 2000.0, 1000.0);

        $chart = $this->makeService()->buildYearlySummary($userId, $shopId, 2025, self::TODAY)['data']['chart'];

        $this->assertCount(12, $chart['prev_profit']);
        $this->assertCount(12, $chart['cumulative_profit']);
        $this->assertSame(1000.0, $chart['prev_profit'][11]);
        $this->assertSame(2000.0, end($chart['cumulative_profit']));
    }
}
