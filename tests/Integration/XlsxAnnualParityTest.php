<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use ExportService;
use GoalRepository;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ที่ผูก invariant สำคัญของ workbook ร้านเดี่ยว:
 * กำไรจาก sheet รายปี = Σ sheet รายเดือน = Σ sheet รายวัน (today เดียวกัน)
 */
final class XlsxAnnualParityTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function exportService(): ExportService
    {
        $recordRepository = new RecordRepository($this->pdo);
        $shopRepository = new ShopRepository($this->pdo);

        return new ExportService(
            new RecordService($recordRepository, $shopRepository, $this->pdo),
            $shopRepository
        );
    }

    private function annualService(): AnnualService
    {
        return new AnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        );
    }

    public function testAllThreeSingleShopSheetsAgreeOnProfit(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านคอร์ส');

        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);
        $this->createRecord($shopId, '2026-01-06', 4000.0, 2000.0);
        $this->createRecord($shopId, '2026-03-10', 20000.0, 19500.0);
        $this->createRecord($shopId, '2026-07-10', 1000.0, 3500.0);   // เดือนขาดทุน
        $this->createRecord($shopId, '2026-08-28', 2000.0, 500.0);    // ล่วงหน้าในเดือนนี้ — ต้องนับ
        $this->createRecord($shopId, '2026-11-01', 90000.0, 0.0);     // เดือนหน้า — ต้องไม่นับ

        $daily = $this->exportService()->buildYearlyDailyPayload($userId, $shopId, 2026, self::TODAY)['data'];
        $annualData = $this->annualService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data'];
        $annual = $annualData['summary'];

        // sheet รายเดือนกินแถวเดือนชุดนี้โดยตรง → เทียบกับมันคือเทียบกับสิ่งที่อยู่ในไฟล์จริง
        $monthlyProfit = array_sum(array_column($annualData['months'], 'profit'));

        $this->assertSame($daily['totals']['profit'], $monthlyProfit);
        $this->assertSame($daily['totals']['profit'], (float)$annual['profit']);

        // ม.ค. 6,000 + มี.ค. 500 + ก.ค. -2,500 + ส.ค. 1,500 = 5,500
        $this->assertSame(5500.0, (float)$annual['profit']);
        $this->assertSame($daily['totals']['revenue'], (float)$annual['total_revenue']);
        $this->assertSame($daily['totals']['ad_cost'], (float)$annual['total_ad_cost']);
    }

    public function testAnnualSummaryCarriesCountsAndBestWorst(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);   // +4000
        $this->createRecord($shopId, '2026-03-10', 20000.0, 19500.0); // +500
        $this->createRecord($shopId, '2026-07-10', 1000.0, 3500.0);   // -2500

        $summary = $this->annualService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(3, $summary['months_with_data']);
        $this->assertSame(2, $summary['profit_months']);
        $this->assertSame(1, $summary['loss_months']);
        $this->assertSame(1, $summary['best_month']['month']);
        $this->assertSame(7, $summary['worst_month']['month']);
    }

    public function testYearOverYearUsesSamePeriod(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);   // ปีนี้ +4000
        $this->createRecord($shopId, '2025-01-05', 3000.0, 1000.0);   // ปีก่อน +2000 (ในช่วง)
        // ปีก่อน ต.ค. — เกิน lastMonth ต้องไม่รั่วเข้าฐานเทียบ
        $this->createRecord($shopId, '2025-10-05', 90000.0, 0.0);

        $summary = $this->annualService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(2025, $summary['prev_year']);
        $this->assertSame(2000.0, $summary['prev_year_profit']);
        $this->assertSame(2000.0, $summary['yoy_profit_change']);
        $this->assertSame(100.0, $summary['yoy_profit_change_percent']);
    }

    public function testForeignShopIsRejected(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($ownerId);
        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);

        $intruderId = $this->createUser('intruder@example.com');

        $result = $this->annualService()->buildYearlySummary($intruderId, $shopId, 2026, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testInvalidYearIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        foreach ([1999, 2101] as $invalidYear) {
            $result = $this->annualService()->buildYearlySummary($userId, $shopId, $invalidYear, self::TODAY);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('ไม่ถูกต้อง', $result['error']);
        }
    }
}
