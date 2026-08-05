<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AnnualService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของการตัดเดือนอนาคต + best/worst ด้วยกำไร — DB จริง
 */
final class AnnualServiceCutoffTest extends IntegrationTestCase
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

    public function testCurrentYearExcludesFutureMonths(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-01-10', 5000.0, 1000.0);   // +4000
        $this->createRecord($shopId, '2026-07-10', 2000.0, 1000.0);   // +1000 (เดือนที่จบแล้ว)
        $this->createRecord($shopId, '2026-08-10', 3000.0, 2500.0);   // +500 (เดือนปัจจุบัน ยังไม่จบ)

        $data = $this->makeService()->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data'];

        // ต้องหยุดที่ ส.ค. — ไม่มี ก.ย.–ธ.ค. โผล่มาเป็น ฿0
        $this->assertCount(8, $data['months']);
        $this->assertSame('2026-08', end($data['months'])['month_key']);
        $this->assertNotContains('2026-09', $data['chart']['months']);
        $this->assertTrue($data['has_data']);

        // worst ต้องเป็นเดือนที่มีข้อมูลจริง ไม่ใช่เดือนอนาคต
        // ⚠️ และต้องไม่ใช่เดือนปัจจุบันที่ยังไม่จบด้วย — ส.ค. กำไรน้อยสุด (฿500) ก็จริง
        // แต่ยังกรอกไม่ครบเดือน เทียบยอดสะสมกับเดือนที่จบแล้วไม่ได้
        $this->assertSame(7, $data['summary']['worst_month']['month']);
        $this->assertSame(1000.0, $data['summary']['worst_month']['profit']);
        $this->assertSame(1, $data['summary']['best_month']['month']);
        $this->assertSame(4000.0, $data['summary']['best_month']['profit']);
    }

    public function testPastYearStillCoversTwelveMonths(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2025-03-10', 1000.0, 100.0);

        $data = $this->makeService()->buildYearlySummary($userId, $shopId, 2025, self::TODAY)['data'];

        $this->assertCount(12, $data['months']);
        $this->assertSame('2025-12', end($data['months'])['month_key']);
    }

    public function testBestMonthUsesProfitNotRevenue(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // เม.ย. รายได้สูงสุดแต่แอดหนัก · พ.ค. รายได้น้อยกว่าแต่กำไรดีกว่า
        $this->createRecord($shopId, '2025-04-10', 20000.0, 19500.0);   // +500
        $this->createRecord($shopId, '2025-05-10', 4000.0, 1000.0);     // +3000

        $summary = $this->makeService()->buildYearlySummary($userId, $shopId, 2025, self::TODAY)['data']['summary'];

        $this->assertSame(5, $summary['best_month']['month']);
        $this->assertSame(3000.0, $summary['best_month']['profit']);
        $this->assertSame(4, $summary['worst_month']['month']);
    }

    public function testEmptyShopHasNullBestAndWorst(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $data = $this->makeService()->buildYearlySummary($userId, $shopId, 2025, self::TODAY)['data'];

        $this->assertFalse($data['has_data']);
        $this->assertNull($data['summary']['best_month']);
        $this->assertNull($data['summary']['worst_month']);
    }
}
