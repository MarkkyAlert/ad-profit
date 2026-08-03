<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ getDaysSinceLastRecord — DB จริง
 * (พิสูจน์ว่า getRecentByShopId เรียงล่าสุดถูก + แยกร้านถูก)
 */
final class RecordServiceDaysSinceTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-10';

    private function makeService(): RecordService
    {
        return new RecordService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            $this->pdo
        );
    }

    public function testReturnsDaysSinceLastSeededRecord(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // มีหลายวัน — ต้องหยิบวันล่าสุด (2026-08-04) มาคำนวณ
        $this->createRecord($shopId, '2026-07-20', 100.0, 10.0);
        $this->createRecord($shopId, '2026-08-04', 200.0, 20.0);
        $this->createRecord($shopId, '2026-08-01', 150.0, 15.0);

        $result = $this->makeService()->getDaysSinceLastRecord($userId, $shopId, self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['has_records']);
        $this->assertSame('2026-08-04', $result['data']['last_record_date']);
        $this->assertSame(6, $result['data']['days_since']); // 08-04 → 08-10
    }

    public function testReturnsNoRecordsForEmptyShop(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->makeService()->getDaysSinceLastRecord($userId, $shopId, self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['has_records']);
        $this->assertNull($result['data']['days_since']);
    }

    public function testShopsAreIsolatedFromEachOther(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // กรอกเฉพาะร้าน B
        $this->createRecord($shopB, '2026-08-09', 100.0, 10.0);

        $resultA = $this->makeService()->getDaysSinceLastRecord($userId, $shopA, self::TODAY);
        $resultB = $this->makeService()->getDaysSinceLastRecord($userId, $shopB, self::TODAY);

        // ร้าน A ยังไม่มีข้อมูลของตัวเอง
        $this->assertFalse($resultA['data']['has_records']);
        $this->assertNull($resultA['data']['days_since']);

        // ร้าน B มีข้อมูล 1 วันก่อน
        $this->assertTrue($resultB['data']['has_records']);
        $this->assertSame(1, $resultB['data']['days_since']);
    }

    /**
     * พิสูจน์ SQL ของ findLatestOnOrBeforeDate กับ DB จริง
     * (unit test จำลอง repo ไว้ จึงไม่ได้ยิง WHERE record_date <= :cutoff จริง)
     */
    public function testFutureDatedRecordFallsBackToLatestRecordUpToToday(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-01', 200.0, 20.0);
        $this->createRecord($shopId, '2026-07-15', 100.0, 10.0);
        $this->createRecord($shopId, '2027-08-20', 500.0, 50.0); // พิมพ์ปีผิด

        $result = $this->makeService()->getDaysSinceLastRecord($userId, $shopId, self::TODAY);

        $this->assertSame('2026-08-01', $result['data']['last_record_date']);
        $this->assertSame(9, $result['data']['days_since']);
    }

    public function testFallbackIsScopedToTheSameShop(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2027-08-20', 500.0, 50.0); // ร้าน A มีแต่รายการอนาคต
        $this->createRecord($shopB, '2026-08-05', 100.0, 10.0); // ร้าน B ต้องไม่ถูกหยิบมาใช้

        $result = $this->makeService()->getDaysSinceLastRecord($userId, $shopA, self::TODAY);

        $this->assertTrue($result['data']['has_records']);
        $this->assertNull($result['data']['days_since']);
        $this->assertNull($result['data']['last_record_date']);
    }
}
