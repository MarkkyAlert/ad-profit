<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * โน้ตของวันที่เคยบันทึกไว้ต้องไม่หายเพราะกรอกวันเดิมซ้ำ
 *
 * ฟอร์มหลักในหน้า "บันทึกข้อมูลรายวัน" ไม่เคยเติมโน้ตเดิมกลับมา แต่คำสั่งบันทึกทับ
 * ทุกช่องเสมอ (ON DUPLICATE KEY UPDATE note = VALUES(note)) — แก้ยอดขายของวันเดิม
 * จึงลบโน้ตทิ้งเงียบ ๆ · หน้าประวัติกับตารางกรอกหลายวันเติมโน้ตเดิมก่อนอยู่แล้ว
 * มีแค่ฟอร์มหลักที่ไม่ได้ทำ
 *
 * ตัดสินแล้ว: ให้ฟอร์ม "ดึงโน้ตเดิมมาโชว์" — service จึงต้องมีทางให้เพจถามได้
 */
final class RecordServiceNotePreservationTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function makeService(array $rows, bool $canAccess = true): RecordService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['record_date'] >= $start && $row['record_date'] <= $end
            ))
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    /** ⭐ วันที่มีข้อมูลอยู่แล้วต้องคืนค่าครบ รวมโน้ต */
    public function testReturnsTheExistingRowIncludingItsNote(): void
    {
        $service = $this->makeService([
            ['record_date' => '2026-08-01', 'revenue' => 5000.0, 'ad_cost' => 1000.0, 'note' => 'แอดชุดใหม่เริ่มวิ่ง'],
        ]);

        $result = $service->getRecordForDate(1, 1, '2026-08-01');

        $this->assertTrue($result['success']);
        $this->assertSame('แอดชุดใหม่เริ่มวิ่ง', $result['data']['note']);
        $this->assertSame(5000.0, $result['data']['revenue']);
        $this->assertSame(1000.0, $result['data']['ad_cost']);
    }

    /** วันที่ยังไม่มีข้อมูล → คืน null ไม่ใช่ error */
    public function testReturnsNullForADayWithNoRecord(): void
    {
        $service = $this->makeService([]);

        $result = $service->getRecordForDate(1, 1, '2026-08-01');

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']);
    }

    /** โน้ตว่างเปล่าในฐานข้อมูลต้องออกมาเป็นค่าว่าง ไม่ใช่ตัวอักษร "null" */
    public function testEmptyNoteComesBackAsAnEmptyString(): void
    {
        $service = $this->makeService([
            ['record_date' => '2026-08-01', 'revenue' => 1.0, 'ad_cost' => 0.0, 'note' => null],
        ]);

        $this->assertSame('', $service->getRecordForDate(1, 1, '2026-08-01')['data']['note']);
    }

    public function testRejectsAShopTheUserCannotAccess(): void
    {
        $result = $this->makeService([], false)->getRecordForDate(1, 999, '2026-08-01');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testRejectsAMalformedDate(): void
    {
        $result = $this->makeService([])->getRecordForDate(1, 1, '01/08/2026');

        $this->assertFalse($result['success']);
    }
}
