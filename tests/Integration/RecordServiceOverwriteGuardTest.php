<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ตัวกัน "เว้นช่องแล้วของเดิมหาย" ต้องใช้กับทุกทางที่เขียนข้อมูล ไม่ใช่เฉพาะ CSV
 *
 * รอบก่อนแก้ให้ฟอร์มเดี่ยวเติมโน้ตเดิม และให้ CSV ปฏิเสธเมื่อช่องว่างจะทับยอดจริง
 * แต่ **ตารางกรอกหลายวัน** ตกหล่น — พิมพ์วันที่ที่มีข้อมูลอยู่แล้วลงตาราง กรอกยอดใหม่
 * แล้วโน้ตเดิมหายไปเงียบ ๆ พร้อมข้อความ "บันทึกเรียบร้อยแล้ว"
 */
final class RecordServiceOverwriteGuardTest extends IntegrationTestCase
{
    private function makeService(): RecordService
    {
        return new RecordService(new RecordRepository($this->pdo), new ShopRepository($this->pdo), $this->pdo);
    }

    /** ⭐ ตารางกรอกหลายวันต้องไม่ลบโน้ตเดิมทิ้งเงียบ ๆ */
    public function testBulkRowWithAnEmptyNoteDoesNotWipeTheExistingNote(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, 'โน้ตที่เขียนไว้เมื่อวาน');

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '7000', 'ad_cost' => '2000', 'note' => ''],
        ]);

        $this->assertFalse($result['success'], 'ยอมให้ทับโน้ตเดิมโดยไม่เตือน');
        $this->assertStringContainsString('แถวที่ 1', (string)$result['error']);
        $this->assertStringContainsString('โน้ต', (string)$result['error']);

        $row = $this->pdo->query("SELECT revenue, note FROM daily_records WHERE shop_id = {$shopId}")->fetch();
        $this->assertSame(5000.0, (float)$row['revenue'], 'ยอดเดิมถูกเขียนทับไปแล้วทั้งที่ปฏิเสธ');
        $this->assertSame('โน้ตที่เขียนไว้เมื่อวาน', $row['note']);
    }

    /** กรอกโน้ตมาด้วยก็ทับได้ตามปกติ — ผู้ใช้เห็นและตั้งใจ */
    public function testBulkRowWithANoteOverwritesNormally(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, 'โน้ตเดิม');

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '7000', 'ad_cost' => '2000', 'note' => 'โน้ตใหม่'],
        ]);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame('โน้ตใหม่', $this->pdo
            ->query("SELECT note FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
    }

    /** วันที่ยังไม่มีโน้ตอยู่แล้ว → เว้นว่างได้ตามปกติ ไม่มีอะไรจะหาย */
    public function testADayWithoutANoteIsNotBlocked(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, null);

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '7000', 'ad_cost' => '2000', 'note' => ''],
        ]);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /**
     * ⭐ ค่าเดิมเป็น 0 อยู่แล้ว → เขียนทับด้วย 0 ไม่ได้ทำให้อะไรหาย ต้องไม่ขวาง
     *
     * เดิมขึ้นข้อความ "รายได้ (มียอด 0.00 อยู่แล้ว)" ซึ่งอ่านแล้วไม่มีเหตุผล และทำให้
     * คนที่ลงวันที่ยิงแอดแต่ไม่มียอด นำเข้าไฟล์ซ้ำไม่ได้เลย
     */
    public function testAnExistingZeroDoesNotBlockABlankCell(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 0.0, 900.0, null);

        $service = $this->makeService();
        $parsed = $service->parseImportCsv("date,revenue,ad_cost,note\n2026-08-01,,900,\n");
        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows']);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** ยอดจริงยังต้องถูกกันเหมือนเดิม */
    public function testARealAmountIsStillProtected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5200.0, 900.0, null);

        $service = $this->makeService();
        $parsed = $service->parseImportCsv("date,revenue,ad_cost,note\n2026-08-01,,900,\n");
        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('5,200.00', (string)$result['error']);
    }

    /** รายงาน "แถวแรกที่ผู้ใช้เห็น" ไม่ใช่แถวที่วันที่น้อยสุด */
    public function testTheReportedRowIsTheFirstOneOnScreen(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 1000.0, 100.0, null);
        $this->createRecord($shopId, '2026-08-09', 2000.0, 200.0, null);

        // ไฟล์เรียงวันใหม่ก่อน: แถว 2 = 08-09, แถว 3 = 08-01
        $csv = "date,revenue,ad_cost,note\n2026-08-09,,200,\n2026-08-01,,100,\n";
        $service = $this->makeService();
        $parsed = $service->parseImportCsv($csv);
        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows']);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', (string)$result['error']);
    }
}
