<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewDailyService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ avg_profit_per_day + วันกำไรดี/แย่สุด + วันกรอกไม่ครบ (มุมวันรวมร้าน)
 */
final class OverviewDailyServiceAnalysisTest extends TestCase
{
    private const MONTH = '2026-06';

    /**
     * @param array<int,array<string,mixed>> $dailyTotals
     * @param array<int,array<string,mixed>> $shops
     */
    private function makeService(array $dailyTotals = [], ?array $shops = null): OverviewDailyService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getDailyTotalsByShopIdsAndDateRange')->willReturn($dailyTotals);
        $recordRepository->method('getTotalsByShopIdsAndDateRange')->willReturn([]);

        // ทุกร้านเริ่มติดตามตั้งแต่วันแรกของชุดข้อมูล — เคสที่ร้านเริ่มไม่พร้อมกัน
        // อยู่ใน tests/Integration/OverviewDailyServiceAnalysisTest.php ซึ่งใช้ DB จริง
        $firstDate = $dailyTotals === [] ? '0000-00-00' : (string)$dailyTotals[0]['record_date'];
        $recordRepository->method('getFirstRecordDateByShopIds')->willReturnCallback(
            static function (array $shopIds) use ($firstDate): array {
                $map = [];
                foreach ($shopIds as $shopId) {
                    $map[(int)$shopId] = $firstDate;
                }

                return $map;
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops ?? [
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        return new OverviewDailyService($recordRepository, $shopRepository);
    }

    /**
     * @param array<int,array{0:string,1:float,2:float,3?:int}> $rows [วันที่, รายได้, ค่าแอด, ร้านที่กรอก]
     * @return array<int,array<string,mixed>>
     */
    private function dailyTotals(array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'record_date' => $row[0],
                'total_revenue' => $row[1],
                'total_ad_cost' => $row[2],
                'shops_count' => $row[3] ?? 2,
            ],
            $rows
        );
    }

