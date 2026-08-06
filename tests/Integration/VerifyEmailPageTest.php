<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ หน้ายืนยันอีเมลต้องไม่แตะบัญชีของคนที่กดลิงก์ ถ้าลิงก์นั้นเป็นของคนอื่น
 *
 * ⚠️⚠️ ช่องโหว่ที่ทำซ้ำได้จริงก่อนแก้: ลิงก์เป็น GET ธรรมดา ไม่มี CSRF ไม่มีหน้ายืนยัน
 * และโค้ดล้าง `$_SESSION` ทิ้งทันทีที่ยืนยันสำเร็จ **โดยไม่ดูว่าคนที่กดเป็นเจ้าของ
 * ลิงก์หรือเปล่า** → ใครก็ได้ส่งลิงก์ของตัวเองให้เหยื่อกด (ทาง LINE/อีเมล) แล้ว
 *   · เหยื่อถูกเตะออกจากระบบทุกเครื่องทันที ทั้งที่บัญชีตัวเองไม่ได้ถูกแตะเลย
 *   · หน้าจอขึ้นว่า "เปลี่ยนอีเมลเรียบร้อยแล้ว — ต่อไปนี้ให้เข้าสู่ระบบด้วย
 *     <อีเมลของผู้ส่งลิงก์>" ซึ่งเป็นฉากตั้งต้นของการหลอกเอารหัสผ่าน
 *
 * เป็นบั๊กคลาสเดียวกับที่ `reset-password.php` แก้ไปแล้ว (คอมเมนต์ในไฟล์นั้นอธิบายครบ)
 * หน้านี้เขียนทีหลังแล้วพลาดซ้ำ
 */
final class VerifyEmailPageTest extends ControllerTestCase
{
    private function makeToken(int $userId, string $newEmail): string
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$userId, $newEmail, hash('sha256', $token)]);

        return $token;
    }

    public function testALinkForAnotherAccountDoesNotLogTheVisitorOut(): void
    {
        $victimId = $this->createUser('victim@example.com', 'VictimPass123');
        $shopId = $this->createShop($victimId);
        $session = $this->startSession($victimId, $shopId);

        $attackerId = $this->createUser('attacker@evil.test', 'AttackPass123');
        $token = $this->makeToken($attackerId, 'attacker-new@evil.test');

        $this->assertSame(200, $this->get('/dashboard.php', $session)['status'], 'เหยื่อควรใช้งานได้ก่อนกดลิงก์');

        $this->get('/verify-email.php?token=' . $token, $session);

        $this->assertSame(
            200,
            $this->get('/dashboard.php', $session)['status'],
            'เหยื่อถูกเตะออกจากระบบด้วยลิงก์ของคนอื่น'
        );
    }

    /** ⚠️ และต้องไม่เปลี่ยนอีเมลให้ด้วย — ไม่งั้นกลายเป็นเปลี่ยน state ผ่าน GET ของคนอื่น */
    public function testALinkForAnotherAccountChangesNothing(): void
    {
        $victimId = $this->createUser('victim@example.com', 'VictimPass123');
        $shopId = $this->createShop($victimId);
        $session = $this->startSession($victimId, $shopId);

        $attackerId = $this->createUser('attacker@evil.test', 'AttackPass123');
        $token = $this->makeToken($attackerId, 'attacker-new@evil.test');

        $body = (string)$this->get('/verify-email.php?token=' . $token, $session)['body'];

        $this->assertStringNotContainsString(
            'attacker-new@evil.test',
            $body,
            'หน้าจอบอกเหยื่อให้ไปล็อกอินด้วยอีเมลของผู้ส่งลิงก์'
        );
        $this->assertSame(
            'attacker@evil.test',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$attackerId}")->fetchColumn(),
            'ยืนยันให้บัญชีอื่นผ่านการกดของคนที่ไม่ใช่เจ้าของ'
        );
        $this->assertSame(
            'victim@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$victimId}")->fetchColumn()
        );
    }

    /** ✅ เจ้าของลิงก์ตัวจริงที่ยังไม่ได้ล็อกอิน (กดจากมือถือ) ต้องยืนยันได้ตามปกติ */
    public function testTheRealOwnerCanStillConfirmWithoutLoggingIn(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $token = $this->makeToken($userId, 'moved@example.com');

        $this->get('/verify-email.php?token=' . $token);

        $this->assertSame(
            'moved@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn(),
            'เจ้าของลิงก์ยืนยันไม่ได้ — ตัวกันเข้มเกินจนใช้งานจริงไม่ได้'
        );
    }

    /** ✅ เจ้าของที่ล็อกอินอยู่ก็ยืนยันได้ และถูกเตะออกทุกเครื่องตามกติกา */
    public function testTheRealOwnerCanConfirmWhileSignedInAndIsThenSignedOut(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $token = $this->makeToken($userId, 'moved@example.com');

        $this->get('/verify-email.php?token=' . $token, $session);

        $this->assertSame(
            'moved@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn()
        );
        $this->assertSame(
            302,
            $this->get('/dashboard.php', $session)['status'],
            'เปลี่ยนอีเมลแล้วต้องถูกเตะออกทุกเครื่อง — อีเมลคือช่องทางกู้บัญชี'
        );
    }
}
