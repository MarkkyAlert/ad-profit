<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::upsertManyRecords (bulk entry)
 * ส่ง db = null → ข้าม transaction/lock, stub repository ล้วน
 */
final class RecordServiceBulkTest extends TestCase
{
    private function makeService(bool $canAccess = true): RecordService
    {
        $recordRepository = $this->createStub(RecordRepository::class);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $date, mixed $revenue, mixed $adCost, ?string $note = null): array
    {
        return [
            'record_date' => $date,
            'revenue' => $revenue,
            'ad_cost' => $adCost,
            'note' => $note,
        ];
    }

    public function testAllValidRowsSucceed(): void
    {
        $service = $this->makeService();

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('2024-01-01', 1000, 200),
            $this->row('2024-01-02', 1500, 300, 'แอดใหม่'),
            $this->row('2024-01-03', 900, 0),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['saved_count']);
    }

    public function testNegativeRevenueFailsWholeBatchAndWritesNothing(): void
    {
        // เคสนี้ต้อง verify interaction → ใช้ createMock + expects(never())
        $recordRepository = $this->createMock(RecordRepository::class);
        $recordRepository->expects($this->never())->method('upsert');

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new RecordService($recordRepository, $shopRepository, null);

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('2024-01-01', 1000, 200),
            $this->row('2024-01-02', -50, 100), // แถวที่ 2 ติดลบ
            $this->row('2024-01-03', 900, 100),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', $result['error']);
        $this->assertStringContainsString('ติดลบ', $result['error']);
    }

    public function testDuplicateDateWithinBatchFails(): void
    {
        $service = $this->makeService();

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('2024-01-01', 1000, 200),
            $this->row('2024-01-01', 1500, 300), // วันซ้ำ
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ซ้ำ', $result['error']);
        $this->assertStringContainsString('2024-01-01', $result['error']);
    }

    public function testBlankRowsAreSkipped(): void
    {
        $service = $this->makeService();

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('2024-01-01', 1000, 200),
            $this->row('', '', '', null),   // แถวว่างสนิท → ข้าม
            $this->row('2024-01-02', 500, 100),
            $this->row('', '', '', null),   // แถวว่างสนิท → ข้าม
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['saved_count']); // นับเฉพาะแถวที่กรอกจริง
    }

    public function testAllBlankRowsFail(): void
    {
        $service = $this->makeService();

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('', '', '', null),
            $this->row('', '', '', null),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('อย่างน้อย 1 แถว', $result['error']);
    }

    public function testMoreThanMaxRowsFails(): void
    {
        $service = $this->makeService();

        $rows = [];
        for ($day = 1; $day <= RecordService::BULK_MAX_ROWS + 1; $day++) {
            // ใช้วันที่ไม่ซ้ำกัน เพื่อให้ fail ที่กติกาจำนวนแถว ไม่ใช่วันซ้ำ
            $rows[] = $this->row(sprintf('2024-%02d-01', $day), 100, 10);
        }

        $result = $service->upsertManyRecords(1, 1, $rows);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString((string)RecordService::BULK_MAX_ROWS, $result['error']);
    }

    /**
     * ⭐ เพดานอยู่ตรงไหนแน่: พอดี = ผ่าน · เกิน 1 = ไม่ผ่าน
     *
     * ⚠️ เดิมเทสต์นี้ส่ง `BULK_MAX_ROWS + 1` เหมือนเทสต์ข้างบนทุกอย่าง ต่างแค่คอมเมนต์
     * จึงเป็นเคสเดียวกันสองชื่อ และไม่มีอะไรพิสูจน์ว่าค่าปริยายอยู่ที่เลขไหน
     */
    public function testTheDefaultCapAllowsExactlyBulkMaxRows(): void
    {
        $rows = [];
        for ($day = 1; $day <= RecordService::BULK_MAX_ROWS; $day++) {
            $rows[] = $this->row(sprintf('2024-01-%02d', $day), 100, 10);
        }

        $atTheCap = $this->makeService()->upsertManyRecords(1, 1, $rows);
        $this->assertTrue($atTheCap['success'], 'จำนวนแถวพอดีเพดานกลับถูกปฏิเสธ: ' . ($atTheCap['error'] ?? ''));

        $rows[] = $this->row('2024-02-01', 100, 10);
        $overTheCap = $this->makeService()->upsertManyRecords(1, 1, $rows);
        $this->assertFalse($overTheCap['success'], 'เกินเพดานไป 1 แถวแล้วยังผ่าน');
    }

    public function testHigherCapAllowsMoreThanBulkMaxRows(): void
    {
        $service = $this->makeService();

        // 40 แถว (เกิน 31) แต่ส่ง cap สูงกว่า → ต้องผ่าน
        $rows = [];
        $date = new \DateTimeImmutable('2024-01-01');
        for ($index = 0; $index < 40; $index++) {
            $rows[] = $this->row($date->modify('+' . $index . ' days')->format('Y-m-d'), 100, 10);
        }

        $result = $service->upsertManyRecords(1, 1, $rows, RecordService::IMPORT_MAX_ROWS);

        $this->assertTrue($result['success']);
        $this->assertSame(40, $result['saved_count']);
    }

    public function testImportCapIsStillEnforced(): void
    {
        $service = $this->makeService();

        $rows = [];
        $date = new \DateTimeImmutable('2024-01-01');
        for ($index = 0; $index < 6; $index++) {
            $rows[] = $this->row($date->modify('+' . $index . ' days')->format('Y-m-d'), 100, 10);
        }

        // cap ต่ำกว่าจำนวนแถว → ต้อง fail
        $result = $service->upsertManyRecords(1, 1, $rows, 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('5', $result['error']);
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $service = $this->makeService(false);

        $result = $service->upsertManyRecords(1, 999, [
            $this->row('2024-01-01', 1000, 200),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testInvalidDateFormatReportsRowNumber(): void
    {
        $service = $this->makeService();

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('2024-01-01', 1000, 200),
            $this->row('15/01/2024', 1000, 200), // รูปแบบวันที่ผิด
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', $result['error']);
    }

    public function testNonNumericRevenueReportsRowNumber(): void
    {
        $service = $this->makeService();

        $result = $service->upsertManyRecords(1, 1, [
            $this->row('2024-01-01', 'abc', 200),
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 1', $result['error']);
    }
}
