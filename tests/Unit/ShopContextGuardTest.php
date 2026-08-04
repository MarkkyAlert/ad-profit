<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ฟอร์มต้องบอกได้ว่าถูกเรนเดอร์ให้ร้านไหน
 *
 * ทุก endpoint ที่เขียนข้อมูลอ่านรหัสร้านจาก session ซึ่งเปลี่ยนได้จากอีกแท็บ —
 * เปิดหน้าบันทึกของร้าน A ค้างไว้ สลับไปร้าน B แล้วกลับมากดบันทึก ข้อมูลจะลงร้าน B
 * และถ้าร้าน B มีวันนั้นอยู่แล้ว ตัวเลขจริงถูกเขียนทับ พร้อมข้อความสีเขียวว่าสำเร็จ
 */
final class ShopContextGuardTest extends TestCase
{
    public function testFieldCarriesTheShopIdTheFormWasRenderedFor(): void
    {
        $this->assertSame(
            '<input type="hidden" name="shop_context_id" value="7">',
            shop_context_field(7)
        );
    }

    /** ค่าที่ไม่ใช่ตัวเลขต้องไม่หลุดออกไปเป็น HTML */
    public function testFieldAlwaysEmitsAnInteger(): void
    {
        $this->assertStringContainsString('value="0"', shop_context_field(0));
        $this->assertStringNotContainsString('<script', shop_context_field(-1));
    }

    /**
     * ⭐ กับดักที่เคยทำให้ guard นี้ยิงผิดทุกครั้ง
     *
     * `includes/header.php` ถูก include กลางหน้า ตัวแปรจึงอยู่ scope เดียวกับเพจ
     * ลูปเลือกร้านเคยใช้ชื่อ `$shopId` ทับค่าของเพจด้วย "ร้านสุดท้ายในรายการ"
     * ทำให้ฟอร์มทุกอันบอกร้านผิด แล้ว guard ปฏิเสธการบันทึกทุกครั้ง
     */
    public function testHeaderDoesNotReuseThePageLevelShopIdVariable(): void
    {
        $header = (string)file_get_contents(dirname(__DIR__, 2) . '/includes/header.php');

        $this->assertStringNotContainsString(
            '$shopId =',
            $header,
            'header.php กำหนดค่าให้ $shopId ทับตัวแปรของเพจที่ include มัน'
        );
    }

    /** ทุกฟอร์มที่เขียนข้อมูลต้องพก shop_context_field ไปด้วย */
    public function testEveryWriteFormCarriesTheShopContextField(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['add-record.php' => 3, 'history.php' => 2, 'dashboard.php' => 2] as $page => $expected) {
            $source = (string)file_get_contents($root . '/' . $page);

            $this->assertSame(
                substr_count($source, 'csrf_field()'),
                substr_count($source, 'shop_context_field('),
                $page . ': จำนวนฟอร์มกับจำนวน shop_context_field ไม่เท่ากัน'
            );
            $this->assertSame($expected, substr_count($source, 'shop_context_field('), $page);
        }
    }
}
