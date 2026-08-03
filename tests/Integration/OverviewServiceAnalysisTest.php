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
