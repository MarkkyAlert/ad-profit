<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

use PasswordResetRepository;

/**
 * ทางเข้า-ออกของระบบที่ระดับ endpoint/หน้าเว็บ — เดิมมีแต่เทสต์ชั้น Service
 *
 * `api/auth.php` และ `reset-password.php` ทำงานที่ Service ไม่รู้จักเลย:
 *  · พา token กลับไปเมื่อบันทึกไม่ผ่าน (ไม่งั้นพิมพ์รหัสยืนยันผิดครั้งเดียว = ขออีเมลใหม่)
 *  · แสดงว่า "ลิงก์นี้เป็นของอีเมลไหน" และเตือนเมื่อไม่ตรงกับบัญชีที่ล็อกอินอยู่
 *  · ล้าง session หลังรีเซ็ตสำเร็จ
 *
 * ⚠️ ช่องโหว่ที่เคยมีจริง: หน้านี้เคยเก็บ token ลง `$_SESSION` ทำให้ส่งลิงก์ของตัวเอง
 * ให้เหยื่อกด แล้วรหัสที่เหยื่อพิมพ์ไปตกที่บัญชีผู้ส่ง — เทสต์ชุดนี้ล็อกไม่ให้ย้อนกลับไป
 */
final class AuthEndpointTest extends ControllerTestCase
{
    private function issueResetToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        (new PasswordResetRepository($this->pdo))->createToken($userId, hash('sha256', $token), 3600);

