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

    /**
     * เส้นแบ่ง "โดนหน่วง" กับ "ไม่โดนหน่วง"
     *
     * ⚠️ ต้องเผื่อความคลาดเคลื่อนของการจับเวลา (~±50 มิลลิวินาที) แต่ต้องไม่เผื่อจน
     * เทสต์ผ่านทั้งที่ไม่มีการหน่วงเลย — 60% ของเวลาหน่วงจริงห่างจากทั้งสองฝั่งพอ
     */
    private const THROTTLE_THRESHOLD_MS = LOGIN_ACCOUNT_MAX_DELAY_MS * 0.6;

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

    /** เวลาที่ใช้ล็อกอิน 1 ครั้ง (มิลลิวินาที) */
    private function timeLogin(AuthService $service, string $email, string $password, string $ip): float
    {
        $startedAt = microtime(true);
        $service->login($email, $password, $ip);

        return (microtime(true) - $startedAt) * 1000;
    }

    /**
     * เวลาพื้นฐานของการล็อกอินที่ไม่โดนหน่วง
     *
     * ⚠️ ต้องวัดจริง ไม่ใช่ตั้งเป็นตัวเลขตายตัว — การตรวจรหัสผ่านกินเวลาหลักร้อย
     * มิลลิวินาทีอยู่แล้ว ถ้าเทียบกับเลขตายตัวเล็ก ๆ เทสต์จะผ่านแม้ถอดการหน่วงออก
     */
    private function baselineLoginMilliseconds(AuthService $service): float
    {
        // ⚠️ ต้องยิงทิ้ง 1 ครั้งก่อนจับเวลา — ครั้งแรกของโปรเซสต้องสร้าง hash หลอก
        // (ตัวที่ใช้เผาเวลาให้เท่ากับการ verify จริง) ซึ่งกินเวลาหลายร้อยมิลลิวินาที
        // ถ้าไม่ยิงทิ้งก่อน "เวลาพื้นฐาน" จะสูงกว่าความจริงจนกลบเวลาหน่วงมิด
        $this->timeLogin($service, 'baseline-warmup@example.com', 'เดา', '192.0.2.2');

        return $this->timeLogin($service, 'baseline-probe@example.com', 'เดา', '192.0.2.1');
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
     * ⭐⭐ ไล่เดาบัญชีเดียวจาก **หลายเครื่อง** ต้องช้าลงเรื่อย ๆ
     *
     * bucket อีก 2 ตัวผูกกับ IP ทั้งคู่ คนร้ายที่หมุนเปลี่ยน IP จึงเลี่ยงได้หมด
     * (แต่ละ IP ใหม่เริ่มนับ 0) · bucket ต่อบัญชีนับรวมทุกเครื่อง
     */
    public function testGuessingOneAccountFromManyMachinesGetsSlower(): void
    {
        $this->makeUser('victim@example.com');
        $service = $this->service();

        // เดาจาก IP ใหม่ทุกครั้ง — bucket ที่ผูกกับ IP จึงไม่มีวันชนเพดาน
        for ($attempt = 1; $attempt <= (int)LOGIN_ACCOUNT_MAX_ATTEMPTS; $attempt++) {
            $result = $service->login('victim@example.com', 'เดา', '203.0.113.' . $attempt);
            $this->assertFalse($result['success'] ?? false);
        }

        $baselineMs = $this->baselineLoginMilliseconds($service);
        $throttledMs = $this->timeLogin($service, 'victim@example.com', 'เดา', '198.51.100.200');

        $this->assertGreaterThan(
            0,
            $this->accountAttempts(),
            'ไม่มี bucket ต่อบัญชีเลย — การไล่เดาจากหลายเครื่องไม่ถูกนับ'
        );
        $this->assertGreaterThanOrEqual(
            self::THROTTLE_THRESHOLD_MS,
            $throttledMs - $baselineMs,
            'เกินเพดานต่อบัญชีแล้วแต่ตอบเร็วเท่าเดิม — การหน่วงไม่ได้ทำงาน'
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

        $this->assertGreaterThan(
            0,
            $delayOf->invoke($service, $cap + 1),
            'เกินเพดานแล้วยังไม่หน่วงเลย'
        );
        $this->assertLessThanOrEqual(
            (int)LOGIN_ACCOUNT_MAX_DELAY_MS,
            $delayOf->invoke($service, $cap + 500),
            'เวลาหน่วงไม่มีเพดาน — ยิงเยอะ ๆ แล้ว PHP จะค้างรอกันจนเว็บล่ม'
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
     * ⭐ อีเมลที่ไม่มีบัญชีต้องตอบช้าเท่ากับอีเมลที่มีบัญชี
     *
     * ถ้าไม่เท่ากัน คนร้ายจับเวลาก็รู้ได้ว่าอีเมลไหนมีบัญชีอยู่จริง — ซึ่งเป็นสิ่งที่
     * ข้อความ "อีเมลหรือรหัสผ่านไม่ถูกต้อง" (ตอบเหมือนกันทุกกรณี) ตั้งใจปิดไว้
     */
    public function testAnUnknownEmailIsThrottledJustLikeARealOne(): void
    {
        $service = $this->service();

        for ($attempt = 1; $attempt <= (int)LOGIN_ACCOUNT_MAX_ATTEMPTS; $attempt++) {
            $service->login('ghost@example.com', 'เดา', '203.0.113.' . $attempt);
        }

        $baselineMs = $this->baselineLoginMilliseconds($service);
        $ghostMs = $this->timeLogin($service, 'ghost@example.com', 'เดา', '198.51.100.201');

        $this->assertGreaterThanOrEqual(
            self::THROTTLE_THRESHOLD_MS,
            $ghostMs - $baselineMs,
            'อีเมลที่ไม่มีบัญชีตอบเร็วกว่า — จับเวลาแล้วรู้ได้ว่าอีเมลไหนมีอยู่จริง'
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
