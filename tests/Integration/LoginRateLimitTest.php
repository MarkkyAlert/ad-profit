<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use PasswordResetRepository;
use ShopRepository;
use UserRepository;

/**
 * ตัวจำกัดการเดารหัสผ่าน — ต้องกันคนร้ายได้ **โดยไม่กันคนที่ไม่ได้ทำอะไรผิด**
 *
 * เรื่องนี้เคยพลาดทั้งสองทางในคอมมิตติดกัน:
 *  1. เดิม "ถามว่าเกินยัง" แล้วค่อยนับทีหลัง โดยมี `password_verify()` (~100ms) คั่น
 *     → ยิงพร้อมกัน 40 ครั้งผ่านเข้าไป 28 ครั้ง ทั้งที่เพดาน 5
 *  2. แก้เป็น "นับก่อน" แล้วลืมว่าครั้งที่ **รหัสถูก** ก็ถูกนับด้วย และ bucket ต่อ IP
 *     ตั้งใจไม่ล้างตอนสำเร็จ → ออฟฟิศที่ใช้เน็ตร่วมกัน คนที่ 6 ที่พิมพ์รหัสถูกถูกปฏิเสธ
 *
 * `AuthServiceLoginTest` เขียว 18/18 ตลอดทั้งสองเหตุการณ์ — ไฟล์นี้จึงมีไว้ล็อก
 * "ทั้งสองด้าน" ไม่ใช่ด้านเดียว
 */
final class LoginRateLimitTest extends IntegrationTestCase
{
    private const OFFICE_IP = '198.51.100.7';


    protected function setUp(): void
    {
        parent::setUp();

        // ล็อกอินสำเร็จเรียก `session_regenerate_id(true)` ซึ่งต้องมี session เปิดอยู่
        // (ท่าเดียวกับ `AuthServiceTest` — ไม่แตะ base class / ไม่แตะ AuthService)
        if ((string)ini_get('session.save_path') === '') {
            ini_set('session.save_path', sys_get_temp_dir());
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start(['use_cookies' => '0', 'cache_limiter' => '']);
        }
        $_SESSION = [];
    }

    /**
     * AuthService ที่บันทึกว่า "ถูกสั่งให้หน่วงกี่มิลลิวินาที" แทนการรอจริง
     *
     * ทำให้ตรวจได้ว่าทางไหนเรียกการหน่วงบ้างและด้วยค่าเท่าไร โดยไม่ต้องพึ่งนาฬิกา
     */
    private function spyingService()
    {
        return new class (
            $this->pdo,
            new UserRepository($this->pdo),
            new ShopRepository($this->pdo),
            new PasswordResetRepository($this->pdo)
        ) extends AuthService {
            /** @var list<int> */
            public array $throttleDelays = [];

            protected function applyAccountThrottleDelay(int $attempts): void
            {
                $this->throttleDelays[] = $this->accountThrottleDelayMilliseconds($attempts);
            }
        };
    }

    private function service(): AuthService
    {
        return new AuthService(
            $this->pdo,
            new UserRepository($this->pdo),
            new ShopRepository($this->pdo),
            new PasswordResetRepository($this->pdo)
        );
    }

