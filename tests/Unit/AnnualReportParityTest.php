<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * รายงานประจำปีบนจอกับในไฟล์ Excel ต้องเล่าเรื่องเดียวกัน
 *
 * ทั้งสองที่เคยคัดลอกข้อความ/สูตรของกันและกันไว้ พร้อมคอมเมนต์ "แก้ต้องแก้คู่" —
 * แล้วก็เพี้ยนกันจริง:
 *  · หน้าเว็บเขียน "ไม่คิดฤดูกาล/การเปิดรอบ" ส่วนไฟล์ Excel เขียน "ไม่คิดฤดูกาล"
 *  · หน้าเว็บนับ "เท่าทุน" (เดือนที่กำไร 0 พอดี) แต่ไฟล์ Excel ไม่นับ ผู้ใช้บวกเลข
 *    กำไร+ขาดทุนแล้วไม่ครบตามจำนวนเดือนที่มีข้อมูล
 *
 * เทสต์นี้ล็อกที่ helper กลาง เพื่อให้ "คัดลอกอีกครั้ง" ทำไม่ได้เงียบ ๆ
 */
final class AnnualReportParityTest extends TestCase
{
    /** ⭐ ป้าย "เหลืออีก" ต้องบอกทั้งเดือนเต็มและเศษของเดือนนี้ */
    public function testRemainingLabelCountsThisMonthsLeftoverDays(): void
    {
        $this->assertSame(
            '4 เดือน + อีก 27 วันของเดือนนี้',
            projection_remaining_label(['months_remaining' => 4, 'current_month_remaining_days' => 27])
        );
    }

    /** สิ้นเดือนพอดี → ไม่ต้องมีท่อนเศษวัน */
    public function testRemainingLabelOmitsLeftoverDaysWhenThereAreNone(): void
    {
        $this->assertSame(
            '4 เดือน',
            projection_remaining_label(['months_remaining' => 4, 'current_month_remaining_days' => 0])
        );
    }

    /** ⭐ คำอธิบายใต้ตัวเลขมีครบทั้งช่วงที่เหลือ ฐานที่ใช้ และคำเตือน */
    public function testFootnoteMentionsRangeBasisAndCaveat(): void
    {
        $footnote = projection_footnote_text([
            'months_remaining' => 4,
            'current_month_remaining_days' => 27,
            'basis_month_count' => 3,
        ]);

        $this->assertStringContainsString('4 เดือน + อีก 27 วันของเดือนนี้', $footnote);
        $this->assertStringContainsString('3 เดือนล่าสุด', $footnote);
        $this->assertStringContainsString('ไม่คิดฤดูกาล/การเปิดรอบ', $footnote);
        $this->assertStringContainsString('ไม่ใช่ตัวเลขที่เกิดขึ้นจริง', $footnote);
    }

    /** ⭐ เดือนกำไร + ขาดทุน + เท่าทุน ต้องรวมได้เท่าจำนวนเดือนที่มีข้อมูลเสมอ */
    public function testMonthBucketsAlwaysAddUp(): void
    {
        $counts = annual_month_outcome_counts([
            'months_with_data' => 7,
            'profit_months' => 4,
            'loss_months' => 2,
        ]);

        $this->assertSame(1, $counts['break_even'], 'เดือนที่กำไร 0 พอดีหายไปจากผลรวม');
        $this->assertSame(
            $counts['with_data'],
            $counts['profit'] + $counts['loss'] + $counts['break_even']
        );
    }

    /** ตัวเลขที่ขัดกันเอง (นับเกิน) ต้องไม่ทำให้ "เท่าทุน" ติดลบ */
    public function testBreakEvenNeverGoesNegative(): void
    {
        $counts = annual_month_outcome_counts([
            'months_with_data' => 2,
            'profit_months' => 3,
            'loss_months' => 1,
        ]);

        $this->assertSame(0, $counts['break_even']);
    }

    /** ปีที่ยังไม่มีข้อมูลเลย → ทุกช่องเป็น 0 ไม่ใช่ค่าประหลาด */
    /**
     * ⚠️⚠️ **"ศูนย์" ตรงนี้คือสัญญาของ helper ไม่ใช่สิ่งที่หน้าเว็บควรพิมพ์ออกไป**
     *
     * ชื่อเทสต์อ่านเผิน ๆ เหมือนบอกว่า "ปีว่างต้องรายงานศูนย์" ซึ่งจริงเฉพาะที่ชั้นนี้ —
     * ตัวนับต้องคืนตัวเลขให้เอาไปคำนวณต่อได้ ไม่ใช่ null
     *
     * แต่ **หน้าเว็บห้ามพิมพ์ ฿0 ให้ร้านที่ยังไม่เคยกรอกอะไรเลย** เพราะ 0 อ่านว่า
     * "ทำมาแล้วได้ศูนย์" ไม่ใช่ "ยังไม่ได้เริ่ม" · กติกานั้นถูกล็อกไว้ที่
     * `Tests\Integration\EmptyShopHonestyTest` — ถ้าจะแก้ตรงนี้ให้อ่านตัวนั้นก่อน
     */
    public function testAnEmptyYearReportsZeros(): void
    {
        $this->assertSame(
            ['with_data' => 0, 'profit' => 0, 'loss' => 0, 'break_even' => 0],
            annual_month_outcome_counts([])
        );
    }
}
