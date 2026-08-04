<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use PasswordResetRepository;
use ShopRepository;
use UserRepository;

/**
 * ตาข่ายของการสมัครสมาชิก — เดิมมีเทสต์เดียว (happy path) ทั้งที่มี 6 สาขาที่ตอบ error
 *
 * ประเด็นหลัก: การสมัครมีตัวจำกัดจำนวนครั้งแค่ bucket เดียวที่ผูกกับ (IP + อีเมล)
 * กุญแจจึงเปลี่ยนทุกครั้งที่เปลี่ยนอีเมลที่ลอง เครื่องเดียวยิงทดสอบได้ไม่จำกัด
 * ว่าอีเมลไหนมีในระบบแล้วบ้าง — ฝั่งล็อกอินมี bucket ต่อ IP กันไว้แล้ว แต่ฝั่งสมัครไม่มี
 */
final class AuthServiceRegisterTest extends IntegrationTestCase
{
    private const IP = '203.0.113.55';

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
            new PasswordResetRepository($this->pdo)
        );
    }

    /** ⭐ เครื่องเดียวไล่ทดสอบอีเมลไปเรื่อย ๆ ต้องถูกหยุด ไม่ใช่ยิงได้ไม่จำกัด */
    public function testOneAddressCannotProbeUnlimitedEmails(): void
    {
        $this->createUser('taken@example.com');
        $service = $this->makeService();

        $blockedAt = null;
        for ($attempt = 1; $attempt <= RATE_LIMIT_MAX_ATTEMPTS + 3; $attempt++) {
            // เปลี่ยนอีเมลทุกครั้ง — bucket ต่อ (IP + อีเมล) จับไม่ได้
            $result = $service->register("probe{$attempt}@example.com", 'short', 'short', self::IP);

            if (str_contains((string)($result['error'] ?? ''), 'บ่อยเกินไป')) {
                $blockedAt = $attempt;
                break;
            }
        }

        $this->assertNotNull($blockedAt, 'ยิงสมัครด้วยอีเมลใหม่ได้ไม่จำกัดจากเครื่องเดียว');
        $this->assertLessThanOrEqual(RATE_LIMIT_MAX_ATTEMPTS + 1, $blockedAt);
    }

    /** คนละ IP ต้องไม่โดนหางเลข */
    public function testAnotherAddressIsNotAffected(): void
    {
        $service = $this->makeService();

        for ($attempt = 1; $attempt <= RATE_LIMIT_MAX_ATTEMPTS + 2; $attempt++) {
            $service->register("probe{$attempt}@example.com", 'short', 'short', self::IP);
        }

        $result = $service->register('fresh@example.com', 'ValidPass123', 'ValidPass123', '198.51.100.9');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** อีเมลที่มีอยู่แล้วต้องไม่บอกตรง ๆ ว่ามีอยู่ */
    public function testDuplicateEmailDoesNotRevealThatTheAccountExists(): void
    {
        $this->createUser('taken@example.com');

        $taken = $this->makeService()->register('taken@example.com', 'ValidPass123', 'ValidPass123', self::IP);
        $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');
        $invalid = $this->makeService()->register('free@example.com', 'ValidPass123', 'nomatch12345', self::IP);

        // ⚠️ "ข้อความไม่ว่าง" พิสูจน์อะไรไม่ได้ — ทุกเคสที่ล้มก็คืนข้อความไทยที่ไม่ว่าง
        // สิ่งที่ต้องพิสูจน์คือ อีเมลที่มีคนใช้แล้วต้องไม่บอกใบ้ว่ามีบัญชีนั้นอยู่จริง
        $this->assertFalse($taken['success']);
        $this->assertFalse($invalid['success']);
        $this->assertStringNotContainsString('มีอยู่แล้ว', (string)$taken['error']);
        $this->assertStringNotContainsString('ซ้ำ', (string)$taken['error']);
        $this->assertStringNotContainsString('taken@example.com', (string)$taken['error']);

        // และต้องไม่มีบัญชีใหม่เกิดขึ้นจากความพยายามทั้งสองครั้ง
        $this->assertSame(1, $this->countRows('users'), 'สมัครซ้ำแล้วได้บัญชีเพิ่ม');
    }

    /** สมัครสำเร็จต้องได้ผู้ใช้ + ร้านเริ่มต้น 1 ร้าน */
    public function testSuccessfulRegisterCreatesUserAndDefaultShop(): void
    {
        $result = $this->makeService()->register('new@example.com', 'ValidPass123', 'ValidPass123', self::IP);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(1, $this->countRows('users'));
        $this->assertSame(1, $this->countRows('shops'));
    }

    /** สมัครสำเร็จไม่ล้างประวัติการไล่ทดสอบของ IP นั้น (เกณฑ์เดียวกับฝั่งล็อกอิน) */
    public function testSuccessfulRegisterDoesNotWipeTheAddressHistory(): void
    {
        $service = $this->makeService();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $service->register("probe{$attempt}@example.com", 'short', 'short', self::IP);
        }
        $before = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM auth_rate_limits WHERE action_type = 'register_ip'")->fetchColumn();

        $service->register('new@example.com', 'ValidPass123', 'ValidPass123', self::IP);

        $after = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM auth_rate_limits WHERE action_type = 'register_ip'")->fetchColumn();

        $this->assertSame(1, $before);
        $this->assertSame($before, $after, 'สมัครสำเร็จแล้วประวัติของ IP ถูกล้างทิ้ง');
    }
}
