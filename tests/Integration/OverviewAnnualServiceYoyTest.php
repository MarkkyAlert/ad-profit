<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewAnnualService;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของ YoY รวมทุกร้าน (same-period) — seed 2 ปี หลายร้าน ลง DB จริง
 */
final class OverviewAnnualServiceYoyTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): OverviewAnnualService
    {
        return new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    public function testYearOverYearRollupUsesSamePeriod(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // ปีนี้ (2026) → A 4000 + B 2000 = 6000
        $this->createRecord($shopA, '2026-01-10', 5000.0, 1000.0);
        $this->createRecord($shopB, '2026-08-10', 3000.0, 1000.0);

        // ปีก่อน (2025) ในช่วง ม.ค.-ส.ค. → A 1000 + B 2000 = 3000
        $this->createRecord($shopA, '2025-01-10', 2000.0, 1000.0);
        $this->createRecord($shopB, '2025-06-10', 3000.0, 1000.0);

        // ปีก่อน ต.ค./ธ.ค. — นอกช่วงเทียบ ต้องไม่ถูกนับ
        $this->createRecord($shopA, '2025-10-10', 90000.0, 0.0);
        $this->createRecord($shopB, '2025-12-10', 90000.0, 0.0);

        $summary = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(6000.0, $summary['profit']);
        // ถ้าเทียบปีก่อนเต็ม 12 เดือนจะได้ 183000 — ต้องเป็น 3000
        $this->assertSame(3000.0, $summary['prev_year_profit']);
        $this->assertSame(2025, $summary['prev_year']);
        $this->assertSame(3000.0, $summary['yoy_profit_change']);
        $this->assertSame(100.0, $summary['yoy_profit_change_percent']);
    }

    public function testFirstYearWithoutHistoryHasNullPercent(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-03-10', 5000.0, 1000.0);

        $summary = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }

    public function testAnotherUsersPreviousYearIsNotCounted(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopA = $this->createShop($ownerId, 'ร้าน A');
        $this->createShop($ownerId, 'ร้าน B');
        $this->createRecord($shopA, '2026-01-10', 3000.0, 1000.0);

        $otherId = $this->createUser('other@example.com');
        $otherShop = $this->createShop($otherId, 'ร้านคนอื่น A');
        $this->createShop($otherId, 'ร้านคนอื่น B');
        // ปีก่อนของ user อื่น — ต้องไม่หลุดเข้ามาเป็นฐานเทียบ
        $this->createRecord($otherShop, '2025-01-10', 90000.0, 0.0);

        $summary = $this->makeService()->buildYearlyOverview($ownerId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }

    public function testPreviousYearAggregatesEveryShop(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');
        $shopC = $this->createShop($userId, 'ร้าน C');

        $this->createRecord($shopA, '2026-02-10', 4000.0, 1000.0);   // +3000

        // ปีก่อน 3 ร้าน รวม 1000 + 500 - 500 = 1000
        $this->createRecord($shopA, '2025-02-10', 2000.0, 1000.0);
        $this->createRecord($shopB, '2025-03-10', 1500.0, 1000.0);
        $this->createRecord($shopC, '2025-04-10', 500.0, 1000.0);

        $summary = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(1000.0, $summary['prev_year_profit']);
        $this->assertSame(2000.0, $summary['yoy_profit_change']);
        $this->assertSame(200.0, $summary['yoy_profit_change_percent']);
    }
}