    public function testAverageProfitPerDayUsesProfitNotRevenue(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 5000.0, 1000.0],   // +4000
            ['2026-06-02', 3000.0, 1000.0],   // +2000
        ]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame(6000.0, $summary['profit']);
        $this->assertSame(3000.0, $summary['avg_profit_per_day']);    // 6000 / 2
        // ของเดิมต้องยังอยู่ (ไม่ลบ)
        $this->assertSame(4000.0, $summary['avg_revenue_per_day']);   // 8000 / 2
    }

    public function testAverageProfitIsNullWithoutDays(): void
    {
        $service = $this->makeService();

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame(0, $summary['days_count']);
        $this->assertNull($summary['avg_profit_per_day']);
        $this->assertNull($summary['avg_revenue_per_day']);
    }

    public function testAverageProfitCanBeNegative(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 1000.0, 3000.0],   // -2000
            ['2026-06-02', 1000.0, 2000.0],   // -1000
        ]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame(-1500.0, $summary['avg_profit_per_day']);
    }

    public function testBestDayRanksByProfitNotRevenue(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 20000.0, 19500.0],   // รายได้สูงสุด แต่กำไรแค่ 500
            ['2026-06-02', 4000.0, 1000.0],     // กำไรสูงสุด 3000 ← best
        ]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame('2026-06-02', $summary['best_day']['record_date']);
        $this->assertSame(3000.0, $summary['best_day']['profit']);
        $this->assertSame('2026-06-01', $summary['worst_day']['record_date']);
        $this->assertSame(500.0, $summary['worst_day']['profit']);
    }

    public function testWorstDayPicksRealLoss(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 3000.0, 1000.0],   // +2000
            ['2026-06-05', 500.0, 2500.0],    // -2000 ← ขาดทุนจริง
            ['2026-06-09', 2000.0, 1000.0],   // +1000
        ]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame('2026-06-05', $summary['worst_day']['record_date']);
        $this->assertSame(-2000.0, $summary['worst_day']['profit']);
        $this->assertSame('2026-06-01', $summary['best_day']['record_date']);
    }

    public function testBestAndWorstAreNullWithoutDays(): void
    {
        $service = $this->makeService();

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertNull($summary['best_day']);
        $this->assertNull($summary['worst_day']);
    }

    public function testSingleDayIsBothBestAndWorst(): void
    {
        $service = $this->makeService($this->dailyTotals([['2026-06-03', 3000.0, 1000.0]]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame('2026-06-03', $summary['best_day']['record_date']);
        $this->assertSame('2026-06-03', $summary['worst_day']['record_date']);
    }

    public function testCompleteFlagComparesAgainstShopCount(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 3000.0, 1000.0, 2],   // ครบ 2 ร้าน
            ['2026-06-02', 1000.0, 500.0, 1],    // กรอกร้านเดียว
        ]));

        $data = $service->buildDailyOverview(1, self::MONTH)['data'];

        $this->assertTrue($data['days'][0]['is_complete']);
        $this->assertFalse($data['days'][1]['is_complete']);
        $this->assertSame(1, $data['summary']['incomplete_days']);
        $this->assertSame(2, $data['summary']['total_shops']);
    }

    public function testAllCompleteDaysGiveZeroIncomplete(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 3000.0, 1000.0, 2],
            ['2026-06-02', 2000.0, 1000.0, 2],
        ]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame(0, $summary['incomplete_days']);
    }

    public function testIncompleteCountWithThreeShops(): void
    {
        $service = $this->makeService(
            $this->dailyTotals([
                ['2026-06-01', 3000.0, 1000.0, 3],   // ครบ
                ['2026-06-02', 2000.0, 1000.0, 2],   // ขาด 1
                ['2026-06-03', 1000.0, 500.0, 1],    // ขาด 2
            ]),
            [
                ['id' => 1, 'name' => 'ร้าน A'],
                ['id' => 2, 'name' => 'ร้าน B'],
                ['id' => 3, 'name' => 'ร้าน C'],
            ]
        );

        $data = $service->buildDailyOverview(1, self::MONTH)['data'];

        $this->assertSame(3, $data['summary']['total_shops']);
        $this->assertSame(2, $data['summary']['incomplete_days']);
        $this->assertTrue($data['days'][0]['is_complete']);
        $this->assertFalse($data['days'][2]['is_complete']);
    }

    public function testCanViewGuardStillBlocksSingleShop(): void
    {
        $service = $this->makeService(
            $this->dailyTotals([['2026-06-01', 3000.0, 1000.0, 1]]),
            [['id' => 1, 'name' => 'ร้านเดียว']]
        );

        $data = $service->buildDailyOverview(1, self::MONTH)['data'];

        // guard เดิมต้องมาก่อน — ไม่มี summary/days ให้เลย
        $this->assertFalse($data['can_view']);
        $this->assertArrayNotHasKey('summary', $data);
        $this->assertArrayNotHasKey('days', $data);
    }

    /**
     * ⭐ วันที่บางร้านยังไม่กรอกต้องไม่ชนะ "วันแย่สุด"
     *
     * มี 3 ร้าน · 08-01 กรอกครบได้กำไร 300 · 08-02 กรอกแค่ 1 ร้านได้ 50
     * ยอด 50 ต่ำเพราะยังกรอกไม่ครบ ไม่ใช่เพราะผลงานแย่ — เดิมมันชนะ worst_day
     */
    public function testIncompleteDayDoesNotWinWorstDay(): void
    {
        $shops = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B'], ['id' => 3, 'name' => 'C']];
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 600.0, 300.0, 3],   // ครบ 3 ร้าน → กำไร 300
            ['2026-06-02', 100.0, 50.0, 1],    // กรอกแค่ 1 ร้าน → กำไร 50
        ]), $shops);

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame('2026-06-01', $summary['worst_day']['record_date']);
        $this->assertSame('2026-06-01', $summary['best_day']['record_date']);
        $this->assertSame(1, $summary['incomplete_days']);
    }

    /** ค่าเฉลี่ยต่อวันต้องไม่ถูกวันที่กรอกไม่ครบเจือจาง */
    public function testAveragePerDayUsesCompleteDaysOnly(): void
    {
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 400.0, 200.0, 2],   // ครบ 2 ร้าน → กำไร 200
            ['2026-06-02', 100.0, 50.0, 1],    // ไม่ครบ
        ]));

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertSame(1, $summary['complete_days_count']);
        $this->assertSame(200.0, $summary['avg_profit_per_day']);
    }

    /** ไม่มีวันไหนกรอกครบเลย → ไม่มีวันดี/แย่ให้แสดง แทนที่จะโชว์วันที่ข้อมูลไม่ครบ */
    public function testNoCompleteDaysLeavesRankingEmpty(): void
    {
        $shops = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B'], ['id' => 3, 'name' => 'C']];
        $service = $this->makeService($this->dailyTotals([
            ['2026-06-01', 100.0, 50.0, 1],
        ]), $shops);

        $summary = $service->buildDailyOverview(1, self::MONTH)['data']['summary'];

        $this->assertNull($summary['best_day']);
        $this->assertNull($summary['worst_day']);
        $this->assertNull($summary['avg_profit_per_day']);
        $this->assertSame(1, $summary['incomplete_days']);
    }
}
