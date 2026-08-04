<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ป้าย % การเปลี่ยนแปลง — ห้ามบอกทิศทางขัดกับตัวเลขที่แสดง
 *
 * เดิมมี 2 เกณฑ์ปนกันในระบบเดียว: หน้ารายปีถือ 0% เป็นกลาง แต่ history/dashboard
 * ใช้ `>= 0` จึงขึ้น "↑ 0.0%" สีเขียวทั้งที่ยอดเท่าเดิมเป๊ะ
 */
final class ChangeBadgeTest extends TestCase
{
    /** ⭐ เท่าเดิม = ไม่มีลูกศร ไม่มีสี */
    public function testZeroIsNeutralNotGreenUp(): void
    {
        $badge = format_change_badge(0.0);

        $this->assertSame('0.0%', $badge['text']);
        $this->assertSame('text-slate-400', $badge['class']);
        $this->assertSame(0, $badge['direction']);
    }

    /**
     * ค่าที่ปัดแล้วเป็น 0.0% ต้องเป็นกลางด้วย — ไม่งั้นเห็น "↓ 0.0%" สีแดง
     * ซึ่งลูกศรกับตัวเลขขัดกันเองในป้ายเดียว
     */
    public function testValuesThatRoundToZeroAreAlsoNeutral(): void
    {
        foreach ([-0.04, 0.04, -0.001, 0.001] as $value) {
            $badge = format_change_badge($value);

            $this->assertSame('0.0%', $badge['text'], "ค่า {$value}");
            $this->assertSame('text-slate-400', $badge['class'], "ค่า {$value}");
        }
    }

    public function testPositiveIsGreenWithUpArrow(): void
    {
        $badge = format_change_badge(12.34);

        $this->assertSame('↑ 12.3%', $badge['text']);
        $this->assertSame('text-green-400', $badge['class']);
        $this->assertSame(1, $badge['direction']);
    }

    public function testNegativeIsRedWithDownArrowAndNoMinusSign(): void
    {
        $badge = format_change_badge(-8.25);

        // เครื่องหมายลบซ้ำกับลูกศรลง — แสดงค่าสัมบูรณ์
        $this->assertSame('↓ 8.3%', $badge['text']);
        $this->assertSame('text-red-400', $badge['class']);
        $this->assertSame(-1, $badge['direction']);
    }

    /** null = ไม่มีฐานให้เทียบ ต้องไม่กลายเป็น 0% */
    public function testNullKeepsItsOwnPlaceholder(): void
    {
        $this->assertSame('–', format_change_badge(null)['text']);
        $this->assertSame('ใหม่', format_change_badge(null, 'ใหม่')['text']);
        $this->assertSame('text-slate-400', format_change_badge(null)['class']);
    }
}
