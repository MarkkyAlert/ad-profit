<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * เลขแถวในข้อความ error ของการกรอกหลายวัน
 *
 * ฟอร์ม bulk ตัดแถวที่ผู้ใช้ไม่ได้แตะออกก่อน submit ลำดับใน payload จึงไม่ตรงกับ
 * เลขที่เห็นบนตาราง — ถ้า service นับเองจะบอก "แถวที่ 2" ทั้งที่ผู้ใช้เห็นเป็นแถวที่ 3
 * แล้วไปแก้ผิดแถว
 */
final class RecordServiceBulkRowNumberTest extends TestCase
{
    private function makeService(): RecordService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new RecordService($this->createStub(RecordRepository::class), $shopRepository, null);
    }

    public function testErrorUsesTheRowNumberTheUserSees(): void
    {
        // ผู้ใช้เห็นตาราง 5 แถว แต่กรอกแค่แถวที่ 1 กับแถวที่ 4 (แถวที่ 4 ติดลบ)
        $result = $this->makeService()->upsertManyRecords(1, 1, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => 100.0, 'ad_cost' => 10.0],
            ['row_number' => 4, 'record_date' => '2026-08-04', 'revenue' => -50.0, 'ad_cost' => 10.0],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 4', $result['error']);
        $this->assertStringNotContainsString('แถวที่ 2', $result['error']);
    }

    /** ไม่ส่ง row_number มา (เช่นเรียกจาก CSV import) → นับตามลำดับเหมือนเดิม */
    public function testFallsBackToPositionWhenRowNumberIsAbsent(): void
    {
        $result = $this->makeService()->upsertManyRecords(1, 1, [
            ['record_date' => '2026-08-01', 'revenue' => 100.0, 'ad_cost' => 10.0],
            ['record_date' => '2026-08-02', 'revenue' => -50.0, 'ad_cost' => 10.0],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', $result['error']);
    }

    public function testIgnoresNonPositiveRowNumber(): void
    {
        $result = $this->makeService()->upsertManyRecords(1, 1, [
            ['row_number' => 0, 'record_date' => '2026-08-01', 'revenue' => 100.0, 'ad_cost' => 10.0],
            ['row_number' => 0, 'record_date' => '2026-08-02', 'revenue' => -50.0, 'ad_cost' => 10.0],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', $result['error']);
    }

    public function testRowNumberAlsoAppearsForNoteTooLong(): void
    {
        $result = $this->makeService()->upsertManyRecords(1, 1, [
            ['row_number' => 7, 'record_date' => '2026-08-01', 'revenue' => 100.0, 'ad_cost' => 10.0,
             'note' => str_repeat('ก', 256)],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 7', $result['error']);
    }
}
