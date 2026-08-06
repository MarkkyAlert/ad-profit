<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

final class RecordServiceTest extends TestCase
{
    /**
     * สร้าง service โดย mock repository ทั้งคู่ และส่ง db = null (ข้าม transaction/lock)
     * userCanAccessShop คุมได้ผ่านพารามิเตอร์ $canAccess
     */
    private function makeService(bool $canAccess = true): RecordService
    {
        $recordRepository = $this->createStub(RecordRepository::class);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    public function testUpsertFailsWhenRevenueIsNegative(): void
    {
        $service = $this->makeService();

        $result = $service->upsertRecord(1, 1, '2024-01-15', -1.0, 0.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ติดลบ', $result['error']);
    }

    public function testUpsertFailsWhenAdCostIsNegative(): void
    {
        $service = $this->makeService();

        $result = $service->upsertRecord(1, 1, '2024-01-15', 100.0, -5.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ติดลบ', $result['error']);
    }

    public function testUpsertFailsWhenNoteExceeds255Chars(): void
    {
        $service = $this->makeService();

        $longNote = str_repeat('ก', 256); // 256 ตัวอักษร (mb) > 255

        $result = $service->upsertRecord(1, 1, '2024-01-15', 100.0, 10.0, $longNote);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('255', $result['error']);
    }

    /**
     * ค่าเกิน DECIMAL(12,2) ต้องถูกปฏิเสธพร้อมบอกสาเหตุ
     *
     * เดิมปล่อยผ่านไปถึง DB: strict mode → exception แล้ว rollback ทั้งชุดพร้อม error
     * ลอย ๆ "ไม่สามารถบันทึกข้อมูลได้" ที่ไม่บอกว่าแถวไหน · non-strict → ตัดค่าเงียบ
     * แล้วรายงานว่าสำเร็จ (โค้ดไม่ได้ตั้ง sql_mode เอง จึงขึ้นกับ host)
     */
    public function testRevenueBeyondColumnLimitIsRejected(): void
    {
        $result = $this->makeService()->upsertRecord(1, 1, '2026-08-01', RecordService::MAX_AMOUNT + 1, 0.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่เกิน', $result['error']);
    }

    public function testAmountExactlyAtColumnLimitIsAccepted(): void
    {
        $result = $this->makeService()->upsertRecord(1, 1, '2026-08-01', RecordService::MAX_AMOUNT, 0.0, null);

        $this->assertTrue($result['success']);
    }

    /** ยอดรายวันเป็นข้อมูลจริงของวันที่เกิดขึ้นแล้วเท่านั้น */
    public function testFutureDatesAreRejectedBeforeAnyWritePathTouchesTheRepository(): void
    {
        $recordRepository = $this->createMock(RecordRepository::class);
        $recordRepository->expects($this->never())->method('upsert');
        $recordRepository->expects($this->never())->method('updateByIdAndShopId');

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new RecordService($recordRepository, $shopRepository, null);

        $single = $service->upsertRecord(1, 1, '2026-08-11', 100.0, 10.0, null, '2026-08-10');
        $bulk = $service->upsertManyRecords(1, 1, [
            ['row_number' => 1, 'record_date' => '2026-08-11', 'revenue' => 100.0, 'ad_cost' => 10.0],
        ], null, '2026-08-10');
        $update = $service->updateRecord(1, 1, 1, '2026-08-11', 100.0, 10.0, null, '2026-08-10');

        foreach (['single' => $single, 'bulk' => $bulk, 'update' => $update] as $label => $result) {
            $this->assertFalse($result['success'], $label);
            $this->assertStringContainsString('อนาคต', (string)$result['error'], $label);
        }
    }

    /**
     * ปีนอกช่วงที่รายงานรองรับต้องถูกปฏิเสธ
     *
     * เดิมบันทึกสำเร็จแล้วหายจากทุกหน้ารายงาน เพราะ resolve_calendar_year() และ
     * isValidYear ของ service รายปีจำกัดที่ 2000-2100 (เจอจริงกับ CSV วันที่ 01/01/2450)
     */
    public function testYearOutsideReportableRangeIsRejected(): void
    {
        $service = $this->makeService();

        foreach (['1907-01-01', '2450-01-01', '1999-12-31', '2101-01-01'] as $date) {
            $result = $service->upsertRecord(1, 1, $date, 100.0, 10.0, null);

            $this->assertFalse($result['success'], "ยอมรับวันที่ {$date} ที่อยู่นอกช่วงรายงาน");
            $this->assertStringContainsString('ปี', $result['error']);
        }
    }

    public function testYearsAtTheEdgeOfTheRangeAreAccepted(): void
    {
        $service = $this->makeService();

        $this->assertTrue($service->upsertRecord(1, 1, '2000-01-01', 100.0, 10.0, null, '2000-01-01')['success']);
        $this->assertTrue($service->upsertRecord(1, 1, '2100-12-31', 100.0, 10.0, null, '2100-12-31')['success']);
    }
}
