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
    /**
     * โน้ตที่ถูกส่งลง repository จริง — ไม่ใช่ค่าที่เทสต์คำนวณเอง
     *
     * `upsertRecord()` ไม่ได้คืนค่าที่ normalize แล้วออกมา จึงต้องดักที่ปลายทาง
     */
    private function normalizedNoteFor(?string $note): ?string
    {
        $captured = 'ยังไม่ถูกเรียก';

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('upsert')->willReturnCallback(
            function (int $shopId, string $date, float $revenue, float $adCost, ?string $storedNote) use (&$captured): bool {
                $captured = $storedNote;

                return true;
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new RecordService($recordRepository, $shopRepository, null);
        $result = $service->upsertRecord(1, 1, '2024-01-15', 100.0, 10.0, $note);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertNotSame('ยังไม่ถูกเรียก', $captured, 'ไม่ได้เรียก repository เลย — เทสต์ไม่ได้พิสูจน์อะไร');

        return $captured;
    }

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
     * ⭐⭐ โน้ตที่เป็นอักขระมองไม่เห็นล้วน = ไม่มีโน้ต (ฟอร์มวันเดียว)
     *
     * ⚠️ `trim()` ของ PHP ไม่ตัด NBSP / ช่องว่างญี่ปุ่น / zero-width · ถ้าเก็บไว้:
     *   · บนจอเห็นเป็นช่องว่าง ไม่มีใครรู้ว่ามีอะไรอยู่
     *   · ตัวกัน "ช่องว่างทับของเดิม" จะนับว่ามีโน้ตจริง แล้ว **บล็อกแถวนั้นตลอดกาล**
     *     พร้อมข้อความ `โน้ต (มีข้อความ "" อยู่แล้ว)` ที่อ่านไม่รู้เรื่อง
     *
     * ⚠️ ทางตารางกรอกหลายวันแปลงให้ตั้งแต่ต้นทาง เทสต์ตรงนั้นจึงไม่ครอบจุดนี้
     * — ต้องยิงที่ `upsertRecord()` ตรง ๆ ถึงจะพิสูจน์ได้ (วัดแล้ว: กลายพันธุ์ไม่แดง)
     */
    public function testANoteMadeOnlyOfInvisibleSpacesCountsAsNoNote(): void
    {
        $service = $this->makeService();

        foreach (["\u{00A0}", "\u{3000}", "\u{200B}", "\u{FEFF}\u{00A0}"] as $invisible) {
            $this->assertNull(
                $this->normalizedNoteFor($invisible),
                'เก็บอักขระที่มองไม่เห็นไว้เป็นโน้ต — จะบล็อกการแก้ไขแถวนั้นตลอดกาล'
            );
        }
    }

    /** ⭐ ส่วนโน้ตจริงที่มีช่องว่างหุ้ม ต้องถูกตัดหัวท้ายแต่เนื้อในต้องอยู่ครบ */
    public function testARealNoteKeepsItsContentAfterTrimming(): void
    {
        $this->assertSame('ยอดวันอังคาร', $this->normalizedNoteFor("\u{00A0}ยอดวันอังคาร\u{3000}"));
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
