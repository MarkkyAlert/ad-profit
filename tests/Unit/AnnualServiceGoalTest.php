<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ goal_progress ในหน้ารายปี
 * today คงที่ = 2026-08-15 (cutoff = ส.ค.)
 */
final class AnnualServiceGoalTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     * @param array<int,array<string,mixed>> $goals
     */
    private function makeService(array $monthlyTotals = [], array $goals = []): AnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use ($monthlyTotals): array {
                return array_values(array_filter(
                    $monthlyTotals,
                    static fn(array $row): bool => (string)$row['month_key'] >= $start
                        && (string)$row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('getByShopAndMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end) use ($goals): array {
                return array_values(array_filter(
                    $goals,
                    static fn(array $row): bool => (string)$row['goal_month'] >= $start
                        && (string)$row['goal_month'] <= $end
                ));
            }
        );

        return new AnnualService($recordRepository, $shopRepository, $goalRepository);
    }

    /**
     * @param array<int,array{0:int,1:float,2:float}> $months [เดือน, รายได้, ค่าแอด]
     * @return array<int,array<string,mixed>>
     */
    private function totalsFor(int $year, array $months): array
    {
        return array_map(
            static fn(array $row): array => [
                'month_key' => sprintf('%04d-%02d', $year, $row[0]),
                'total_revenue' => $row[1],
                'total_ad_cost' => $row[2],
                'days_count' => 5,
            ],
            $months
        );
    }

    /**
     * @param array<int,array{0:int,1:float|null,2:float|null}> $goals [เดือน, เป้ารายได้, เป้ากำไร]
     * @return array<int,array<string,mixed>>
     */
    private function goalsFor(int $year, array $goals): array
    {
        return array_map(
            static fn(array $row): array => [
                'goal_month' => sprintf('%04d-%02d', $year, $row[0]),
                'target_revenue' => $row[1],
                'target_profit' => $row[2],
            ],
            $goals
        );
    }

    public function testProgressIsCalculatedForMonthsWithGoals(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0]]),   // รายได้ 8000 · กำไร 5000
            $this->goalsFor(2026, [[1, 10000.0, 4000.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertCount(1, $progress);
        $this->assertSame(1, $progress[0]['month']);
        $this->assertSame('2026-01', $progress[0]['month_key']);
        $this->assertSame(8000.0, $progress[0]['actual_revenue']);
        $this->assertSame(5000.0, $progress[0]['actual_profit']);
        $this->assertSame(80.0, $progress[0]['revenue_progress']);    // 8000 / 10000
        $this->assertSame(125.0, $progress[0]['profit_progress']);    // 5000 / 4000
        // รายได้ยังไม่ถึงเป้า แต่กำไรถึงแล้ว — สองแกนแยกกัน
        $this->assertFalse($progress[0]['revenue_reached']);
        $this->assertTrue($progress[0]['profit_reached']);
    }

    public function testMonthsWithoutGoalAreExcluded(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0], [2, 9000.0, 3000.0], [3, 7000.0, 2000.0]]),
            $this->goalsFor(2026, [[2, 5000.0, 1000.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        // มีเป้าแค่ ก.พ. — เดือนอื่นต้องไม่โผล่เป็นแถวว่าง
        $this->assertCount(1, $progress);
        $this->assertSame(2, $progress[0]['month']);
    }

    public function testRevenueOnlyGoalLeavesProfitFieldsNull(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0]]),
            $this->goalsFor(2026, [[1, 10000.0, null]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertSame(80.0, $progress[0]['revenue_progress']);
        $this->assertFalse($progress[0]['revenue_reached']);
        // ไม่ได้ตั้งเป้ากำไร → null ทั้งคู่ (ไม่ใช่ 0% / false ที่จะอ่านว่า "ไม่ถึงเป้า")
        $this->assertNull($progress[0]['target_profit']);
        $this->assertNull($progress[0]['profit_progress']);
        $this->assertNull($progress[0]['profit_reached']);
    }

    public function testProfitOnlyGoalLeavesRevenueFieldsNull(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0]]),
            $this->goalsFor(2026, [[1, null, 4000.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertNull($progress[0]['target_revenue']);
        $this->assertNull($progress[0]['revenue_progress']);
        $this->assertNull($progress[0]['revenue_reached']);
        $this->assertSame(125.0, $progress[0]['profit_progress']);
        $this->assertTrue($progress[0]['profit_reached']);
    }

    public function testFutureMonthGoalIsExcludedByCutoff(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0]]),
            // ธ.ค. ยังมาไม่ถึง (today = ส.ค.) — ตั้งเป้าไว้ล่วงหน้าก็ไม่ควรโชว์ว่า "ทำได้ 0%"
            $this->goalsFor(2026, [[1, 10000.0, 4000.0], [12, 50000.0, 20000.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertCount(1, $progress);
        $this->assertSame(1, $progress[0]['month']);
    }

    public function testPastYearIncludesGoalsThroughDecember(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2025, [[12, 8000.0, 3000.0]]),
            $this->goalsFor(2025, [[12, 4000.0, 2000.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['goal_progress'];

        // ปีอดีต cutoff = 12 → เป้า ธ.ค. ต้องรวม
        $this->assertCount(1, $progress);
        $this->assertSame(12, $progress[0]['month']);
        $this->assertTrue($progress[0]['revenue_reached']);
    }

    public function testNoGoalsGivesEmptyArray(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 8000.0, 3000.0]]));

        $data = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];

        $this->assertSame([], $data['goal_progress']);
    }

    /**
     * เป้า 0 = "ยังไม่ได้ตั้งเป้าจริง" ไม่ใช่ "ถึงเป้าแล้ว"
     *
     * เดิมหน้ารายปีตอบว่าถึงเป้า (✓ เขียว) ขณะที่แดชบอร์ดตอบว่ายังไม่ถึง สำหรับเป้าเดียวกัน
     * ตอนนี้ทั้งสองหน้าใช้ `GoalService::isReached()` ตัวเดียวกัน
     */
    public function testZeroTargetIsNotCountedAsReached(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0]]),
            $this->goalsFor(2026, [[1, 0.0, 0.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertNull($progress[0]['revenue_progress'], 'เป้า 0 หารไม่ได้ → ไม่มี %');
        $this->assertNull($progress[0]['profit_progress']);
        $this->assertFalse($progress[0]['revenue_reached']);
        $this->assertFalse($progress[0]['profit_reached']);
    }

    public function testNegativeProfitAgainstGoalStaysNegative(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[7, 2000.0, 5000.0]]),   // ขาดทุน 3000
            $this->goalsFor(2026, [[7, null, 6000.0]])
        );

        $progress = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['goal_progress'];

        $this->assertSame(-3000.0, $progress[0]['actual_profit']);
        $this->assertSame(-50.0, $progress[0]['profit_progress']);
        $this->assertFalse($progress[0]['profit_reached']);
    }

    public function testFutureYearHasNoGoalProgress(): void
    {
        $service = $this->makeService([], $this->goalsFor(2027, [[1, 10000.0, 4000.0]]));

        $data = $service->buildYearlySummary(1, 1, 2027, self::TODAY)['data'];

        $this->assertSame([], $data['goal_progress']);
    }

    public function testGoalProgressDoesNotAffectExistingPayload(): void
    {
        $service = $this->makeService(
            $this->totalsFor(2026, [[1, 8000.0, 3000.0]]),
            $this->goalsFor(2026, [[1, 10000.0, 4000.0]])
        );

        $data = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];

        // cutoff / best-worst / chart เดิมต้องไม่ถูกกระทบจากการเพิ่ม goal
        $this->assertCount(8, $data['months']);
        $this->assertSame(8, $data['last_month']);
        $this->assertSame(1, $data['summary']['best_month']['month']);
        $this->assertCount(8, $data['chart']['prev_profit']);
    }
}
