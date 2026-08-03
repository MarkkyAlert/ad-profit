<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ profit_margin (ย้ายจาก view มาที่ service) + ตัวนับเดือนกำไร/ขาดทุน
 * today คงที่ = 2026-08-15 (cutoff = ส.ค.)
 */
final class AnnualServiceSummaryTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     */
    private function makeService(array $monthlyTotals = []): AnnualService
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

        return new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));
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
                'days_count' => 4,
            ],
            $months
        );
    }

    public function testProfitMarginMatchesTheFormerViewFormula(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 8000.0, 3000.0],
            [2, 2000.0, 1000.0],
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        // รายได้ 10000 · กำไร 6000 → 60.0%
        $this->assertSame(10000.0, $summary['total_revenue']);
        $this->assertSame(6000.0, $summary['profit']);
        $this->assertSame(60.0, $summary['profit_margin']);
        // สูตรเดิมที่ view เคยคำนวณ — ต้องได้เท่ากันเป๊ะ
        $this->assertSame(
            round(($summary['profit'] / $summary['total_revenue']) * 100, 1),
            $summary['profit_margin']
        );
    }

    public function testProfitMarginIsNullWhenNoRevenue(): void
    {
        $service = $this->makeService();

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['total_revenue']);
        // หารศูนย์ไม่ได้ → null (ไม่ใช่ 0% ที่จะอ่านว่า "ขายได้แต่ไม่มีกำไรเลย")
        $this->assertNull($summary['profit_margin']);
    }

    public function testProfitMarginIsNegativeWhenLosing(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 2000.0, 5000.0]]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(-150.0, $summary['profit_margin']);   // -3000 / 2000
    }

    public function testMonthCountsSplitProfitAndLoss(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 3000.0, 1000.0],   // +2000 กำไร
            [2, 1000.0, 3000.0],   // -2000 ขาดทุน
            [3, 5000.0, 1000.0],   // +4000 กำไร
            [7, 500.0, 2000.0],    // -1500 ขาดทุน
            // เม.ย./พ.ค./มิ.ย./ส.ค. ไม่มีข้อมูล — ต้องไม่ถูกนับเลย
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(4, $summary['months_with_data']);
        $this->assertSame(2, $summary['profit_months']);
        $this->assertSame(2, $summary['loss_months']);
    }

    public function testUnfilledMonthsAreNotCounted(): void
    {
        // ปีนี้ถึง ส.ค. = 8 เดือนในตาราง แต่กรอกจริงแค่เดือนเดียว
        $service = $this->makeService($this->totalsFor(2026, [[1, 3000.0, 1000.0]]));

        $data = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];

        $this->assertCount(8, $data['months']);
        // เดือนที่ยังไม่กรอกมีกำไร 0 แต่ไม่ใช่ "เดือนเท่าทุน" — ต้องไม่ถูกนับ
        $this->assertSame(1, $data['summary']['months_with_data']);
        $this->assertSame(1, $data['summary']['profit_months']);
        $this->assertSame(0, $data['summary']['loss_months']);
    }

    public function testBreakEvenMonthCountsAsNeitherProfitNorLoss(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 3000.0, 1000.0],   // +2000 กำไร
            [2, 2000.0, 2000.0],   // เท่าทุนพอดี
            [3, 1000.0, 2000.0],   // -1000 ขาดทุน
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(3, $summary['months_with_data']);
        $this->assertSame(1, $summary['profit_months']);
        $this->assertSame(1, $summary['loss_months']);
        // เดือนเท่าทุน = ส่วนต่าง ไม่อยู่ในทั้งสองฝั่ง
        $this->assertSame(1, $summary['months_with_data'] - $summary['profit_months'] - $summary['loss_months']);
    }

    public function testCountsMatchMonthsWithDaysCount(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 3000.0, 1000.0],
            [4, 1000.0, 2000.0],
            [8, 2000.0, 500.0],
        ]));

        $data = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];

        // months_with_data ต้องตรงกับจำนวนเดือนที่ days_count > 0 ในตาราง
        $withDays = count(array_filter($data['months'], static fn(array $row): bool => (int)$row['days_count'] > 0));
        $this->assertSame($withDays, $data['summary']['months_with_data']);
        $this->assertSame($data['summary']['months_with_data'] > 0, $data['has_data']);
    }

    public function testEmptyYearHasZeroCounts(): void
    {
        $service = $this->makeService();

        $summary = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['summary'];

        $this->assertSame(0, $summary['months_with_data']);
        $this->assertSame(0, $summary['profit_months']);
        $this->assertSame(0, $summary['loss_months']);
    }

    public function testFutureMonthsAreExcludedFromCounts(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 3000.0, 1000.0],
            // ธ.ค. เกิน cutoff — ต่อให้มีแถวก็ต้องไม่ถูกนับ
            [12, 90000.0, 0.0],
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(1, $summary['months_with_data']);
        $this->assertSame(1, $summary['profit_months']);
    }
}
