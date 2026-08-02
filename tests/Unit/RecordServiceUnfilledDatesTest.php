<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::getUnfilledDatesForMonth
 * ส่ง $today คงที่เสมอ เพื่อให้ผลลัพธ์ deterministic (ไม่ผูกกับวันที่รันเทสต์)
 */
final class RecordServiceUnfilledDatesTest extends TestCase
{
    private const TODAY = '2026-08-02';

    /**
     * @param array<int,string> $filledDates วันที่ที่ "กรอกแล้ว"
     */
    private function makeService(array $filledDates, bool $canAccess = true): RecordService
    {
        $rows = array_map(
            static fn(string $date): array => ['record_date' => $date],
            $filledDates
        );

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use ($rows): array {
                // จำลอง SQL BETWEEN — คืนเฉพาะที่อยู่ในช่วง เพื่อพิสูจน์ว่า service ส่งช่วงถูก
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

    public function testPastMonthPartiallyFilledReturnsMissingDates(): void
    {
        // ก.พ. 2026 มี 28 วัน กรอกไว้ 3 วัน
        $service = $this->makeService(['2026-02-01', '2026-02-02', '2026-02-28']);

        $result = $service->getUnfilledDatesForMonth(1, 1, '2026-02', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-02', $result['data']['month']);
        $this->assertSame(25, $result['data']['count']);           // 28 - 3
        $this->assertSame('2026-02-03', $result['data']['missing_dates'][0]);
        $this->assertSame('2026-02-27', end($result['data']['missing_dates']));
        $this->assertNotContains('2026-02-01', $result['data']['missing_dates']);
        $this->assertNotContains('2026-02-28', $result['data']['missing_dates']);
    }

    public function testPastMonthFullyFilledReturnsEmpty(): void
    {
        $filled = [];
        for ($day = 1; $day <= 31; $day++) {
            $filled[] = sprintf('2026-01-%02d', $day);
        }

        $service = $this->makeService($filled);

        $result = $service->getUnfilledDatesForMonth(1, 1, '2026-01', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['missing_dates']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function testCurrentMonthDoesNotIncludeFutureDates(): void
    {
        // today = 2026-08-02 → พิจารณาแค่ 08-01, 08-02 (ไม่นับ 08-03 เป็นต้นไป)
        $service = $this->makeService(['2026-08-01']);

        $result = $service->getUnfilledDatesForMonth(1, 1, '2026-08', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame(['2026-08-02'], $result['data']['missing_dates']);
        $this->assertSame(1, $result['data']['count']);
    }

    public function testCurrentMonthIncludesTodayItself(): void
    {
        $service = $this->makeService([]);

        $result = $service->getUnfilledDatesForMonth(1, 1, '2026-08', '2026-08-01');

        // today = วันที่ 1 → ต้องมีแค่วันเดียวคือวันนี้
        $this->assertSame(['2026-08-01'], $result['data']['missing_dates']);
    }

    public function testFutureMonthReturnsEmpty(): void
    {
        $service = $this->makeService([]);

        $result = $service->getUnfilledDatesForMonth(1, 1, '2026-09', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['missing_dates']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function testLeapYearFebruaryHas29Days(): void
    {
        $service = $this->makeService([]);

        $result = $service->getUnfilledDatesForMonth(1, 1, '2024-02', self::TODAY);

        $this->assertSame(29, $result['data']['count']);
        $this->assertSame('2024-02-29', end($result['data']['missing_dates']));
    }

    public function testInvalidMonthFormatFails(): void
    {
        $service = $this->makeService([]);

        foreach (['2026-13', 'invalid', '2026/08', ''] as $badMonth) {
            $result = $service->getUnfilledDatesForMonth(1, 1, $badMonth, self::TODAY);
            $this->assertFalse($result['success'], 'ต้อง fail สำหรับ: ' . $badMonth);
            $this->assertStringContainsString('YYYY-MM', $result['error']);
        }
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $service = $this->makeService([], false);

        $result = $service->getUnfilledDatesForMonth(1, 999, '2026-08', self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
