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

    /** ⭐ เปิดลิงก์ของบัญชีอื่นขณะล็อกอินอยู่ → ต้องเตือนว่าคนละบัญชีกัน */
    public function testTheResetPageWarnsWhenTheLinkBelongsToAnotherAccount(): void
    {
        $victimId = $this->createUser('victim@example.com', 'OldPass123');
        $attackerId = $this->createUser('attacker@example.com', 'OtherPass123');
        $shopId = $this->createShop($victimId);
        $token = $this->issueResetToken($attackerId);

        $session = $this->startSession($victimId, $shopId);
        $response = $this->get('/reset-password.php?token=' . rawurlencode($token), $session);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('attacker@example.com', $response['body'], 'ไม่บอกว่าลิงก์เป็นของใคร');
    }
}
