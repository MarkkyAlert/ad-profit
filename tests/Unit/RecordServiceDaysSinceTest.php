<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::getDaysSinceLastRecord
 * ส่ง $today คงที่ทุกเคส เพื่อไม่ผูกกับวันที่รันเทสต์
 */
final class RecordServiceDaysSinceTest extends TestCase
{
    private const TODAY = '2026-08-10';

    /**
     * @param array<int,string> $recentDates วันที่ของ record ล่าสุด (เรียง DESC เหมือน repo จริง)
     */
    private function makeService(array $recentDates, bool $canAccess = true): RecordService
    {
        $rows = array_map(
            static fn(string $date): array => ['record_date' => $date],
            $recentDates
        );

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getRecentByShopId')->willReturn($rows);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    public function testReturnsDaysSinceWhenLastRecordIsFiveDaysAgo(): void
    {
        $service = $this->makeService(['2026-08-05']);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['has_records']);
        $this->assertSame(5, $result['data']['days_since']);
        $this->assertSame('2026-08-05', $result['data']['last_record_date']);
    }

    public function testReturnsZeroWhenRecordedToday(): void
    {
        $service = $this->makeService(['2026-08-10']);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertTrue($result['data']['has_records']);
        $this->assertSame(0, $result['data']['days_since']); // 0 ≠ ไม่เคยกรอก
    }

    public function testNoRecordsIsDistinctFromZeroDays(): void
    {
        $service = $this->makeService([]);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['has_records']);
        $this->assertNull($result['data']['days_since']);      // ต้องเป็น null ไม่ใช่ 0
        $this->assertNull($result['data']['last_record_date']);
    }

    public function testExactThresholdBoundaryOfThreeDays(): void
    {
        $service = $this->makeService(['2026-08-07']);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertSame(3, $result['data']['days_since']);
    }

    public function testCountsAcrossMonthBoundary(): void
    {
        $service = $this->makeService(['2026-07-31']);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertSame(10, $result['data']['days_since']);
    }

    public function testCountsAcrossYearBoundary(): void
    {
        $service = $this->makeService(['2025-12-31']);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertSame(222, $result['data']['days_since']);
    }

    public function testFutureDatedRecordIsClampedToZero(): void
    {
        $service = $this->makeService(['2026-08-20']);

        $result = $service->getDaysSinceLastRecord(1, 1, self::TODAY);

        $this->assertTrue($result['data']['has_records']);
        $this->assertSame(0, $result['data']['days_since']); // ไม่ติดลบ
    }

    public function testFailsWhenUserCannotAccessShop(): void
    {
        $service = $this->makeService(['2026-08-05'], false);

        $result = $service->getDaysSinceLastRecord(1, 999, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
