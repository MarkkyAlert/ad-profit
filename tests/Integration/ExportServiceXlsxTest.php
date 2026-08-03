<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use ExportService;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของชั้นสร้างข้อมูล xlsx — DB จริง
 */
final class ExportServiceXlsxTest extends IntegrationTestCase
{
    private function makeService(): ExportService
    {
        $recordRepository = new RecordRepository($this->pdo);
        $shopRepository = new ShopRepository($this->pdo);

        return new ExportService(
            new RecordService($recordRepository, $shopRepository, $this->pdo),
            $shopRepository
        );
    }

    public function testYearlyRowsComeBackSortedWithinTheYear(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านคอร์ส');

        $this->createRecord($shopId, '2026-03-10', 3000.0, 1000.0, 'รอบเดือน มี.ค.');
        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);
        $this->createRecord($shopId, '2026-12-31', 2000.0, 500.0);
        // นอกปี — ต้องไม่หลุดเข้ามา
        $this->createRecord($shopId, '2025-12-31', 90000.0, 0.0);
        $this->createRecord($shopId, '2027-01-01', 90000.0, 0.0);

        $data = $this->makeService()->buildYearlyDailyPayload($userId, $shopId, 2026)['data'];

        $dates = array_map(static fn(array $row): string => (string)$row['record_date'], $data['rows']);
        $this->assertSame(['2026-01-05', '2026-03-10', '2026-12-31'], $dates);

        $this->assertSame('ร้านคอร์ส', $data['shop_name']);
        $this->assertSame(10000.0, $data['totals']['revenue']);
        $this->assertSame(2500.0, $data['totals']['ad_cost']);
        $this->assertSame(7500.0, $data['totals']['profit']);
        $this->assertSame('รอบเดือน มี.ค.', $data['rows'][1]['note']);
    }

    public function testAnotherUsersShopIsRejected(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($ownerId, 'ร้านเจ้าของ');
        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);

        $intruderId = $this->createUser('intruder@example.com');

        $result = $this->makeService()->buildYearlyDailyPayload($intruderId, $shopId, 2026);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testAnotherShopRecordsAreNotIncluded(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านหลัก');
        $otherShopId = $this->createShop($userId, 'ร้านอื่น');

        $this->createRecord($shopId, '2026-01-05', 3000.0, 1000.0);
        $this->createRecord($otherShopId, '2026-01-05', 99999.0, 0.0);

        $data = $this->makeService()->buildYearlyDailyPayload($userId, $shopId, 2026)['data'];

        $this->assertCount(1, $data['rows']);
        $this->assertSame(3000.0, $data['totals']['revenue']);
    }

    public function testFormulaLikeNoteSurvivesAsRawDataForTheController(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-01-05', 3000.0, 1000.0, '=SUM(A1:A9)');

        $data = $this->makeService()->buildYearlyDailyPayload($userId, $shopId, 2026)['data'];

        // service คืนโน้ตดิบ — การเติม ' กัน formula injection เป็นหน้าที่ controller
        $this->assertSame('=SUM(A1:A9)', $data['rows'][0]['note']);
        $this->assertSame(6, $data['note_column_index']);
    }

    public function testEmptyYearStillReturnsShopName(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านว่าง');

        $data = $this->makeService()->buildYearlyDailyPayload($userId, $shopId, 2026)['data'];

        $this->assertSame('ร้านว่าง', $data['shop_name']);
        $this->assertSame([], $data['rows']);
        $this->assertSame(0.0, $data['totals']['profit']);
    }
}
