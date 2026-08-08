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

    /**
     * ⭐ ตารางกรอกหลายวัน "ล้างโน้ต" ได้ — ตารางเติมโน้ตเดิมมาให้เห็นก่อนแล้ว
     *
     * เดิมตัวกันเช็กจาก "ค่าที่ส่งมาว่างไหม" ทำให้ล้างโน้ตไม่ได้เลยสักทาง
     * และข้อความ error บอกให้ "แก้โน้ต" ทั้งที่ไม่มีค่าไหนแปลว่า "ว่าง"
     * (ตัดสินแล้ว: เติมโน้ตเดิมให้เห็นก่อน แล้วลบได้)
     */
    public function testBulkRowCanDeliberatelyClearANote(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, 'โน้ตที่เขียนไว้เมื่อวาน');

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '7000', 'ad_cost' => '2000', 'note' => ''],
        ]);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));

        $row = $this->pdo->query("SELECT revenue, note FROM daily_records WHERE shop_id = {$shopId}")->fetch();
        $this->assertSame(7000.0, (float)$row['revenue']);
        $this->assertNull($row['note']);
    }

    /**
     * ⭐⭐ โน้ตที่เป็น "อักขระมองไม่เห็น" ล้วน ไม่ใช่โน้ตจริง — ต้องล้างออกได้
     *
     * ⚠️ `trim()` ของ PHP ไม่ตัด NBSP / ช่องว่างญี่ปุ่น / zero-width · ตัวกัน
     * "ช่องว่างทับของเดิม" จึงมองว่ายังมีโน้ตจริงอยู่ แล้ว **ปฏิเสธทั้งชุด**
     * พร้อมข้อความที่อ่านแล้วงงที่สุดในระบบ: โน้ต (มีข้อความ "" อยู่แล้ว)
     * — เครื่องหมายคำพูดสองตัวติดกัน เพราะข้างในมองไม่เห็น
     *
     * ผลคือแถวนั้นแก้ไม่ได้ทั้งแถวตลอดกาล (บทเรียนเดียวกับชื่อร้าน/ชื่อที่แสดง)
     */
    public function testANoteMadeOnlyOfInvisibleSpacesDoesNotBlockTheWholeBatch(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, "\u{00A0}");

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '7000', 'ad_cost' => '2000',
             // ธงนี้คือสิ่งที่ controller ตั้งให้เมื่อ "โหลดข้อมูลเดือนไม่สำเร็จ"
             // (ผู้ใช้ยังไม่เคยเห็นโน้ตเดิม การกดบันทึกจึงอาจลบมันทิ้งโดยไม่ตั้งใจ)
             'note' => '', 'note_was_missing' => true],
        ]);

        $this->assertTrue(
            $result['success'],
            'แถวถูกปฏิเสธเพราะโน้ตที่มองไม่เห็นถูกนับว่าเป็นข้อความจริง: ' . (string)($result['error'] ?? '')
        );
    }

    /** ⭐ และโน้ตที่พิมพ์มาเป็นอักขระมองไม่เห็นล้วน ต้องเก็บเป็น "ไม่มีโน้ต" */
    public function testANoteTypedAsInvisibleSpacesIsStoredAsNoNote(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '7000', 'ad_cost' => '2000',
             'note' => "\u{3000}\u{200B}"],
        ]);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertNull(
            $this->pdo->query("SELECT note FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn() ?: null,
            'เก็บอักขระที่มองไม่เห็นไว้เป็นโน้ต — บนจอจะเห็นเป็นช่องว่างโดยไม่มีใครรู้ว่ามีอะไรอยู่'
        );
    }

    /** ⭐ ไฟล์ CSV ที่ "ไม่มีคอลัมน์โน้ตเลย" ต้องไม่ล้างโน้ตของวันที่มีอยู่ */
    public function testACsvWithoutANoteColumnDoesNotWipeExistingNotes(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, 'โน้ตเดิม');

        $service = $this->makeService();
        $parsed = $service->parseImportCsv("date,revenue,ad_cost\n2026-08-01,7000,2000\n");
        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows']);

        $this->assertFalse($result['success'], 'ไฟล์ที่ไม่มีคอลัมน์โน้ตล้างโน้ตเดิมทิ้ง');
        $this->assertStringContainsString('โน้ต', (string)$result['error']);
        $this->assertSame('โน้ตเดิม', $this->pdo
            ->query("SELECT note FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
    }

    /** ไฟล์ที่มีคอลัมน์โน้ตแต่เว้นว่าง = ตั้งใจล้าง ทำได้ */
    public function testACsvWithAnEmptyNoteColumnMayClearTheNote(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, 'โน้ตเดิม');

        $service = $this->makeService();
        $parsed = $service->parseImportCsv("date,revenue,ad_cost,note\n2026-08-01,7000,2000,\n");
        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows']);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertNull($this->pdo
            ->query("SELECT note FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn());
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
     *
     * ⚠️⚠️ [2026-08-07] ตั้งแต่เจ้าของระบบตัดสินให้ "ช่องยอดว่างในไฟล์ CSV = ปฏิเสธ"
     * ตัวอ่านไฟล์จะไม่ปล่อยแถวที่ยอดว่างมาถึงตัวกันนี้อีกแล้ว **สำหรับช่องยอด**
     * (ช่องโน้ตยังมาถึงได้ตามปกติ — ดูเคสด้านบน)
     *
     * เทสต์ 3 ตัวด้านล่างจึงเรียก `upsertManyRecords()` ตรง ๆ พร้อมตั้งธง `revenue_was_blank`
     * เอง — เป็นการยืนยัน **สัญญาของเมธอด** ไม่ใช่เส้นทางที่ผู้ใช้เดินได้จริงแล้ว
     * เก็บไว้เป็นด่านที่สอง เผื่อวันหนึ่งมีคนคลายกติกาของตัวอ่านไฟล์กลับ
     */
    public function testAnExistingZeroDoesNotBlockABlankCell(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 0.0, 900.0, null);

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            $this->blankRevenueRow(1, '2026-08-01', '900'),
        ]);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** ยอดจริงยังต้องถูกกันเหมือนเดิม */
    public function testARealAmountIsStillProtected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5200.0, 900.0, null);

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            $this->blankRevenueRow(1, '2026-08-01', '900'),
        ]);

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

        // เรียงวันใหม่ก่อน: แถว 2 = 08-09, แถว 3 = 08-01
        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            $this->blankRevenueRow(2, '2026-08-09', '200'),
            $this->blankRevenueRow(3, '2026-08-01', '100'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', (string)$result['error']);
    }

    /**
     * แถวที่ "ช่องรายได้ว่าง" แบบที่ตัวอ่านไฟล์เคยส่งมา — ประกอบเองเพราะตัวอ่านไม่ส่งแล้ว
     *
     * @return array<string,mixed>
     */
    private function blankRevenueRow(int $rowNumber, string $date, string $adCost): array
    {
        return [
            'row_number' => $rowNumber,
            'record_date' => $date,
            'revenue' => '0',
            'ad_cost' => $adCost,
            'note' => null,
            'revenue_was_blank' => true,
            'ad_cost_was_blank' => false,
            'note_was_missing' => false,
        ];
    }
}
