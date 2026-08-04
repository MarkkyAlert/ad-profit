<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use ShopRepository;
use UserRepository;

/**
 * ตาข่ายรองรับของ AuthService::login/logout — ก่อนหน้านี้ไม่มีเทสต์ครอบเลยแม้แต่เคสเดียว
 *
 * ต้องเป็น integration เพราะ AuthService รับ PDO แบบ required และ login เขียน $_SESSION
 * (setUp เปิด session เองเหมือน AuthServiceTest)
 */
final class AuthServiceLoginTest extends IntegrationTestCase
{
    private const IP = '203.0.113.9';

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
        return new AuthService($this->pdo, new UserRepository($this->pdo), new ShopRepository($this->pdo));
    }

    public function testLoginSucceedsAndEstablishesSession(): void
    {
        $userId = $this->createUser('owner@example.com', 'password123');
        $shopId = $this->createShop($userId, 'ร้านหลัก');

        $result = $this->makeService()->login('owner@example.com', 'password123', self::IP);

        $this->assertTrue($result['success']);
        $this->assertSame($userId, $result['user_id']);
        $this->assertSame($shopId, $result['shop_id']);

        $this->assertSame($userId, $_SESSION['user_id']);
        $this->assertSame('owner@example.com', $_SESSION['email']);
        $this->assertSame($shopId, $_SESSION['current_shop_id']);
        $this->assertSame('ร้านหลัก', $_SESSION['current_shop_name']);
    }

    public function testEmailIsNormalisedBeforeLookup(): void
    {
        $this->createUser('owner@example.com', 'password123');

        $result = $this->makeService()->login('  OWNER@Example.COM  ', 'password123', self::IP);

        $this->assertTrue($result['success']);
    }

    public function testUnknownEmailAndWrongPasswordGiveTheSameMessage(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        $unknown = $service->login('nobody@example.com', 'password123', self::IP);
        $wrongPassword = $service->login('owner@example.com', 'wrong-password', '198.51.100.7');

        $this->assertFalse($unknown['success']);
        $this->assertFalse($wrongPassword['success']);
        // ข้อความต้องเหมือนกัน ไม่งั้นบอกใบ้ว่าอีเมลไหนมีอยู่จริง
        $this->assertSame($unknown['error'], $wrongPassword['error']);
    }

    public function testEmptyCredentialsAreRejected(): void
    {
        $result = $this->makeService()->login('', '', self::IP);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('กรอกอีเมลและรหัสผ่าน', $result['error']);
    }

    public function testFailedLoginDoesNotEstablishSession(): void
    {
        $this->createUser('owner@example.com', 'password123');

        $this->makeService()->login('owner@example.com', 'wrong-password', self::IP);

        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    /** ผู้ใช้ที่ร้านหายไป (ข้อมูลเพี้ยน) ต้องยังเข้าได้ โดยระบบสร้างร้านเริ่มต้นให้ */
    public function testLoginCreatesDefaultShopWhenUserHasNone(): void
    {
        $userId = $this->createUser('noshop@example.com', 'password123');

        $result = $this->makeService()->login('noshop@example.com', 'password123', self::IP);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->countRows('shops'));

        $shop = (new ShopRepository($this->pdo))->getFirstByUserId($userId);
        $this->assertNotNull($shop);
        $this->assertSame('ร้านค้าของฉัน', $shop['name']);
    }

    public function testLoginIsBlockedAfterTooManyFailedAttempts(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        for ($attempt = 0; $attempt < RATE_LIMIT_MAX_ATTEMPTS; $attempt++) {
            $service->login('owner@example.com', 'wrong-password', self::IP);
        }

        // ครั้งถัดไปต้องถูกกัน แม้จะใส่รหัสผ่านถูก
        $blocked = $service->login('owner@example.com', 'password123', self::IP);

        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('บ่อยเกินไป', $blocked['error']);
    }

    /**
     * ล็อกอินสำเร็จต้องล้างตัวนับ "ของบัญชีนั้น" ไม่งั้นความพยายามที่ล้มเหลวก่อนหน้า
     * ค้างไปกันครั้งถัดไป
     *
     * bucket ต่อ IP ไม่ถูกล้างโดยตั้งใจ (ดู testSuccessfulLoginDoesNotClearTheIpBucket)
     */
    public function testSuccessfulLoginClearsTheFailureCounter(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        $service->login('owner@example.com', 'wrong-password', self::IP);
        $service->login('owner@example.com', 'wrong-password', self::IP);
        $this->assertTrue($service->login('owner@example.com', 'password123', self::IP)['success']);

        $accountBuckets = (int)$this->pdo
            ->query("SELECT COUNT(*) FROM auth_rate_limits WHERE action_type = 'login'")
            ->fetchColumn();

        $this->assertSame(0, $accountBuckets);
    }

    public function testRateLimitIsScopedPerClientIp(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        for ($attempt = 0; $attempt < RATE_LIMIT_MAX_ATTEMPTS; $attempt++) {
            $service->login('owner@example.com', 'wrong-password', self::IP);
        }

        // อีก IP ต้องไม่ติดล็อกไปด้วย
        $this->assertTrue($service->login('owner@example.com', 'password123', '198.51.100.7')['success']);
    }

    public function testLoginRefreshesLastLoginAt(): void
    {
        $userId = $this->createUser('owner@example.com', 'password123');
        $this->createShop($userId);

        $before = $this->pdo->query('SELECT last_login_at FROM users WHERE id = ' . $userId)->fetchColumn();
        $this->assertNull($before);

        $this->makeService()->login('owner@example.com', 'password123', self::IP);

        $after = $this->pdo->query('SELECT last_login_at FROM users WHERE id = ' . $userId)->fetchColumn();
        $this->assertNotNull($after);
    }

    /**
     * ตัวนับต้องลงตาราง auth_rate_limits จริง ๆ
     *
     * regression guard ของบั๊กที่ทำให้ rate limit ตายทั้งระบบ: SQL ใช้ชื่อ placeholder
     * ซ้ำในคำสั่งเดียว → MySQL native prepare ตอบ HY093 → INSERT ล้มเงียบทุกครั้ง
     * แถวจึงเป็น 0 ตลอด และฝั่งอ่านเห็น "ไม่มีแถว = ยังไม่ถูกจำกัด"
     */
    public function testFailedAttemptsArePersistedToTheDatabase(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        $service->login('owner@example.com', 'wrong-password', self::IP);
        $service->login('owner@example.com', 'wrong-password', self::IP);

        // ระบุ action_type ให้ชัด — มี bucket ต่อ IP (login_ip) อยู่อีกแถวหนึ่งด้วย
        $row = $this->pdo
            ->query("SELECT action_type, client_ip, attempts FROM auth_rate_limits WHERE action_type = 'login'")
            ->fetch();

        $this->assertIsArray($row, 'ไม่มีแถวใน auth_rate_limits — ตัวนับไม่ได้ถูกบันทึก');
        $this->assertSame('login', $row['action_type']);
        $this->assertSame(self::IP, $row['client_ip']);
        $this->assertSame(2, (int)$row['attempts']);
    }

    /**
     * rate limit ต้องไม่ขึ้นกับ timezone ของ PHP
     *
     * connection ไม่ได้ pin time_zone ไว้ ถ้าโค้ดเอา started_at (นาฬิกา MySQL) ไปเทียบกับ
     * time() ของ PHP อายุหน้าต่างจะเพี้ยนตามส่วนต่างของโซน แล้วถือว่าหมดอายุทุกครั้ง
     * = ไม่มีการจำกัดเลย (เคยเกิดจริงบน CI ที่ MariaDB เป็น UTC ส่วนแอปเป็น Asia/Bangkok
     * ขณะที่เครื่อง dev ทั้งสองฝั่งโซนเดียวกันจึงไม่เห็นอาการ)
     *
     * เทสต์นี้บังคับให้ PHP อยู่คนละโซนกับ DB เสมอ ไม่ว่าจะรันบนเครื่องไหน
     */
    public function testRateLimitIsUnaffectedByPhpTimezone(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14 — ไกลจากทุกโซนที่ DB จะเป็น

        try {
            for ($attempt = 0; $attempt < RATE_LIMIT_MAX_ATTEMPTS; $attempt++) {
                $service->login('owner@example.com', 'wrong-password', self::IP);
            }

            $blocked = $service->login('owner@example.com', 'password123', self::IP);
            $this->assertFalse($blocked['success'], 'หน้าต่าง rate limit ถูกคิดด้วยนาฬิกา PHP');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /** พ้นหน้าต่างเวลาแล้วตัวนับต้องเริ่มใหม่ (สาขา CASE ... THEN 1 ที่ไม่เคยถูกรัน) */
    public function testCounterRestartsAfterTheWindowExpires(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = $this->makeService();

        for ($attempt = 0; $attempt < RATE_LIMIT_MAX_ATTEMPTS; $attempt++) {
            $service->login('owner@example.com', 'wrong-password', self::IP);
        }
        $this->assertFalse($service->login('owner@example.com', 'password123', self::IP)['success']);

        // ดันหน้าต่างให้หมดอายุ แทนการรอจริง
        $this->pdo->exec(sprintf(
            'UPDATE auth_rate_limits SET started_at = started_at - INTERVAL %d SECOND',
            RATE_LIMIT_WINDOW_SECONDS + 5
        ));

        $this->assertTrue($service->login('owner@example.com', 'password123', self::IP)['success']);
    }

    public function testLogoutRemovesEveryAuthKey(): void
    {
        $userId = $this->createUser('owner@example.com', 'password123');
        $this->createShop($userId);

        $service = $this->makeService();
        $service->login('owner@example.com', 'password123', self::IP);
        $this->assertArrayHasKey('user_id', $_SESSION);

        $service->logout();

        foreach (['user_id', 'email', 'display_name', 'session_version',
                  'auth_started_at', 'last_activity_at', 'current_shop_id',
                  'current_shop_name', 'csrf_token'] as $key) {
            $this->assertArrayNotHasKey($key, $_SESSION, "logout ไม่ได้ล้าง '{$key}'");
        }
    }

    /**
     * logout ต้องล้าง session ทั้งก้อน ไม่ใช่เฉพาะ key ที่ระบุไว้
     *
     * เดิม unset ทีละ key → ตัวนับ rate limit แบบ session ติดข้าม logout ไปหาคนถัดไป
     * ที่ใช้เครื่องเดียวกัน
     */
    public function testLogoutLeavesNothingBehindInTheSession(): void
    {
        $userId = $this->createUser('owner@example.com', 'password123');
        $this->createShop($userId);

        $service = $this->makeService();
        $service->login('owner@example.com', 'password123', self::IP);
        $_SESSION['auth_rate_limits'] = ['บางคีย์' => ['attempts' => 4, 'started_at' => time()]];

        $service->logout();

        $this->assertSame([], $_SESSION);
    }

    /**
     * password spraying: รหัสเดียวยิงหลายบัญชีจาก IP เดียว ต้องถูกจำกัด
     *
     * bucket เดิมผูกกับ (action, ip, email) → เปลี่ยนอีเมลทุกครั้งก็ไม่มีวันชนเพดาน
     */
    public function testSprayingAcrossManyAccountsFromOneIpIsThrottled(): void
    {
        $service = $this->makeService();
        for ($i = 0; $i < RATE_LIMIT_MAX_ATTEMPTS + 1; $i++) {
            $this->createUser("victim{$i}@example.com", 'password123');
        }

        // เดารหัสเดียวกันกับบัญชีคนละอันทุกครั้ง
        for ($i = 0; $i < RATE_LIMIT_MAX_ATTEMPTS; $i++) {
            $service->login("victim{$i}@example.com", 'guessed-password', self::IP);
        }

        $blocked = $service->login('victim5@example.com', 'password123', self::IP);

        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('บ่อยเกินไป', $blocked['error']);
    }

    /** IP อื่นต้องไม่ติดล็อกไปด้วย */
    public function testSprayingLimitIsScopedPerIp(): void
    {
        $service = $this->makeService();
        for ($i = 0; $i < RATE_LIMIT_MAX_ATTEMPTS; $i++) {
            $this->createUser("victim{$i}@example.com", 'password123');
            $service->login("victim{$i}@example.com", 'guessed-password', self::IP);
        }

        $this->assertTrue($service->login('victim0@example.com', 'password123', '198.51.100.77')['success']);
    }

    /**
     * ล็อกอินสำเร็จของบัญชีหนึ่ง ต้องไม่ล้างประวัติการเดาบัญชีอื่นจาก IP เดียวกัน
     * ไม่งั้นผู้โจมตีที่มีบัญชีของตัวเองจะรีเซ็ตตัวนับได้ตลอด
     */
    public function testSuccessfulLoginDoesNotClearTheIpBucket(): void
    {
        $service = $this->makeService();
        $this->createUser('attacker@example.com', 'password123');
        for ($i = 0; $i < RATE_LIMIT_MAX_ATTEMPTS; $i++) {
            $this->createUser("victim{$i}@example.com", 'password123');
            $service->login("victim{$i}@example.com", 'guessed-password', self::IP);
        }

        $this->assertFalse($service->login('attacker@example.com', 'password123', self::IP)['success']);
    }

    /** hash ที่ใช้เผาเวลาต้องเป็น bcrypt จริง ไม่งั้น password_verify คืน false ทันทีโดยไม่ทำงาน */
    public function testDummyHashUsedForTimingIsARealBcryptHash(): void
    {
        $reflection = new \ReflectionClass(AuthService::class);
        $dummy = (string)$reflection->getConstant('DUMMY_PASSWORD_HASH');

        $this->assertSame('bcrypt', password_get_info($dummy)['algoName']);
        $this->assertFalse(password_verify('any-password', $dummy));
    }
}
