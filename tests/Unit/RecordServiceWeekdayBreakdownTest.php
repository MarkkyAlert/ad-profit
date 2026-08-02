<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::getWeekdayBreakdown
 * today = 2026-08-02 (อาทิตย์) คงที่ทุกเคส
 */
final class RecordServiceWeekdayBreakdownTest extends TestCase
{
    private const TODAY = '2026-08-02';

    /**
     * @param array<int,array{0:string,1:float,2:float}> $records [date, revenue, ad_cost]
     */
    private function makeService(array $records, bool $canAccess = true): RecordService
    {
        $rows = array_map(
            static fn(array $row): array => [
                'record_date' => $row[0],
                'revenue' => $row[1],
                'ad_cost' => $row[2],
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

    /**
     * @param array<int,array<string,mixed>> $weekdays
     * @return array<string,mixed>
     */
    private function pick(array $weekdays, int $weekday): array
    {
        foreach ($weekdays as $row) {
            if ((int)$row['weekday'] === $weekday) {
                return $row;
            }
        }

        self::fail('ไม่พบ weekday ' . $weekday);
    }

    public function testAggregatesPerWeekdayInOrder(): void
    {
        // จันทร์ 8 ครั้ง (8 มิ.ย. → 27 ก.ค.) + อาทิตย์ 2 ครั้ง
        $records = [];
        for ($index = 0; $index < 8; $index++) {
            $date = (new \DateTimeImmutable('2026-06-08'))->modify('+' . ($index * 7) . ' days')->format('Y-m-d');
            $records[] = [$date, 1000.0 + $index * 100, 200.0];
        }
        $records[] = ['2026-08-02', 500.0, 100.0];
        $records[] = ['2026-07-26', 300.0, 100.0];

        $result = $this->makeService($records)->getWeekdayBreakdown(1, 1, 8, self::TODAY);

        $this->assertTrue($result['success']);
        $data = $result['data'];

        $this->assertTrue($data['has_data']);
        $this->assertSame(8, $data['weeks']);
        $this->assertCount(7, $data['weekdays']);
        // เรียง จันทร์(1) → อาทิตย์(7)
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($data['weekdays'], 'weekday'));

        $monday = $this->pick($data['weekdays'], 1);
        $this->assertSame(8, $monday['sample_count']);
        // รายได้รวม 10,800 · ค่าแอดรวม 1,600 → กำไรเฉลี่ย (10800-1600)/8
        $this->assertSame(1150.0, $monday['avg_profit']);
        $this->assertSame(1350.0, $monday['avg_revenue']);
        $this->assertSame(6.75, $monday['avg_roas']);   // ratio of sums = 10800/1600

        $sunday = $this->pick($data['weekdays'], 7);
        $this->assertSame(2, $sunday['sample_count']);
        $this->assertSame(300.0, $sunday['avg_profit']); // ((500-100)+(300-100))/2
    }

    public function testWeekdayWithoutRecordsHasNullAverages(): void
    {
        $result = $this->makeService([['2026-07-27', 1000.0, 200.0]])  // จันทร์
            ->getWeekdayBreakdown(1, 1, 8, self::TODAY);

        $tuesday = $this->pick($result['data']['weekdays'], 2);

        $this->assertSame(0, $tuesday['sample_count']);
        $this->assertNull($tuesday['avg_profit']);
        $this->assertNull($tuesday['avg_revenue']);
        $this->assertNull($tuesday['avg_roas']);
    }

    public function testAvgRoasIsNullWhenAdCostTotalIsZero(): void
    {
        $result = $this->makeService([
            ['2026-07-27', 1000.0, 0.0],   // จันทร์
            ['2026-07-20', 2000.0, 0.0],   // จันทร์
        ])->getWeekdayBreakdown(1, 1, 8, self::TODAY);

        $monday = $this->pick($result['data']['weekdays'], 1);

        $this->assertSame(2, $monday['sample_count']);
        $this->assertSame(1500.0, $monday['avg_profit']);
        $this->assertNull($monday['avg_roas']);
    }

    public function testNegativeAverageProfitIsNotClamped(): void
    {
        $result = $this->makeService([
            ['2026-08-01', 100.0, 900.0],  // เสาร์ → -800
            ['2026-07-25', 100.0, 700.0],  // เสาร์ → -600
        ])->getWeekdayBreakdown(1, 1, 8, self::TODAY);

        $saturday = $this->pick($result['data']['weekdays'], 6);

        $this->assertSame(2, $saturday['sample_count']);
        $this->assertSame(-700.0, $saturday['avg_profit']);
    }

    public function testWindowSizeFollowsWeeksParameter(): void
    {
        $service = $this->makeService([]);

        $eightWeeks = $service->getWeekdayBreakdown(1, 1, 8, self::TODAY)['data'];
        $fourWeeks = $service->getWeekdayBreakdown(1, 1, 4, self::TODAY)['data'];

        $this->assertSame('2026-08-02', $eightWeeks['end_date']);
        $this->assertSame('2026-06-08', $eightWeeks['start_date']);   // 56 วันรวมวันนี้
        $this->assertSame(8, $eightWeeks['weeks']);

        $this->assertSame('2026-07-06', $fourWeeks['start_date']);    // 28 วันรวมวันนี้
        $this->assertSame(4, $fourWeeks['weeks']);
    }

    public function testRecordsOutsideWindowAreExcluded(): void
    {
        $result = $this->makeService([
            ['2026-06-01', 9999.0, 1.0],   // ก่อน window (start = 2026-06-08)
        ])->getWeekdayBreakdown(1, 1, 8, self::TODAY);

        $this->assertFalse($result['data']['has_data']);
    }

    public function testNoRecordsReturnsHasDataFalseWithSevenRows(): void
    {
        $result = $this->makeService([])->getWeekdayBreakdown(1, 1, 8, self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['has_data']);
        $this->assertCount(7, $result['data']['weekdays']);
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $result = $this->makeService([], false)->getWeekdayBreakdown(1, 999, 8, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
