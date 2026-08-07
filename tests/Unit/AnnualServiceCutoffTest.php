<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
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
            [7, 4000.0, 3000.0],   // +1000 ← แย่สุดในบรรดาเดือนที่ **จบแล้ว**
            [8, 3000.0, 2500.0],   // +500  ← เดือนปัจจุบัน ยังกรอกไม่ครบเดือน
        ]));

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(6, $summary['best_month']['month']);

        // ⚠️ ส.ค. กำไรน้อยสุดก็จริง แต่เป็นเดือนปัจจุบันที่ยังไม่จบ
        // เทียบยอดสะสมกับเดือนที่จบแล้วไม่ได้ — ยังไม่จบ = ยังตัดสินไม่ได้ ไม่ใช่ "แย่"
        $this->assertSame(7, $summary['worst_month']['month']);
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

    /**
     * ⭐⭐ อ่านข้อมูล "ปีก่อน" ไม่สำเร็จ ต้องไม่ถูกยุบไปรวมกับ "ปีก่อนไม่มีข้อมูล"
     *
     * ⚠️ วัดจริงก่อนแก้: ปีก่อนมีกำไรจริง ฿219,000 · ทำให้ query ของปีก่อนล้มอย่างเดียว
     * → หน้าเว็บขึ้น "ไม่มีข้อมูลปีก่อน" คู่กับ "ปีก่อนช่วงเดียวกัน ฿0" และ**ไม่มีแถบแดง
     * เตือนอะไรเลย** — ระบบพูดสิ่งที่ไม่จริงอย่างมั่นใจ
     */
    public function testAFailedPreviousYearReadIsNotReportedAsNoData(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end, ?string $notAfter = null): array {
                if (str_starts_with($start, '2025')) {
                    throw new \RuntimeException('จำลอง query ปีก่อนล้ม');
                }

                return [[
                    'month_key' => '2026-01',
                    'total_revenue' => 40000.0,
                    'total_ad_cost' => 9000.0,
                    'days_count' => 31,
                ]];
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $summary = (new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class)))
            ->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'] ?? [];

        $this->assertTrue(
            $summary['prev_year_unavailable'] ?? false,
            'อ่านปีก่อนไม่สำเร็จแต่ไม่ได้บอกไว้ หน้าเว็บจึงเขียนว่า "ไม่มีข้อมูลปีก่อน"'
        );
        $this->assertFalse($summary['prev_year_has_data'] ?? true);
    }

    /** อ่านสำเร็จแต่ปีก่อนไม่มีข้อมูลจริง ๆ ต้องไม่ถูกรายงานว่าอ่านไม่สำเร็จ */
    public function testAnEmptyPreviousYearIsNotReportedAsUnavailable(): void
    {
        $summary = $this->makeService($this->totalsFor(2026, [[1, 40000.0, 9000.0]]))
            ->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'] ?? [];

        $this->assertFalse($summary['prev_year_unavailable'] ?? true, 'อ่านสำเร็จแต่บอกว่าล้ม');
        $this->assertFalse($summary['prev_year_has_data'] ?? true);
    }
}
