<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::getMonthlyRecords (ข้อมูลของหน้า history + CSV export)
 *
 * เมธอดนี้ไม่เคยมีเทสต์ครอบมาก่อน ทั้งที่ตัวเลขจากมันโผล่ทั้งหน้าเว็บและไฟล์ export
 */
final class RecordServiceMonthlyRecordsTest extends TestCase
{
    /**
     * @param array<int,array{0:string,1:float,2:float}> $rows [วันที่, รายได้, ค่าแอด]
     */
    private function makeService(array $rows, bool $canAccess = true): RecordService
    {
        $records = array_map(
            static fn(array $row): array => [
                'id' => 1,
                'shop_id' => 1,
                'record_date' => $row[0],
                'revenue' => $row[1],
                'ad_cost' => $row[2],
                'note' => '',
            ],
            $rows
        );

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn($records);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    /**
     * ROAS รวมต้องเป็น ratio of sums (รวมรายได้ ÷ รวมค่าแอด) ให้ตรงกับทุกที่ในระบบ
     *
     * fixture นี้ทำให้สองสูตรต่างกันชัด:
     *   ratio of sums      = 6000 / 2000 = 3.0   ← ที่ถูก
     *   เฉลี่ยของ ROAS รายวัน = (3.0 + 2.0) / 2 = 2.5
     */
    public function testAvgRoasIsRatioOfSumsNotAverageOfDailyRoas(): void
    {
        $service = $this->makeService([
            ['2026-08-01', 3000.0, 1000.0],
            ['2026-08-02', 1000.0, 0.0],
            ['2026-08-03', 2000.0, 1000.0],
        ]);

        $totals = $service->getMonthlyRecords(1, 1, '2026-08')['data']['totals'];

        $this->assertSame(3.0, $totals['avg_roas']);
    }

    /** วันที่ยิงแอดฟรี (ad_cost = 0) ต้องนับรายได้เข้าตัวตั้ง ไม่ใช่ถูกตัดออกทั้งวัน */
    public function testRevenueFromZeroAdCostDaysStillCountsTowardAvgRoas(): void
    {
        $withFreeDay = $this->makeService([
            ['2026-08-01', 2000.0, 1000.0],
            ['2026-08-02', 8000.0, 0.0],
        ]);

        // 10000 / 1000 = 10.0 — ถ้าตัดวันที่ ad_cost = 0 ทิ้งจะได้ 2.0
        $this->assertSame(10.0, $withFreeDay->getMonthlyRecords(1, 1, '2026-08')['data']['totals']['avg_roas']);
    }

    public function testAvgRoasIsNullWhenNoAdSpendAtAll(): void
    {
        $service = $this->makeService([
            ['2026-08-01', 3000.0, 0.0],
            ['2026-08-02', 1000.0, 0.0],
        ]);

        $this->assertNull($service->getMonthlyRecords(1, 1, '2026-08')['data']['totals']['avg_roas']);
    }

    public function testTotalsSumEveryRow(): void
    {
        $service = $this->makeService([
            ['2026-08-01', 3000.0, 1000.0],
            ['2026-08-02', 1000.0, 0.0],
            ['2026-08-03', 2000.0, 1000.0],
        ]);

        $totals = $service->getMonthlyRecords(1, 1, '2026-08')['data']['totals'];

        $this->assertSame(6000.0, $totals['total_revenue']);
        $this->assertSame(2000.0, $totals['total_ad_cost']);
        $this->assertSame(4000.0, $totals['total_profit']);
    }

    /** ROAS ของ "แต่ละวัน" ยังเป็น null เมื่อไม่ได้ยิงแอด — ไม่เปลี่ยนพร้อมกับยอดรวม */
    public function testDailyRoasIsStillNullWhenDayHasNoAdCost(): void
    {
        $service = $this->makeService([
            ['2026-08-01', 3000.0, 1000.0],
            ['2026-08-02', 1000.0, 0.0],
        ]);

        $records = $service->getMonthlyRecords(1, 1, '2026-08')['data']['records'];

        $this->assertSame(3.0, $records[0]['roas']);
        $this->assertNull($records[1]['roas']);
    }

    /**
     * คอลัมน์เทียบ = เทียบกับ "รายการก่อนหน้าใน list" ไม่ใช่วันก่อนหน้าตามปฏิทิน
     *
     * fixture เว้นวันที่ 2 ไว้ตั้งใจ: แถว 2026-08-03 เทียบกับ 2026-08-01
     * (ป้ายในหน้าเว็บ/CSV จึงเขียนว่า "เทียบครั้งก่อน" ไม่ใช่ "เทียบเมื่อวาน")
     */
    public function testComparePercentUsesPreviousRecordInList(): void
    {
        $service = $this->makeService([
            ['2026-08-01', 1000.0, 100.0],
            ['2026-08-03', 2000.0, 100.0],
        ]);

        $records = $service->getMonthlyRecords(1, 1, '2026-08')['data']['records'];

        $this->assertNull($records[0]['compare_revenue_percent']);
        $this->assertSame(100.0, $records[1]['compare_revenue_percent']);
    }

    public function testEmptyMonthGivesZeroTotalsAndNullRoas(): void
    {
        $service = $this->makeService([]);

        $data = $service->getMonthlyRecords(1, 1, '2026-08')['data'];

        $this->assertSame([], $data['records']);
        $this->assertSame(0.0, $data['totals']['total_revenue']);
        $this->assertNull($data['totals']['avg_roas']);
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $result = $this->makeService([], false)->getMonthlyRecords(1, 999, '2026-08');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testRejectsMalformedMonth(): void
    {
        $result = $this->makeService([])->getMonthlyRecords(1, 1, '2026-8');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('YYYY-MM', $result['error']);
    }
}
