<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::getWeekdayContext
 * ส.ค. 2026: 3, 10, 17, 24, 31 = วันจันทร์ · 4 = อังคาร
 */
final class RecordServiceWeekdayContextTest extends TestCase
{
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
        $recordRepository->method('getRecentByShopId')->willReturnCallback(
            static function (int $shopId, int $limit = 7) use ($rows): array {
                $sorted = $rows;
                usort($sorted, static fn(array $a, array $b): int => strcmp($b['record_date'], $a['record_date']));
                return array_slice($sorted, 0, max(1, $limit));
            }
        );
        // "วันล่าสุด" นับเฉพาะวันที่ผ่านมาแล้ว — เทสต์จึงต้องส่ง $today ที่ทำให้ข้อมูลเป็นอดีต
        $recordRepository->method('findLatestOnOrBeforeDate')->willReturnCallback(
            static function (int $shopId, string $cutoff) use ($rows): ?array {
                $eligible = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['record_date'] <= $cutoff
                ));
                usort($eligible, static fn(array $a, array $b): int => strcmp($b['record_date'], $a['record_date']));
                return $eligible[0] ?? null;
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    public function testAveragesOtherSameWeekdaysOfTheMonth(): void
    {
        $service = $this->makeService([
            ['2026-08-03', 1000.0, 200.0],  // จันทร์
            ['2026-08-10', 2000.0, 400.0],  // จันทร์
            ['2026-08-17', 3000.0, 600.0],  // จันทร์
            ['2026-08-24', 900.0, 300.0],   // จันทร์ = target
            ['2026-08-04', 5000.0, 100.0],  // อังคาร — ต้องไม่ถูกนับ
        ]);

        $result = $service->getWeekdayContext(1, 1, '2026-08-24');

        $this->assertTrue($result['success']);
        $data = $result['data'];

        $this->assertTrue($data['has_data']);
        $this->assertTrue($data['comparable']);
        $this->assertSame(1, $data['weekday']);            // จันทร์
        $this->assertSame(3, $data['sample_count']);       // ตัด target ออกแล้ว
        $this->assertSame(2000.0, $data['avg_revenue']);   // (1000+2000+3000)/3
        $this->assertSame(1600.0, $data['avg_profit']);    // (4800)/3
        $this->assertSame(5.0, $data['avg_roas']);         // ratio of sums = 6000/1200
        $this->assertSame(900.0, $data['target_revenue']);
        $this->assertSame(600.0, $data['target_profit']);
        $this->assertSame(3.0, $data['target_roas']);      // 900/300
    }

    public function testOnlyOccurrenceOfWeekdayIsNotComparable(): void
    {
        $service = $this->makeService([
            ['2026-08-24', 900.0, 300.0],
        ]);

        $result = $service->getWeekdayContext(1, 1, '2026-08-24');

        $data = $result['data'];
        $this->assertTrue($data['has_data']);
        $this->assertFalse($data['comparable']);
        $this->assertSame(0, $data['sample_count']);
        $this->assertNull($data['avg_revenue']);
        $this->assertNull($data['avg_roas']);
    }

    public function testNoRecordsAtAllReturnsHasDataFalse(): void
    {
        $service = $this->makeService([]);

        $result = $service->getWeekdayContext(1, 1, null, '2026-08-31');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['has_data']);
        $this->assertFalse($result['data']['comparable']);
        $this->assertNull($result['data']['target_date']);
    }

    public function testAvgRoasIsNullWhenSampleAdCostTotalIsZero(): void
    {
        $service = $this->makeService([
            ['2026-08-03', 1000.0, 0.0],
            ['2026-08-10', 2000.0, 0.0],
            ['2026-08-24', 900.0, 300.0],
        ]);

        $data = $service->getWeekdayContext(1, 1, '2026-08-24')['data'];

        $this->assertSame(2, $data['sample_count']);
        $this->assertSame(1500.0, $data['avg_revenue']);
        $this->assertNull($data['avg_roas']);              // หารด้วย 0 ไม่ได้
    }

    public function testDefaultsToLatestRecordDate(): void
    {
        $service = $this->makeService([
            ['2026-08-03', 1000.0, 200.0],
            ['2026-08-17', 3000.0, 600.0],   // ล่าสุด
            ['2026-08-10', 2000.0, 400.0],
        ]);

        $data = $service->getWeekdayContext(1, 1, null, '2026-08-31')['data'];

        $this->assertSame('2026-08-17', $data['target_date']);
        $this->assertSame(2, $data['sample_count']);       // 03 + 10
        $this->assertSame(3000.0, $data['target_revenue']);
    }

    public function testExplicitTargetDateUsesThatMonthOnly(): void
    {
        $service = $this->makeService([
            ['2026-08-24', 900.0, 300.0],
            ['2026-09-07', 9999.0, 1.0],   // จันทร์แต่คนละเดือน — ต้องไม่ถูกนับ
        ]);

        $data = $service->getWeekdayContext(1, 1, '2026-08-24')['data'];

        $this->assertSame(0, $data['sample_count']);
        $this->assertFalse($data['comparable']);
    }

    public function testInvalidTargetDateFails(): void
    {
        $service = $this->makeService([['2026-08-24', 900.0, 300.0]]);

        $result = $service->getWeekdayContext(1, 1, '24/08/2026');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('วันที่', $result['error']);
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $service = $this->makeService([['2026-08-24', 900.0, 300.0]], false);

        $result = $service->getWeekdayContext(1, 999, '2026-08-24');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
