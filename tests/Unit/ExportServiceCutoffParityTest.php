<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExportService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

/**
 * unit test ของ cutoff ใน sheet รายวัน + invariant "สองแท็บต้องรวมเท่ากัน"
 * today คงที่ = 2026-08-15 (cutoff = สิ้นเดือน ส.ค.)
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

        // จำลอง GROUP BY เดือน จาก fixture ชุดเดียวกัน — สองแท็บจึงมาจากข้อมูลเดียวกันจริง
        $recordService->method('getMonthlyTotals')->willReturnCallback(
            static function (int $u, int $s, string $start, string $end) use ($records): array {
                $byMonth = [];
                foreach ($records as $record) {
                    $monthKey = substr($record[0], 0, 7);
                    if ($monthKey < $start || $monthKey > $end) {
                        continue;
                    }

                    $byMonth[$monthKey] ??= ['month_key' => $monthKey, 'total_revenue' => 0.0, 'total_ad_cost' => 0.0];
                    $byMonth[$monthKey]['total_revenue'] += $record[1];
                    $byMonth[$monthKey]['total_ad_cost'] += $record[2];
                }

                ksort($byMonth);

                return ['success' => true, 'data' => array_values($byMonth)];
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

    public function testFutureDatedRecordsInsideCurrentMonthAreKept(): void
    {
        $service = $this->makeService([
            ['2026-08-02', 4000.0, 1000.0],
            // ลงล่วงหน้าแต่ยังอยู่ในเดือนนี้ (today = 15 ส.ค.) → ต้องเก็บไว้
            ['2026-08-25', 2000.0, 500.0],
            ['2026-08-31', 1000.0, 200.0],
        ]);

        $data = $service->buildYearlyDailyPayload(1, 1, 2026, self::TODAY)['data'];

        $this->assertCount(3, $data['rows']);
        $this->assertSame('2026-08-31', end($data['rows'])['record_date']);
        $this->assertSame(5300.0, $data['totals']['profit']);
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

    /**
     * invariant หลักของ commit นี้ — ไฟล์เดียวกันต้องไม่มีสองยอดรวม
     */
    public function testDailyAndMonthlyTotalsAgreeOnTheSameFixture(): void
    {
        $service = $this->makeService([
            ['2026-01-05', 5000.0, 1000.0],
            ['2026-01-06', 4000.0, 1000.0],
            ['2026-03-10', 20000.0, 19500.0],
            ['2026-07-10', 1000.0, 3500.0],    // เดือนขาดทุน
            ['2026-08-02', 4000.0, 1000.0],
            ['2026-08-25', 2000.0, 500.0],     // ล่วงหน้าในเดือนนี้ — ต้องนับทั้งสองแท็บ
            ['2026-11-01', 90000.0, 0.0],      // อนาคตจริง — ต้องไม่นับทั้งสองแท็บ
        ]);

        $daily = $service->buildYearlyDailyPayload(1, 1, 2026, self::TODAY)['data'];
        $monthly = $service->buildYearlyMonthlyPayload(1, 1, 2026, self::TODAY)['data'];

        $monthlyProfit = array_sum(array_column($monthly['months'], 'profit'));
        $monthlyRevenue = array_sum(array_column($monthly['months'], 'revenue'));
        $monthlyAdCost = array_sum(array_column($monthly['months'], 'ad_cost'));

        $this->assertSame($daily['totals']['profit'], $monthlyProfit);
        $this->assertSame($daily['totals']['revenue'], $monthlyRevenue);
        $this->assertSame($daily['totals']['ad_cost'], $monthlyAdCost);

        // ค่าที่คาดจริง (ไม่ใช่แค่ "เท่ากันแต่ผิดทั้งคู่")
        // ม.ค. 7,000 + มี.ค. 500 + ก.ค. -2,500 + ส.ค. 4,500 = 9,500
        $this->assertSame(36000.0, $daily['totals']['revenue']);
        $this->assertSame(9500.0, $daily['totals']['profit']);
    }

    public function testBothTabsAgreeForAPastYearToo(): void
    {
        $service = $this->makeService([
            ['2025-02-10', 3000.0, 1000.0],
            ['2025-12-20', 5000.0, 2000.0],
        ]);

        $daily = $service->buildYearlyDailyPayload(1, 1, 2025, self::TODAY)['data'];
        $monthly = $service->buildYearlyMonthlyPayload(1, 1, 2025, self::TODAY)['data'];

        $this->assertSame(
            $daily['totals']['profit'],
            array_sum(array_column($monthly['months'], 'profit'))
        );
        $this->assertSame(5000.0, $daily['totals']['profit']);
    }
}
