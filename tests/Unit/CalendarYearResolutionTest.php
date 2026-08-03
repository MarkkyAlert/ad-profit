<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * resolve_calendar_year() — ตรรกะแปลงปีที่เคยคัดลอกอยู่ 4 ที่ใน controller
 *
 * ย้ายมาเป็น helper เพื่อให้เทสต์ได้จริง (เดิมอยู่ในเพจ/endpoint จึงไม่มีเทสต์เลย
 * และ 1 ใน 4 ที่ทำไม่เหมือนเพื่อน)
 */
final class CalendarYearResolutionTest extends TestCase
{
    public function testKeepsValidChristianYear(): void
    {
        $this->assertSame(2026, resolve_calendar_year('2026'));
        $this->assertSame(2000, resolve_calendar_year('2000'));
        $this->assertSame(2100, resolve_calendar_year('2100'));
    }

    public function testConvertsBuddhistYear(): void
    {
        $this->assertSame(2026, resolve_calendar_year('2569'));
        $this->assertSame(2025, resolve_calendar_year('2568'));
    }

    /** ขอบของช่วง พ.ศ. ที่แปลงแล้วยังอยู่ในช่วงที่ยอมรับ (2000–2100) */
    public function testBuddhistYearsAtTheEdgeOfTheAcceptedRange(): void
    {
        $this->assertSame(2000, resolve_calendar_year('2543'));
        $this->assertSame(2100, resolve_calendar_year('2643'));
    }

    /** อยู่ในช่วงที่ถือว่าเป็น พ.ศ. แต่แปลงแล้วหลุดช่วงที่ยอมรับ → ตกไปปีปัจจุบัน */
    public function testBuddhistYearsThatFallOutsideTheAcceptedRange(): void
    {
        $currentYear = (int)date('Y');

        $this->assertSame($currentYear, resolve_calendar_year('2400')); // 1857
        $this->assertSame($currentYear, resolve_calendar_year('2700')); // 2157
    }

    public function testFallsBackToCurrentYearForGarbage(): void
    {
        $currentYear = (int)date('Y');

        foreach (['', 'abc', '26', '20261', '-2026', '0000', '1999', '2101', 'ปีนี้'] as $bad) {
            $this->assertSame($currentYear, resolve_calendar_year($bad), "'{$bad}' ควรตกไปที่ปีปัจจุบัน");
        }
    }

    public function testUsesExplicitFallbackBeforeCurrentYear(): void
    {
        // overview.php ใช้ปีของเดือนที่เลือกเป็น fallback
        $this->assertSame(2024, resolve_calendar_year('', '2024'));
        $this->assertSame(2024, resolve_calendar_year('ไม่ใช่ปี', '2567'));
    }

    public function testFallbackItselfIsValidatedToo(): void
    {
        $this->assertSame((int)date('Y'), resolve_calendar_year('', 'ก็ไม่ใช่ปี'));
        $this->assertSame((int)date('Y'), resolve_calendar_year('', '1800'));
    }

    public function testAcceptsIntegerInput(): void
    {
        $this->assertSame(2026, resolve_calendar_year(2026));
        $this->assertSame(2026, resolve_calendar_year(2569));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame(2026, resolve_calendar_year('  2026  '));
    }
}
