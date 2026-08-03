<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของการตัดเดือนอนาคต + จัดอันดับ best/worst ด้วยกำไร
 * today คงที่ = 2026-08-15 (ปีปัจจุบัน = 2026, เดือนปัจจุบัน = ส.ค.)
 */
final class AnnualServiceCutoffTest extends TestCase
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
                // จำลอง BETWEEN — คืนเฉพาะเดือนที่อยู่ในช่วงที่ service ขอ
                return array_values(array_filter(
                    $monthlyTotals,
                    static fn(array $row): bool => (string)$row['month_key'] >= $start
                        && (string)$row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new AnnualService($recordRepository, $shopRepository);
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
                'days_count' => 30,
            ],
            $months
        );
    }

    public function testCurrentYearStopsAtCurrentMonth(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 5000.0, 1000.0],
            [8, 3000.0, 1000.0],
        ]));

        $data = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];

        // ส.ค. = เดือนที่ 8 → ต้องมีแค่ 8 เดือน ไม่มี ก.ย.–ธ.ค.
        $this->assertCount(8, $data['months']);
        $this->assertSame(8, $data['last_month']);
        $this->assertSame('2026-08', end($data['months'])['month_key']);
        $this->assertCount(8, $data['chart']['months']);
        $this->assertNotContains('2026-12', $data['chart']['months']);
    }

    public function testWorstMonthIsNotAFutureMonth(): void
    {
        // เคสบั๊กเดิม: ก.ย.–ธ.ค. ยังมาไม่ถึง แต่เคยถูกเลือกเป็น worst เพราะรายได้ 0
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 5000.0, 1000.0],   // +4000
            [6, 9000.0, 1000.0],   // +8000
            [8, 3000.0, 2500.0],   // +500  ← แย่สุดในบรรดาเดือนที่มีข้อมูล
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(6, $summary['best_month']['month']);
        $this->assertSame(8, $summary['worst_month']['month']);
        $this->assertLessThanOrEqual(8, $summary['worst_month']['month']);   // ไม่ใช่เดือนอนาคต
    }

    public function testPastYearKeepsAllTwelveMonths(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [[3, 1000.0, 100.0]]));

        $data = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data'];

        $this->assertCount(12, $data['months']);
        $this->assertSame(12, $data['last_month']);
        $this->assertSame('2025-12', end($data['months'])['month_key']);
    }

    public function testFutureYearHasNoMonths(): void
    {
        $service = $this->makeService();

        $data = $service->buildYearlySummary(1, 1, 2027, self::TODAY)['data'];

        $this->assertSame([], $data['months']);
        $this->assertSame(0, $data['last_month']);
        $this->assertFalse($data['has_data']);
        $this->assertNull($data['summary']['best_month']);
        $this->assertNull($data['summary']['worst_month']);
        $this->assertSame(0.0, $data['summary']['total_revenue']);
    }

    public function testBestMonthRanksByProfitNotRevenue(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [
            // รายได้สูงสุด แต่แอดหนัก → กำไรแค่ 500
            [4, 20000.0, 19500.0],
            // รายได้น้อยกว่า แต่กำไรสูงสุด 3000
            [5, 4000.0, 1000.0],
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['summary'];

        $this->assertSame(5, $summary['best_month']['month']);
        $this->assertSame(3000.0, $summary['best_month']['profit']);
        $this->assertSame(4, $summary['worst_month']['month']);
        $this->assertSame(500.0, $summary['worst_month']['profit']);
    }

    public function testWorstMonthPicksRealLossOverEmptyMonth(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [
            [2, 1000.0, 200.0],     // +800
            [9, 500.0, 1500.0],     // -1000 ← ขาดทุนจริง ต้องเป็น worst
            // เดือนอื่นไม่มีข้อมูล (กำไร 0) — ต้องไม่ถูกเลือก
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['summary'];

        $this->assertSame(9, $summary['worst_month']['month']);
        $this->assertSame(-1000.0, $summary['worst_month']['profit']);
    }

    public function testNoDataAtAllGivesNullBestAndWorst(): void
    {
        $service = $this->makeService();

        $data = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data'];

        $this->assertCount(12, $data['months']);   // ยังแสดงตาราง 12 เดือน (ค่า 0)
        $this->assertFalse($data['has_data']);
        $this->assertNull($data['summary']['best_month']);
        $this->assertNull($data['summary']['worst_month']);
    }
}
