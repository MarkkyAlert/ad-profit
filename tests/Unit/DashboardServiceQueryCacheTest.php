<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * โหลดแดชบอร์ด "เดือนนี้" ครั้งเดียวยิง query ช่วงเดียวกัน 3 รอบ
 *
 * สรุปช่วงที่เลือก · ฐานเทียบเดือนก่อน (ฝั่งเดือนปัจจุบัน) · ความคืบหน้าเป้า
 * ทั้งสามอ่านข้อมูลชุดเดียวกันเป๊ะ ๆ — memo ต่อการเรียก 1 ครั้งพอ
 */
final class DashboardServiceQueryCacheTest extends TestCase
{
    /** ⭐ ช่วงวันเดียวกันต้องไม่ถูกยิงซ้ำ ไม่ว่าจะมีกี่ส่วนของแดชบอร์ดที่ต้องใช้ */
    public function testIdenticalDateRangeIsQueriedOnlyOnce(): void
    {
        $calls = [];

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use (&$calls): array {
                $calls[] = "{$shopId}|{$start}|{$end}";

                return [['record_date' => $start, 'revenue' => 1000.0, 'ad_cost' => 200.0]];
            }
        );
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('findByShopAndMonth')->willReturn([
            'target_revenue' => 10000.0,
            'target_profit' => null,
        ]);

        $result = (new DashboardService($recordRepository, $shopRepository, $goalRepository))
            ->buildDashboard(1, 1, 'month_pick', null, null, '2026-08', '2026-08-20');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));

        // ช่วงที่ต่างกันจริงยิงได้ตามจำนวน แต่ช่วงซ้ำต้องไม่ยิงซ้ำ
        // (สรุปทั้งเดือน 08-01..08-31 ถูกใช้ทั้งการ์ดสรุปและความคืบหน้าเป้า)
        $this->assertSame(
            array_values(array_unique($calls)),
            $calls,
            'ยิง query ช่วงเดิมซ้ำ: ' . implode(' · ', $calls)
        );
    }

    /** cache ต้องไม่ข้ามร้าน — คนละ shopId คือคนละชุดข้อมูล */
    public function testDifferentShopsDoNotShareCachedRows(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end): array => [
                ['record_date' => $start, 'revenue' => $shopId * 1000.0, 'ad_cost' => 0.0],
            ]
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new DashboardService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));

        $shopOne = $service->getSummary(1, 1, '2026-08-01', '2026-08-31');
        $shopTwo = $service->getSummary(1, 2, '2026-08-01', '2026-08-31');

        $this->assertSame(1000.0, $shopOne['data']['total_revenue']);
        $this->assertSame(2000.0, $shopTwo['data']['total_revenue'], 'ร้านที่ 2 ได้ข้อมูลของร้านที่ 1');
    }
}
