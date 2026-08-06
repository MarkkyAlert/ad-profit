<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExportService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

/**
 * unit test ของ cutoff ใน sheet รายวัน (ปีปัจจุบันตัดที่วันนี้)
 * ส่วน parity ระหว่างแท็บอยู่ที่ Tests\Integration\XlsxAnnualParityTest (DB จริง)
 * today คงที่ = 2026-08-15
 */
final class ExportServiceCutoffParityTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array{0:string,1:float,2:float}> $records [วันที่, รายได้, ค่าแอด]
     */
    private function makeService(array $records): ExportService
    {
        $recordService = $this->createStub(RecordService::class);

        // จำลอง BETWEEN ของ getByDateRange — คืนเฉพาะวันที่อยู่ในช่วงที่ service ขอ
        $recordService->method('getRecordsByDateRange')->willReturnCallback(
            static function (int $u, int $s, string $start, string $end) use ($records): array {
                $rows = [];
                foreach ($records as $record) {
                    if ($record[0] >= $start && $record[0] <= $end) {
                        $rows[] = [
                            'record_date' => $record[0],
                            'revenue' => $record[1],
                            'ad_cost' => $record[2],
                            'note' => null,
                        ];
                    }
                }

                return ['success' => true, 'data' => $rows];
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'name' => 'ร้านคอร์ส']);

        return new ExportService($recordService, $shopRepository);
    }

    public function testNextMonthRecordsAreCutOff(): void
    {
        $service = $this->makeService([
            ['2026-01-05', 5000.0, 1000.0],
            ['2026-08-02', 4000.0, 1000.0],
            // ก.ย. เป็นต้นไป = อนาคตจริง ต้องไม่โผล่
            ['2026-09-01', 90000.0, 0.0],
            ['2026-12-31', 90000.0, 0.0],
        ]);

        $data = $service->buildYearlyDailyPayload(1, 1, 2026, self::TODAY)['data'];

        $dates = array_column($data['rows'], 'record_date');
        $this->assertSame(['2026-01-05', '2026-08-02'], $dates);
        $this->assertSame(9000.0, $data['totals']['revenue']);
    }

    public function testFutureDatedRecordsInsideCurrentMonthAreCutOff(): void
    {
        $service = $this->makeService([
            ['2026-08-02', 4000.0, 1000.0],
            // อยู่ในเดือนนี้แต่ยังไม่ถึง (today = 15 ส.ค.) → ต้องไม่ export
            ['2026-08-25', 2000.0, 500.0],
            ['2026-08-31', 1000.0, 200.0],
        ]);

        $data = $service->buildYearlyDailyPayload(1, 1, 2026, self::TODAY)['data'];

        $this->assertCount(1, $data['rows']);
        $this->assertSame('2026-08-02', end($data['rows'])['record_date']);
        $this->assertSame(3000.0, $data['totals']['profit']);
    }

    public function testPastYearStillCoversTheWholeYear(): void
    {
        $service = $this->makeService([
            ['2025-01-05', 1000.0, 100.0],
            ['2025-12-31', 2000.0, 200.0],
        ]);

        $data = $service->buildYearlyDailyPayload(1, 1, 2025, self::TODAY)['data'];

        $this->assertCount(2, $data['rows']);
        $this->assertSame('2025-12-31', end($data['rows'])['record_date']);
    }

    public function testFutureYearHasNoRows(): void
    {
        $service = $this->makeService([['2027-01-05', 5000.0, 1000.0]]);

        $data = $service->buildYearlyDailyPayload(1, 1, 2027, self::TODAY)['data'];

        $this->assertSame([], $data['rows']);
        $this->assertSame(0.0, $data['totals']['profit']);
        // ยังต้องคืนชื่อร้าน/โครง payload ให้ writer ใช้ได้
        $this->assertSame('ร้านคอร์ส', $data['shop_name']);
        $this->assertSame(6, $data['note_column_index']);
    }
}
