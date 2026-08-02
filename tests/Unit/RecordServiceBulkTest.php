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
