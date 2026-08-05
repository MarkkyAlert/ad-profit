<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use EmailChangeRepository;
use PasswordResetRepository;
use PDO;
use ProfileService;
use UserRepository;

/**
 * เปลี่ยนอีเมลต้องยืนยันที่กล่องจดหมายใหม่ก่อนเสมอ
 *
 * ⚠️⚠️ เดิมเปลี่ยนได้ทันทีโดยไม่มีชั้นไหนกันเลย · พิมพ์ผิดตัวเดียว (`@gmial.com`)
 * ระบบขึ้นว่า "เปลี่ยนอีเมลเรียบร้อยแล้ว" แต่:
 *   · แท็บที่เปิดอยู่ยังใช้ได้ ผู้ใช้จึงไม่รู้ตัว
 *   · วันไหนหลุดจากระบบ จะเข้าไม่ได้อีกเลย
 *   · กด "ลืมรหัสผ่าน" ลิงก์จะไปกล่องจดหมายที่ไม่มีอยู่จริง
 * = **บัญชีหายถาวร กู้คืนไม่ได้**
 */
final class EmailChangeVerificationTest extends IntegrationTestCase
{
    private const CURRENT_PASSWORD = 'MyPass12345';

    /** @var array<int,string> ลิงก์ที่ "ส่ง" ออกไป (แทนการส่งอีเมลจริง) */
    private array $sentTokens = [];

    private function makeUser(string $email = 'owner@example.com'): int
    {
        $this->pdo
            ->prepare('INSERT INTO users (email, password_hash, display_name, session_version) VALUES (?, ?, ?, 1)')
            ->execute([$email, password_hash(self::CURRENT_PASSWORD, PASSWORD_DEFAULT), 'ผู้ใช้']);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * ProfileService ที่ดักลิงก์ไว้แทนการส่งอีเมลจริง
     *
     * ⚠️ ดักที่ `sendEmailChangeLink()` ไม่ใช่ mock ทั้ง EmailService — ตัวจริงยัง
     * ประกอบ URL เองอยู่ ซึ่งเป็นส่วนที่พังได้
     */
    private function service(): ProfileService
    {
        $tokens = &$this->sentTokens;
        $pdo = $this->pdo;

        return new class ($pdo, $tokens) extends ProfileService {
            /** @var array<int,string> */
            private array $captured;

            /** @param array<int,string> $captured */
            public function __construct(PDO $pdo, array &$captured)
            {
                parent::__construct(
                    new UserRepository($pdo),
                    $pdo,
                    new PasswordResetRepository($pdo),
                    new EmailChangeRepository($pdo),
                    null
                );
                $this->captured = &$captured;
            }

            protected function sendEmailChangeLink(string $newEmail, string $token): bool
            {
                $this->captured[] = $token;

                return true;
            }
        };
    }

    private function currentEmail(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string)$stmt->fetchColumn();
    }

    /**
     * ⭐⭐ พิมพ์อีเมลผิด แล้วบัญชีต้องไม่หาย
     *
     * นี่คือสถานการณ์ที่ทำให้กู้บัญชีคืนไม่ได้เลยถ้าไม่มีชั้นนี้
     */
    public function testATypoInTheNewEmailDoesNotLockTheOwnerOut(): void
    {
        $userId = $this->makeUser('owner@example.com');

        $result = $this->service()->changeEmail($userId, 'owner@gmial.com', self::CURRENT_PASSWORD);

        $this->assertTrue($result['success'] ?? false, 'ขอเปลี่ยนอีเมลไม่ผ่าน');
        $this->assertSame(
            'owner@example.com',
            $this->currentEmail($userId),
            'อีเมลถูกเปลี่ยนทันทีทั้งที่ยังไม่ได้ยืนยัน — พิมพ์ผิดแล้วบัญชีหายถาวร'
        );
    }

    /** ⭐ กดลิงก์จากกล่องจดหมายใหม่แล้วอีเมลจึงเปลี่ยนจริง */
    public function testTheEmailChangesOnlyAfterTheLinkIsUsed(): void
    {
        $userId = $this->makeUser('owner@example.com');
        $service = $this->service();

        $service->changeEmail($userId, 'new@example.com', self::CURRENT_PASSWORD);
        $this->assertCount(1, $this->sentTokens, 'ไม่ได้ส่งลิงก์ยืนยันออกไป');
        $this->assertSame('owner@example.com', $this->currentEmail($userId));

        $confirmed = $service->confirmEmailChange($this->sentTokens[0]);

        $this->assertTrue($confirmed['success'] ?? false, (string)($confirmed['error'] ?? ''));
        $this->assertSame('new@example.com', $this->currentEmail($userId), 'ยืนยันแล้วแต่อีเมลไม่เปลี่ยน');
    }