        return $token;
    }

    /**
     * ⚠️⚠️ คลาสนี้ต้องรันบนเซิร์ฟเวอร์ที่ **ตั้งค่าอีเมลแล้ว**
     *
     * `requestPasswordReset()` ปฏิเสธตั้งแต่ต้นเมื่อระบบอีเมลยังไม่พร้อม (ก่อนจองโควตา
     * ก่อนสร้าง token) ถ้าปล่อยให้ปิดอยู่ตามค่าปริยาย เทสต์ทุกตัวที่ตรวจเรื่อง token
     * จะไปตกทางที่ถูกปฏิเสธ แล้วเขียวโดยไม่ได้ตรวจสิ่งที่ชื่อเทสต์บอก
     *
     * ชี้ไปโฮสต์ `.invalid` = "ตั้งค่าแล้วแต่ส่งไม่ออก" ซึ่งเป็นสภาพจริงบนเซิร์ฟเวอร์
     * ตอน SMTP ล่มชั่วคราว · token ยังต้องถูกสร้างตามปกติ
     *
     * ส่วนกรณี "ยังไม่ได้ตั้งค่าเลย" อยู่ที่ `EmailSystemNotConfiguredTest`
     * (คลาสนั้นตั้งใจไม่เขียนทับ env เพื่อให้ได้เซิร์ฟเวอร์ที่อีเมลปิดอยู่)
     *
     * @return array<string,string>
     */
    protected static function serverEnvironmentOverrides(): array
    {
        return [
            'MAIL_ENABLED' => 'true',
            'MAIL_HOST' => 'smtp.example.invalid',
            'MAIL_USERNAME' => 'test@example.invalid',
            'MAIL_PASSWORD' => 'not-a-real-password',
            'MAIL_FROM_ADDRESS' => 'no-reply@example.invalid',
            'MAIL_RETRY_ATTEMPTS' => '0',
            'MAIL_TIMEOUT_SECONDS' => '5',
        ];
    }

    /** token ของหน้าเข้าสู่ระบบ (ใช้ส่งฟอร์มที่ยังไม่ได้ล็อกอิน) */
    private function guestCsrf(string $sessionId): string
    {
        $response = $this->get('/login.php', $sessionId);
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $response['body'], $matched) === 1) {
            return $matched[1];
        }

        $this->fail('หา csrf_token ในหน้าเข้าสู่ระบบไม่เจอ');
    }

    /** ⭐ ทุกคำสั่งของ auth ต้องเป็น POST เท่านั้น */
    public function testAuthRejectsGet(): void
    {
        $response = $this->getJson('/api/auth.php?action=login');

        $this->assertSame(405, $response['status']);
        $this->assertSame('POST', $response['headers']['allow'] ?? '');
    }

    /** ⭐ ส่ง body ที่ไม่ใช่ฟอร์ม → 415 */
    public function testAuthRejectsNonFormBodies(): void
    {
        $response = $this->request(
            'POST',
            '/api/auth.php',
            [],
            null,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            '{"action":"login"}'
        );

        $this->assertSame(415, $response['status']);
    }

    /** ⭐ สมัครสมาชิกโดยไม่มี CSRF token ต้องไม่สร้างบัญชี */
    public function testRegisterWithoutCsrfCreatesNoAccount(): void
    {
        $response = $this->postJson('/api/auth.php', [
            'action' => 'register',
            'email' => 'newbie@example.com',
            'password' => 'ValidPass123',
            'password_confirm' => 'ValidPass123',
        ]);

        $this->assertSame(403, $response['status']);
        $this->assertSame(0, $this->countRows('users'), 'สมัครสมาชิกได้โดยไม่มี CSRF token');
    }

    /** ⭐ เข้าสู่ระบบโดยไม่มี CSRF token ต้องไม่สำเร็จ */
    public function testLoginWithoutCsrfIsRejected(): void
    {
        $this->createUser('owner@example.com', 'RealPass123');

        $response = $this->postJson('/api/auth.php', [
            'action' => 'login',
            'email' => 'owner@example.com',
            'password' => 'RealPass123',
        ]);

        $this->assertSame(403, $response['status']);
    }

    /** ⭐ ออกจากระบบต้องผ่าน CSRF ด้วย ไม่งั้นเว็บอื่นเตะผู้ใช้ออกได้ */
    public function testLogoutRequiresCsrf(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->postJson('/api/auth.php', ['action' => 'logout'], $session);

        $this->assertSame(403, $response['status'], 'เว็บอื่นสั่งให้ผู้ใช้ออกจากระบบได้');
    }

    /** ⭐ สมัครสมาชิกสำเร็จต้องได้บัญชี + ร้านเริ่มต้น 1 ร้าน */
    public function testRegisterCreatesAnAccountAndItsFirstShop(): void
    {
        $guest = $this->startSession(0, 0);
        $token = $this->guestCsrf($guest);

        $response = $this->post('/api/auth.php', [
            'action' => 'register',
            'csrf_token' => $token,
            'email' => 'newbie@example.com',
            'password' => 'ValidPass123',
            'password_confirm' => 'ValidPass123',
        ], $guest);

        $this->assertSame(302, $response['status'], (string)$response['body']);
        $this->assertSame(1, $this->countRows('users'));
        $this->assertSame(1, $this->countRows('shops'), 'สมัครแล้วไม่มีร้านให้เริ่มใช้งาน');
    }

    /**
     * ⭐ session ที่เทสต์สร้างเอง ต้องมีคีย์ชุดเดียวกับตอนล็อกอินจริง
     *
     * ⚠️ เทสต์ชั้น controller ทั้งหมดใช้ `startSession()` แทนการกรอกฟอร์มล็อกอิน
     * ถ้าคีย์ไม่ตรงกับที่ `AuthService::establishSession()` เขียนจริง เทสต์ทุกตัว
     * จะรันอยู่บน session ปลอมที่ไม่มีวันเกิดขึ้นจริง — เพิ่มคีย์ใหม่ในระบบแล้ว
     * เทสต์ยังเขียวหมด ทั้งที่ผู้ใช้จริงจะเจอปัญหา
     */
    public function testTheTestSessionHasTheSameShapeAsARealLogin(): void
    {
        $userId = $this->createUser('owner@example.com', 'RealPass123');
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');

        // ⚠️⚠️ ต้องล็อกอินจาก session ที่ **ยังไม่มีคีย์ของการล็อกอินเลย**
        //
        // `session_regenerate_id(true)` ยก `$_SESSION` เดิมข้ามไปไฟล์ใหม่ทั้งก้อน
        // ถ้าเริ่มจาก session ที่เทสต์เขียนคีย์ครบไว้แล้ว ไฟล์หลังล็อกอินจะมีคีย์ครบ
        // เสมอ **ไม่ว่า `establishSession()` จะเขียนอะไรจริง ๆ หรือไม่** — เทสต์เวอร์ชัน
        // แรกจึงเขียวแม้ตัดการเขียน session ของแอปเหลือคีย์เดียว ซึ่งตรงข้ามกับหน้าที่ของมัน
        $guest = $this->startBlankSession();

        // ต้องดูว่า "ไฟล์ไหนเพิ่งเกิดใหม่" — ล็อกอินสำเร็จได้ไฟล์ใหม่เสมอ
        $pattern = sys_get_temp_dir() . '/ad-profit-controller-tests-*/sess_*';
        $before = array_flip((array)glob($pattern));

        $this->post('/api/auth.php', [
            'action' => 'login',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'owner@example.com',
            'password' => 'RealPass123',
        ], $guest);

        $keysOf = static function (string $raw): array {
            preg_match_all('/(?:^|;)([a-z_]+)\|/', $raw, $matched);
            $keys = array_values(array_filter(
                $matched[1],
                static fn(string $key): bool => $key !== 'flash' && $key !== 'csrf_token'
            ));
            sort($keys);

            return $keys;
        };

        $realKeys = [];
        foreach ((array)glob($pattern) as $file) {
            if (isset($before[(string)$file])) {
                continue;
            }

            $raw = (string)file_get_contents((string)$file);
            if (str_contains($raw, 'user_id|i:' . $userId . ';')) {
                $realKeys = $keysOf($raw);
            }
        }
        $this->assertNotSame([], $realKeys, 'ล็อกอินจริงไม่สำเร็จ — เทียบคีย์ไม่ได้');

        $fabricated = $keysOf($this->flashMessages($this->startSession($userId, $shopId)));

        $this->assertSame(
            $realKeys,
            $fabricated,
            'คีย์ของ session ที่เทสต์สร้าง ไม่ตรงกับตอนล็อกอินจริง'
        );

        // และต้องมีคีย์ที่ระบบพึ่งพาจริง ๆ ครบ — กันกรณีที่ทั้งสองฝั่ง "ขาดเหมือนกัน"
        $this->assertSame(
            ['auth_started_at', 'current_shop_id', 'current_shop_name', 'email', 'last_activity_at', 'session_version', 'user_id'],
            $realKeys,
            'การล็อกอินจริงเขียนคีย์ไม่ครบตามที่ระบบใช้'
        );
    }

    /** ⭐ ล็อกอินสำเร็จผ่าน endpoint จริง → ต้องได้ session ที่ใช้งานต่อได้ */
    public function testASuccessfulLoginProducesAWorkingSession(): void
    {
        $userId = $this->createUser('owner@example.com', 'RealPass123');
        $this->createShop($userId, 'ร้านของฉัน');
        $guest = $this->startSession(0, 0);

        $response = $this->post('/api/auth.php', [
            'action' => 'login',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'owner@example.com',
            'password' => 'RealPass123',
        ], $guest);

        $this->assertSame(302, $response['status'], (string)$response['body']);
        $this->assertStringNotContainsString('login.php', $response['headers']['location'] ?? '');

        // ⚠️⚠️ ต้อง **ใช้งานต่อด้วย session ที่การล็อกอินสร้างขึ้นจริง**
        //
        // การดูแค่ปลายทางของ redirect พิสูจน์ได้แค่ว่า "ตรวจรหัสผ่านผ่าน" — ไม่ได้
        // พิสูจน์ว่าสิ่งที่เขียนลง session ใช้งานได้ · เคยพิสูจน์แล้วว่าเปลี่ยน
        // `auth_started_at` เป็น 0 ทำให้ผู้ใช้หลุดทันทีในคำขอถัดไป (นับว่าหมดอายุแล้ว)
        // ทั้งที่เทสต์ทั้ง 933 ตัวยังเขียว เพราะไม่มีใครแตะ session ที่ได้มาเลย
        $newSession = $this->sessionIdFrom($response);
        $this->assertNotSame('', $newSession, 'ล็อกอินแล้วไม่ได้ session ใหม่กลับมา');
        $this->assertNotSame($guest, $newSession, 'ไม่ได้เปลี่ยน session id หลังล็อกอิน');

        $afterLogin = $this->get('/dashboard.php', $newSession);
        $this->assertSame(
            200,
            $afterLogin['status'],
            'ล็อกอินสำเร็จแล้วแต่ใช้งานต่อไม่ได้ — ค่าที่เขียนลง session ใช้ไม่ได้จริง'
        );

        // และต้องยังใช้ได้ในคำขอถัดไปด้วย (ไม่ใช่ผ่านครั้งเดียวแล้วหลุด)
        $this->assertSame(200, $this->get('/add-record.php', $newSession)['status']);
    }

    /** ⭐ รหัสผ่านผิด → ต้องไม่ได้เข้าระบบ และไม่บอกใบ้ว่าอีเมลนี้มีอยู่จริง */
    public function testAWrongPasswordDoesNotLogIn(): void
    {
        $userId = $this->createUser('owner@example.com', 'RealPass123');
        $this->createShop($userId);
        $guest = $this->startSession(0, 0);

        $response = $this->post('/api/auth.php', [
            'action' => 'login',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'owner@example.com',
            'password' => 'ผิดแน่นอน',
        ], $guest);

        $this->assertStringContainsString('login.php', $response['headers']['location'] ?? '');

        // ข้อความต้องเป็นแบบรวม ๆ — ห้ามบอกว่า "อีเมลนี้มีอยู่แต่รหัสผิด"
        $flash = $this->flashMessages($guest);
        $this->assertStringContainsString('อีเมลหรือรหัสผ่านไม่ถูกต้อง', $flash);
        $this->assertStringNotContainsString('ไม่พบอีเมล', $flash);
        $this->assertStringNotContainsString('รหัสผ่านผิด', $flash);
    }

    /** ⭐ ออกจากระบบแล้วต้องเข้าหน้าที่ต้องล็อกอินไม่ได้อีก */
    public function testLogoutEndsTheSession(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->assertSame(200, $this->get('/dashboard.php', $session)['status']);

        $this->post('/api/auth.php', [
            'action' => 'logout',
            'csrf_token' => $this->csrfTokenFor($session),
        ], $session);

        $afterLogout = $this->get('/dashboard.php', $session);
        $this->assertSame(302, $afterLogout['status'], 'ออกจากระบบแล้วยังเข้าแดชบอร์ดได้');
        $this->assertStringContainsString('login.php', $afterLogout['headers']['location'] ?? '');
    }

    /** ⭐ ขอลิงก์รีเซ็ตต้องผ่าน CSRF ด้วย ไม่งั้นเว็บอื่นสั่งส่งอีเมลรัว ๆ ได้ */
    public function testForgotPasswordRequiresCsrf(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');

        $response = $this->postJson('/api/auth.php', [
            'action' => 'forgot_password',
            'email' => 'owner@example.com',
        ]);

        $this->assertSame(403, $response['status']);
        $this->assertSame(0, $this->countRows('password_reset_tokens'), 'สร้างลิงก์ได้โดยไม่มี CSRF token');
    }

    /** ⭐ ขอลิงก์รีเซ็ตของบัญชีที่มีจริง → ต้องได้ token เก็บไว้ในระบบ */
    public function testForgotPasswordCreatesATokenForAKnownAccount(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startSession(0, 0);

        $response = $this->post('/api/auth.php', [
            'action' => 'forgot_password',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'owner@example.com',
        ], $guest);

        $this->assertSame(302, $response['status']);
        $this->assertSame(1, $this->countRows('password_reset_tokens'));
        $this->assertSame(
            $userId,
            (int)$this->pdo->query('SELECT user_id FROM password_reset_tokens LIMIT 1')->fetchColumn()
        );
    }

    /**
     * ⭐ อีเมลที่ไม่มีในระบบต้องตอบเหมือนกันเป๊ะ — ไม่บอกใบ้ว่ามีบัญชีนี้หรือไม่
     *
     * ถ้าตอบต่างกัน คนที่ไล่เดาจะรู้ได้ทันทีว่าอีเมลไหนสมัครไว้แล้ว
     */
    public function testForgotPasswordDoesNotRevealWhetherTheAccountExists(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startSession(0, 0);

        $known = $this->post('/api/auth.php', [
            'action' => 'forgot_password',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'owner@example.com',
        ], $guest);

        $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');

        $unknown = $this->post('/api/auth.php', [
            'action' => 'forgot_password',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'never-signed-up@example.com',
        ], $guest);

        $this->assertSame($known['status'], $unknown['status'], 'ตอบคนละรหัสสถานะ = บอกใบ้ว่ามีบัญชีอยู่จริง');
        $this->assertSame(
            $known['headers']['location'] ?? '',
            $unknown['headers']['location'] ?? '',
            'พาไปคนละหน้า = บอกใบ้ว่ามีบัญชีอยู่จริง'
        );
        $this->assertSame(1, $this->countRows('password_reset_tokens'), 'สร้าง token ให้อีเมลที่ไม่มีในระบบ');
    }

    /**
     * ⭐ ลิงก์รีเซ็ตต้องไม่หลุดออกมาในคำตอบ เว้นแต่เปิดสวิตช์ dev ไว้ชัดเจน
     *
     * เซิร์ฟเวอร์ทดสอบรันด้วย APP_ENV=development แต่ `EXPOSE_DEV_RESET_LINK`
     * เป็น false ตามค่าปริยาย — ถ้าเงื่อนไขนี้พังขึ้นมา ลิงก์รีเซ็ตจะโผล่ให้ใครก็ได้
     * ที่ยิง endpoint นี้เห็น
     */
    public function testTheResetLinkIsNotLeakedInTheResponse(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startSession(0, 0);

        $response = $this->postJson('/api/auth.php', [
            'action' => 'forgot_password',
            'csrf_token' => $this->guestCsrf($guest),
            'email' => 'owner@example.com',
        ], $guest);

        $this->assertStringNotContainsString('reset_link', $response['body']);
        $this->assertStringNotContainsString('reset-password.php?token=', $response['body']);
        $this->assertStringNotContainsString('reset-password.php?token=', $this->flashMessages($guest));
    }

    /**
     * ⭐ ตั้งรหัสใหม่ไม่ผ่าน → ต้องพา token กลับมาให้กรอกใหม่ได้
     *
     * เดิมเด้งไปหน้า "ลืมรหัสผ่าน" พร้อมข้อความ "ลิงก์ไม่ถูกต้อง" (ซึ่งไม่จริง)
     * ผู้ใช้ที่พิมพ์รหัสยืนยันผิดครั้งเดียวจึงต้องขออีเมลใหม่ทั้งใบ
     */
    public function testAFailedResetKeepsTheTokenInTheRedirect(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $token = $this->issueResetToken($userId);
        $guest = $this->startSession(0, 0);

        $response = $this->post('/api/auth.php', [
            'action' => 'reset_password',
            'csrf_token' => $this->guestCsrf($guest),
            'token' => $token,
            'password' => 'BrandNew456',
            'password_confirm' => 'พิมพ์ไม่ตรง',
        ], $guest);

        $location = $response['headers']['location'] ?? '';
        $this->assertStringContainsString('reset-password.php', $location, 'เด้งไปหน้าอื่นแทนที่จะให้กรอกใหม่');
        $this->assertStringContainsString(rawurlencode($token), $location, 'ไม่ได้พา token กลับมา');
        $this->assertSame(1, $this->countRows('password_reset_tokens'), 'ลิงก์ถูกเผาทิ้งทั้งที่ยังไม่ได้ใช้');
    }

    /** CSRF ผิดก็ต้องพา token กลับไปด้วย ไม่ใช่เด้งไปหน้า forgot แล้วเสีย flow */
    public function testInvalidCsrfOnResetKeepsTheTokenInTheRedirect(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $token = $this->issueResetToken($userId);
        $guest = $this->startSession(0, 0);

        $response = $this->post('/api/auth.php', [
            'action' => 'reset_password',
            'csrf_token' => 'invalid-token',
            'token' => $token,
            'password' => 'BrandNew456',
            'password_confirm' => 'BrandNew456',
        ], $guest);

        $location = $response['headers']['location'] ?? '';
        $this->assertSame(302, $response['status'], (string)$response['body']);
        $this->assertStringContainsString('reset-password.php', $location);
        $this->assertStringContainsString(rawurlencode($token), $location);
        $this->assertSame(1, $this->countRows('password_reset_tokens'), 'CSRF ผิดแต่ลิงก์ถูกใช้หรือถูกลบ');
        $this->assertTrue(password_verify('OldPass123', (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn()));
    }

    /** ⭐ ตั้งรหัสใหม่สำเร็จ → รหัสเปลี่ยนจริง และลิงก์ถูกใช้ไปแล้ว */
    public function testASuccessfulResetChangesThePasswordAndBurnsTheToken(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $token = $this->issueResetToken($userId);
        $guest = $this->startSession(0, 0);

        $this->post('/api/auth.php', [
            'action' => 'reset_password',
            'csrf_token' => $this->guestCsrf($guest),
            'token' => $token,
            'password' => 'BrandNew456',
            'password_confirm' => 'BrandNew456',
        ], $guest);

        $hash = (string)$this->pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();
        $this->assertTrue(password_verify('BrandNew456', $hash), 'รหัสผ่านไม่ได้เปลี่ยน');
        $this->assertSame(0, $this->countRows('password_reset_tokens'), 'ลิงก์เดิมยังใช้ซ้ำได้');
    }

    /** ⭐ ใช้ลิงก์เดิมซ้ำครั้งที่สองไม่ได้ */
    public function testAUsedResetLinkCannotBeReused(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $token = $this->issueResetToken($userId);
        $guest = $this->startSession(0, 0);

        $fields = [
            'action' => 'reset_password',
            'csrf_token' => $this->guestCsrf($guest),
            'token' => $token,
            'password' => 'BrandNew456',
            'password_confirm' => 'BrandNew456',
        ];
        $this->post('/api/auth.php', $fields, $guest);

        $second = $this->post('/api/auth.php', array_merge($fields, [
            'csrf_token' => $this->guestCsrf($guest),
            'password' => 'ThirdPass789',
            'password_confirm' => 'ThirdPass789',
        ]), $guest);

        $this->assertStringNotContainsString('reset-password.php?token', $second['headers']['location'] ?? '');
        $this->assertTrue(password_verify('BrandNew456', (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn()));
    }

    /**
     * ⭐ หน้าตั้งรหัสใหม่ต้องบอกว่าลิงก์นี้เป็นของอีเมลไหน และมี token ในฟอร์ม
     *
     * นี่คือส่วนที่ปิดช่องโหว่ "ส่งลิงก์ของตัวเองให้เหยื่อกด" — ถ้าหน้าไม่บอกอีเมล
     * เหยื่อไม่มีทางรู้ว่ากำลังตั้งรหัสให้บัญชีใคร
     */
    public function testTheResetPageNamesTheAccountAndCarriesTheTokenInTheForm(): void
    {
        $userId = $this->createUser('victim@example.com', 'OldPass123');
        $token = $this->issueResetToken($userId);

        $response = $this->get('/reset-password.php?token=' . rawurlencode($token));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('victim@example.com', $response['body'], 'ไม่บอกว่าลิงก์เป็นของบัญชีไหน');
        $this->assertStringContainsString(
            'name="token" value="' . $token . '"',
            $response['body'],
            'token ไม่ได้อยู่ในฟอร์ม (แปลว่าไปอยู่ที่อื่น เช่น session)'
        );
    }

    /** ⭐ ลิงก์ปลอม/หมดอายุ → เด้งไปหน้าขอลิงก์ใหม่ ไม่ใช่แสดงฟอร์มลอย ๆ */
    public function testAnInvalidResetLinkDoesNotShowTheForm(): void
    {
        $response = $this->get('/reset-password.php?token=' . str_repeat('a', 64));

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('forgot-password.php', $response['headers']['location'] ?? '');
    }

    /**
     * ⭐ เปิดลิงก์ของบัญชีอื่นขณะล็อกอินอยู่ → ต้องเตือนว่าคนละบัญชีกัน
     *
     * ⚠️ ห้ามยืนยันด้วย "อีเมลของลิงก์ปรากฏบนหน้า" อย่างเดียว — กล่องนั้นแสดงเสมอ
     * ทุกกรณีอยู่แล้ว การเตือนจริง ๆ คือกล่องสีเหลืองที่บอก **อีเมลที่ล็อกอินอยู่**
     * และคำว่า "ไม่ใช่บัญชีเดียวกับลิงก์นี้" ซึ่งเป็นสิ่งเดียวที่ทำให้เหยื่อรู้ตัว
     */
    public function testTheResetPageWarnsWhenTheLinkBelongsToAnotherAccount(): void
    {
        $victimId = $this->createUser('victim@example.com', 'OldPass123');
        $attackerId = $this->createUser('attacker@example.com', 'OtherPass123');
        $shopId = $this->createShop($victimId);
        $token = $this->issueResetToken($attackerId);

        $session = $this->startSession($victimId, $shopId);
        $response = $this->get('/reset-password.php?token=' . rawurlencode($token), $session);
        $body = $response['body'];

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('attacker@example.com', $body, 'ไม่บอกว่าลิงก์เป็นของใคร');
        $this->assertStringContainsString(
            'ไม่ใช่บัญชีเดียวกับลิงก์นี้',
            $body,
            'ไม่มีคำเตือนว่ากำลังจะตั้งรหัสให้บัญชีของคนอื่น'
        );
        $this->assertStringContainsString(
            'victim@example.com',
            $body,
            'ไม่ได้บอกว่าตอนนี้ล็อกอินด้วยบัญชีไหน — เหยื่อไม่มีทางเทียบได้'
        );
        $this->assertStringContainsString('อย่ากรอกรหัสผ่านของคุณลงไป', $body);
    }

    /** ⭐ ลิงก์ของบัญชีตัวเอง → ต้องไม่มีคำเตือน (ไม่งั้นเตือนจนคนไม่กล้าใช้ของตัวเอง) */
    public function testTheResetPageDoesNotWarnForYourOwnLink(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $token = $this->issueResetToken($userId);

        $session = $this->startSession($userId, $shopId);
        $body = $this->get('/reset-password.php?token=' . rawurlencode($token), $session)['body'];

        $this->assertStringContainsString('owner@example.com', $body);
        $this->assertStringNotContainsString(
            'ไม่ใช่บัญชีเดียวกับลิงก์นี้',
            $body,
            'เตือนทั้งที่เป็นลิงก์ของบัญชีตัวเอง'
        );
    }

    /** ⭐ ยังไม่ได้ล็อกอินแล้วเปิดลิงก์ → ไม่ต้องเตือน แต่ต้องบอกว่าลิงก์เป็นของบัญชีไหน */
    public function testAnAnonymousVisitorSeesTheOwnerButNoWarning(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $token = $this->issueResetToken($userId);

        $body = $this->get('/reset-password.php?token=' . rawurlencode($token))['body'];

        $this->assertStringContainsString('owner@example.com', $body);
        $this->assertStringNotContainsString('ไม่ใช่บัญชีเดียวกับลิงก์นี้', $body);
    }
}
