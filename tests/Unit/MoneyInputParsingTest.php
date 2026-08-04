<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ตัวแปลง "ข้อความ → จำนวนเงิน" ต้องอ่านเหมือนกันทุกทาง และห้ามเดาเมื่อกำกวม
 *
 * ประวัติของจุดนี้:
 *  - เดิมลบจุลภาคทิ้งเสมอ → ไฟล์ Excel ยุโรป `1234,56` กลายเป็น 123,456 (100 เท่า)
 *  - แก้ให้ CSV เดาตัวคั่นเอง → `1.234` กลายเป็น 1,234 (1000 เท่า) และค่าผิดรูปอย่าง
 *    `1.2.3` ถูกยอมรับเป็น 12.30 · ส่วนฟอร์มปกติยังใช้ตัวเก่าอยู่ (ไม่ตรงกัน 2 มาตรฐาน)
 *
 * กติกาตอนนี้ — เหมือนที่ระบบทำกับ "วันที่กำกวม": อ่านได้หลายแบบ = ปฏิเสธ อย่าเดา
 */
final class MoneyInputParsingTest extends TestCase
{
    /** อ่านค่าผ่านเส้นทางไฟล์ CSV จริง — คืน null เมื่อไฟล์ถูกปฏิเสธ */
    private function csvParse(string $raw): ?string
    {
        $service = new RecordService(
            $this->createStub(RecordRepository::class),
            $this->createStub(ShopRepository::class),
            null
        );

        $result = $service->parseImportCsv("date,revenue,ad_cost\n2026-08-01,\"{$raw}\",100\n");

        return ($result['success'] ?? false) === true
            ? (string)($result['rows'][0]['revenue'] ?? '')
            : null;
    }

    /**
     * @return array<string,array{0:string,1:float}>
     */
    public static function validAmountProvider(): array
    {
        return [
            'จำนวนเต็ม' => ['1234', 1234.0],
            'จุดเป็นทศนิยม' => ['1234.56', 1234.56],
            'จุลภาคเป็นทศนิยม' => ['1234,56', 1234.56],
            'ทศนิยมตำแหน่งเดียว' => ['1234,5', 1234.5],
            'อังกฤษเต็มรูปแบบ' => ['1,234.56', 1234.56],
            'ยุโรปเต็มรูปแบบ' => ['1.234,56', 1234.56],
            'อังกฤษหลายกลุ่ม' => ['1,234,567.89', 1234567.89],
            'ยุโรปหลายกลุ่ม' => ['1.234.567,89', 1234567.89],
            'เว้นวรรคคั่นหลักพัน' => ['1 234,56', 1234.56],
            'มีสัญลักษณ์เงิน' => ['฿1,234.56', 1234.56],
            'ศูนย์' => ['0', 0.0],
            'ศูนย์ทศนิยม' => ['0.00', 0.0],
            'ทศนิยมล้วน' => ['0.56', 0.56],
        ];
    }

    #[DataProvider('validAmountProvider')]
    public function testValidAmountsAreReadTheSameWayEverywhere(string $typed, float $expected): void
    {
        $viaForm = parse_decimal_input($typed, false);

        $this->assertTrue($viaForm['valid'], "ฟอร์มปฏิเสธค่าที่ถูกต้อง: {$typed}");
        $this->assertSame($expected, $viaForm['value'], "ฟอร์มอ่าน {$typed} ผิด");
        $viaCsv = $this->csvParse($typed);
        $this->assertNotNull($viaCsv, "CSV ปฏิเสธค่าที่ถูกต้อง: {$typed}");
        $this->assertSame($expected, (float)$viaCsv, "CSV อ่าน {$typed} ไม่ตรงกับฟอร์ม");
    }

    /**
     * ⭐ ค่าที่อ่านได้หลายแบบหรือผิดรูป ต้องถูกปฏิเสธ ไม่ใช่เดา
     *
     * @return array<string,array{0:string}>
     */
    public static function rejectedAmountProvider(): array
    {
        return [
            'กำกวม: จุด + 3 หลัก' => ['1.234'],
            'กำกวม: จุลภาค + 3 หลัก' => ['1,234'],
            'ทศนิยม 3 ตำแหน่ง' => ['12.3456'],
            'จุดซ้ำติดกัน' => ['1..2'],
            'ตัวคั่นปนกันมั่ว' => ['1.2.3'],
            'ขึ้นต้นด้วยตัวคั่น' => ['.5.5'],
            'สัญกรณ์วิทยาศาสตร์' => ['1e3'],
            'ลบสองตัว' => ['--5'],
            'ไม่ใช่ตัวเลข' => ['abc'],
            'ตัวเลขปนตัวอักษร' => ['12abc'],
        ];
    }

    #[DataProvider('rejectedAmountProvider')]
    public function testAmbiguousOrMalformedAmountsAreRejectedEverywhere(string $typed): void
    {
        $viaForm = parse_decimal_input($typed, false);
        $this->assertFalse($viaForm['valid'], "ฟอร์มยอมรับค่าที่ควรปฏิเสธ: {$typed}");

        $this->assertNull(
            $this->csvParse($typed),
            "CSV ยอมรับค่าที่ควรปฏิเสธ: {$typed}"
        );
    }

    /** ช่องว่างยังทำงานตามเดิม */
    public function testEmptyStringHonoursTheAllowEmptyFlag(): void
    {
        $this->assertFalse(parse_decimal_input('', false)['valid']);
        $this->assertTrue(parse_decimal_input('', true)['valid']);
        $this->assertNull(parse_decimal_input('', true)['value']);
    }

    /** ค่าติดลบอ่านได้ (ชั้นบนเป็นคนปฏิเสธว่าเงินติดลบไม่ได้) */
    public function testNegativeAmountsAreReadThenRejectedByTheBusinessRule(): void
    {
        $parsed = parse_decimal_input('-1234,56', false);

        $this->assertTrue($parsed['valid']);
        $this->assertSame(-1234.56, $parsed['value']);
    }
}
