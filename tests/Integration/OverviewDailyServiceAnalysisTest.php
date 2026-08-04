<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewDailyService;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของมุมวันรวมร้าน — DB จริง 2 ร้าน กรอกไม่เท่ากันบางวัน
 */
final class OverviewDailyServiceAnalysisTest extends IntegrationTestCase
{
    private const MONTH = '2026-06';

    private function makeService(): OverviewDailyService
    {
        return new OverviewDailyService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    public function testIncompleteDaysFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // 1 มิ.ย. ครบ 2 ร้าน
        $this->createRecord($shopA, '2026-06-01', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-06-01', 2000.0, 500.0);
        // 2 มิ.ย. กรอกร้านเดียว
        $this->createRecord($shopA, '2026-06-02', 1000.0, 500.0);
        // 3 มิ.ย. กรอกร้านเดียว (อีกร้าน)
        $this->createRecord($shopB, '2026-06-03', 1500.0, 500.0);

        $data = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data'];

        $this->assertSame(2, $data['summary']['total_shops']);
        $this->assertSame(2, $data['summary']['incomplete_days']);

        $byDate = [];
        foreach ($data['days'] as $row) {
            $byDate[(string)$row['record_date']] = $row;
        }

        $this->assertTrue($byDate['2026-06-01']['is_complete']);
        $this->assertSame(2, $byDate['2026-06-01']['shops_count']);
        $this->assertFalse($byDate['2026-06-02']['is_complete']);
        $this->assertSame(1, $byDate['2026-06-02']['shops_count']);
        $this->assertFalse($byDate['2026-06-03']['is_complete']);
    }

    public function testAverageProfitAndBestWorstDayFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // 1 มิ.ย. รายได้สูงสุดของเดือน แต่แอดหนัก → กำไรแค่ 500
        $this->createRecord($shopA, '2026-06-01', 20000.0, 19500.0);
        // 2 มิ.ย. กำไรดีสุด 3000
        $this->createRecord($shopA, '2026-06-02', 2000.0, 500.0);
        $this->createRecord($shopB, '2026-06-02', 3000.0, 1500.0);
        // 3 มิ.ย. ขาดทุนจริง
        $this->createRecord($shopB, '2026-06-03', 500.0, 2000.0);

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        // ยอดรวมยังนับทุกวันตามเดิม: 500 + 3000 - 1500 = 2000 · 3 วัน
        $this->assertSame(2000.0, $summary['profit']);
        $this->assertSame(3, $summary['days_count']);

        // แต่การจัดอันดับและค่าเฉลี่ยนับเฉพาะวันที่ทุกร้านกรอกครบ
        // ที่นี่มีแค่ 2 มิ.ย. — 1 มิ.ย. และ 3 มิ.ย. กรอกแค่ร้านเดียว
        // (เดิม "วันแย่สุด" คือ 3 มิ.ย. ซึ่งยอดต่ำเพราะยังกรอกไม่ครบ ไม่ใช่เพราะผลงานแย่)
        $this->assertSame(2, $summary['incomplete_days']);
        $this->assertSame(1, $summary['complete_days_count']);
        $this->assertSame(3000.0, $summary['avg_profit_per_day']);

        $this->assertSame('2026-06-02', $summary['best_day']['record_date']);
        $this->assertSame(3000.0, $summary['best_day']['profit']);
        $this->assertSame('2026-06-02', $summary['worst_day']['record_date']);
        $this->assertSame(3000.0, $summary['worst_day']['profit']);
    }

    public function testAllShopsLoggedEveryDayGivesZeroIncomplete(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-06-01', 1000.0, 100.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 100.0);
        $this->createRecord($shopA, '2026-06-02', 1000.0, 100.0);
        $this->createRecord($shopB, '2026-06-02', 1000.0, 100.0);

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        $this->assertSame(0, $summary['incomplete_days']);
    }

    public function testAnotherUsersRecordsDoNotAffectCompleteness(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopA = $this->createShop($ownerId, 'ร้าน A');
        $shopB = $this->createShop($ownerId, 'ร้าน B');
        $this->createRecord($shopA, '2026-06-01', 1000.0, 100.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 100.0);

        $otherId = $this->createUser('other@example.com');
        $otherShopA = $this->createShop($otherId, 'ร้านคนอื่น A');
        $this->createShop($otherId, 'ร้านคนอื่น B');
        $this->createRecord($otherShopA, '2026-06-01', 90000.0, 0.0);

        $data = $this->makeService()->buildDailyOverview($ownerId, self::MONTH)['data'];

        $this->assertSame(2, $data['summary']['total_shops']);
        $this->assertSame(0, $data['summary']['incomplete_days']);
        $this->assertSame(1800.0, $data['summary']['profit']);   // ไม่รวมร้านคนอื่น
    }
}
