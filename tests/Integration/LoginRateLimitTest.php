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
