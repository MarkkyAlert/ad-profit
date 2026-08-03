<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use ExportService;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ payload รายเดือนสำหรับ xlsx — DB จริง
 */
final class ExportServiceMonthlyPayloadTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): ExportService
    {
        $recordRepository = new RecordRepository($this->pdo);
        $shopRepository = new ShopRepository($this->pdo);

        return new ExportService(
            new RecordService($recordRepository, $shopRepository, $this->pdo),
            $shopRepository
        );
    }

    public function testMonthlyTotalsAggregateAcrossDays(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านคอร์ส');

        // ม.ค. 2 วัน → รายได้ 8000 · กำไร 6000
        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);
        $this->createRecord($shopId, '2026-01-06', 3000.0, 1000.0);
        // ก.พ. ขาดทุน
        $this->createRecord($shopId, '2026-02-10', 1000.0, 2500.0);

        $data = $this->makeService()->buildYearlyMonthlyPayload($userId, $shopId, 2026, self::TODAY)['data'];

        $this->assertCount(8, $data['months']);
        $this->assertSame('ม.ค.', $data['months'][0]['month_label']);
        $this->assertSame(8000.0, $data['months'][0]['revenue']);
        $this->assertSame(6000.0, $data['months'][0]['profit']);
        $this->assertSame(4.0, $data['months'][0]['roas']);

        $this->assertSame(-1500.0, $data['months'][1]['profit']);

        // เดือนที่ยังไม่กรอก → 0 ไม่ใช่หายไปจากแกน x
        $this->assertSame(0.0, $data['months'][2]['profit']);
        $this->assertNull($data['months'][2]['roas']);
    }

    public function testFutureDatedRecordsAreExcluded(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-10', 3000.0, 1000.0);
        $this->createRecord($shopId, '2026-11-10', 90000.0, 0.0);

        $data = $this->makeService()->buildYearlyMonthlyPayload($userId, $shopId, 2026, self::TODAY)['data'];

        $this->assertCount(8, $data['months']);
        $this->assertSame(2000.0, $data['months'][7]['profit']);
        $this->assertSame(2000.0, array_sum(array_column($data['months'], 'profit')));
    }

    public function testAnotherUsersShopIsRejected(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($ownerId);
        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);

        $intruderId = $this->createUser('intruder@example.com');

        $result = $this->makeService()->buildYearlyMonthlyPayload($intruderId, $shopId, 2026, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testAnotherShopRecordsDoNotLeakIn(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านหลัก');
        $otherShopId = $this->createShop($userId, 'ร้านอื่น');

        $this->createRecord($shopId, '2026-01-05', 3000.0, 1000.0);
        $this->createRecord($otherShopId, '2026-01-05', 99999.0, 0.0);

        $months = $this->makeService()->buildYearlyMonthlyPayload($userId, $shopId, 2026, self::TODAY)['data']['months'];

        $this->assertSame(2000.0, $months[0]['profit']);
    }
}
