<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use PasswordResetRepository;
use PDO;
use ProfileService;
use RuntimeException;
use UserRepository;

/**
 * ล้างลิงก์รีเซ็ตไม่สำเร็จ → ต้อง rollback ทั้งชุด ไม่ใช่ commit รหัสผ่านใหม่ต่อ
 *
 * เดิม `revokePasswordResetTokens()` กลืน error ไว้ ทำให้ระบบ commit การเปลี่ยนรหัสผ่าน
 * ต่อไปโดยที่ลิงก์รีเซ็ตเก่ายังใช้ได้ แล้วบอกผู้ใช้ว่า "สำเร็จ" — ซึ่งคือช่องโหว่ที่
 * การแก้รอบก่อนตั้งใจปิดพอดี · เทสต์นี้ใช้ DB จริงเพื่อพิสูจน์ว่า rollback เกิดขึ้นจริง
 */
final class ProfileServiceRollbackTest extends IntegrationTestCase
{
    /** repository ที่ลบ token ไม่สำเร็จเสมอ (จำลอง DB สะดุด) */
    private function failingResetRepository(PDO $pdo): PasswordResetRepository
    {
        return new class ($pdo) extends PasswordResetRepository {
            public function deleteByUserId(int $userId): bool
            {
                throw new RuntimeException('จำลอง DB ล้มเหลวตอนลบ token');
            }
        };
    }

    /** ⭐ รหัสผ่านต้องไม่เปลี่ยน และ token เก่าต้องยังอยู่ครบ */
    public function testPasswordChangeIsRolledBackWhenRevocationFails(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        (new PasswordResetRepository($this->pdo))
            ->createToken($userId, hash('sha256', bin2hex(random_bytes(32))), 1);

        $result = (new ProfileService(
            new UserRepository($this->pdo),
            $this->pdo,
            $this->failingResetRepository($this->pdo)
        ))->changePassword($userId, 'OldPass123', 'BrandNew456', 'BrandNew456');

        $this->assertFalse($result['success'], 'บอกว่าสำเร็จทั้งที่ลิงก์เก่ายังใช้ได้');

        $hash = (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();
        $this->assertTrue(password_verify('OldPass123', $hash), 'รหัสผ่านถูกเปลี่ยนทั้งที่ทั้งชุดควร rollback');
        $this->assertSame(1, $this->countRows('password_reset_tokens'));
    }

    /** เปลี่ยนอีเมลก็ต้อง rollback เหมือนกัน */
    public function testEmailChangeIsRolledBackWhenRevocationFails(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        (new PasswordResetRepository($this->pdo))
            ->createToken($userId, hash('sha256', bin2hex(random_bytes(32))), 1);

        $result = (new ProfileService(
            new UserRepository($this->pdo),
            $this->pdo,
            $this->failingResetRepository($this->pdo)
        ))->changeEmail($userId, 'moved@example.com', 'OldPass123');

        $this->assertFalse($result['success']);
        $this->assertSame('owner@example.com', (string)$this->pdo
            ->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** ทางปกติยังทำงานครบ — เปลี่ยนรหัสผ่านสำเร็จและ token หายไป */
    public function testTheNormalPathStillCommits(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        (new PasswordResetRepository($this->pdo))
            ->createToken($userId, hash('sha256', bin2hex(random_bytes(32))), 1);

        $result = (new ProfileService(
            new UserRepository($this->pdo),
            $this->pdo,
            new PasswordResetRepository($this->pdo)
        ))->changePassword($userId, 'OldPass123', 'BrandNew456', 'BrandNew456');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(0, $this->countRows('password_reset_tokens'));
    }
}
