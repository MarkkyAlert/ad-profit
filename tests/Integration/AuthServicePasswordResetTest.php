<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/StubEmailService.php';

use AuthService;
use PasswordResetRepository;
use ShopRepository;
use UserRepository;

/**
 * ตาข่ายของ requestPasswordReset / resetPassword — ก่อนหน้านี้ไม่มีเทสต์เลยแม้แต่เคสเดียว
 * (มีแค่ฝั่ง repository ใน PasswordResetRepositoryTest)
 *
 * ⚠️ ต้องส่ง `StubEmailService` เข้าไป ไม่ใช่ null — `requestPasswordReset()` ปฏิเสธ
 * ตั้งแต่ต้นเมื่อระบบอีเมลยังไม่พร้อม (ดูเหตุผลใน `StubEmailService`) การส่ง null
 * จะทำให้ทุกเทสต์ในไฟล์นี้ไปตกทางที่ถูกปฏิเสธ แทนที่จะทดสอบตรรกะ token ตามชื่อ
 */
final class AuthServicePasswordResetTest extends IntegrationTestCase
{
    private const IP = '203.0.113.44';

    protected function setUp(): void
    {
        parent::setUp();

        if ((string)ini_get('session.save_path') === '') {
            ini_set('session.save_path', sys_get_temp_dir());
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start(['use_cookies' => '0', 'cache_limiter' => '']);
        }

        $_SESSION = [];
        $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');
    }

    private function makeService(): AuthService
    {
        return new AuthService(
            $this->pdo,
            new UserRepository($this->pdo),
            new ShopRepository($this->pdo),
            new PasswordResetRepository($this->pdo),
            new StubEmailService()
        );
    }

    /** ขอรีเซ็ตด้วยอีเมลที่มีจริง → ออก token 1 ใบ */
    public function testRequestCreatesATokenForAKnownEmail(): void
    {
        $this->createUser('owner@example.com');

        $result = $this->makeService()->requestPasswordReset('owner@example.com', self::IP);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->countRows('password_reset_tokens'));
    }

    /** อีเมลที่ไม่มีในระบบต้องได้ข้อความเดียวกัน และต้องไม่สร้าง token */
    public function testUnknownEmailLooksIdenticalButCreatesNothing(): void
    {
        $this->createUser('owner@example.com');
        $service = $this->makeService();

        $known = $service->requestPasswordReset('owner@example.com', self::IP);
        $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');
        $unknown = $service->requestPasswordReset('nobody@example.com', '198.51.100.1');

        $this->assertTrue($unknown['success']);
        $this->assertSame($known['message'], $unknown['message']);
        $this->assertSame(1, $this->countRows('password_reset_tokens')); // มีแค่ของ known
    }

    public function testMalformedEmailIsRejected(): void
    {
        $result = $this->makeService()->requestPasswordReset('ไม่ใช่อีเมล', self::IP);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('password_reset_tokens'));
    }

    /** ขอซ้ำในหน้าต่างเดียวกันต้องถูกจำกัด (password_reset ตั้งเพดานไว้ 1 ครั้ง) */
    public function testSecondRequestInTheSameWindowIsThrottled(): void
    {
        $this->createUser('owner@example.com');
        $service = $this->makeService();

        $service->requestPasswordReset('owner@example.com', self::IP);
        $second = $service->requestPasswordReset('owner@example.com', self::IP);

        $this->assertFalse($second['success']);
        $this->assertStringContainsString('บ่อยเกินไป', $second['error']);
    }

    /**
     * เส้นทางหลัก: ขอ token → ใช้ token → รหัสผ่านเปลี่ยน · token ถูกลบ · session อื่นถูกเตะ
     */
    public function testResetChangesPasswordAndInvalidatesTokenAndSessions(): void
    {
        $userId = $this->createUser('owner@example.com', 'old-password');
        $userRepository = new UserRepository($this->pdo);
        $versionBefore = $userRepository->getSessionVersion($userId);

        $service = $this->makeService();
        $token = $this->issueTokenFor($userId);

        $result = $service->resetPassword($token, 'brand-new-password', 'brand-new-password', self::IP);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));

        $user = $userRepository->findByEmail('owner@example.com');
        $this->assertTrue(password_verify('brand-new-password', (string)$user['password_hash']));
        $this->assertSame(0, $this->countRows('password_reset_tokens'));
        $this->assertGreaterThan($versionBefore, $userRepository->getSessionVersion($userId));
    }

    /** token ใช้แล้วต้องใช้ซ้ำไม่ได้ */
    public function testTokenCannotBeUsedTwice(): void
    {
        $userId = $this->createUser('owner@example.com');
        $service = $this->makeService();
        $token = $this->issueTokenFor($userId);

        $this->assertTrue($service->resetPassword($token, 'first-password-1', 'first-password-1', self::IP)['success']);

        $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');
        $second = $service->resetPassword($token, 'second-password-1', 'second-password-1', self::IP);

        $this->assertFalse($second['success']);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $userId = $this->createUser('owner@example.com');
        $token = $this->issueTokenFor($userId);
        $this->pdo->exec('UPDATE password_reset_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE');

        $result = $this->makeService()->resetPassword($token, 'brand-new-password', 'brand-new-password', self::IP);

        $this->assertFalse($result['success']);
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->createUser('owner@example.com');

        $result = $this->makeService()
            ->resetPassword(bin2hex(random_bytes(32)), 'brand-new-password', 'brand-new-password', self::IP);

        $this->assertFalse($result['success']);
    }

    public function testMismatchedConfirmationIsRejectedAndPasswordUnchanged(): void
    {
        $userId = $this->createUser('owner@example.com', 'old-password');
        $token = $this->issueTokenFor($userId);

        $result = $this->makeService()->resetPassword($token, 'brand-new-password', 'different-password', self::IP);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่ตรงกัน', $result['error']);

        $user = (new UserRepository($this->pdo))->findByEmail('owner@example.com');
        $this->assertTrue(password_verify('old-password', (string)$user['password_hash']));
    }

    public function testTooShortPasswordIsRejected(): void
    {
        $userId = $this->createUser('owner@example.com');
        $token = $this->issueTokenFor($userId);

        $result = $this->makeService()->resetPassword($token, 'sh', 'sh', self::IP);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->countRows('password_reset_tokens')); // token ยังใช้ได้อยู่
    }

    /** ออก token จริงผ่าน repository แล้วคืนค่า token ดิบให้เทสต์ใช้ */
    private function issueTokenFor(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        (new PasswordResetRepository($this->pdo))
            ->createToken($userId, hash('sha256', $token), (int)PASSWORD_RESET_TOKEN_TTL_HOURS);

        return $token;
    }
}
