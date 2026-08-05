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

    /** สิ้นเดือน = ไม่มีเศษของเดือนปัจจุบันมาบวก ตัวเลขที่ assert ไว้จึงเป็นตัวคูณเดือนเต็มล้วน */
    private const MONTH_END = '2026-08-31';

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
     *        28 = กรอกเกินครึ่งเดือน (เข้าฐาน projection) · 0 = ยังไม่กรอก
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
        $months = $this->monthRows([[6, 1000.0, 28], [7, 2000.0, 28], [8, 3000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026, self::MONTH_END);

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
        $months = $this->monthRows([[5, -500.0, 28], [6, 4000.0, 28], [7, 1200.0, 28], [8, 800.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 5500.0, 8, true, 2026, self::MONTH_END);

        $this->assertLessThanOrEqual($projection['projection_mid'], $projection['projection_low']);
        $this->assertLessThanOrEqual($projection['projection_high'], $projection['projection_mid']);
    }

    public function testIdenticalMonthsCollapseRangeToAPoint(): void
    {
        $months = $this->monthRows([[6, 2000.0, 28], [7, 2000.0, 28], [8, 2000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026, self::MONTH_END);

        // min == max → ช่วงยุบเป็นจุด (ปกติ ไม่ใช่บั๊ก)
        $this->assertSame($projection['projection_low'], $projection['projection_high']);
        $this->assertSame($projection['projection_mid'], $projection['projection_low']);
    }

    public function testOnlyTheThreeMostRecentFilledMonthsAreUsed(): void
    {
        // 5 เดือน — 2 เดือนแรกกำไรมหาศาล ต้องไม่ถูกใช้เป็นฐาน
        $months = $this->monthRows([
            [4, 99000.0, 28],
            [5, 99000.0, 28],
            [6, 1000.0, 28],
            [7, 2000.0, 28],
            [8, 3000.0, 28],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 204000.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);
        $this->assertSame(204000.0 + 4 * 3000.0, $projection['projection_high']);
    }

    public function testUnfilledMonthsAreNotUsedAsBasis(): void
    {
        // เดือน 7 มี profit ติดมาแต่ days_count = 0 (ยังไม่ได้กรอก) → ห้ามใช้เป็นฐาน
        $months = $this->monthRows([[6, 1000.0, 28], [7, 0.0, 0], [8, 3000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 4000.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(2, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);     // (1000 + 3000) / 2
        $this->assertSame(4000.0 + 4 * 1000.0, $projection['projection_low']);
    }

    public function testMonthsBeyondCutoffAreIgnored(): void
    {
        $months = $this->monthRows([[7, 1000.0, 28], [8, 3000.0, 28], [11, 90000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 4000.0, 8, true, 2026, self::MONTH_END);

        // พ.ย. เกิน lastMonth — ต้องไม่ถูกนับเป็นฐาน
        $this->assertSame(2, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);
    }

    public function testRecentLossesProjectBelowCurrentProfit(): void
    {
        $months = $this->monthRows([[6, -1000.0, 28], [7, -2000.0, 28], [8, -3000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 1000.0, 8, true, 2026, self::MONTH_END);

        // ขาดทุนต่อเนื่อง → ประมาณการต้องต่ำกว่ากำไรสะสมปัจจุบัน และติดลบได้
        $this->assertLessThan(1000.0, $projection['projection_mid']);
        $this->assertSame(1000.0 + 4 * -3000.0, $projection['projection_low']);
        $this->assertLessThan(0.0, $projection['projection_low']);
    }

    public function testPastYearIsNotProjected(): void
    {
        $months = $this->monthRows([[10, 1000.0, 28], [11, 2000.0, 28], [12, 3000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 12, false, 2026, '2026-12-31');

        $this->assertFalse($projection['available']);
        $this->assertSame('not_current_year', $projection['reason']);
    }

    public function testCompletedYearIsNotProjected(): void
    {
        $months = $this->monthRows([[10, 1000.0, 28], [11, 2000.0, 28], [12, 3000.0, 28]]);

        // ธ.ค. แล้ว — ไม่มีเดือนเหลือให้เดา
        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 12, true, 2026, '2026-12-31');

        $this->assertFalse($projection['available']);
        $this->assertSame('year_complete', $projection['reason']);
    }

    public function testSingleFilledMonthIsInsufficient(): void
    {
        $months = $this->monthRows([[7, 0.0, 0], [8, 3000.0, 28]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 3000.0, 8, true, 2026, self::MONTH_END);

        $this->assertFalse($projection['available']);
        $this->assertSame('insufficient_data', $projection['reason']);
    }

    public function testNoFilledMonthsIsInsufficient(): void
    {
        $projection = $this->makeService()->calculateYearEndProjection([], 0.0, 8, true, 2026, self::MONTH_END);

        $this->assertFalse($projection['available']);
        $this->assertSame('insufficient_data', $projection['reason']);
    }

    public function testProjectionIsAttachedToYearlySummary(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end): array {
                $rows = [
                    // ⚠️ วันนี้คือ 15 ส.ค. — ส.ค. จึงกรอกได้มากสุด 15 วัน
                    // (ของเดิมใส่ 28 วัน ซึ่งเป็นไปไม่ได้จริง)
                    ['month_key' => '2026-06', 'total_revenue' => 3000.0, 'total_ad_cost' => 1000.0, 'days_count' => 30],
                    ['month_key' => '2026-07', 'total_revenue' => 4000.0, 'total_ad_cost' => 1000.0, 'days_count' => 31],
                    ['month_key' => '2026-08', 'total_revenue' => 2000.0, 'total_ad_cost' => 1000.0, 'days_count' => 15],
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

        // กำไรที่ทำได้จริง 2000 + 3000 + 1000 = 6000
        $actualProfit = 6000.0;

        // ⚠️ ส.ค. กรอก 15 จาก 31 วัน = ยังไม่ถึงครึ่งเดือน จึง **ไม่เข้าฐานคำนวณ**
        // (เดือนที่กรอกน้อยเกินไปไม่ใช่ตัวแทนของเดือนนั้น) → ฐาน = มิ.ย. + ก.ค.
        // เดือนปัจจุบันจะเข้าฐานเมื่อผ่านครึ่งเดือน และตอนนั้นต้องถูกเทียบเป็น
        // "ถ้าทำแบบนี้ทั้งเดือน" ก่อน ไม่งั้นค่าเฉลี่ยร่วงทันทีในวันที่ข้ามครึ่งเดือน
        $average = (2000.0 + 3000.0) / 2;
        $lowest = 2000.0;

        // เหลือ ก.ย.–ธ.ค. = 4 เดือนเต็ม + เศษของ ส.ค. อีก 16/31 (วันนี้คือ 15 ส.ค.)
        $effectiveMonths = 4 + 16 / 31;

        $this->assertTrue($summary['projection']['available']);
        $this->assertSame($actualProfit, $summary['profit']);
        $this->assertSame(round($average, 2), $summary['projection']['avg_recent']);
        $this->assertSame(4, $summary['projection']['months_remaining']);
        $this->assertSame(
            round($actualProfit + $effectiveMonths * $average, 2),
            $summary['projection']['projection_mid']
        );
        $this->assertSame(
            round($actualProfit + $effectiveMonths * $lowest, 2),
            $summary['projection']['projection_low']
        );
    }

    public function testPastYearSummaryHasNoProjection(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([
            ['month_key' => '2025-03', 'total_revenue' => 3000.0, 'total_ad_cost' => 1000.0, 'days_count' => 28],
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));

        $summary = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['summary'];

        $this->assertFalse($summary['projection']['available']);
        $this->assertSame('not_current_year', $summary['projection']['reason']);
    }

    /**
     * ⭐ เดือนที่กรอกไม่ถึงครึ่งต้องไม่เข้าฐานประมาณการ
     *
     * ต้นเดือน ส.ค. กรอกไป 2 วันได้กำไร 200 — ถ้านับเป็นเดือนเต็ม ค่าเฉลี่ยจะถูกลากลง
     * และ projection_low จะเอา 200 ไปคูณเดือนที่เหลือ ทำให้ช่วงต่ำผิดรูป
     */
    public function testSparselyFilledMonthIsExcludedFromBasis(): void
    {
        $months = $this->monthRows([[6, 3000.0, 28], [7, 3000.0, 28], [8, 200.0, 2]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6200.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(2, $projection['basis_month_count']);   // นับแค่ มิ.ย. กับ ก.ค.
        $this->assertSame(3000.0, $projection['avg_recent']);
        $this->assertSame(6200.0 + 4 * 3000.0, $projection['projection_low']);
    }

    /** กรอกครึ่งเดือนพอดีต้องยังนับ (ขอบเขตล่าง) */
    public function testMonthFilledExactlyHalfIsIncluded(): void
    {
        // ส.ค. มี 31 วัน — 16 วัน (16*2 = 32 >= 31) ผ่านเกณฑ์
        $months = $this->monthRows([[6, 1000.0, 28], [7, 1000.0, 28], [8, 4000.0, 16]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertSame(2000.0, $projection['avg_recent']);
    }

    /** เดือนที่กรอกน้อยทุกเดือน → ข้อมูลไม่พอ ดีกว่าเดาจากฐานที่บิดเบือน */
    public function testAllSparseMonthsGiveInsufficientData(): void
    {
        $months = $this->monthRows([[6, 100.0, 2], [7, 200.0, 3], [8, 300.0, 1]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 600.0, 8, true, 2026, self::MONTH_END);

        $this->assertFalse($projection['available']);
        $this->assertSame('insufficient_data', $projection['reason']);
    }

    /** ก.พ. ปีอธิกสุรทิน — 14 วันไม่ถึงครึ่งของ 29 แต่ถึงครึ่งของ 28 */
    public function testFebruaryThresholdFollowsTheActualYear(): void
    {
        $months = $this->monthRows([[1, 1000.0, 28], [2, 5000.0, 14], [3, 1000.0, 28]]);

        $leap = $this->makeService()->calculateYearEndProjection($months, 7000.0, 3, true, 2028, '2028-03-31');
        $common = $this->makeService()->calculateYearEndProjection($months, 7000.0, 3, true, 2026, '2026-03-31');

        $this->assertSame(2, $leap['basis_month_count']);    // 2028 ก.พ. มี 29 วัน → ตัดออก
        $this->assertSame(3, $common['basis_month_count']);  // 2026 ก.พ. มี 28 วัน → นับ
    }
}
