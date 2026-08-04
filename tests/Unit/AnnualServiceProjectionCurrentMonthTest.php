<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ส่วนที่เหลือของ "เดือนปัจจุบัน" หายไปจากประมาณการสิ้นปี
 *
 * $cumulativeProfit นับเดือนปัจจุบันแบบครึ่ง ๆ กลาง ๆ (เท่าที่กรอกมา) ส่วน
 * $monthsRemaining = 12 − lastMonth ถือว่าเดือนปัจจุบัน "ผ่านไปแล้ว" ทั้งเดือน
 * → วันที่เหลือของเดือนนี้ไม่ถูกนับทั้งสองทาง ประมาณการจึงต่ำกว่าความจริงเกือบ 1 เดือน
 * เมื่ออยู่ต้นเดือน (4 ส.ค. = ขาดไป 27 วัน)
 */
final class AnnualServiceProjectionCurrentMonthTest extends TestCase
{
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

    /** ⭐ ต้นเดือน ส.ค. → ยังเหลืออีก 27 จาก 31 วันของเดือนนี้ที่ต้องนับ */
    public function testRemainderOfTheCurrentMonthCountsTowardTheProjection(): void
    {
        // 3 เดือนล่าสุดกำไรเดือนละ 2000 เท่ากันหมด → avg = 2000
        $months = $this->monthRows([[6, 2000.0, 30], [7, 2000.0, 31], [8, 2000.0, 31]]);

        $projection = $this->makeService()
            ->calculateYearEndProjection($months, 6000.0, 8, true, 2026, '2026-08-04');

        // เดือนเต็มที่เหลือ = ก.ย.–ธ.ค. = 4 เดือน + เศษของ ส.ค. อีก 27/31 เดือน
        $expected = 6000.0 + (4 + 27 / 31) * 2000.0;

        $this->assertSame(round($expected, 2), $projection['projection_mid']);
        $this->assertSame(4, $projection['months_remaining'], 'ตัวเลข "เหลืออีกกี่เดือน" ยังนับเป็นเดือนเต็ม');
        $this->assertEqualsWithDelta(27 / 31, $projection['current_month_remaining_ratio'], 0.0001);
    }

    /** สิ้นเดือนแล้วไม่มีเศษให้บวก */
    public function testNoRemainderOnTheLastDayOfTheMonth(): void
    {
        $months = $this->monthRows([[6, 2000.0, 30], [7, 2000.0, 31], [8, 2000.0, 31]]);

        $projection = $this->makeService()
            ->calculateYearEndProjection($months, 6000.0, 8, true, 2026, '2026-08-31');

        $this->assertSame(6000.0 + 4 * 2000.0, $projection['projection_mid']);
        $this->assertSame(0.0, $projection['current_month_remaining_ratio']);
    }

    /**
     * ธ.ค. เป็นเดือนสุดท้าย — เดิมตอบ year_complete ทันทีทั้งที่ยังเหลือวันในเดือนอยู่
     * ทำให้การ์ดประมาณการหายไปทั้งเดือนสุดท้ายของปี
     */
    public function testDecemberStillProjectsTheRestOfTheMonth(): void
    {
        $months = $this->monthRows([[10, 2000.0, 31], [11, 2000.0, 30], [12, 2000.0, 31]]);

        $projection = $this->makeService()
            ->calculateYearEndProjection($months, 20000.0, 12, true, 2026, '2026-12-10');

        $this->assertTrue($projection['available'], 'ต้นเดือน ธ.ค. ยังควรประมาณการได้');
        $this->assertSame(0, $projection['months_remaining']);
        $this->assertEqualsWithDelta(21 / 31, $projection['current_month_remaining_ratio'], 0.0001);
        $this->assertSame(round(20000.0 + (21 / 31) * 2000.0, 2), $projection['projection_mid']);
    }

    /** วันสุดท้ายของปีถึงจะเรียกว่าจบปีจริง */
    public function testYearIsOnlyCompleteOnTheFinalDay(): void
    {
        $months = $this->monthRows([[10, 2000.0, 31], [11, 2000.0, 30], [12, 2000.0, 31]]);

        $projection = $this->makeService()
            ->calculateYearEndProjection($months, 20000.0, 12, true, 2026, '2026-12-31');

        $this->assertFalse($projection['available']);
        $this->assertSame('year_complete', $projection['reason']);
    }

    /** ไม่ส่ง $today = ใช้วันนี้จริง ต้องไม่พังและต้องอยู่ในช่วงที่เป็นไปได้ */
    public function testWithoutTodayTheRatioStaysWithinRange(): void
    {
        $months = $this->monthRows([[6, 2000.0, 30], [7, 2000.0, 31], [8, 2000.0, 31]]);

        $projection = $this->makeService()->calculateYearEndProjection($months, 6000.0, 8, true, 2026);

        $this->assertGreaterThanOrEqual(0.0, $projection['current_month_remaining_ratio']);
        $this->assertLessThan(1.0, $projection['current_month_remaining_ratio']);
    }
}
