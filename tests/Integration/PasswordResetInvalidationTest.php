<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use PasswordResetRepository;
use ProfileService;
use ShopRepository;
use UserRepository;

/**
 * ลิงก์รีเซ็ตรหัสผ่านที่ค้างอยู่ ต้องใช้ไม่ได้ทันทีที่เจ้าของบัญชีเปลี่ยน credential เอง
 *
 * สถานการณ์จริง: ผู้ใช้ได้อีเมล "ลืมรหัสผ่าน" ที่ตัวเองไม่ได้ขอ (มีคนพยายามเข้าบัญชี)
 * จึงรีบไปเปลี่ยนรหัสผ่านเองในหน้าโปรไฟล์ — ถ้าลิงก์เก่ายังใช้ได้ คนที่ถือลิงก์นั้น
 * ยังตั้งรหัสผ่านทับของใหม่ได้ การป้องกันตัวของผู้ใช้จึงไม่มีผลอะไรเลย
 *
 * `AuthService::resetPassword` ลบ token ทิ้งถูกต้องอยู่แล้ว แต่ `ProfileService`
 * ไม่รู้จักตาราง token เลย — คู่แฝดที่ตกหล่น
 */
final class PasswordResetInvalidationTest extends IntegrationTestCase
{
    private const IP = '203.0.113.77';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');
    }

    private function makeAuthService(): AuthService
    {
        return new AuthService(
            $this->pdo,
            new UserRepository($this->pdo),
            new ShopRepository($this->pdo),
            new PasswordResetRepository($this->pdo)
        );
    }

    private function makeProfileService(): ProfileService
    {
        return new ProfileService(
            new UserRepository($this->pdo),
            $this->pdo,
            new PasswordResetRepository($this->pdo)
        );
    }

    /** ออก token ให้ user แล้วคืนค่าดิบที่จะอยู่ในลิงก์อีเมล */
    private function issueToken(int $userId): string
    {
        $raw = bin2hex(random_bytes(32));
        (new PasswordResetRepository($this->pdo))->createToken($userId, hash('sha256', $raw), 1);

        return $raw;
    }

    /** ควบคุม: ไม่แตะโปรไฟล์เลย ลิงก์ต้องใช้ได้ตามปกติ */
    public function testTokenStillWorksWhenNothingElseHappened(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $raw = $this->issueToken($userId);

        $result = $this->makeAuthService()->resetPassword($raw, 'FreshPass456', 'FreshPass456', self::IP);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** ⭐ เปลี่ยนรหัสผ่านเองในหน้าโปรไฟล์ → ลิงก์เก่าต้องตายทันที */
    public function testChangingPasswordInProfileKillsOutstandingResetLinks(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $raw = $this->issueToken($userId);

        $changed = $this->makeProfileService()
            ->changePassword($userId, 'OldPass123', 'BrandNew456', 'BrandNew456');
        $this->assertTrue($changed['success'], (string)($changed['error'] ?? ''));

        $this->assertSame(0, $this->countRows('password_reset_tokens'), 'token เก่ายังค้างอยู่ในตาราง');

        $hijack = $this->makeAuthService()->resetPassword($raw, 'Hijacked789', 'Hijacked789', self::IP);
        $this->assertFalse($hijack['success'], 'ลิงก์เก่ายังใช้เขียนทับรหัสผ่านใหม่ได้');

        $hash = (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();
        $this->assertTrue(password_verify('BrandNew456', $hash), 'รหัสผ่านถูกเขียนทับด้วยลิงก์เก่า');
    }

    /**
     * ⭐ เปลี่ยนอีเมลก็เหมือนกัน — อีเมลคือช่องทางกู้บัญชี
     * (CLAUDE.md ระบุไว้แล้วว่าถือเป็น credential เท่ากับรหัสผ่าน)
     */
    public function testChangingEmailInProfileKillsOutstandingResetLinks(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $raw = $this->issueToken($userId);

        $changed = $this->makeProfileService()->changeEmail($userId, 'new@example.com', 'OldPass123');
        $this->assertTrue($changed['success'], (string)($changed['error'] ?? ''));

        $this->assertSame(0, $this->countRows('password_reset_tokens'));

        $hijack = $this->makeAuthService()->resetPassword($raw, 'Hijacked789', 'Hijacked789', self::IP);
        $this->assertFalse($hijack['success'], 'ลิงก์ที่ส่งไปอีเมลเดิมยังใช้ได้หลังย้ายอีเมล');
    }

    /** เปลี่ยนแค่ชื่อที่แสดง ไม่ใช่ credential → ไม่ต้องไปยุ่งกับ token */
    public function testChangingOnlyTheDisplayNameLeavesTheTokenAlone(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $this->issueToken($userId);

        $result = $this->makeProfileService()->updateProfile($userId, 'ชื่อใหม่');

        // ⚠️ ถ้า updateProfile ล้มเงียบ ๆ จำนวน token ก็ยังเป็น 1 อยู่ดี — ต้องพิสูจน์ว่า
        // "เปลี่ยนชื่อสำเร็จจริง" ก่อน ไม่งั้นเทสต์นี้ผ่านด้วยเหตุผลที่ตรงข้ามกับชื่อของมัน
        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame('ชื่อใหม่', (string)$this->pdo
            ->query("SELECT display_name FROM users WHERE id = {$userId}")->fetchColumn());
        $this->assertSame(1, $this->countRows('password_reset_tokens'));
    }

    /** ProfileService ต้องทำงานได้เหมือนเดิมแม้ไม่ได้ฉีด repository เข้ามา (unit test ส่ง null) */
    public function testProfileServiceStillWorksWithoutTheResetRepository(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');

        $result = (new ProfileService(new UserRepository($this->pdo), $this->pdo))
            ->changePassword($userId, 'OldPass123', 'BrandNew456', 'BrandNew456');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }
}