    /** ⭐ ยืนยันแล้วต้องเตะ session ทุกเครื่อง (อีเมลคือช่องทางกู้บัญชี) */
    public function testConfirmingSignsEveryDeviceOut(): void
    {
        $userId = $this->makeUser();
        $service = $this->service();
        $service->changeEmail($userId, 'new@example.com', self::CURRENT_PASSWORD);

        $before = (int)$this->pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();
        $service->confirmEmailChange($this->sentTokens[0]);
        $after = (int)$this->pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();

        $this->assertGreaterThan($before, $after, 'เปลี่ยนอีเมลแล้วเครื่องอื่นยังค้างอยู่ในบัญชี');
    }

    /** ⭐ ลิงก์ใช้ได้ครั้งเดียว */
    public function testTheLinkCannotBeUsedTwice(): void
    {
        $userId = $this->makeUser();
        $service = $this->service();
        $service->changeEmail($userId, 'new@example.com', self::CURRENT_PASSWORD);

        $this->assertTrue($service->confirmEmailChange($this->sentTokens[0])['success'] ?? false);

        // ⚠️ ต้องยืนยันว่า "คำขอถูกลบทิ้ง" ตรง ๆ ด้วย
        // การเช็กแค่ว่าครั้งที่สองล้มเหลว ผ่านได้เพราะการอัปเดตอีเมลเป็นค่าเดิม
        // ไม่มีแถวไหนเปลี่ยน (บังเอิญ) ไม่ใช่เพราะลิงก์ถูกทำให้ใช้ไม่ได้จริง
        $this->assertSame(0, $this->countRows('email_change_requests'), 'ใช้ลิงก์แล้วแต่คำขอยังค้างอยู่');
        $this->assertFalse(
            $service->confirmEmailChange($this->sentTokens[0])['success'] ?? false,
            'ลิงก์เดิมใช้ซ้ำได้'
        );
    }

    /** ⭐ ขอใหม่ต้องทับของเดิม — ลิงก์เก่าใช้ไม่ได้ทันที */
    public function testAskingAgainInvalidatesTheOlderLink(): void
    {
        $userId = $this->makeUser();
        $service = $this->service();

        $service->changeEmail($userId, 'typo@example.com', self::CURRENT_PASSWORD);
        $service->changeEmail($userId, 'correct@example.com', self::CURRENT_PASSWORD);

        $this->assertFalse(
            $service->confirmEmailChange($this->sentTokens[0])['success'] ?? false,
            'ลิงก์ของอีเมลที่พิมพ์ผิดยังใช้ได้ — กดพลาดแล้วบัญชีไปอยู่กับอีเมลที่ผิด'
        );
        $this->assertTrue($service->confirmEmailChange($this->sentTokens[1])['success'] ?? false);
        $this->assertSame('correct@example.com', $this->currentEmail($userId));
    }

    /** ⭐ รหัสผ่านปัจจุบันผิด = ไม่ส่งลิงก์เลย */
    public function testAWrongPasswordSendsNothing(): void
    {
        $userId = $this->makeUser();

        $result = $this->service()->changeEmail($userId, 'new@example.com', 'รหัสผิด');

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame([], $this->sentTokens, 'ส่งลิงก์ออกไปทั้งที่รหัสผ่านผิด');
        $this->assertSame(0, $this->countRows('email_change_requests'));
    }

    /**
     * ⭐ ระหว่างรอยืนยัน มีคนอื่นสมัครด้วยอีเมลนั้นไปแล้ว
     *
     * ⚠️ ต้องเช็กซ้ำตอนยืนยัน ไม่ใช่เช็กแค่ตอนขอ
     */
    public function testAnEmailTakenWhileWaitingIsRejectedAtConfirmTime(): void
    {
        $userId = $this->makeUser('owner@example.com');
        $service = $this->service();
        $service->changeEmail($userId, 'shared@example.com', self::CURRENT_PASSWORD);

        $this->makeUser('shared@example.com');   // คนอื่นสมัครไปก่อน

        $confirmed = $service->confirmEmailChange($this->sentTokens[0]);

        $this->assertFalse($confirmed['success'] ?? true, 'ยืนยันผ่านทั้งที่อีเมลถูกใช้ไปแล้ว');
        $this->assertSame('owner@example.com', $this->currentEmail($userId));

        // ⚠️ ต้องบอกสาเหตุจริง ๆ ด้วย — ถ้าไม่เช็กเอง ฐานข้อมูลจะปฏิเสธให้แทน
        // แต่ผู้ใช้จะได้ข้อความกลาง ๆ ว่า "ไม่สามารถเปลี่ยนอีเมลได้" ซึ่งไม่บอกว่าต้องทำอะไรต่อ
        $this->assertStringContainsString(
            'ถูกใช้งานแล้ว',
            (string)($confirmed['error'] ?? ''),
            'ไม่ได้บอกว่าอีเมลถูกใช้ไปแล้ว ผู้ใช้จะไม่รู้ว่าต้องเลือกอีเมลอื่น'
        );
    }
}
