<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ช่องว่างในไฟล์ CSV ต้องไม่ลบยอดขายจริงทิ้งเงียบ ๆ
 *
 * กติกา "ช่องว่าง = 0" ถูกตัดสินไว้แล้วสำหรับวันที่ยังไม่มีข้อมูล (ไม่ได้ยิงแอด/ไม่มียอด
 * เป็นเรื่องปกติ) — แต่ตอนนั้นยังไม่ได้ชั่งน้ำหนักกรณี "ทับข้อมูลที่มีอยู่แล้ว"
 * ผู้ใช้ที่ดาวน์โหลด CSV ไปแก้ใน Excel แล้วเผลอลบตัวเลขทิ้ง 1 ช่อง จะได้ข้อความ
 * "บันทึกเรียบร้อยแล้ว" ทั้งที่เพิ่งลบยอดขายจริงของวันนั้นไป
 *
 * ตัดสินแล้ว: ปฏิเสธทั้งไฟล์พร้อมบอกว่าแถวไหน — วันใหม่ยังคง "ช่องว่าง = 0" เหมือนเดิม
 */
final class RecordServiceImportOverwriteTest extends IntegrationTestCase
{
    private function makeService(): RecordService
    {
        return new RecordService(new RecordRepository($this->pdo), new ShopRepository($this->pdo), $this->pdo);
    }

    private function importCsv(int $userId, int $shopId, string $csv): array
    {
        $service = $this->makeService();
        $parsed = $service->parseImportCsv($csv);

        if (($parsed['success'] ?? false) !== true) {
            return $parsed;
        }

        return $service->upsertManyRecords($userId, $shopId, $parsed['rows']);
    }

    /** ⭐ ช่องว่างที่จะไปทับวันที่มียอดอยู่แล้ว → ปฏิเสธทั้งไฟล์ */
    public function testBlankAmountThatWouldOverwriteRealDataIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5200.0, 1000.0, 'โน้ตเดิม');

        $result = $this->importCsv($userId, $shopId, "date,revenue,ad_cost,note\n2026-08-01,,1000,\n");

        $this->assertFalse($result['success'], 'ยอมให้ช่องว่างทับยอดจริง');
        $this->assertStringContainsString('แถวที่ 2', (string)$result['error']);

        // ข้อมูลเดิมต้องอยู่ครบ ไม่ใช่แค่ error แล้วเขียนไปแล้วครึ่งทาง
        $row = $this->pdo->query("SELECT revenue, note FROM daily_records WHERE shop_id = {$shopId}")->fetch();
        $this->assertSame(5200.0, (float)$row['revenue']);
        $this->assertSame('โน้ตเดิม', $row['note']);
    }

    /** วันใหม่ที่ยังไม่มีข้อมูล — ช่องว่างยังเท่ากับ 0 ตามที่ตัดสินไว้เดิม */
    public function testBlankAmountOnANewDateStillMeansZero(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->importCsv($userId, $shopId, "date,revenue,ad_cost,note\n2026-08-01,,1000,\n");

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(0.0, (float)$this->pdo
            ->query("SELECT revenue FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
    }

    /** ใส่ 0 มาเองชัดเจน ทับได้ — ผู้ใช้ตั้งใจ ต่างจากช่องว่างที่มักเป็นอุบัติเหตุ */
    public function testAnExplicitZeroMayStillOverwrite(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5200.0, 1000.0, null);

        $result = $this->importCsv($userId, $shopId, "date,revenue,ad_cost,note\n2026-08-01,0,1000,\n");

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(0.0, (float)$this->pdo
            ->query("SELECT revenue FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
    }

    /** ช่องค่าแอดว่างก็ต้องกันเหมือนกัน ไม่ใช่กันแค่ช่องรายได้ */
    public function testBlankAdCostIsGuardedToo(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5200.0, 1000.0, null);

        $result = $this->importCsv($userId, $shopId, "date,revenue,ad_cost,note\n2026-08-01,5200,,\n");

        $this->assertFalse($result['success']);
        $this->assertSame(1000.0, (float)$this->pdo
            ->query("SELECT ad_cost FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
    }

    /** ทั้งไฟล์ถูกปฏิเสธ — แถวอื่นที่ไม่มีปัญหาต้องไม่ถูกเขียนลงไปก่อน */
    public function testTheWholeFileIsRejectedNotJustTheBadRow(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-02', 5200.0, 1000.0, null);

        $csv = "date,revenue,ad_cost,note\n2026-08-01,1111,222,ปกติ\n2026-08-02,,1000,\n";
        $result = $this->importCsv($userId, $shopId, $csv);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->countRows('daily_records'), 'แถวที่ไม่มีปัญหาถูกเขียนลงไปแล้ว');
    }

    /** ร้านอื่นมีวันนั้นอยู่ ต้องไม่ทำให้ร้านนี้ถูกปฏิเสธ */
    public function testAnotherShopsDataDoesNotBlockThisImport(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');
        $this->createRecord($shopB, '2026-08-01', 9999.0, 1.0, null);

        $result = $this->importCsv($userId, $shopA, "date,revenue,ad_cost,note\n2026-08-01,,1000,\n");

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }
}
