<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewService;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของรายงานวิเคราะห์มุมเดือน — DB จริง 2 เดือน × 2 ร้าน
 */
final class OverviewServiceAnalysisTest extends IntegrationTestCase
{
    private function makeService(): OverviewService
    {
        return new OverviewService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    public function testRankingShareAndMomentumFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // เดือนก่อน (พ.ค.)
        $this->createRecord($shopA, '2026-05-10', 1000.0, 200.0);   // A prev profit 800
        $this->createRecord($shopB, '2026-05-10', 900.0, 400.0);    // B prev profit 500

        // เดือนที่ดู (มิ.ย.) — A รายได้สูงกว่าแต่แอดหนักจนกำไรน้อยกว่า B
        $this->createRecord($shopA, '2026-06-10', 5000.0, 4600.0);  // A profit 400
        $this->createRecord($shopB, '2026-06-10', 2000.0, 400.0);   // B profit 1600

        $result = $this->makeService()->buildOverview($userId, '2026-06');
        $this->assertTrue($result['success']);

        $rows = $result['data']['comparison']['rows'];
        $this->assertCount(2, $rows);

        // จัดอันดับด้วยกำไร → B ขึ้นก่อน แม้ A รายได้สูงกว่า
        $this->assertSame('ร้าน B', $rows[0]['shop_name']);
        $this->assertSame(1600.0, $rows[0]['profit']);
        $this->assertSame('ร้าน A', $rows[1]['shop_name']);
        $this->assertSame(400.0, $rows[1]['profit']);

        // สัดส่วนกำไร: รวม 2000 → B 80% / A 20%
        $this->assertSame(80.0, $rows[0]['profit_share']);
        $this->assertSame(20.0, $rows[1]['profit_share']);

        // momentum: B 500 → 1600 = +220% · A 800 → 400 = -50%
        $this->assertSame(500.0, $rows[0]['prev_profit']);
        $this->assertSame(1100.0, $rows[0]['profit_change']);
        $this->assertSame(220.0, $rows[0]['profit_change_percent']);

        $this->assertSame(800.0, $rows[1]['prev_profit']);
        $this->assertSame(-400.0, $rows[1]['profit_change']);
        $this->assertSame(-50.0, $rows[1]['profit_change_percent']);
    }

    public function testShopWithoutPreviousMonthHasNullMomentum(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้านเก่า');
        $shopB = $this->createShop($userId, 'ร้านใหม่');

        $this->createRecord($shopA, '2026-05-10', 1000.0, 200.0);
        $this->createRecord($shopA, '2026-06-10', 1000.0, 200.0);
        $this->createRecord($shopB, '2026-06-10', 900.0, 100.0);   // ไม่มีข้อมูลเดือนก่อน

        $rows = $this->makeService()->buildOverview($userId, '2026-06')['data']['comparison']['rows'];

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['shop_name']] = $row;
        }

        $this->assertSame(0.0, $byName['ร้านใหม่']['prev_profit']);
        $this->assertNull($byName['ร้านใหม่']['profit_change_percent']);
        $this->assertSame(0.0, $byName['ร้านเก่า']['profit_change_percent']);   // เท่าเดิม
    }

    public function testPerShopDayCountsAndProfitTrendFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // มิ.ย.: A กรอก 3 วัน · B กรอก 1 วัน
        $this->createRecord($shopA, '2026-06-01', 1000.0, 200.0);
        $this->createRecord($shopA, '2026-06-02', 1000.0, 200.0);
        $this->createRecord($shopA, '2026-06-03', 1000.0, 200.0);
        $this->createRecord($shopB, '2026-06-01', 5000.0, 4000.0);

        // พ.ค. (อยู่ในช่วงเทรนด์ 6 เดือน): A รายได้น้อยกว่าแต่กำไรดีกว่า
        $this->createRecord($shopA, '2026-05-10', 800.0, 100.0);

        $data = $this->makeService()->buildOverview($userId, '2026-06')['data'];

        $byName = [];
        foreach ($data['comparison']['rows'] as $row) {
            $byName[$row['shop_name']] = $row;
        }

        // days_count ต่อร้าน — เห็นชัดว่า B กรอกน้อยกว่า
        $this->assertSame(3, $byName['ร้าน A']['days_count']);
        $this->assertSame(1, $byName['ร้าน B']['days_count']);

        // เทรนด์กำไร: A พ.ค. = 700 · มิ.ย. = 2400 · B มิ.ย. = 1000
        $seriesByName = [];
        foreach ($data['charts']['trend']['series'] as $series) {
            $seriesByName[$series['shop_name']] = $series;
        }

        $months = $data['charts']['trend']['months'];
        $mayIndex = array_search('2026-05', $months, true);
        $juneIndex = array_search('2026-06', $months, true);

        $this->assertSame(700.0, $seriesByName['ร้าน A']['profit'][$mayIndex]);
        $this->assertSame(2400.0, $seriesByName['ร้าน A']['profit'][$juneIndex]);
        $this->assertSame(1000.0, $seriesByName['ร้าน B']['profit'][$juneIndex]);

        // B รายได้สูงกว่า A ในเดือน มิ.ย. แต่กำไรน้อยกว่า
        $this->assertGreaterThan(
            $seriesByName['ร้าน A']['revenue'][$juneIndex],
            $seriesByName['ร้าน B']['revenue'][$juneIndex]
        );
        $this->assertLessThan(
            $seriesByName['ร้าน A']['profit'][$juneIndex],
            $seriesByName['ร้าน B']['profit'][$juneIndex]
        );
    }

    public function testUsersAreIsolated(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopA = $this->createShop($ownerId, 'ร้าน A');
        $shopB = $this->createShop($ownerId, 'ร้าน B');
        $this->createRecord($shopA, '2026-06-10', 1000.0, 100.0);

        $otherUserId = $this->createUser('other@example.com');
        $otherShopA = $this->createShop($otherUserId, 'ร้านคนอื่น A');
        $otherShopB = $this->createShop($otherUserId, 'ร้านคนอื่น B');
        $this->createRecord($otherShopA, '2026-06-10', 9999.0, 1.0);

        $rows = $this->makeService()->buildOverview($ownerId, '2026-06')['data']['comparison']['rows'];

        $this->assertCount(2, $rows);   // เห็นเฉพาะร้านของตัวเอง
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('คนอื่น', $row['shop_name']);
        }
        $this->assertSame(900.0, $rows[0]['profit']);
    }
}
