<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของประมาณการสิ้นปี (run-rate)
 * method เป็น pure — เรียกตรงได้ ไม่ต้องผ่าน repo
 */
final class AnnualServiceProjectionTest extends TestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): AnnualService
    {
        return new AnnualService(
            $this->createStub(RecordRepository::class),
            $this->createStub(ShopRepository::class),
            $this->createStub(GoalRepository::class)
        );
    }

    /**
     * @param array<int,array{0:int,1:float,2:int}> $rows [เดือน, กำไร, จำนวนวันที่กรอก]
     * @return array<int,array<string,mixed>>
     */
    private function monthRows(array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'month' => $row[0],
                'profit' => $row[1],
                'days_count' => $row[2],
            ],
            $rows
        );
    }

    public function testProjectionSpansMinToMaxOfRecentMonths(): void
    {
        // 3 เดือนล่าสุด 1000 / 2000 / 3000 → avg 2000 · เหลือ 4 เดือน (lastMonth = 8)
        $months = $this->monthRows([[6, 1000.0, 5], [7, 2000.0, 5], [8, 3000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true);

        $this->assertTrue($projection['available']);
        $this->assertSame(4, $projection['months_remaining']);
        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);

        $this->assertSame(6000.0 + 4 * 1000.0, $projection['projection_low']);
        $this->assertSame(6000.0 + 4 * 2000.0, $projection['projection_mid']);
        $this->assertSame(6000.0 + 4 * 3000.0, $projection['projection_high']);
    }

    public function testRangeIsOrderedLowToHigh(): void
    {
        $months = $this->monthRows([[5, -500.0, 3], [6, 4000.0, 3], [7, 1200.0, 3], [8, 800.0, 3]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 5500.0, 8, true);

        $this->assertLessThanOrEqual($projection['projection_mid'], $projection['projection_low']);
        $this->assertLessThanOrEqual($projection['projection_high'], $projection['projection_mid']);
    }

    public function testIdenticalMonthsCollapseRangeToAPoint(): void
    {
        $months = $this->monthRows([[6, 2000.0, 5], [7, 2000.0, 5], [8, 2000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true);

        // min == max → ช่วงยุบเป็นจุด (ปกติ ไม่ใช่บั๊ก)
        $this->assertSame($projection['projection_low'], $projection['projection_high']);
        $this->assertSame($projection['projection_mid'], $projection['projection_low']);
    }

    public function testOnlyTheThreeMostRecentFilledMonthsAreUsed(): void
    {
        // 5 เดือน — 2 เดือนแรกกำไรมหาศาล ต้องไม่ถูกใช้เป็นฐาน
        $months = $this->monthRows([
            [4, 99000.0, 5],
            [5, 99000.0, 5],
            [6, 1000.0, 5],
            [7, 2000.0, 5],
            [8, 3000.0, 5],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 204000.0, 8, true);

        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);
        $this->assertSame(204000.0 + 4 * 3000.0, $projection['projection_high']);
    }

    public function testUnfilledMonthsAreNotUsedAsBasis(): void
    {
        // เดือน 7 มี profit ติดมาแต่ days_count = 0 (ยังไม่ได้กรอก) → ห้ามใช้เป็นฐาน
        $months = $this->monthRows([[6, 1000.0, 5], [7, 0.0, 0], [8, 3000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 4000.0, 8, true);

        $this->assertSame(2, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);     // (1000 + 3000) / 2
        $this->assertSame(4000.0 + 4 * 1000.0, $projection['projection_low']);
    }

    public function testMonthsBeyondCutoffAreIgnored(): void
    {
        $months = $this->monthRows([[7, 1000.0, 5], [8, 3000.0, 5], [11, 90000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 4000.0, 8, true);

        // พ.ย. เกิน lastMonth — ต้องไม่ถูกนับเป็นฐาน
        $this->assertSame(2, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);
    }

    public function testRecentLossesProjectBelowCurrentProfit(): void
    {
        $months = $this->monthRows([[6, -1000.0, 5], [7, -2000.0, 5], [8, -3000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 1000.0, 8, true);

        // ขาดทุนต่อเนื่อง → ประมาณการต้องต่ำกว่ากำไรสะสมปัจจุบัน และติดลบได้
        $this->assertLessThan(1000.0, $projection['projection_mid']);
        $this->assertSame(1000.0 + 4 * -3000.0, $projection['projection_low']);
        $this->assertLessThan(0.0, $projection['projection_low']);
    }

    public function testPastYearIsNotProjected(): void
    {
        $months = $this->monthRows([[10, 1000.0, 5], [11, 2000.0, 5], [12, 3000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 12, false);

        $this->assertFalse($projection['available']);
        $this->assertSame('not_current_year', $projection['reason']);
    }

    public function testCompletedYearIsNotProjected(): void
    {
        $months = $this->monthRows([[10, 1000.0, 5], [11, 2000.0, 5], [12, 3000.0, 5]]);

        // ธ.ค. แล้ว — ไม่มีเดือนเหลือให้เดา
        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 12, true);

        $this->assertFalse($projection['available']);
        $this->assertSame('year_complete', $projection['reason']);
    }

    public function testSingleFilledMonthIsInsufficient(): void
    {
        $months = $this->monthRows([[7, 0.0, 0], [8, 3000.0, 5]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 3000.0, 8, true);

        $this->assertFalse($projection['available']);
        $this->assertSame('insufficient_data', $projection['reason']);
    }

    public function testNoFilledMonthsIsInsufficient(): void
    {
        $projection = $this->makeService()->calculateYearEndProjection([], 0.0, 8, true);

        $this->assertFalse($projection['available']);
        $this->assertSame('insufficient_data', $projection['reason']);
    }

    public function testProjectionIsAttachedToYearlySummary(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end): array {
                $rows = [
                    ['month_key' => '2026-06', 'total_revenue' => 3000.0, 'total_ad_cost' => 1000.0, 'days_count' => 5],
                    ['month_key' => '2026-07', 'total_revenue' => 4000.0, 'total_ad_cost' => 1000.0, 'days_count' => 5],
                    ['month_key' => '2026-08', 'total_revenue' => 5000.0, 'total_ad_cost' => 1000.0, 'days_count' => 5],
                ];

                return array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['month_key'] >= $start && $row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        // กำไร 2000 + 3000 + 4000 = 9000 · avg 3000 · เหลือ 4 เดือน
        $this->assertTrue($summary['projection']['available']);
        $this->assertSame(9000.0, $summary['profit']);
        $this->assertSame(3000.0, $summary['projection']['avg_recent']);
        $this->assertSame(9000.0 + 4 * 3000.0, $summary['projection']['projection_mid']);
        $this->assertSame(9000.0 + 4 * 2000.0, $summary['projection']['projection_low']);
    }

    public function testPastYearSummaryHasNoProjection(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([
            ['month_key' => '2025-03', 'total_revenue' => 3000.0, 'total_ad_cost' => 1000.0, 'days_count' => 5],
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));

        $summary = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['summary'];

        $this->assertFalse($summary['projection']['available']);
        $this->assertSame('not_current_year', $summary['projection']['reason']);
    }
}
