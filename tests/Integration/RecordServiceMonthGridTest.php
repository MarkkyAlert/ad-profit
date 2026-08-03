<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ buildEditableMonthGrid — DB จริง
 */
final class RecordServiceMonthGridTest extends IntegrationTestCase
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

    public function testGridReflectsSeededRecords(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-06-01', 1000.0, 200.0, 'คอร์ส A');
        $this->createRecord($shopId, '2026-06-15', 2000.0, 0.0, null);

        $data = $this->makeService()->buildEditableMonthGrid($userId, $shopId, '2026-06', self::TODAY)['data'];

        $this->assertCount(30, $data['days']);

        $byDate = [];
        foreach ($data['days'] as $day) {
            $byDate[$day['date']] = $day;
        }

        $this->assertTrue($byDate['2026-06-01']['has_record']);
        $this->assertSame(1000.0, $byDate['2026-06-01']['revenue']);
        $this->assertSame(200.0, $byDate['2026-06-01']['ad_cost']);
        $this->assertSame('คอร์ส A', $byDate['2026-06-01']['note']);

        $this->assertTrue($byDate['2026-06-15']['has_record']);
        $this->assertSame(0.0, $byDate['2026-06-15']['ad_cost']);
        $this->assertSame('', $byDate['2026-06-15']['note']);   // note NULL → ''

        $this->assertFalse($byDate['2026-06-02']['has_record']);
        $this->assertNull($byDate['2026-06-02']['revenue']);
    }

    public function testShopsAreIsolated(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopB, '2026-06-01', 9999.0, 1.0, 'ของร้าน B');

        $data = $this->makeService()->buildEditableMonthGrid($userId, $shopA, '2026-06', self::TODAY)['data'];

        $byDate = [];
        foreach ($data['days'] as $day) {
            $byDate[$day['date']] = $day;
        }

        // ร้าน A ต้องไม่เห็นข้อมูลของร้าน B
        $this->assertFalse($byDate['2026-06-01']['has_record']);
        $this->assertNull($byDate['2026-06-01']['revenue']);
    }

    public function testRejectsShopOfAnotherUser(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($ownerId);
        $otherUserId = $this->createUser('intruder@example.com');

        $result = $this->makeService()->buildEditableMonthGrid($otherUserId, $shopId, '2026-06', self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
