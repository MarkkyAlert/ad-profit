<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewAnnualService;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของ cutoff เดือนอนาคตในมุมปีรวมร้าน — DB จริง
 */
final class OverviewAnnualServiceCutoffTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): OverviewAnnualService
    {
        return new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    public function testCurrentYearExcludesFutureMonths(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-01-10', 5000.0, 1000.0);
        $this->createRecord($shopB, '2026-08-10', 3000.0, 1000.0);

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];

        $this->assertSame(8, $data['last_month']);
        $this->assertCount(8, $data['months']);
        $this->assertSame('2026-08', end($data['months'])['month_key']);
        $this->assertNotContains('2026-09', $data['chart']['months']);
    }

    public function testPastYearStillCoversTwelveMonths(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2025-03-10', 1000.0, 100.0);

        $data = $this->makeService()->buildYearlyOverview($userId, 2025, self::TODAY)['data'];

        $this->assertCount(12, $data['months']);
        $this->assertSame('2025-12', end($data['months'])['month_key']);
    }

    public function testFutureDatedRecordsAreNotCounted(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-08-10', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-11-10', 90000.0, 0.0);

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];

        $this->assertSame(3000.0, $data['summary']['total_revenue']);

        $shopById = [];
        foreach ($data['shops'] as $row) {
            $shopById[(string)$row['shop_name']] = $row;
        }
        $this->assertSame(0.0, $shopById['ร้าน B']['total_revenue']);
    }

    public function testSingleShopUserStillCannotView(): void
    {
        $userId = $this->createUser();
        $this->createShop($userId, 'ร้านเดียว');

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];

        // cutoff ต้องไม่ไปกระทบ guard เดิม
        $this->assertFalse($data['can_view']);
        $this->assertArrayNotHasKey('months', $data);
    }
}