    private function makeUser(string $email, string $password = 'CorrectPass123'): int
    {
        $this->pdo
            ->prepare('INSERT INTO users (email, password_hash, display_name, session_version) VALUES (?, ?, ?, 1)')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), 'ผู้ใช้']);

        return (int)$this->pdo->lastInsertId();
    }

    private function wasThrottled(array $result): bool
    {
        return str_contains((string)($result['error'] ?? ''), 'บ่อยเกินไป');
    }

    private function accountAttempts(): int
    {
        return (int)$this->pdo
            ->query("SELECT COALESCE(SUM(attempts), 0) FROM auth_rate_limits WHERE action_type = 'login_account'")
            ->fetchColumn();
    }

    private function accountAttemptsFor(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(attempts), 0) FROM auth_rate_limits
             WHERE action_type = 'login_account' AND bucket_key = :bucket_key"
        );
        $stmt->execute([':bucket_key' => hash('sha256', 'login_account||' . strtolower(trim($email)))]);

        return (int)$stmt->fetchColumn();
    }

    private function ipAttempts(): int
    {
        return (int)$this->pdo
            ->query("SELECT COALESCE(SUM(attempts), 0) FROM auth_rate_limits WHERE action_type = 'login_ip'")
            ->fetchColumn();
    }

    /**
     * ⭐ พนักงานหลายคนใช้เน็ตออฟฟิศเดียวกัน พิมพ์รหัสถูกทุกคน → ต้องเข้าได้ทุกคน
     *
     * ⚠️ นี่คือด้านที่พลาดตอนแก้เรื่องการยิงพร้อมกัน · เพดานคือ 5 แต่คนที่ 6 ที่พิมพ์
     * รหัส **ถูก** ต้องไม่ถูกปฏิเสธ เพราะไม่มีใครทำอะไรผิดเลยสักคน
     */
    public function testManyColleaguesOnOneOfficeIpCanAllLogIn(): void
    {
        $total = (int)RATE_LIMIT_MAX_ATTEMPTS + 3;
        for ($index = 1; $index <= $total; $index++) {
            $this->makeUser("staff{$index}@office.com");
        }

        $loggedIn = 0;
        for ($index = 1; $index <= $total; $index++) {
            $result = $this->service()->login("staff{$index}@office.com", 'CorrectPass123', self::OFFICE_IP);
            $loggedIn += ($result['success'] ?? false) === true ? 1 : 0;
        }

        $this->assertSame($total, $loggedIn, 'คนที่พิมพ์รหัสถูกถูกปฏิเสธเพราะเพื่อนร่วมออฟฟิศล็อกอินไปก่อน');
        $this->assertSame(0, $this->ipAttempts(), 'ล็อกอินสำเร็จยังกินโควตาของ IP อยู่');
    }

    /** ⭐ พิมพ์ผิดหลายครั้งแล้วพิมพ์ถูก ต้องเข้าได้ (ตราบใดที่ยังไม่ถึงเพดาน) */
    public function testTheCorrectPasswordStillWorksAfterSomeMistakes(): void
    {
        $this->makeUser('owner@example.com');
        $service = $this->service();

        for ($attempt = 1; $attempt < (int)RATE_LIMIT_MAX_ATTEMPTS; $attempt++) {
            $service->login('owner@example.com', 'ผิด', self::OFFICE_IP);
        }

        $result = $service->login('owner@example.com', 'CorrectPass123', self::OFFICE_IP);
        $this->assertTrue(($result['success'] ?? false), 'พิมพ์ถูกแล้วยังเข้าไม่ได้');
    }

    /**
     * ⭐ ไล่เดารหัสของบัญชีเดียว **จากเครื่องเดียว** ต้องถูกกันที่เพดาน
     *
     * ⚠️⚠️ ชื่อเมธอดอย่าตัดคำว่า "จากเครื่องเดียว" ทิ้ง — ตัวที่กันจริงคือ bucket
     * ต่อ IP ไม่ใช่ bucket ต่อบัญชี · พิสูจน์ด้วยการทดลอง: ปิดการเช็ก bucket ต่อบัญชี
     * ทิ้งไปเลย เทสต์นี้ก็ยังเขียว เพราะ `login_ip` นับทุกอีเมลจาก IP นั้น ค่าจึง
     * ≥ ตัวนับต่อบัญชีเสมอ และชนเพดานก่อนหรือพร้อมกันเสมอ
     *
     * แปลว่า **การไล่เดาบัญชีเดียวจากหลาย IP ไม่มีเพดานของตัวเอง** (แต่ละ IP ใหม่
     * เริ่มนับ 0 ใหม่) — เป็นเรื่องที่ต้องให้เจ้าของระบบตัดสิน ไม่ใช่แก้เงียบ ๆ
     * เพราะ bucket ที่ผูกกับอีเมลล้วนเปิดช่องให้คนร้ายจงใจล็อกบัญชีเหยื่อทิ้งได้
     */
    public function testGuessingOneAccountFromOneMachineIsStoppedAtTheLimit(): void
    {
        $this->makeUser('victim@example.com');
        $service = $this->service();

        $reached = 0;
        for ($attempt = 1; $attempt <= (int)RATE_LIMIT_MAX_ATTEMPTS + 7; $attempt++) {
            $reached += $this->wasThrottled($service->login('victim@example.com', 'เดา', '203.0.113.1')) ? 0 : 1;
        }

        $this->assertLessThanOrEqual((int)RATE_LIMIT_MAX_ATTEMPTS, $reached, 'เดารหัสได้เกินเพดาน');
    }

    /**
     * ⭐ ไล่เดาหลายบัญชีจาก IP เดียว (password spraying) ต้องถูกกันด้วย
     *
     * bucket ที่ผูกกับอีเมลจับไม่ได้เลย เพราะแต่ละบัญชีถูกลองแค่ครั้งเดียว
     */
    public function testSprayingManyAccountsFromOneIpIsStopped(): void
    {
        $total = (int)RATE_LIMIT_MAX_ATTEMPTS + 15;
        for ($index = 1; $index <= $total; $index++) {
            $this->makeUser("target{$index}@example.com");
        }

        $service = $this->service();
        $reached = 0;
        for ($index = 1; $index <= $total; $index++) {
            $reached += $this->wasThrottled($service->login("target{$index}@example.com", 'เดา', '203.0.113.2')) ? 0 : 1;
        }

        $this->assertLessThanOrEqual((int)RATE_LIMIT_MAX_ATTEMPTS, $reached, 'ไล่เดาหลายบัญชีจาก IP เดียวได้เกินเพดาน');
    }

    /**
     * ⭐ คนร้ายล็อกอินบัญชีของตัวเองสำเร็จ ต้องล้างประวัติการไล่เดาบัญชีอื่นไม่ได้
     *
     * ⚠️ นี่คือเหตุผลที่ bucket ต่อ IP ไม่ถูก "ล้าง" ตอนสำเร็จ — คืนได้แค่โควตา
     * ของครั้งที่สำเร็จนั้นครั้งเดียว
     */
    public function testASuccessfulLoginDoesNotEraseSomeoneElsesFailedAttempts(): void
    {
        $this->makeUser('attacker@example.com');
        $this->makeUser('victim@example.com', 'OtherPass123');
        $service = $this->service();

        $guesses = (int)RATE_LIMIT_MAX_ATTEMPTS - 1;
        for ($attempt = 1; $attempt <= $guesses; $attempt++) {
            $service->login('victim@example.com', 'เดา', '203.0.113.4');
        }

        $service->login('attacker@example.com', 'CorrectPass123', '203.0.113.4');

        $this->assertGreaterThanOrEqual(
            $guesses,
            $this->ipAttempts(),
            'ล็อกอินบัญชีตัวเองสำเร็จแล้วล้างประวัติการไล่เดาบัญชีอื่นทิ้งได้'
        );
    }

    /**
     * ⭐⭐ ไล่เดาบัญชีเดียวจาก **หลายเครื่อง** ต้องถูกหน่วง
     *
     * bucket อีก 2 ตัวผูกกับ IP ทั้งคู่ คนร้ายที่หมุนเปลี่ยน IP จึงเลี่ยงได้หมด
     * (แต่ละ IP ใหม่เริ่มนับ 0) · bucket ต่อบัญชีนับรวมทุกเครื่อง
     *
     * ⚠️⚠️ พิสูจน์ด้วยการ **ดักดูว่าถูกเรียกไหม** ไม่ใช่จับเวลา
     * เวอร์ชันแรกจับเวลาล็อกอินทั้งกระบวนการแล้วเทียบกับเวลาปกติ — ผ่านตอนรัน
     * ไฟล์เดียว แต่แดงตอนรันทั้งชุด เพราะเวลาตรวจรหัสผ่านแกว่งเป็นหลักวินาที
     * เมื่อเครื่องมีภาระ ขณะที่สิ่งที่วัดคือ 0.4 วินาที · เทสต์ที่เดี๋ยวเขียวเดี๋ยวแดง
     * แย่กว่าไม่มีเทสต์ เพราะทำให้คนเลิกเชื่อสีแดง
     */
    public function testGuessingOneAccountFromManyMachinesGetsSlower(): void
    {
        $this->makeUser('victim@example.com');
        $service = $this->spyingService();

        // เดาจาก IP ใหม่ทุกครั้ง — bucket ที่ผูกกับ IP จึงไม่มีวันชนเพดาน
        for ($attempt = 1; $attempt <= (int)LOGIN_ACCOUNT_MAX_ATTEMPTS; $attempt++) {
            $result = $service->login('victim@example.com', 'เดา', '203.0.113.' . $attempt);
            $this->assertFalse($result['success'] ?? false);
        }

        $this->assertSame(
            array_fill(0, (int)LOGIN_ACCOUNT_MAX_ATTEMPTS, 0),
            $service->throttleDelays,
            'ยังไม่เกินเพดานแต่โดนหน่วงแล้ว'
        );

        $service->throttleDelays = [];
        $service->login('victim@example.com', 'เดา', '198.51.100.200');

        $this->assertSame(
            [(int)LOGIN_ACCOUNT_MAX_DELAY_MS],
            $service->throttleDelays,
            'เกินเพดานต่อบัญชีแล้วแต่ไม่ถูกหน่วง — ไล่เดาจากหลายเครื่องได้ฟรี'
        );
    }

    /**
     * ⭐ การหน่วงต้องรออยู่จริง ไม่ใช่คำนวณเลขไว้เฉย ๆ
     *
     * วัดเฉพาะเมธอดที่หน่วง (ไม่มีอย่างอื่นปน) — ภาระของเครื่องทำให้ช้าลงได้
     * แต่ทำให้เร็วขึ้นไม่ได้ เทสต์นี้จึงไม่มีทางแดงเพราะเครื่องช้า
     */
    public function testTheDelayActuallyWaits(): void
    {
        $service = $this->service();
        $apply = new \ReflectionMethod(AuthService::class, 'applyAccountThrottleDelay');

        $startedAt = microtime(true);
        $apply->invoke($service, (int)LOGIN_ACCOUNT_MAX_ATTEMPTS + 1);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertGreaterThanOrEqual(
            (int)LOGIN_ACCOUNT_MAX_DELAY_MS * 0.9,
            $elapsedMs,
            'คำนวณเวลาหน่วงไว้แต่ไม่ได้รอจริง'
        );
    }

    /**
     * ⭐⭐ เจ้าของบัญชีที่พิมพ์รหัส **ถูก** ต้องเข้าได้เสมอ แม้ถูกไล่เดามาก่อน
     *
     * ⚠️ นี่คือเหตุผลที่เลือก "หน่วงเวลา" แทน "ปฏิเสธ" — ถ้าปฏิเสธ คนร้ายจะพิมพ์
     * รหัสผิดรัว ๆ เพื่อล็อกบัญชีของเหยื่อไม่ให้เข้าใช้เองได้ (denial of service)
     */
    public function testTheRealOwnerCanStillGetInWhileBeingAttacked(): void
    {
        $this->makeUser('victim@example.com');
        $service = $this->service();

        // คนร้ายไล่เดาเกินเพดานต่อบัญชีไปไกล จากหลายเครื่อง
        for ($attempt = 1; $attempt <= (int)LOGIN_ACCOUNT_MAX_ATTEMPTS + 5; $attempt++) {
            $service->login('victim@example.com', 'เดา', '203.0.113.' . $attempt);
        }

        // เจ้าของบัญชีเข้าจากเครื่องของตัวเอง ด้วยรหัสที่ถูกต้อง
        $result = $service->login('victim@example.com', 'CorrectPass123', '198.51.100.99');

        $this->assertTrue(
            ($result['success'] ?? false),
            'คนร้ายพิมพ์รหัสผิดรัว ๆ แล้วล็อกเจ้าของบัญชีออกจากระบบได้'
        );
        $this->assertSame(0, $this->accountAttempts(), 'เข้าสำเร็จแล้วยังไม่ล้างประวัติของบัญชีนี้');
    }

    /**
     * ⭐ ผู้ใช้ทั่วไปที่พิมพ์ผิดไม่กี่ครั้งต้องไม่ถูกหน่วง **เลย**
     *
     * ⚠️ ข้อนี้จับเวลาเทียบกับการล็อกอินธรรมดาไม่ได้ — ถ้าโค้ดหน่วงตั้งแต่ครั้งแรก
     * ตัวที่เอามาเทียบก็โดนหน่วงเท่ากัน ผลต่างเป็นศูนย์ แล้วเทสต์จะเขียวทั้งที่พัง
     * (ลองแล้ว) · จึงต้องถามกติกาตรง ๆ ว่า "ครั้งที่เท่าไรถึงเริ่มหน่วง"
     */
    public function testNobodyIsSlowedDownUntilTheCapIsPassed(): void
    {
        $delayOf = new \ReflectionMethod(AuthService::class, 'accountThrottleDelayMilliseconds');
        $service = $this->service();
        $cap = (int)LOGIN_ACCOUNT_MAX_ATTEMPTS;

        for ($attempt = 1; $attempt <= $cap; $attempt++) {
            $this->assertSame(
                0,
                $delayOf->invoke($service, $attempt),
                "ผิดครั้งที่ {$attempt} (ยังไม่เกินเพดาน {$cap}) ก็โดนหน่วงแล้ว"
            );
        }

        // ⚠️ ต้องส่งเพดานสูง ๆ เข้าไปเอง ไม่งั้นเพดานที่เทสต์ตั้งไว้ (400) จะกลบ
        // การคูณสองทุกครั้ง แล้วเทสต์จะผ่านแม้สูตรถูกเปลี่ยนเป็น `max` (พิสูจน์แล้ว)
        $roomy = 1000000;
        $this->assertSame(500, $delayOf->invoke($service, $cap + 1, $roomy), 'ครั้งแรกที่เกินต้องหน่วง 500ms');
        $this->assertSame(1000, $delayOf->invoke($service, $cap + 2, $roomy), 'ครั้งถัดไปต้องเป็นสองเท่า');
        $this->assertSame(2000, $delayOf->invoke($service, $cap + 3, $roomy), 'ครั้งถัดไปต้องเป็นสองเท่าอีก');

        // ⭐ ช่วงที่ "เพดาน" เป็นตัวตัดสินจริง (ไม่ใช่ทางลัด `$over >= 20`)
        $this->assertSame(
            1500,
            $delayOf->invoke($service, $cap + 10, 1500),
            'เพดานไม่ได้ตัด — สูตรคูณสองจะพาเวลาหน่วงพุ่งเป็นนาที/ชั่วโมง'
        );
        $this->assertSame(
            1500,
            $delayOf->invoke($service, $cap + 500, 1500),
            'เกินไปไกลมากแล้วยังไม่ถูกเพดานตัด'
        );
    }

    /** ⭐ พิมพ์ผิดครั้งเดียวต้องถูกนับแค่ครั้งเดียว */
    public function testOneTypoCountsOnce(): void
    {
        $this->makeUser('owner@example.com');
        $this->service()->login('owner@example.com', 'พิมพ์ผิด', '198.51.100.11');

        $this->assertSame(1, $this->accountAttemptsFor('owner@example.com'), 'นับผิดพลาด');
    }

    /**
     * ⭐ อีเมลที่ไม่มีบัญชีต้องถูกหน่วงเท่ากับอีเมลที่มีบัญชี
     *
     * ถ้าไม่เท่ากัน คนร้ายจับเวลาก็รู้ได้ว่าอีเมลไหนมีบัญชีอยู่จริง — ซึ่งเป็นสิ่งที่
     * ข้อความ "อีเมลหรือรหัสผ่านไม่ถูกต้อง" (ตอบเหมือนกันทุกกรณี) ตั้งใจปิดไว้
     */
    public function testAnUnknownEmailIsThrottledJustLikeARealOne(): void
    {
        $this->makeUser('real@example.com');
        $service = $this->spyingService();

        foreach (['real@example.com', 'ghost@example.com'] as $email) {
            for ($attempt = 1; $attempt <= (int)LOGIN_ACCOUNT_MAX_ATTEMPTS; $attempt++) {
                $service->login($email, 'เดา', '203.0.113.' . $attempt);
            }
        }

        $service->throttleDelays = [];
        $service->login('real@example.com', 'เดา', '198.51.100.210');
        $realDelays = $service->throttleDelays;

        $service->throttleDelays = [];
        $service->login('ghost@example.com', 'เดา', '198.51.100.211');

        $this->assertSame(
            $realDelays,
            $service->throttleDelays,
            'อีเมลที่ไม่มีบัญชีถูกหน่วงไม่เท่ากัน — จับเวลาแล้วรู้ได้ว่าอีเมลไหนมีอยู่จริง'
        );
        $this->assertNotSame([], $realDelays, 'ไม่มีการหน่วงเลยทั้งสองทาง — เทียบแล้วไม่ได้พิสูจน์อะไร');
    }

    /**
     * ⭐⭐ กดลิงก์ที่หมดอายุ ต้องถูกนับครั้งละ 1 ไม่ใช่ 2
     *
     * ⚠️ เดิม `resetPassword()` เรียกทั้ง `reserveAttempt()` ที่หัวเมธอด **และ**
     * `markFailedAttempt()` อีกครั้งในกิ่ง "ลิงก์ไม่ถูกต้อง" → นับ 2 ครั้งต่อการกด 1 ครั้ง
     * ผู้ใช้ที่กดลิงก์เก่าในอีเมล (ซึ่งหมดอายุไปแล้ว) จะชนเพดานตั้งแต่ครั้งที่ 3
     * ทั้งที่เพดานคือ 5 · ถังนี้ผูกกับ IP ล้วน ออฟฟิศจึงแชร์โควตากันทั้งตึก
     */
    public function testAnExpiredLinkCountsOncePerClick(): void
    {
        $this->makeUser('owner@example.com');
        $service = $this->service();

        for ($click = 1; $click <= 3; $click++) {
            $service->resetPassword('ลิงก์ที่ใช้ไม่ได้', 'NewPass12345', 'NewPass12345', '203.0.113.88');
        }

        $attempts = (int)$this->pdo
            ->query("SELECT attempts FROM auth_rate_limits WHERE action_type = 'reset_password' LIMIT 1")
            ->fetchColumn();

        $this->assertSame(3, $attempts, 'กด 3 ครั้งถูกนับ ' . $attempts . ' ครั้ง — ชนเพดานเร็วกว่าที่ควร');
    }

    /**
     * ⭐⭐ ขอลิงก์ลืมรหัสผ่าน 1 ครั้ง ต้องถูกนับ 1 ไม่ใช่ 2
     *
     * ⚠️ บั๊กเดียวกับ `testAnExpiredLinkCountsOncePerClick()` เป๊ะ ๆ แต่คนละทาง —
     * ตอนแก้รอบนั้นแก้เฉพาะ `resetPassword()` ส่วน `requestPasswordReset()`
     * ยังเรียกทั้ง `reserveAttempt()` (ซึ่งนับให้แล้ว) และ `markFailedAttempt()` ซ้ำอีก
     *
     * ⚠️ ตอนที่พบ อาการยัง **มองไม่เห็นจากหน้าเว็บ** เพราะเพดานของทางนี้เป็น 1 พอดี
     * ผลลัพธ์ที่ผู้ใช้เจอจึงถูกต้องโดยบังเอิญ · แต่ถ้าใครขยับเพดานเป็น 5 จะได้จริง 2
     * เทสต์นี้จึงตรวจ "ตัวนับ" ตรง ๆ ไม่ใช่ตรวจว่าถูกกันตอนไหน
     */
    public function testAskingForAResetLinkOnceCountsOnce(): void
    {
        $this->makeUser('owner@example.com');
        $this->service()->requestPasswordReset('owner@example.com', '203.0.113.77');

        $attempts = (int)$this->pdo
            ->query("SELECT attempts FROM auth_rate_limits WHERE action_type = 'password_reset' LIMIT 1")
            ->fetchColumn();

        $this->assertSame(
            1,
            $attempts,
            'ขอ 1 ครั้งถูกนับ ' . $attempts . ' ครั้ง — ใครขยับเพดานจะได้จำนวนครั้งไม่ตรงกับที่ตั้ง'
        );
    }

    /** ⭐ ขอลิงก์รีเซ็ตรหัสผ่านซ้ำ ๆ ต้องถูกกัน (เพดานแค่ 1 ครั้งต่อหน้าต่าง) */
    public function testRepeatedPasswordResetRequestsAreThrottled(): void
    {
        $this->makeUser('owner@example.com');
        $service = $this->service();

        $service->requestPasswordReset('owner@example.com', '203.0.113.5');
        $second = $service->requestPasswordReset('owner@example.com', '203.0.113.5');

        $this->assertTrue(
            $this->wasThrottled($second) || ($second['success'] ?? false) === false,
            'ขอลิงก์รีเซ็ตซ้ำได้ไม่จำกัด'
        );
        $this->assertLessThanOrEqual(1, $this->countRows('password_reset_tokens'), 'สร้างลิงก์ซ้ำหลายใบ');
    }
}
