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

    /**
     * ⭐⭐ [เจ้าของระบบตัดสิน 2026-08-07] วันใหม่ที่เว้นช่องยอดไว้ **ก็ถูกปฏิเสธ**
     *
     * เดิมกติกาคือ "ช่องว่าง = 0 สำหรับวันใหม่" ซึ่งทำให้ CSV เป็นทางเขียนเดียวที่เดา
     * แทนผู้ใช้ (ฟอร์มวันเดียวและตารางกรอกหลายวันบังคับกรอกทั้งคู่)
     *
     * ⚠️ ที่เปลี่ยนเพราะความเสียหายสองฝั่งไม่เท่ากัน: เดาเป็น 0 แล้วผิด = ได้วันขาดทุน
     * ปลอมที่ไหลเข้าทุกรายงานโดยผู้ใช้ไม่รู้ตัว · ปฏิเสธแล้วผิด = ผู้ใช้พิมพ์ 0 เอง
     */
    public function testBlankAmountOnANewDateIsRejectedToo(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->importCsv($userId, $shopId, "date,revenue,ad_cost,note\n2026-08-01,,1000,\n");

        $this->assertFalse($result['success'], 'ยังเดาช่องว่างเป็น 0 อยู่');
        $this->assertStringContainsString('แถวที่ 2', (string)$result['error']);
        $this->assertStringContainsString('ใส่ 0', (string)$result['error'], 'ไม่ได้บอกผู้ใช้ว่าต้องทำอะไรต่อ');

        $this->assertSame(0, (int)$this->pdo
            ->query("SELECT COUNT(*) FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
    }

    /** ⚠️ ใส่ 0 มาเองยังต้องผ่านตามปกติ — ไม่ใช่ปฏิเสธทุกอย่างที่เป็นศูนย์ */
    public function testAnExplicitZeroOnANewDateStillWorks(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->importCsv($userId, $shopId, "date,revenue,ad_cost,note\n2026-08-01,0,1000,\n");

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
        // ร้าน B มีโน้ตของวันเดียวกันอยู่ — ตัวกันต้องมองเฉพาะข้อมูลของร้านที่กำลังนำเข้า
        $this->createRecord($shopB, '2026-08-01', 9999.0, 1.0, 'โน้ตของร้าน B');

        // ⚠️ ใช้ไฟล์ที่ **ไม่มีคอลัมน์โน้ตเลย** เพื่อให้เดินผ่านตัวกัน "ช่องว่างทับของเดิม" จริง
        // (ช่องยอดว่างถูกปฏิเสธตั้งแต่ชั้น parser แล้ว จึงใช้เป็นตัวกระตุ้นไม่ได้อีก)
        $result = $this->importCsv($userId, $shopA, "date,revenue,ad_cost\n2026-08-01,1111,222\n");

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame('โน้ตของร้าน B', (string)$this->pdo
            ->query("SELECT note FROM daily_records WHERE shop_id = {$shopB}")->fetchColumn(),
            'ข้อมูลของอีกร้านถูกแตะ');
    }
}
