<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::buildEditableMonthGrid
 * ส่ง $today คงที่ทุกเคสให้ deterministic
 */
final class RecordServiceMonthGridTest extends TestCase
{
    private const TODAY = '2026-08-10';

    /**
     * @param array<int,array{0:string,1:float,2:float,3:string}> $records [date, revenue, ad_cost, note]
     */
    private function makeService(array $records, bool $canAccess = true): RecordService
    {
        $rows = array_map(
            static fn(array $row): array => [
                'record_date' => $row[0],
                'revenue' => $row[1],
                'ad_cost' => $row[2],
                'note' => $row[3],
            ],
            $records
        );

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use ($rows): array {
                return array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['record_date'] >= $start && $row['record_date'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    public function testPastMonthReturnsEveryDayWithExistingValues(): void
    {
        $service = $this->makeService([
            ['2026-06-01', 1000.0, 200.0, 'คอร์ส A'],
            ['2026-06-15', 2000.0, 0.0, ''],
        ]);

        $result = $service->buildEditableMonthGrid(1, 1, '2026-06', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-06', $result['data']['month']);

        $days = $result['data']['days'];
        $this->assertCount(30, $days);                       // มิ.ย. 30 วัน
        $this->assertSame('2026-06-01', $days[0]['date']);
        $this->assertSame('2026-06-30', $days[29]['date']);

        // วันที่มี record → เติมค่าเดิม
        $this->assertTrue($days[0]['has_record']);
        $this->assertSame(1000.0, $days[0]['revenue']);
        $this->assertSame(200.0, $days[0]['ad_cost']);
        $this->assertSame('คอร์ส A', $days[0]['note']);

        $this->assertTrue($days[14]['has_record']);          // 2026-06-15
        $this->assertSame(2000.0, $days[14]['revenue']);
        $this->assertSame(0.0, $days[14]['ad_cost']);

        // วันที่ยังไม่มี record → ค่าว่าง
        $this->assertFalse($days[1]['has_record']);
        $this->assertNull($days[1]['revenue']);
        $this->assertNull($days[1]['ad_cost']);
        $this->assertSame('', $days[1]['note']);
    }

    public function testCurrentMonthStopsAtToday(): void
    {
        $result = $this->makeService([])->buildEditableMonthGrid(1, 1, '2026-08', self::TODAY);

        $days = $result['data']['days'];
        $this->assertCount(10, $days);                       // 1..10 ส.ค.
        $this->assertSame('2026-08-01', $days[0]['date']);
        $this->assertSame(self::TODAY, $days[9]['date']);
    }

    public function testFutureMonthReturnsNoDays(): void
    {
        $result = $this->makeService([])->buildEditableMonthGrid(1, 1, '2026-09', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['days']);
    }

    public function testLeapFebruaryHas29Days(): void
    {
        $result = $this->makeService([])->buildEditableMonthGrid(1, 1, '2024-02', self::TODAY);

        $days = $result['data']['days'];
        $this->assertCount(29, $days);
        $this->assertSame('2024-02-29', $days[28]['date']);
    }

    public function testNonLeapFebruaryHas28Days(): void
    {
        $result = $this->makeService([])->buildEditableMonthGrid(1, 1, '2026-02', self::TODAY);

        $this->assertCount(28, $result['data']['days']);
    }

    public function testEmptyMonthMarksEveryDayAsNoRecord(): void
    {
        $result = $this->makeService([])->buildEditableMonthGrid(1, 1, '2026-07', self::TODAY);

        $days = $result['data']['days'];
        $this->assertCount(31, $days);
        foreach ($days as $day) {
            $this->assertFalse($day['has_record']);
            $this->assertNull($day['revenue']);
        }
    }

    public function testInvalidMonthFails(): void
    {
        $service = $this->makeService([]);

        foreach (['2026-13', 'invalid', '2026/08', ''] as $badMonth) {
            $result = $service->buildEditableMonthGrid(1, 1, $badMonth, self::TODAY);
            $this->assertFalse($result['success'], 'ต้อง fail สำหรับ: ' . $badMonth);
            $this->assertStringContainsString('YYYY-MM', $result['error']);
        }
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $result = $this->makeService([], false)->buildEditableMonthGrid(1, 999, '2026-06', self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
