<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * resolve_calendar_month() — cutoff เดือนอนาคตของหน้ารายเดือน/รายวัน
 *
 * ฝั่งรายปีมี cutoff อยู่แล้ว (AnnualService/OverviewAnnualService) แต่ฝั่งเดือนไม่มีเลย
 * เลือกเดือนหน้าแล้วเห็นหน้าเต็มที่เป็น ฿0 พร้อม "เทียบเดือนก่อน −100%" ทุกการ์ด
 */
final class CalendarMonthResolutionTest extends TestCase
{
    private const TODAY = '2026-08-04';

    public function testKeepsValidPastMonth(): void
    {
        $this->assertSame('2026-07', resolve_calendar_month('2026-07', null, self::TODAY));
        $this->assertSame('2020-01', resolve_calendar_month('2020-01', null, self::TODAY));
    }

    public function testKeepsCurrentMonth(): void
    {
        $this->assertSame('2026-08', resolve_calendar_month('2026-08', null, self::TODAY));
    }

    /** เดือนอนาคตถูกดึงกลับมาเป็นเดือนปัจจุบัน */
    public function testFutureMonthIsPulledBackToCurrent(): void
    {
        $this->assertSame('2026-08', resolve_calendar_month('2026-09', null, self::TODAY));
        $this->assertSame('2026-08', resolve_calendar_month('2030-05', null, self::TODAY));
    }

    public function testFallsBackToCurrentMonthForGarbage(): void
    {
        foreach (['', '2026-13', '2026-00', '2026-8', 'abc', '2026/08', '202608'] as $bad) {
            $this->assertSame('2026-08', resolve_calendar_month($bad, null, self::TODAY), "'{$bad}'");
        }
    }

    public function testUsesExplicitFallbackBeforeCurrentMonth(): void
    {
        $this->assertSame('2026-03', resolve_calendar_month('', '2026-03', self::TODAY));
    }

    /** fallback ก็ต้องผ่านกฎเดียวกัน — รวมถึงห้ามเป็นเดือนอนาคต */
    public function testFallbackIsValidatedAndCappedToo(): void
    {
        $this->assertSame('2026-08', resolve_calendar_month('', 'ไม่ใช่เดือน', self::TODAY));
        $this->assertSame('2026-08', resolve_calendar_month('', '2027-01', self::TODAY));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('2026-07', resolve_calendar_month('  2026-07  ', null, self::TODAY));
    }

    /** ไม่ส่ง $today → ใช้วันนี้จริง และต้องไม่คืนเดือนอนาคตไม่ว่ากรณีใด */
    public function testNeverReturnsAFutureMonthWithoutTodaySeam(): void
    {
        $this->assertLessThanOrEqual(date('Y-m'), resolve_calendar_month('2099-12'));
    }
}
