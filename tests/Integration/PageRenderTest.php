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

    /**
     * ⭐ ทุกหน้าต้องเปิดขึ้นได้โดยไม่มี error/warning หลุดออกมาบนจอ
     *
     * ⚠️ ต้อง `strip_tags()` ก่อนตรวจ — PHP ห่อข้อความไว้เป็น HTML (`<b>Warning</b>:`)
     * การหาสตริง `"Warning:"` ตรง ๆ จึงไม่มีวันเจอ เทสต์เคยเขียวทั้งที่หน้ามีคำเตือนเต็มไปหมด
     * (พิสูจน์แล้วด้วยการใส่ตัวแปรที่ไม่มีจริงลงไปในหน้า)
     *
     * ⚠️ ต้องรันเซิร์ฟเวอร์ด้วย APP_ENV=development ด้วย ไม่งั้น `display_errors` ปิดอยู่
     * และไม่มีอะไรถูกพิมพ์ออกมาให้ตรวจตั้งแต่แรก — ดู ControllerTestCase
     */
    #[DataProvider('pageProvider')]
    public function testEveryPageRendersCleanly(string $path): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, 'โน้ต');
        $session = $this->startSession($userId, $shopId);

        $response = $this->get($path, $session);
        $plainText = strip_tags($response['body']);

        $this->assertSame(200, $response['status'], $path . ' เปิดไม่ขึ้น');
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught', 'Undefined variable'] as $leak) {
            $this->assertStringNotContainsString($leak, $plainText, $path . ' มี "' . $leak . '" หลุดบนหน้า');
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
     * ค่าที่ 2 = คำที่ต้องมีบนหน้า · หน้ารวมร้านบอกด้วยคำของตัวเอง ("ไม่สามารถแสดงข้อมูล
     * ได้ในขณะนี้") เพราะมันไม่ผูกกับร้านเดียว จึงไม่ใช้คำว่าสิทธิ์
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function dataPageProvider(): array
    {
        return [
            'แดชบอร์ด' => ['/dashboard.php', 'ไม่มีสิทธิ์'],
            'ประวัติรายการ' => ['/history.php', 'ไม่มีสิทธิ์'],
            'สรุปประจำปี' => ['/annual.php', 'ไม่มีสิทธิ์'],
            // หน้ารวมร้านซ่อนเนื้อหาผ่าน `can_view` อยู่แล้ว แต่ถ้าไม่ล็อกไว้ วันหนึ่งที่มี
            // service คืน payload บางส่วนตอนล้มเหลว หน้านี้จะกลับไปโชว์ ฿0 เงียบ ๆ
            // ⚠️ หน้ารวมร้านไม่พูดเรื่อง "สิทธิ์" เพราะไม่ผูกกับร้านเดียว — ไม่มีร้านให้เทียบ
            // ก็บอกตรง ๆ ว่าต้องมีกี่ร้าน · ค่าของแถวนี้อยู่ที่ "ไม่มี ฿0 และไม่มีคำชวน"
            'รวมทุกร้าน' => ['/overview.php', 'ต้องมีอย่างน้อย 2 ร้าน'],
        ];
    }

    #[DataProvider('dataPageProvider')]
    public function testAFailedLoadNeverShowsFabricatedNumbers(string $path, string $expectedNotice): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $this->pdo->exec("DELETE FROM shops WHERE user_id = {$userId}");

        $response = $this->get($path, $session);
        $body = $response['body'];

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString($expectedNotice, $body, $path . ' ไม่บอกผู้ใช้ว่าโหลดไม่สำเร็จ');
        $this->assertStringNotContainsString('฿0', $body, $path . ' แสดง ฿0 ราวกับเป็นข้อมูลจริง');

        foreach (['ลองเริ่มบันทึกข้อมูล', 'แนะนำให้เริ่มบันทึกข้อมูล', 'เริ่มบันทึกวันแรก'] as $invitation) {
            $this->assertStringNotContainsString(
                $invitation,
                $body,
                $path . ' ชวนให้เริ่มบันทึกข้อมูล ทั้งที่ปัญหาคือโหลดข้อมูลไม่ได้'
            );
        }
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

    /** ⭐ History ต้องเปิดเดือนอนาคตได้เมื่อมีรายการจริง เพื่อให้แก้ไขและลบข้อมูลล่วงหน้าได้ */
    public function testHistoryShowsRecordsInASelectedFutureMonth(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $futureMonth = (new \DateTimeImmutable('first day of next month'))->format('Y-m');
        $this->createRecord($shopId, $futureMonth . '-01', 9876.0, 123.0, null);
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/history.php?month=' . $futureMonth, $session);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('value="' . $futureMonth . '"', $response['body']);
        $this->assertStringContainsString('9,876', $response['body']);
    }

    /**
     * ⭐⭐ ข้อความ "ยังไม่มีข้อมูล" ห้ามเรียกเดือนที่ผู้ใช้เลือกว่า "เดือนนี้"
     *
     * ⚠️ ผู้ใช้เปิดดูเดือนไหนก็ได้ · เปิด `?month=2025-03` แล้วขึ้นว่า
     * "ยังไม่มีข้อมูลใน**เดือนนี้**" ทั้งที่ตัวเลือกเดือนบนจอเดียวกันโชว์ มี.ค. 2568
     * ผู้ใช้อ่านแล้วเข้าใจว่าเป็นเดือนปัจจุบัน — ต้องเรียกชื่อเดือนที่กำลังดูจริง
     *
     * เป็นรูปแบบเดิมที่เคยแก้ไปแล้ว 5 จุด (แดชบอร์ด/รายปี/รวมร้าน) แต่หน้านี้ตกสำรวจ
     */
    public function testTheEmptyMonthMessageNamesTheMonthOnScreen(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/history.php?month=2025-03', $session);
        $body = (string)$response['body'];

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString(
            'ยังไม่มีข้อมูลในเดือนนี้',
            $body,
            'เรียกเดือนที่ผู้ใช้เลือกว่า "เดือนนี้" ทั้งที่ตัวเลือกเดือนบนจอโชว์เดือนอื่น'
        );
        $this->assertStringContainsString(
            'มี.ค. 2568',
            $body,
            'ต้องเรียกชื่อเดือนที่กำลังดูจริง'
        );
    }

    /**
     * ⭐ `?month=` / `?year=` ที่เป็นอนาคต ต้องถูกหดกลับมาเสมอ ไม่ใช่โชว์ช่วงที่ยังไม่เกิด
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function futureRangeProvider(): array
    {
        $thisMonth = date('Y-m');
        $thisYear = date('Y');

        return [
            'แดชบอร์ด' => ['/dashboard.php?month=2099-12', '2099-12', $thisMonth],
            'ประวัติรายการ' => ['/history.php?month=2099-12', '2099-12', $thisMonth],
            'รวมทุกร้าน' => ['/overview.php?month=2099-12', '2099-12', $thisMonth],
            'สรุปประจำปี' => ['/annual.php?year=2099', '2099', $thisYear],
        ];
    }

    /**
     * ⚠️ ห้ามยืนยันด้วย `value="…" selected` — ตัวเลือกเดือนของ 3 หน้าเป็น
     * `<input type="month">` ไม่ใช่ `<select>` คำว่า selected จึงไม่มีวันปรากฏ
     * แถวพวกนั้นเคยเขียวโดยไม่ได้ตรวจอะไรเลย (มีแค่หน้ารายปีที่เป็น select จริง)
     *
     * ตอนนี้ยืนยันสองด้าน: ค่าอนาคตต้องไม่อยู่ในหน้า **และ** ค่าที่หดแล้วต้องอยู่
     */
    #[DataProvider('futureRangeProvider')]
    public function testFutureRangesAreClampedOnEveryPage(string $path, string $future, string $clamped): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createShop($userId, 'ร้านที่สอง');   // หน้ารวมร้านต้องมี ≥ 2 ร้าน
        $session = $this->startSession($userId, $shopId);

        $response = $this->get($path, $session);

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString(
            'value="' . $future . '"',
            $response['body'],
            $path . ' ยอมให้เลือกช่วงเวลาในอนาคต'
        );
        $this->assertStringContainsString(
            'value="' . $clamped . '"',
            $response['body'],
            $path . ' ไม่ได้หดกลับมาเป็นช่วงปัจจุบัน'
        );
    }

    /**
     * ⭐⭐ เดือนเดียวกันต้องไม่โผล่เป็นทั้ง "เดือนกำไรดีสุด" และ "เดือนกำไรแย่สุด"
     *
     * ⚠️ วัดจริงก่อนแก้ — ร้านที่เริ่มกรอกเดือนก่อน (มีเดือนที่จบแล้วเดือนเดียว):
     *   เดือนกำไรดีสุด   ก.ค. (฿31,000)   ← เขียว
     *   เดือนกำไรแย่สุด  ก.ค. (฿31,000)   ← เทา
     * เกิดกับ **ทุกร้านในช่วง 1–2 เดือนแรกที่ใช้ระบบ**
     *
     * ตัวกันแบบนี้มีอยู่แล้วสำหรับการ์ดคู่ "วันดีสุด/แย่สุด" — ระดับเดือนตกสำรวจ
     */
    public function testOneFinishedMonthIsNotShownAsBothBestAndWorst(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        // เดือนก่อนหน้าเดือนปัจจุบัน = เดือนเดียวที่จบแล้ว
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        foreach ([1, 2, 3] as $day) {
            $this->createRecord($shopId, sprintf('%s-%02d', $lastMonth, $day), 10000.0, 3000.0);
        }

        $body = (string)$this->get('/annual.php', $session)['body'];
        $text = preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '';

        $this->assertStringContainsString(
            'ยังเทียบไม่ได้',
            $text,
            'มีเดือนที่จบแล้วเดือนเดียว แต่ยังโชว์เป็นทั้งดีสุดและแย่สุด'
        );
    }

    /** ⚠️ ปีที่จบไปแล้วไม่มี "เดือนที่ยังไม่จบ" ให้รอ — ข้อความต้องไม่ขัดกับแถบ "ไม่มีข้อมูล" */
    public function testAFinishedYearWithNoDataDoesNotSayWaitForTheMonthToEnd(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $pastYear = (int)date('Y') - 2;
        $body = (string)$this->get('/annual.php?year=' . $pastYear, $session)['body'];
        $text = preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '';

        $this->assertStringNotContainsString(
            'รอให้จบเดือนก่อน',
            $text,
            'ปีที่จบไปแล้วยังบอกให้รอเดือนจบ ทั้งที่จอเดียวกันบอกว่าปีนั้นไม่มีข้อมูล'
        );
    }

    /**
     * ⭐ หน้าแรก (`/`) พาไปถูกที่ทั้งตอนล็อกอินอยู่และไม่ได้ล็อกอิน
     *
     * ⚠️ `index.php` ไม่เคยมีเทสต์คุมเลย (coverage gap จาก logic review 2026-08-07)
     * มันเป็นประตูแรกที่ทุกคนเจอ และตัดสินทางแยกจาก `$_SESSION['user_id']` ตรง ๆ
     */
    public function testTheHomePageSendsAVisitorToLogin(): void
    {
        $response = $this->get('/index.php');

        $this->assertSame(302, $response['status'], 'หน้าแรกไม่ได้พาไปไหนเลย');
        $this->assertStringContainsString(
            'login.php',
            (string)($response['headers']['location'] ?? ''),
            'คนที่ยังไม่ล็อกอินต้องถูกพาไปหน้าเข้าสู่ระบบ'
        );
    }

    /** ล็อกอินแล้วต้องเข้าแดชบอร์ดเลย ไม่ใช่ให้ล็อกอินซ้ำ */
    public function testTheHomePageSendsALoggedInUserToTheDashboard(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/index.php', $session);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString(
            'dashboard.php',
            (string)($response['headers']['location'] ?? ''),
            'คนที่ล็อกอินแล้วถูกพากลับไปหน้าเข้าสู่ระบบอีก'
        );
    }
}
