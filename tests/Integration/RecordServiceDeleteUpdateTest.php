<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * updateRecord / deleteRecord — ก่อนหน้านี้ไม่มีเทสต์ครอบเลยแม้แต่เคสเดียว
 *
 * เป็น integration เพราะสาขาที่สำคัญที่สุดคือ transaction/lock/ownership ซึ่ง unit test
 * ที่ส่ง $db = null ข้ามไปทั้งหมด
 */
final class RecordServiceDeleteUpdateTest extends IntegrationTestCase
{
    private function makeService(): RecordService
    {
        return new RecordService(new RecordRepository($this->pdo), new ShopRepository($this->pdo), $this->pdo);
    }

    private function seedRecord(int $shopId, string $date = '2026-08-01'): int
    {
        $this->createRecord($shopId, $date, 1000.0, 200.0, 'เดิม');

        return (int)$this->pdo
            ->query("SELECT id FROM daily_records WHERE shop_id = {$shopId} AND record_date = '{$date}'")
            ->fetchColumn();
    }

    // ── deleteRecord ────────────────────────────────────────────────────────

    public function testDeleteRemovesTheRow(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $recordId = $this->seedRecord($shopId);

        $result = $this->makeService()->deleteRecord($userId, $shopId, $recordId);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /**
     * ลบซ้ำต้องตอบสำเร็จ (ตัดสินแล้ว) — ผลลัพธ์ตรงกับที่ผู้ใช้ขอไปแล้ว
     *
     * เดิมตอบ error แดง "ไม่พบรายการที่ต้องการลบ" เมื่อกด back แล้ว submit ใหม่
     * หรือเน็ตกระตุกแล้ว retry ทั้งที่ลบสำเร็จไปแล้ว
     */
    public function testDeletingTwiceIsNotAnError(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $recordId = $this->seedRecord($shopId);

        $service = $this->makeService();
        $this->assertTrue($service->deleteRecord($userId, $shopId, $recordId)['success']);
        $this->assertTrue($service->deleteRecord($userId, $shopId, $recordId)['success']);

        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /**
     * รายการของผู้ใช้อื่นต้องไม่ถูกลบ และคำตอบต้องไม่บอกใบ้ว่ามี id นั้นอยู่จริงไหม
     *
     * หลังทำ delete เป็น idempotent คำตอบของ "id ของคนอื่น" กับ "id ที่ไม่มีอยู่จริง"
     * เหมือนกันทุกประการโดยตั้งใจ — สิ่งที่ต้องรับประกันคือข้อมูลไม่หาย ไม่ใช่ข้อความ
     */
    public function testAnotherUsersRecordIsNeitherDeletedNorRevealed(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $ownerShop = $this->createShop($ownerId, 'ร้านเจ้าของ');
        $recordId = $this->seedRecord($ownerShop);

        $otherId = $this->createUser('other@example.com');
        $otherShop = $this->createShop($otherId, 'ร้านคนอื่น');

        $service = $this->makeService();
        $foreignId = $service->deleteRecord($otherId, $otherShop, $recordId);
        $missingId = $service->deleteRecord($otherId, $otherShop, 999999);

        // ข้อมูลของเจ้าของยังอยู่ครบ — นี่คือสิ่งที่ต้องรับประกัน
        $this->assertSame(1, $this->countRows('daily_records'), 'รายการของผู้ใช้อื่นถูกลบ');

        // แยกไม่ออกว่า id นั้นมีอยู่จริงหรือไม่
        $this->assertSame($missingId, $foreignId);
    }

    public function testDeleteRejectsForeignShop(): void
    {
        $userId = $this->createUser('owner@example.com');
        $this->createShop($userId);
        $otherId = $this->createUser('other@example.com');
        $otherShop = $this->createShop($otherId, 'ร้านคนอื่น');

        $result = $this->makeService()->deleteRecord($userId, $otherShop, 1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    // ── updateRecord ────────────────────────────────────────────────────────

    public function testUpdateChangesTheStoredValues(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $recordId = $this->seedRecord($shopId);

        $result = $this->makeService()
            ->updateRecord($userId, $shopId, $recordId, '2026-08-01', 5000.0, 1000.0, 'แก้แล้ว');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));

        $row = $this->pdo->query("SELECT revenue, ad_cost, note FROM daily_records WHERE id = {$recordId}")->fetch();
        $this->assertSame(5000.0, (float)$row['revenue']);
        $this->assertSame(1000.0, (float)$row['ad_cost']);
        $this->assertSame('แก้แล้ว', $row['note']);
    }

    /** ย้ายวันที่ข้ามเดือนได้ (controller เป็นคนพากลับไปเดือนใหม่) */
    public function testUpdateCanMoveTheRecordToAnotherMonth(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $recordId = $this->seedRecord($shopId, '2026-08-15');

        $result = $this->makeService()
            ->updateRecord($userId, $shopId, $recordId, '2026-07-20', 1000.0, 200.0, null);

        $this->assertTrue($result['success']);
        $this->assertSame(
            '2026-07-20',
            $this->pdo->query("SELECT record_date FROM daily_records WHERE id = {$recordId}")->fetchColumn()
        );
    }

    /** ย้ายไปวันที่ที่มีรายการอยู่แล้วต้องถูกปฏิเสธ ไม่ใช่เขียนทับ */
    public function testUpdateToAnOccupiedDateIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $firstId = $this->seedRecord($shopId, '2026-08-01');
        $this->seedRecord($shopId, '2026-08-02');

        $result = $this->makeService()
            ->updateRecord($userId, $shopId, $firstId, '2026-08-02', 1000.0, 200.0, null);

        $this->assertFalse($result['success']);
        $this->assertSame(2, $this->countRows('daily_records'));
        $this->assertSame(
            '2026-08-01',
            $this->pdo->query("SELECT record_date FROM daily_records WHERE id = {$firstId}")->fetchColumn()
        );
    }

    public function testUpdateRejectsNegativeValues(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $recordId = $this->seedRecord($shopId);

        $result = $this->makeService()
            ->updateRecord($userId, $shopId, $recordId, '2026-08-01', -1.0, 200.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ติดลบ', $result['error']);
    }

    public function testUpdateCannotTouchAnotherUsersRecord(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $ownerShop = $this->createShop($ownerId, 'ร้านเจ้าของ');
        $recordId = $this->seedRecord($ownerShop);

        $otherId = $this->createUser('other@example.com');
        $otherShop = $this->createShop($otherId, 'ร้านคนอื่น');

        $result = $this->makeService()
            ->updateRecord($otherId, $otherShop, $recordId, '2026-08-01', 9999.0, 0.0, 'แฮก');

        $this->assertFalse($result['success']);
        $this->assertSame(1000.0, (float)$this->pdo
            ->query("SELECT revenue FROM daily_records WHERE id = {$recordId}")->fetchColumn());
    }

    public function testUpdateOfMissingRecordFails(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->makeService()
            ->updateRecord($userId, $shopId, 999999, '2026-08-01', 100.0, 10.0, null);

        $this->assertFalse($result['success']);
    }
}
