<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ **แถบแจ้งผลทุกแถบต้องอ่านได้และปิดเองได้ — แม้โผล่พร้อมกันสองแถบ**
 *
 * ⚠️⚠️ ทั้งข้อความสำเร็จและข้อความผิดพลาดเคยใช้ `id="app-toast"` เหมือนกัน และวางทับกัน
 * ที่มุมเดียวกัน · สคริปต์ใช้ `getElementById` ซึ่งคืน **ตัวแรกตัวเดียว**:
 *   · แถบที่สองไม่มีตัวจับเวลา → ค้างบนจอตลอด
 *   · แตะปิดไม่ได้
 *   · และวาดทับตัวแรกจนอ่านไม่เห็น
 *
 * ⚠️ เกิดได้จริง ไม่ใช่กรณีสมมติ: บันทึกข้อมูลสำเร็จแล้วเด้งกลับมา ระหว่างนั้นร้านที่เปิด
 * ค้างไว้ถูกลบจากอีกเครื่อง → `resolve_current_shop_id()` เติมข้อความเตือนเข้ามาอีกอัน
 * ผู้ใช้จึงมีทั้งข้อความสำเร็จและข้อความเตือนในหน้าเดียว
 */
final class ToastFeedbackTest extends ControllerTestCase
{
    /** ทุกแถบต้องมี `data-app-toast` (สคริปต์กวาดจากตัวนี้) ไม่ใช่ id ซ้ำ */
    private function toastsIn(string $html): array
    {
        preg_match_all('/<div\s+data-app-toast[^>]*>/i', $html, $matched);

        return $matched[0];
    }

    /**
     * ⭐⭐⭐ ข้อความสำเร็จกับข้อความเตือนโผล่พร้อมกัน — ต้องได้ทั้งคู่ครบเครื่อง
     *
     * สร้างสถานะจริง: บันทึกรายการสำเร็จ (ได้ข้อความสำเร็จติดมากับ redirect)
     * แล้วลบร้านที่ session ชี้อยู่ทิ้งจาก "อีกเครื่อง" ก่อนเปิดหน้าถัดไป
     */
    public function testBothMessagesSurviveWhenTheyAppearTogether(): void
    {
        $userId = $this->createUser('toast@example.com', 'ToastPass12345');
        $shopA = $this->createShop($userId, 'ร้านที่จะถูกลบ');
        $this->createShop($userId, 'ร้านสำรอง');
        $session = $this->startSession($userId, $shopA);

        $this->post('/api/records.php', [
            'action' => 'upsert', 'csrf_token' => $this->csrfTokenFor($session),
            'shop_context_id' => (string)$shopA, 'record_date' => '2026-06-01',
            'revenue' => '1000', 'ad_cost' => '200', 'note' => '',
        ], $session);

        // "อีกเครื่อง" ลบร้านที่ session ยังชี้อยู่ → หน้าถัดไปจะมีข้อความเตือนเพิ่มมาอีกอัน
        $this->pdo->exec('DELETE FROM shops WHERE id = ' . $shopA);

        $body = (string)$this->get('/dashboard.php', $session)['body'];
        $toasts = $this->toastsIn($body);

        $this->assertCount(
            2,
            $toasts,
            'ควรมีแถบแจ้งผล 2 แถบ (สำเร็จ + เตือน) แต่ได้ ' . count($toasts) . ' — ได้ HTML: '
            . mb_substr((string)preg_replace('/\s+/u', ' ', strip_tags($body)), 0, 200)
        );

        /* ⚠️ หัวใจของบั๊ก: ถ้ายังใช้ id ซ้ำ สคริปต์จะเห็นแค่ตัวแรก
           ตรวจว่า **ไม่มี id ซ้ำ** โดยตรง เพราะนั่นคือสิ่งที่ทำให้ตัวที่สองตายไป */
        $this->assertSame(
            0,
            substr_count($body, 'id="app-toast"'),
            'ยังมี id="app-toast" อยู่ — สคริปต์จะจัดการได้แค่แถบแรก แถบที่สองจะค้างบนจอ'
        );

        // แต่ละแถบต้องบอกชนิดของตัวเอง (ตัวกำหนดว่าอยู่นานแค่ไหนและดึงโฟกัสไหม)
        $this->assertStringContainsString('data-toast-kind="success"', $body, 'แถบสำเร็จไม่มีชนิดกำกับ');
        $this->assertStringContainsString('data-toast-kind="error"', $body, 'แถบเตือนไม่มีชนิดกำกับ');
        $this->assertStringContainsString('role="status"', $body, 'แถบสำเร็จไม่มี role ให้โปรแกรมอ่านหน้าจอ');
        $this->assertStringContainsString('role="alert"', $body, 'แถบเตือนไม่มี role ให้โปรแกรมอ่านหน้าจอ');
    }

    /**
     * ⭐⭐ หน้าเข้าสู่ระบบไม่ได้ใช้ header/footer ร่วม — ต้องใช้กติกาเดียวกันเอง
     *
     * ⚠️ หน้านี้คือที่ที่ข้อความผิดพลาดสำคัญที่สุด ("อีเมลหรือรหัสผ่านไม่ถูกต้อง" ·
     * "ลองเข้าสู่ระบบบ่อยเกินไป กรุณารอ 1 นาที") — คนที่พลาดข้อความจะกดซ้ำ
     * แล้วไปชนตัวจำกัดจำนวนครั้งพอดี
     */
    public function testTheLoginPageUsesTheSameFeedbackRules(): void
    {
        $this->createUser('logintoast@example.com', 'LoginToast1234');
        $guest = $this->startSession(0, 0);
        $page = (string)$this->get('/login.php', $guest)['body'];
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page, $matched);

        $this->post('/api/auth.php', [
            'action' => 'login', 'csrf_token' => $matched[1] ?? '',
            'email' => 'logintoast@example.com', 'password' => 'WrongPassword99',
        ], $guest);

        $body = (string)$this->get('/login.php', $guest)['body'];

        $this->assertSame(0, substr_count($body, 'id="app-toast"'), 'หน้าเข้าสู่ระบบยังใช้ id ซ้ำ');
        $this->assertStringContainsString(
            'data-toast-kind="error"',
            $body,
            'แถบผิดพลาดของหน้าเข้าสู่ระบบไม่มีชนิดกำกับ — จะหายเร็วกว่าหน้าอื่นของระบบ'
        );
        $this->assertStringContainsString(
            'role="alert"',
            $body,
            'แถบผิดพลาดของหน้าเข้าสู่ระบบไม่มี role="alert" — โปรแกรมอ่านหน้าจอไม่ประกาศให้'
        );
    }

    /**
     * ⚠️ ด้านตรงข้าม — หน้าที่ไม่มีข้อความอะไรต้องไม่มีแถบโผล่มาเปล่า ๆ
     * (การ "แก้" ที่วาดกล่องไว้ตลอดจะบังเนื้อหาและกินพื้นที่บนมือถือ)
     */
    public function testAQuietPageShowsNoToastAtAll(): void
    {
        $userId = $this->createUser('quiet@example.com', 'QuietPass12345');
        $shopId = $this->createShop($userId, 'ร้านเงียบ');
        $body = (string)$this->get('/dashboard.php', $this->startSession($userId, $shopId))['body'];

        $this->assertSame([], $this->toastsIn($body), 'หน้าที่ไม่มีข้อความอะไร กลับมีแถบแจ้งผลโผล่มา');
    }
}
