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
/**
 * ⭐ ShopRepository ที่ "สร้างร้านไม่สำเร็จ" — ใช้จำลองขั้นที่ 2 ของการสมัครล้มกลางคัน
 *
 * ⚠️ ต้องเป็นคลาสลูกจริง ไม่ใช่ mock — `AuthService` รับ `ShopRepository` แบบมีชนิด
 * และเทสต์นี้ต้องการให้ **ทุกอย่างอื่นเดินจริงบนฐานข้อมูลจริง** จะได้พิสูจน์ว่า
 * แถวที่เขียนไปแล้วถูกย้อนกลับจริง ไม่ใช่แค่ "ไม่เคยถูกเขียน"
 */
final class FailingShopRepository extends ShopRepository
{
    public function create(int $userId, string $name): int
    {
        throw new \RuntimeException('จำลองสร้างร้านเริ่มต้นล้มกลางคัน');
    }
}

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

    /**
     * ⭐ เครื่องเดียวไล่ทดสอบว่า "อีเมลไหนมีบัญชีอยู่" ต้องถูกหยุด
     *
     * ⚠️⚠️ ต้องยิงด้วย **รหัสผ่านที่ถูกรูปแบบ** เท่านั้น — นั่นคือท่าที่ได้คำตอบจริง
     * ("อีเมลนี้ถูกใช้งานแล้ว" = มีบัญชี) · ยิงด้วยรหัสผ่านสั้น ๆ จะถูกปฏิเสธที่ด่าน
     * ตรวจรูปแบบก่อนถึงฐานข้อมูล จึงไม่ได้คำตอบอะไรกลับไปเลย และตั้งใจไม่นับ
     * (การพิมพ์ฟอร์มผิดของผู้ใช้จริงต้องไม่กินโควตาของทั้งออฟฟิศ)
     *
     * เดิมเทสต์นี้ยิงด้วย `'short'` ซึ่งไม่ใช่ท่าที่คนร้ายใช้ — ชื่อเทสต์กับสิ่งที่
     * มันทดสอบจึงไม่ตรงกัน
     */
    public function testOneAddressCannotProbeUnlimitedEmails(): void
    {
        $probeTargets = [];
        for ($index = 1; $index <= RATE_LIMIT_MAX_ATTEMPTS + 3; $index++) {
            $email = "taken{$index}@example.com";
            $this->createUser($email);
            $probeTargets[] = $email;
        }

        $service = $this->makeService();

        $blockedAt = null;
        foreach ($probeTargets as $index => $email) {
            // เปลี่ยนอีเมลทุกครั้ง — bucket ต่อ (IP + อีเมล) จับไม่ได้ ต้องพึ่ง bucket ต่อ IP
            $result = $service->register($email, 'GoodPass123', 'GoodPass123', self::IP);

            if (str_contains((string)($result['error'] ?? ''), 'บ่อยเกินไป')) {
                $blockedAt = $index + 1;
                break;
            }
        }

        $this->assertNotNull($blockedAt, 'ไล่ถามว่าอีเมลไหนมีบัญชีอยู่ได้ไม่จำกัดจากเครื่องเดียว');
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

    /**
     * ⭐⭐ สมัครสมาชิกเขียน 2 ตาราง — ล้มกลางคันต้องไม่เหลือ "ผู้ใช้ที่ไม่มีร้าน"
     *
     * ⚠️⚠️ ทำไมถึงสำคัญกว่าที่เห็น: ถ้า rollback ไม่ทำงาน จะเหลือแถวใน `users`
     * ที่ไม่มีร้านเลย → ผู้ใช้ล็อกอินเข้ามาได้แต่ทุกหน้าพัง **และสมัครใหม่ด้วย
     * อีเมลเดิมไม่ได้อีก** เพราะ `uq_users_email` กันไว้ = บัญชีตายถาวร
     * แก้ได้ทางเดียวคือเข้าไปลบแถวในฐานข้อมูลเอง
     *
     * ⚠️ `register()` มี transaction ครอบอยู่แล้วและทำงานถูก (วัดแล้ว) — เทสต์นี้
     * มีไว้ล็อกไม่ให้หายเงียบ · ก่อนหน้านี้ 5 เทสต์ในไฟล์นี้ไม่มีตัวไหนแตะ rollback เลย
     */
    public function testAFailedShopCreationLeavesNoOrphanUser(): void
    {
        $service = new AuthService(
            $this->pdo,
            new UserRepository($this->pdo),
            new FailingShopRepository($this->pdo),
            new PasswordResetRepository($this->pdo)
        );

        $result = $service->register('orphan@example.com', 'GoodPass123!', 'GoodPass123!', self::IP);

        $this->assertFalse($result['success'], 'บอกว่าสมัครสำเร็จทั้งที่สร้างร้านไม่ได้');
        $this->assertSame(
            0,
            $this->countRows('users'),
            'เหลือผู้ใช้ที่ไม่มีร้าน — ล็อกอินได้แต่ใช้งานไม่ได้ และสมัครใหม่ด้วยอีเมลเดิมไม่ได้อีก'
        );
        $this->assertSame(0, $this->countRows('shops'), 'มีร้านค้างอยู่ทั้งที่การสมัครล้มเหลว');
    }

    /** ⭐ และเมื่อทุกอย่างปกติ ต้องได้ทั้งผู้ใช้และร้านเริ่มต้นครบ (กันเทสต์บนเข้มเกินจริง) */
    public function testASuccessfulRegisterStillWritesBothRows(): void
    {
        $result = $this->makeService()->register('fine@example.com', 'GoodPass123!', 'GoodPass123!', self::IP);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(1, $this->countRows('users'));
        $this->assertSame(1, $this->countRows('shops'));
    }
}
