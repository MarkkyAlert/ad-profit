<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ด่าน "หมดเวลาแล้วต้องเข้าสู่ระบบใหม่" — กลไกที่เดิมไม่มีเทสต์แตะเลยสักตัว
 *
 * มี 3 ทางที่ผู้ใช้ควรถูกเตะออก และผลของการพลาดคือคนละเรื่องกันสิ้นเชิง:
 *  1. **ไม่ได้ใช้งานนาน** (14400 วิ) — คอมที่เปิดค้างไว้ที่ร้าน ใครเดินมาก็ใช้ต่อได้
 *  2. **ล็อกอินมานานมาก** (86400 วิ) — แม้ขยับตลอดก็ต้องยืนยันตัวใหม่วันละครั้ง
 *  3. **เปลี่ยนรหัสผ่าน/อีเมลจากอีกเครื่อง** — เครื่องที่ค้างอยู่ต้องหลุดทันที
 *
 * ⚠️ ทั้งสามทางตัดสินที่ `isAuthSessionAlive()` / `isSessionVersionValid()` ซึ่งอยู่ใน
 * `requireAuth()` — ทดสอบได้ทางเดียวคือปลอมอายุ session แล้วยิงคำขอจริง
 */
final class SessionLifetimeTest extends ControllerTestCase
{
    private const IDLE_LIMIT = 14400;
    private const ABSOLUTE_LIMIT = 86400;

    /** ⭐ ไม่ได้ใช้งานเกินเวลาที่กำหนด → ต้องถูกเตะออก ไม่ใช่ใช้ต่อได้ */
    public function testAnIdleSessionIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId, 1, 0, self::IDLE_LIMIT + 60);

        $response = $this->getJson('/api/month-grid.php?month=2026-08', $session);

        $this->assertSame(401, $response['status'], 'คอมที่เปิดค้างไว้ยังใช้งานต่อได้');
        $this->assertStringContainsString('Session expired', $response['body']);
    }

    /** ยังไม่ถึงเวลา → ต้องใช้งานได้ตามปกติ (กันเทสต์ข้างบนผ่านเพราะเหตุอื่น) */
    public function testASessionJustInsideTheIdleLimitStillWorks(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId, 1, 0, self::IDLE_LIMIT - 120);

        $response = $this->getJson('/api/month-grid.php?month=2026-08', $session);

        $this->assertSame(200, $response['status'], 'เตะผู้ใช้ออกทั้งที่ยังไม่หมดเวลา');
    }

    /** ⭐ ล็อกอินมานานเกินเพดาน → ต้องถูกเตะออกแม้จะเพิ่งใช้งานเมื่อกี้ */
    public function testASessionPastTheAbsoluteLimitIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId, 1, self::ABSOLUTE_LIMIT + 60, 0);

        $response = $this->getJson('/api/month-grid.php?month=2026-08', $session);

        $this->assertSame(401, $response['status'], 'ล็อกอินค้างได้ไม่จำกัดตราบใดที่ยังขยับ');
        $this->assertStringContainsString('Session expired', $response['body']);
    }

    /** ⭐ หมดเวลาแล้วเข้าหน้าเว็บ → ต้องเด้งไปหน้าเข้าสู่ระบบพร้อมบอกเหตุผล */
    public function testAnExpiredSessionOnAPageRedirectsWithAnExplanation(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId, 1, 0, self::IDLE_LIMIT + 60);

        $response = $this->get('/dashboard.php', $session);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('login.php', $response['headers']['location'] ?? '');
    }

    /** ⭐ หมดเวลาแล้วต้อง "เขียนข้อมูลไม่ได้" ด้วย ไม่ใช่แค่ดูไม่ได้ */
    public function testAnExpiredSessionCannotWrite(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $fresh = $this->startSession($userId, $shopId);
        $token = $this->csrfTokenFor($fresh);

        $expired = $this->startSession($userId, $shopId, 1, 0, self::IDLE_LIMIT + 60);
        $response = $this->postJson('/api/records.php', [
            'action' => 'upsert',
            'csrf_token' => $token,
            'shop_context_id' => (string)$shopId,
            'record_date' => '2026-08-01',
            'revenue' => '5000',
            'ad_cost' => '1000',
        ], $expired);

        $this->assertSame(401, $response['status']);
        $this->assertSame(0, $this->countRows('daily_records'), 'session ที่หมดอายุยังบันทึกข้อมูลได้');
    }

    /**
     * ⭐ เปลี่ยนรหัสผ่านจากอีกเครื่อง → เครื่องที่ค้างอยู่ต้องหลุดทันที
     *
     * `session_version` ในไฟล์ session ไม่ตรงกับใน DB = ถือว่าหมดอายุ
     */
    public function testASessionFromBeforeAPasswordChangeIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $oldDevice = $this->startSession($userId, $shopId, 1);

        // อีกเครื่องเปลี่ยนรหัสผ่าน → session_version ใน DB ขยับ
        $this->pdo->exec("UPDATE users SET session_version = session_version + 1 WHERE id = {$userId}");

        $response = $this->getJson('/api/month-grid.php?month=2026-08', $oldDevice);

        $this->assertSame(401, $response['status'], 'เครื่องเก่ายังใช้งานต่อได้หลังเปลี่ยนรหัสผ่าน');
        $this->assertStringContainsString('Session expired', $response['body']);
    }

    /** ⭐ คนที่ล็อกอินอยู่แล้วเปิดหน้าเข้าสู่ระบบ → พาไปแดชบอร์ด ไม่ใช่ให้ล็อกอินซ้ำ */
    public function testALoggedInUserIsSentAwayFromTheLoginPage(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/login.php', $session);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('dashboard.php', $response['headers']['location'] ?? '');
    }

    /** หมดเวลาแล้วเปิดหน้าเข้าสู่ระบบ → ต้องเห็นฟอร์ม ไม่ใช่วนกลับแดชบอร์ด */
    public function testAnExpiredSessionCanStillSeeTheLoginForm(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId, 1, 0, self::IDLE_LIMIT + 60);

        $response = $this->get('/login.php', $session);

        $this->assertSame(200, $response['status'], 'คนที่หมดเวลาแล้วเข้าหน้าเข้าสู่ระบบไม่ได้');
        $this->assertStringContainsString('csrf_token', $response['body']);
    }
}
