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

    /** สิ้นเดือน = ไม่มีเศษของเดือนปัจจุบันมาบวก ตัวเลขที่ assert ไว้จึงเป็นตัวคูณวันเต็มล้วน */
    private const MONTH_END = '2026-08-31';

    /**
     * ⭐ ฐานประมาณการคิดเป็น "กำไรต่อวัน" ตัวคูณจึงเป็น **จำนวนวันที่เหลือ** ไม่ใช่จำนวนเดือน
     *
     * ⚠️ เดือนยาวไม่เท่ากัน การเทียบยอดทั้งเดือนทำให้ ก.พ. (28 วัน) ดูเป็นเดือนที่แย่กว่า
     * มี.ค. (31 วัน) ทั้งที่ผลงานต่อวันเท่ากันเป๊ะ · ดู `testTheForecastDoesNotMoveWhenNothingChanged()`
     *
     * หลัง 31 ส.ค. เหลือ ก.ย. 30 + ต.ค. 31 + พ.ย. 30 + ธ.ค. 31 = 122 วัน
     */
    private const DAYS_LEFT_AFTER_AUGUST = 122;

    /** ข้อมูลตั้งต้นเขียนเป็น "อัตราต่อวัน" เพื่อให้ตัวเลขที่คาดหวังอ่านออก */
    private const DAYS_IN = [6 => 30, 7 => 31, 8 => 31];

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
        // 3 เดือนล่าสุดทำได้วันละ 100 / 200 / 300 → เฉลี่ยวันละ 200
        $months = $this->monthRows([
            [6, 100.0 * self::DAYS_IN[6], 30],
            [7, 200.0 * self::DAYS_IN[7], 31],
            [8, 300.0 * self::DAYS_IN[8], 31],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026, self::MONTH_END);

        $this->assertTrue($projection['available']);
        $this->assertSame(4, $projection['months_remaining']);
        $this->assertSame(self::DAYS_LEFT_AFTER_AUGUST, $projection['remaining_days']);
        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertSame(200.0, $projection['avg_profit_per_day']);

        $this->assertSame(6000.0 + self::DAYS_LEFT_AFTER_AUGUST * 100.0, $projection['projection_low']);
        $this->assertSame(6000.0 + self::DAYS_LEFT_AFTER_AUGUST * 200.0, $projection['projection_mid']);
        $this->assertSame(6000.0 + self::DAYS_LEFT_AFTER_AUGUST * 300.0, $projection['projection_high']);
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
        // ⚠️ ต้องเท่ากัน "ต่อวัน" ไม่ใช่ "ต่อเดือน" — เดือนยาวไม่เท่ากัน
        $months = $this->monthRows([
            [6, 100.0 * self::DAYS_IN[6], 30],
            [7, 100.0 * self::DAYS_IN[7], 31],
            [8, 100.0 * self::DAYS_IN[8], 31],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026, self::MONTH_END);

        // min == max → ช่วงยุบเป็นจุด (ปกติ ไม่ใช่บั๊ก)
        $this->assertSame($projection['projection_low'], $projection['projection_high']);
        $this->assertSame($projection['projection_mid'], $projection['projection_low']);
    }

    public function testOnlyTheThreeMostRecentFilledMonthsAreUsed(): void
    {
        // 5 เดือน — 2 เดือนแรกกำไรมหาศาล ต้องไม่ถูกใช้เป็นฐาน
        $months = $this->monthRows([
            [4, 99000.0, 30],
            [5, 99000.0, 31],
            [6, 100.0 * self::DAYS_IN[6], 30],
            [7, 200.0 * self::DAYS_IN[7], 31],
            [8, 300.0 * self::DAYS_IN[8], 31],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 204000.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertSame(200.0, $projection['avg_profit_per_day']);
        $this->assertSame(204000.0 + self::DAYS_LEFT_AFTER_AUGUST * 300.0, $projection['projection_high']);
    }

    public function testUnfilledMonthsAreNotUsedAsBasis(): void
    {
        // เดือน 7 มี profit ติดมาแต่ days_count = 0 (ยังไม่ได้กรอก) → ห้ามใช้เป็นฐาน
        $months = $this->monthRows([
            [6, 100.0 * self::DAYS_IN[6], 30],
            [7, 0.0, 0],
            [8, 300.0 * self::DAYS_IN[8], 31],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 4000.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(2, $projection['basis_month_count']);
        $this->assertSame(200.0, $projection['avg_profit_per_day']);   // (100 + 300) / 2 ต่อวัน
        $this->assertSame(4000.0 + self::DAYS_LEFT_AFTER_AUGUST * 100.0, $projection['projection_low']);
    }

    public function testMonthsBeyondCutoffAreIgnored(): void
    {
        $months = $this->monthRows([
            [7, 100.0 * self::DAYS_IN[7], 31],
            [8, 300.0 * self::DAYS_IN[8], 31],
            [11, 90000.0, 30],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 4000.0, 8, true, 2026, self::MONTH_END);

        // พ.ย. เกิน lastMonth — ต้องไม่ถูกนับเป็นฐาน
        $this->assertSame(2, $projection['basis_month_count']);
        $this->assertSame(200.0, $projection['avg_profit_per_day']);
    }

    public function testRecentLossesProjectBelowCurrentProfit(): void
    {
        $months = $this->monthRows([
            [6, -100.0 * self::DAYS_IN[6], 30],
            [7, -200.0 * self::DAYS_IN[7], 31],
            [8, -300.0 * self::DAYS_IN[8], 31],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 1000.0, 8, true, 2026, self::MONTH_END);

        // ขาดทุนต่อเนื่อง → ประมาณการต้องต่ำกว่ากำไรสะสมปัจจุบัน และติดลบได้
        $this->assertLessThan(1000.0, $projection['projection_mid']);
        $this->assertSame(1000.0 + self::DAYS_LEFT_AFTER_AUGUST * -300.0, $projection['projection_low']);
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
        // ⭐ ฐานคิดเป็น "กำไรต่อวัน" — มิ.ย. 2,000/30 วัน · ก.ค. 3,000/31 วัน
        $averagePerDay = ((2000.0 / 30) + (3000.0 / 31)) / 2;
        $lowestPerDay = 2000.0 / 30;

        // เหลือ 16 วันของ ส.ค. (วันนี้ 15) + ก.ย. 30 + ต.ค. 31 + พ.ย. 30 + ธ.ค. 31
        $remainingDays = 16 + 30 + 31 + 30 + 31;

        $this->assertTrue($summary['projection']['available']);
        $this->assertSame($actualProfit, $summary['profit']);
        $this->assertSame(round($averagePerDay, 2), $summary['projection']['avg_profit_per_day']);
        $this->assertSame(4, $summary['projection']['months_remaining']);
        $this->assertSame($remainingDays, $summary['projection']['remaining_days']);
        $this->assertSame(
            round($actualProfit + $remainingDays * $averagePerDay, 2),
            $summary['projection']['projection_mid']
        );
        $this->assertSame(
            round($actualProfit + $remainingDays * $lowestPerDay, 2),
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
        $months = $this->monthRows([
            [6, 100.0 * self::DAYS_IN[6], 30],
            [7, 100.0 * self::DAYS_IN[7], 31],
            [8, 200.0, 2],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6200.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(2, $projection['basis_month_count']);   // นับแค่ มิ.ย. กับ ก.ค.
        $this->assertSame(100.0, $projection['avg_profit_per_day']);
        $this->assertSame(6200.0 + self::DAYS_LEFT_AFTER_AUGUST * 100.0, $projection['projection_low']);
    }

    /** กรอกครึ่งเดือนพอดีต้องยังนับ (ขอบเขตล่าง) */
    public function testMonthFilledExactlyHalfIsIncluded(): void
    {
        // ส.ค. มี 31 วัน — 16 วัน (16*2 = 32 >= 31) ผ่านเกณฑ์
        // ⚠️ วันตัดคือสิ้นเดือน ส.ค. จึงไม่มีเศษเดือนให้ขยาย — 4,000 คิดเป็นทั้งเดือน
        $months = $this->monthRows([
            [6, 100.0 * self::DAYS_IN[6], 30],
            [7, 100.0 * self::DAYS_IN[7], 31],
            [8, 300.0 * self::DAYS_IN[8], 16],
        ]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026, self::MONTH_END);

        $this->assertSame(3, $projection['basis_month_count']);
        $this->assertEqualsWithDelta(166.67, $projection['avg_profit_per_day'], 0.01);
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

    /**
     * ⭐⭐⭐ ประมาณการต้องไม่ขยับ ถ้าธุรกิจไม่มีอะไรเปลี่ยน
     *
     * ⚠️ ฐานเดิมเก็บ "กำไรทั้งเดือน" ซึ่งอ่านเดือนสั้นว่าเป็นเดือนที่แย่กว่า
     * ร้านที่ทำกำไรวันละ ฿1,000 เท่ากันเป๊ะทุกวัน กรอกครบทุกวัน จึงเห็น:
     *   15 พ.ค. ฐาน = ก.พ. ฿28,000 · มี.ค. ฿31,000 · เม.ย. ฿30,000 → ฿357,978
     *   16 พ.ค. ฐาน = มี.ค. ฿31,000 · เม.ย. ฿30,000 · พ.ค. ฿31,000 → ฿365,505
     * **กระโดด ฿7,527 ข้ามคืน** เพราะ ก.พ. (28 วัน) หลุดออกจากฐาน 3 เดือนล่าสุด
     * (16 มี.ค. ก็กระโดด ฿4,790 ตอนเดือนปัจจุบันเข้าฐานเป็นเดือนที่ 3)
     *
     * เดินทั้งปี 365 วันแล้วประมาณการต้องเป็น ฿365,000 นิ่งทุกวัน
     */
    public function testTheForecastDoesNotMoveWhenNothingChanged(): void
    {
        $perDay = 1000.0;
        $seen = [];

        for ($month = 2; $month <= 12; $month++) {
            $daysInMonth = (int)(new \DateTimeImmutable(sprintf('2026-%02d-01', $month)))->format('t');

            // ดูทั้งวันที่ 15 และ 16 ของทุกเดือน — เกณฑ์ "ผ่านครึ่งเดือน" อยู่ตรงนั้น
            foreach ([15, 16] as $day) {
                $today = sprintf('2026-%02d-%02d', $month, $day);
                $months = [];
                $cumulative = 0.0;

                for ($m = 1; $m <= $month; $m++) {
                    $days = ($m === $month)
                        ? $day
                        : (int)(new \DateTimeImmutable(sprintf('2026-%02d-01', $m)))->format('t');
                    $months[] = ['month' => $m, 'profit' => $days * $perDay, 'days_count' => $days];
                    $cumulative += $days * $perDay;
                }

                $projection = $this->makeService()
                    ->calculateYearEndProjection($months, $cumulative, $month, true, 2026, $today);

                $this->assertTrue($projection['available'], $today . ': ไม่มีประมาณการ');
                $seen[$today] = (float)$projection['projection_mid'];
            }
        }

        $this->assertNotEmpty($seen, 'ไม่มีวันไหนถูกตรวจเลย — เทสต์นี้จะไม่ได้พิสูจน์อะไร');

        foreach ($seen as $today => $mid) {
            $this->assertEqualsWithDelta(
                365 * $perDay,
                $mid,
                0.01,
                $today . ': ประมาณการขยับทั้งที่ทำกำไรวันละ ฿1,000 เท่าเดิมทุกวัน'
            );
        }
    }

    /** ⚠️ ขอบล่าง/ขอบบนก็ต้องนิ่งด้วย ไม่ใช่แค่ค่ากลาง */
    public function testTheForecastRangeAlsoStaysPut(): void
    {
        $perDay = 1000.0;

        $build = function (string $today, int $lastMonth, int $dayOfMonth) use ($perDay): array {
            $months = [];
            $cumulative = 0.0;
            for ($m = 1; $m <= $lastMonth; $m++) {
                $days = ($m === $lastMonth)
                    ? $dayOfMonth
                    : (int)(new \DateTimeImmutable(sprintf('2026-%02d-01', $m)))->format('t');
                $months[] = ['month' => $m, 'profit' => $days * $perDay, 'days_count' => $days];
                $cumulative += $days * $perDay;
            }

            return $this->makeService()
                ->calculateYearEndProjection($months, $cumulative, $lastMonth, true, 2026, $today);
        };

        $before = $build('2026-05-15', 5, 15);
        $after = $build('2026-05-16', 5, 16);

        $this->assertEqualsWithDelta($before['projection_low'], $after['projection_low'], 0.01);
        $this->assertEqualsWithDelta($before['projection_high'], $after['projection_high'], 0.01);
    }
}
