<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExportService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

/**
 * unit test ของ "ชั้นสร้างข้อมูล" สำหรับ xlsx (ไม่แตะ binary — การเขียนไฟล์อยู่ที่ controller)
 */
final class ExportServiceXlsxTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed>|null $shop
     */
    private function makeService(array $records = [], ?array $shop = ['id' => 1, 'name' => 'ร้านคอร์ส']): ExportService
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('getRecordsByDateRange')->willReturn([
            'success' => true,
            'data' => $records,
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn($shop);

        return new ExportService($recordService, $shopRepository);
    }

    /**
     * @param array<int,array{0:string,1:float,2:float,3?:string}> $rows [วันที่, รายได้, ค่าแอด, โน้ต]
     * @return array<int,array<string,mixed>>
     */
    private function records(array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'record_date' => $row[0],
                'revenue' => $row[1],
                'ad_cost' => $row[2],
                'note' => $row[3] ?? null,
            ],
            $rows
        );
    }

    public function testRowsCarryIsoDateAndComputedProfit(): void
    {
        $service = $this->makeService($this->records([
            ['2026-01-05', 5000.0, 1000.0, 'เปิดรอบใหม่'],
            ['2026-01-06', 2000.0, 2500.0],
        ]));

        $data = $service->buildYearlyDailyPayload(1, 1, 2026)['data'];

        $this->assertSame('ร้านคอร์ส', $data['shop_name']);
        $this->assertCount(2, $data['rows']);

        // วันที่ต้องเป็น ISO ดิบ — controller เป็นคนแปลงเป็น Excel date serial
        $this->assertSame('2026-01-05', $data['rows'][0]['record_date']);
        $this->assertSame(4000.0, $data['rows'][0]['profit']);
        $this->assertSame(5.0, $data['rows'][0]['roas']);
        $this->assertSame('เปิดรอบใหม่', $data['rows'][0]['note']);

        // ขาดทุน → กำไรติดลบ
        $this->assertSame(-500.0, $data['rows'][1]['profit']);
    }

    public function testRoasIsNullWhenNoAdCost(): void
    {
        $service = $this->makeService($this->records([['2026-02-01', 3000.0, 0.0]]));

        $rows = $service->buildYearlyDailyPayload(1, 1, 2026)['data']['rows'];

        $this->assertNull($rows[0]['roas']);
    }

    public function testMissingNoteBecomesEmptyString(): void
    {
        $service = $this->makeService($this->records([['2026-02-01', 3000.0, 500.0]]));

        $rows = $service->buildYearlyDailyPayload(1, 1, 2026)['data']['rows'];

        $this->assertSame('', $rows[0]['note']);
    }

    public function testTotalsSumEveryRow(): void
    {
        $service = $this->makeService($this->records([
            ['2026-01-05', 5000.0, 1000.0],
            ['2026-03-10', 3000.0, 1000.0],
            ['2026-07-10', 1000.0, 2000.0],
        ]));

        $totals = $service->buildYearlyDailyPayload(1, 1, 2026)['data']['totals'];

        $this->assertSame(9000.0, $totals['revenue']);
        $this->assertSame(4000.0, $totals['ad_cost']);
        $this->assertSame(5000.0, $totals['profit']);
        $this->assertSame(2.25, $totals['roas']);
    }

    public function testEmptyYearGivesEmptyRowsAndZeroTotals(): void
    {
        $service = $this->makeService();

        $data = $service->buildYearlyDailyPayload(1, 1, 2026)['data'];

        $this->assertSame([], $data['rows']);
        $this->assertSame(0.0, $data['totals']['revenue']);
        $this->assertNull($data['totals']['roas']);
    }

    public function testNoteColumnIndexIsExposedForTheController(): void
    {
        $service = $this->makeService($this->records([['2026-01-05', 100.0, 10.0]]));

        $data = $service->buildYearlyDailyPayload(1, 1, 2026)['data'];

        // controller ใช้เลขนี้เลือกคอลัมน์ที่ต้อง sanitize — ต้องตรงกับลำดับหัวตาราง (โน้ต = คอลัมน์ที่ 6)
        $this->assertSame(6, $data['note_column_index']);
    }

    public function testForeignShopIsRejected(): void
    {
        $service = $this->makeService($this->records([['2026-01-05', 100.0, 10.0]]), null);

        $result = $service->buildYearlyDailyPayload(1, 999, 2026);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testInvalidYearIsRejected(): void
    {
        $service = $this->makeService();

        foreach ([1999, 2101] as $invalidYear) {
            $result = $service->buildYearlyDailyPayload(1, 1, $invalidYear);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('ไม่ถูกต้อง', $result['error']);
        }
    }

    public function testFailureFromRecordServiceIsPropagated(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('getRecordsByDateRange')->willReturn([
            'success' => false,
            'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'name' => 'ร้านคอร์ส']);

        $result = (new ExportService($recordService, $shopRepository))->buildYearlyDailyPayload(1, 1, 2026);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testXlsxFilenameSanitizesShopName(): void
    {
        $service = $this->makeService();

        // ชื่อไฟล์ใช้ พ.ศ. ให้ตรงกับปีในรายงาน
        $this->assertSame('ร้านคอร์ส_2569.xlsx', $service->buildYearlyXlsxFilename('ร้านคอร์ส', 2026));
        $this->assertSame('a_b_2569.xlsx', $service->buildYearlyXlsxFilename('a/b', 2026));
        $this->assertSame('shop_2569.xlsx', $service->buildYearlyXlsxFilename('   ', 2026));
    }

    public function testCsvFilenameStillWorksAfterRefactor(): void
    {
        $service = $this->makeService();

        // แยก sanitize เป็น helper ร่วม — พฤติกรรมของ CSV ต้องไม่เปลี่ยน
        $this->assertSame('ร้านคอร์ส_2026-01.csv', $service->buildMonthlyCsvFilename('ร้านคอร์ส', '2026-01'));
        $this->assertSame('a_b_2026-01.csv', $service->buildMonthlyCsvFilename('a/b', '2026-01'));
        $this->assertSame('shop_2026-01.csv', $service->buildMonthlyCsvFilename('', '2026-01'));
    }
}
