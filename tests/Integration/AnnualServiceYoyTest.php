<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของ YoY + days_count — seed 2 ปีลง DB จริง
 */
final class AnnualServiceYoyTest extends IntegrationTestCase
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

    public function testYearOverYearUsesSamePeriodFromRealData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ปีนี้ (2026) ม.ค. + ส.ค. → กำไรรวม 4000 + 500 = 4500
        $this->createRecord($shopId, '2026-01-10', 5000.0, 1000.0);
        $this->createRecord($shopId, '2026-08-10', 3000.0, 2500.0);

        // ปีก่อน (2025) ม.ค. + ส.ค. → กำไรรวม 1000 + 500 = 1500
        $this->createRecord($shopId, '2025-01-10', 2000.0, 1000.0);
        $this->createRecord($shopId, '2025-08-10', 1500.0, 1000.0);

        // ปีก่อน ก.ย.-ธ.ค. — นอกช่วงเทียบ ต้องไม่ถูกนับ
        $this->createRecord($shopId, '2025-09-10', 50000.0, 0.0);
        $this->createRecord($shopId, '2025-12-10', 50000.0, 0.0);

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(4500.0, $summary['profit']);
        // ถ้าเทียบปีก่อนเต็ม 12 เดือนจะได้ 101500 — ต้องเป็น 1500
        $this->assertSame(1500.0, $summary['prev_year_profit']);
        $this->assertSame(2025, $summary['prev_year']);
        $this->assertSame(3000.0, $summary['yoy_profit_change']);
        $this->assertSame(200.0, $summary['yoy_profit_change_percent']);
    }

    public function testPerMonthYoyAndDayCountsFromRealData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ปีนี้ ม.ค. กรอก 3 วัน → กำไรรวม 3000
        $this->createRecord($shopId, '2026-01-01', 2000.0, 1000.0);
        $this->createRecord($shopId, '2026-01-02', 2000.0, 1000.0);
        $this->createRecord($shopId, '2026-01-03', 2000.0, 1000.0);
        // ปีนี้ ก.พ. กรอก 1 วัน
        $this->createRecord($shopId, '2026-02-01', 1000.0, 500.0);

        // ปีก่อน ม.ค. กำไร 1500 (มีเดือนเดียวกันให้เทียบ) · ก.พ. ไม่มีข้อมูล
        $this->createRecord($shopId, '2025-01-10', 2500.0, 1000.0);

        $months = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['months'];

        // days_count มาจาก query จริง
        $this->assertSame(3, $months[0]['days_count']);
        $this->assertSame(1, $months[1]['days_count']);
        $this->assertSame(0, $months[2]['days_count']);

        // ม.ค.: 1500 → 3000 = +100%
        $this->assertSame(1500.0, $months[0]['prev_year_profit']);
        $this->assertSame(100.0, $months[0]['yoy_change_percent']);

        // ก.พ.: ปีก่อนไม่มีข้อมูล → null
        $this->assertSame(0.0, $months[1]['prev_year_profit']);
        $this->assertNull($months[1]['yoy_change_percent']);
    }

    public function testFirstYearWithoutHistoryHasNullPercent(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-03-10', 5000.0, 1000.0);

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }

    public function testPreviousYearDataOfAnotherShopIsNotCounted(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านหลัก');
        $otherShopId = $this->createShop($userId, 'ร้านอื่น');

        $this->createRecord($shopId, '2026-01-10', 3000.0, 1000.0);
        // ปีก่อนของ "อีกร้าน" — ต้องไม่หลุดเข้ามาเป็นฐานเทียบ
        $this->createRecord($otherShopId, '2025-01-10', 90000.0, 0.0);

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }
}
