<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * หน้าเว็บทุกหน้า — ชั้นที่เดิมไม่มีเทสต์เลย ทั้งที่ business logic หลุดมาอยู่ตรงนี้เยอะ
 *
 * เน้น 2 เรื่องที่พลาดแล้วเจ็บที่สุด:
 *  1. **หน้าห้ามโกหก** — โหลดข้อมูลไม่สำเร็จต้องไม่แสดงตัวเลข ฿0 ราวกับเป็นข้อมูลจริง
 *     เดิมทุกหน้าเรนเดอร์ค่าตั้งต้น 0 ต่อ ผู้ใช้จึงเห็น "ไม่มีสิทธิ์เข้าถึงร้านค้านี้"
 *     คู่กับ "ทั้งปีทำได้ ฿0 · เดือนกำไรดีสุด ม.ค." ในหน้าเดียวกัน
 *  2. **หน้าต้องเปิดขึ้นจริง** — ตัวแปรใน `includes/header.php` อยู่ scope เดียวกับเพจ
 *     การพลาดตรงนั้นทำให้ทั้งหน้าพัง โดยที่ unit test ทุกตัวยังเขียว
 */
final class PageRenderTest extends ControllerTestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function pageProvider(): array
    {
        return [
            'แดชบอร์ด' => ['/dashboard.php'],
            'บันทึกข้อมูล' => ['/add-record.php'],
            'ประวัติรายการ' => ['/history.php'],
            'สรุปประจำปี' => ['/annual.php'],
            'รวมทุกร้าน' => ['/overview.php'],
            'จัดการร้าน' => ['/shops.php'],
            'โปรไฟล์' => ['/profile.php'],
        ];
    }

    /** ⭐ ทุกหน้าต้องเปิดขึ้นได้โดยไม่มี error/warning หลุดออกมาบนจอ */
    #[DataProvider('pageProvider')]
    public function testEveryPageRendersCleanly(string $path): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, 'โน้ต');
        $session = $this->startSession($userId, $shopId);

        $response = $this->get($path, $session);

        $this->assertSame(200, $response['status'], $path . ' เปิดไม่ขึ้น');
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $leak) {
            $this->assertStringNotContainsString($leak, $response['body'], $path . ' มี ' . $leak . ' หลุดบนหน้า');
        }
    }

    /** ⭐ ทุกหน้าต้องกันคนที่ยังไม่ได้ล็อกอิน */
    #[DataProvider('pageProvider')]
    public function testEveryPageRequiresLogin(string $path): void
    {
        $response = $this->get($path);

        $this->assertSame(302, $response['status'], $path . ' เปิดได้โดยไม่ต้องล็อกอิน');
        $this->assertStringContainsString('login.php', $response['headers']['location'] ?? '');
    }

    /**
     * ⭐ โหลดข้อมูลไม่สำเร็จ → ห้ามแสดงตัวเลขปลอม และห้ามชวนให้ "เริ่มบันทึกข้อมูล"
     *
     * จำลองด้วยการลบร้านทั้งหมดของผู้ใช้ทิ้ง (สภาพข้อมูลที่ผิดปกติ) — Service จะตอบว่า
     * ไม่มีสิทธิ์ ส่วนหน้าเว็บต้องบอกแค่นั้น ไม่ใช่กรอกช่องว่างด้วยศูนย์ให้ดูเหมือนมีข้อมูล
     *
     * @return array<string,array{0:string}>
     */
    public static function dataPageProvider(): array
    {
        return [
            'แดชบอร์ด' => ['/dashboard.php'],
            'ประวัติรายการ' => ['/history.php'],
            'สรุปประจำปี' => ['/annual.php'],
        ];
    }

    #[DataProvider('dataPageProvider')]
    public function testAFailedLoadNeverShowsFabricatedNumbers(string $path): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $this->pdo->exec("DELETE FROM shops WHERE user_id = {$userId}");

        $response = $this->get($path, $session);
        $body = $response['body'];

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $body, $path . ' ไม่บอกผู้ใช้ว่าโหลดไม่สำเร็จ');
        $this->assertStringNotContainsString('฿0', $body, $path . ' แสดง ฿0 ราวกับเป็นข้อมูลจริง');
        $this->assertStringNotContainsString(
            'ลองเริ่มบันทึกข้อมูล',
            $body,
            $path . ' ชวนให้เริ่มบันทึกข้อมูล ทั้งที่ปัญหาคือเข้าถึงร้านไม่ได้'
        );
    }

    /** ⭐ ทุกหน้าที่แสดงข้อมูลต้องแสดงตัวเลขจริงเมื่อโหลดสำเร็จ (กันเทสต์ข้างบนผ่านแบบว่าง ๆ) */
    public function testTheHappyPathStillShowsRealNumbers(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, null);
        $session = $this->startSession($userId, $shopId);

        $body = $this->get('/history.php?month=2026-08', $session)['body'];

        $this->assertStringContainsString('5,000', $body, 'ยอดขายจริงไม่ปรากฏบนหน้าประวัติ');
        $this->assertStringNotContainsString('ไม่มีสิทธิ์', $body);
    }

    /**
     * ⭐ `?month=` / `?year=` ที่เป็นอนาคต ต้องถูกหดกลับมาเสมอ ไม่ใช่โชว์ช่วงที่ยังไม่เกิด
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function futureRangeProvider(): array
    {
        return [
            'แดชบอร์ด' => ['/dashboard.php?month=2099-12', '2099-12'],
            'ประวัติรายการ' => ['/history.php?month=2099-12', '2099-12'],
            'รวมทุกร้าน' => ['/overview.php?month=2099-12', '2099-12'],
            'สรุปประจำปี' => ['/annual.php?year=2099', '2099'],
        ];
    }

    #[DataProvider('futureRangeProvider')]
    public function testFutureRangesAreClampedOnEveryPage(string $path, string $future): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createShop($userId, 'ร้านที่สอง');   // หน้ารวมร้านต้องมี ≥ 2 ร้าน
        $session = $this->startSession($userId, $shopId);

        $response = $this->get($path, $session);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString(
            'value="' . $future . '" selected',
            $response['body'],
            $path . ' ยอมให้เลือกช่วงเวลาในอนาคต'
        );
    }
}
