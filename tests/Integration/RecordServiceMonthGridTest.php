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

    /**
     * ⭐⭐ วันล่วงหน้าที่มีข้อมูลอยู่แล้ว ต้องส่งกลับไปให้ฟอร์มเติมค่าเดิมได้
     *
     * ⚠️ ตารางกรอกหลายวันไม่ควรมีแถวของวันที่ยังมาไม่ถึง (`days` ตัดที่วันนี้)
     * แต่ฟอร์มกรอกวันเดียวต้องเติมค่าเดิมได้ทุกวันที่มีข้อมูล ไม่งั้น:
     *   ลงข้อมูลล่วงหน้าไว้ → กลับมาเลือกวันนั้น → ฟอร์มว่างเปล่า → พิมพ์แค่ยอด
     *   แล้วกดบันทึก → **โน้ตเดิมหายไป** พร้อมข้อความ "บันทึกข้อมูลเรียบร้อยแล้ว"
     *
     * คำเตือนอย่างเดียวไม่พอ — ข้อมูลมีให้ใช้อยู่แล้ว แค่ไม่ได้ส่งมา
     */
    public function testFutureDaysThatAlreadyHaveDataAreSentBackSeparately(): void
    {
        $userId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-02', 1000.0, 100.0);
        $this->createRecord($shopId, '2026-08-30', 5000.0, 1000.0, 'สรุปแคมเปญ: ห้ามลืม');

        $data = $this->makeService()->buildEditableMonthGrid($userId, $shopId, '2026-08', self::TODAY)['data'];

        // ตารางกรอกหลายวันยังไม่มีแถวของวันอนาคตเหมือนเดิม
        $gridDates = array_column($data['days'], 'date');
        $this->assertNotContains('2026-08-30', $gridDates, 'ตารางกรอกหลายวันมีแถวของวันที่ยังมาไม่ถึง');
        $this->assertLessThanOrEqual(self::TODAY, (string)max($gridDates));

        // แต่ฟอร์มวันเดียวต้องเติมค่าเดิมของวันนั้นได้
        $future = $data['future_days_with_records'] ?? [];
        $this->assertCount(1, $future, 'ไม่ได้ส่งวันล่วงหน้าที่มีข้อมูลกลับมา — โน้ตจะหายตอนบันทึกทับ');
        $this->assertSame('2026-08-30', $future[0]['date']);
        $this->assertSame('สรุปแคมเปญ: ห้ามลืม', $future[0]['note']);
        $this->assertSame(5000.0, $future[0]['revenue']);
    }

    /** ⭐ วันล่วงหน้าที่ยังไม่มีข้อมูล ต้องไม่ถูกส่งมา (ไม่ใช่ทุกวันที่เหลือของเดือน) */
    public function testEmptyFutureDaysAreNotSentBack(): void
    {
        $userId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-02', 1000.0, 100.0);

        $data = $this->makeService()->buildEditableMonthGrid($userId, $shopId, '2026-08', self::TODAY)['data'];

        $this->assertSame([], $data['future_days_with_records'] ?? null);
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
