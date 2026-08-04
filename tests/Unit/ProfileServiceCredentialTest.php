<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ProfileService;
use UserRepository;

/**
 * ProfileService: การแยก "เดารหัสผ่าน" ออกจาก "กรอกฟอร์มผิด" และการเตะ session อื่น
 *
 * เดิมมีเทสต์เดียว (display_name ยาวเกิน) — changeEmail/changePassword ไม่มีเลย
 */
final class ProfileServiceCredentialTest extends TestCase
{
    private const CURRENT_PASSWORD = 'current-password';

    private function makeService(?UserRepository $userRepository = null): ProfileService
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        return new ProfileService($userRepository ?? $this->makeUserRepository(), $pdo);
    }

    private function makeUserRepository(): UserRepository
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findById')->willReturn([
            'id' => 1,
            'email' => 'owner@example.com',
            'password_hash' => password_hash(self::CURRENT_PASSWORD, PASSWORD_DEFAULT),
        ]);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('updateEmail')->willReturn(true);
        $repository->method('updatePasswordHash')->willReturn(true);

        return $repository;
    }

    /** รหัสผ่านปัจจุบันผิด = การเดารหัสผ่านจริง → ต้องถูกทำเครื่องหมายให้ controller นับ */
    public function testWrongCurrentPasswordIsMarkedAsCredentialFailure(): void
    {
        $result = $this->makeService()->changeEmail(1, 'new@example.com', 'wrong-password');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['credential_failure'] ?? false);
    }

    public function testWrongCurrentPasswordOnPasswordChangeIsAlsoMarked(): void
    {
        $result = $this->makeService()->changePassword(1, 'wrong-password', 'new-password-1', 'new-password-1');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['credential_failure'] ?? false);
    }

    /**
     * ความผิดพลาดในการกรอกฟอร์มต้องไม่ถูกนับเข้าตัวจำกัดจำนวนครั้ง
     *
     * เดิม controller นับทุก failure → พิมพ์ยืนยันรหัสผ่านผิด 5 ครั้งโดนล็อก 60 วิ
     * ทั้งที่ไม่ได้เดารหัสผ่านเลยสักครั้ง
     */
    public function testFormMistakesAreNotCredentialFailures(): void
    {
        $service = $this->makeService();

        $mismatch = $service->changePassword(1, self::CURRENT_PASSWORD, 'new-password-1', 'ไม่ตรงกัน');
        $tooShort = $service->changePassword(1, self::CURRENT_PASSWORD, 'sh', 'sh');
        $sameAsOld = $service->changePassword(1, self::CURRENT_PASSWORD, self::CURRENT_PASSWORD, self::CURRENT_PASSWORD);
        $badEmail = $service->changeEmail(1, 'ไม่ใช่อีเมล', self::CURRENT_PASSWORD);

        foreach (['ยืนยันไม่ตรง' => $mismatch, 'สั้นเกิน' => $tooShort,
                  'ซ้ำรหัสเดิม' => $sameAsOld, 'อีเมลผิดรูปแบบ' => $badEmail] as $label => $result) {
            $this->assertFalse($result['success'], $label);
            $this->assertArrayNotHasKey('credential_failure', $result, $label . ' ไม่ควรถูกนับเป็นการเดารหัสผ่าน');
        }
    }

    /** เปลี่ยนอีเมลสำเร็จต้องเตะ session อื่น — อีเมลเป็นช่องทางกู้บัญชี */
    public function testSuccessfulEmailChangeBumpsSessionVersion(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn([
            'id' => 1,
            'email' => 'owner@example.com',
            'password_hash' => password_hash(self::CURRENT_PASSWORD, PASSWORD_DEFAULT),
        ]);
        $userRepository->method('findByEmail')->willReturn(null);
        $userRepository->method('updateEmail')->willReturn(true);
        $userRepository->expects($this->once())->method('incrementSessionVersion')->with(1);

        $result = $this->makeService($userRepository)->changeEmail(1, 'new@example.com', self::CURRENT_PASSWORD);

        $this->assertTrue($result['success']);
    }

    /** เปลี่ยนอีเมลไม่สำเร็จต้องไม่เตะใครออก */
    public function testFailedEmailChangeDoesNotBumpSessionVersion(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn([
            'id' => 1,
            'email' => 'owner@example.com',
            'password_hash' => password_hash(self::CURRENT_PASSWORD, PASSWORD_DEFAULT),
        ]);
        $userRepository->expects($this->never())->method('incrementSessionVersion');

        $this->makeService($userRepository)->changeEmail(1, 'new@example.com', 'wrong-password');
    }
}
