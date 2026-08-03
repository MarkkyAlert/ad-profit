<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของ profit_margin + ตัวนับเดือนกำไร/ขาดทุน — DB จริง
 */
final class AnnualServiceSummaryTest extends IntegrationTestCase
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

    public function testMarginAndCountsFromMixedMonths(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ม.ค. กรอก 2 วัน → รายได้ 6000 กำไร +4000
        $this->createRecord($shopId, '2026-01-10', 3000.0, 500.0);
        $this->createRecord($shopId, '2026-01-11', 3000.0, 1500.0);
        // มี.ค. ขาดทุน -1000
        $this->createRecord($shopId, '2026-03-10', 1000.0, 2000.0);
        // มิ.ย. เท่าทุนพอดี
        $this->createRecord($shopId, '2026-06-10', 2000.0, 2000.0);
        // ก.ค. กำไร +1000
        $this->createRecord($shopId, '2026-07-10', 3000.0, 2000.0);
        // ก.พ./เม.ย./พ.ค./ส.ค. ไม่กรอกเลย

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        // รายได้ 12000 · กำไร 4000 → 33.3%
        $this->assertSame(12000.0, $summary['total_revenue']);
        $this->assertSame(4000.0, $summary['profit']);
        $this->assertSame(33.3, $summary['profit_margin']);

        $this->assertSame(4, $summary['months_with_data']);   // 4 เดือนที่กรอก (ไม่ใช่ 8)
        $this->assertSame(2, $summary['profit_months']);      // ม.ค. + ก.ค.
        $this->assertSame(1, $summary['loss_months']);        // มี.ค.
    }

    public function testUnfilledMonthsAreNotCountedAsBreakEven(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-02-10', 5000.0, 1000.0);

        $data = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data'];

        $this->assertCount(8, $data['months']);
        $this->assertSame(1, $data['summary']['months_with_data']);
        $this->assertSame(1, $data['summary']['profit_months']);
        $this->assertSame(0, $data['summary']['loss_months']);
    }

    public function testLossOnlyYearCountsAsLoss(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-01-10', 1000.0, 3000.0);
        $this->createRecord($shopId, '2026-02-10', 500.0, 2000.0);

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0, $summary['profit_months']);
        $this->assertSame(2, $summary['loss_months']);
        $this->assertSame(-233.3, $summary['profit_margin']);   // -3500 / 1500
    }

    public function testEmptyShopHasNullMarginAndZeroCounts(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertNull($summary['profit_margin']);
        $this->assertSame(0, $summary['months_with_data']);
        $this->assertSame(0, $summary['profit_months']);
        $this->assertSame(0, $summary['loss_months']);
    }
}
