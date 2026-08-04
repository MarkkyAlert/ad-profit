<?php

declare(strict_types=1);

namespace Tests\Unit;

use PasswordResetRepository;
use PHPUnit\Framework\TestCase;
use ProfileService;
use RuntimeException;
use UserRepository;

/**
 * การล้างลิงก์รีเซ็ตต้องล้มทั้งชุด ไม่ใช่กลืน error แล้ว commit ต่อ
 *
 * เมธอดนี้ถูกเรียกอยู่ "ใน" ทรานแซกชันเดียวกับการเปลี่ยนรหัสผ่าน/อีเมล
 * ถ้ากลืน error ไว้ ระบบจะบันทึกรหัสผ่านใหม่สำเร็จโดยที่ลิงก์เก่ายังใช้ได้
 * แล้วบอกผู้ใช้ว่า "สำเร็จ" — ซึ่งคือช่องโหว่ที่การแก้รอบก่อนตั้งใจปิดพอดี
 */
final class ProfileServiceTokenRevocationTest extends TestCase
{
    private function makeUserRepository(): UserRepository
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findById')->willReturn([
            'id' => 1,
            'email' => 'owner@example.com',
            'display_name' => 'เจ้าของ',
            'password_hash' => password_hash('OldPass123', PASSWORD_DEFAULT),
        ]);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('updatePasswordHash')->willReturn(true);
        $repository->method('updateEmail')->willReturn(true);

        return $repository;
    }

    /** ⭐ ลบ token ไม่สำเร็จ → ต้อง rollback และตอบว่าไม่สำเร็จ */
    public function testAFailedRevocationRollsBackThePasswordChange(): void
    {
        $resetRepository = $this->createStub(PasswordResetRepository::class);
        $resetRepository->method('deleteByUserId')
            ->willThrowException(new RuntimeException('DB ล่ม'));

        // ส่ง null แทน PDO — เทสต์นี้ยืนยัน "ผลลัพธ์" ว่าไม่บอกว่าสำเร็จ
        // การ rollback จริงยืนยันด้วย integration (ProfileServiceRollbackTest)
        $result = (new ProfileService($this->makeUserRepository(), null, $resetRepository))
            ->changePassword(1, 'OldPass123', 'BrandNew456', 'BrandNew456');

        $this->assertFalse($result['success'], 'บอกว่าสำเร็จทั้งที่ลิงก์เก่ายังใช้ได้');
    }

    /** เปลี่ยนอีเมลก็เหมือนกัน */
    public function testAFailedRevocationRollsBackTheEmailChange(): void
    {
        $resetRepository = $this->createStub(PasswordResetRepository::class);
        $resetRepository->method('deleteByUserId')
            ->willThrowException(new RuntimeException('DB ล่ม'));

        // ส่ง null แทน PDO — เทสต์นี้ยืนยัน "ผลลัพธ์" ว่าไม่บอกว่าสำเร็จ
        // การ rollback จริงยืนยันด้วย integration (ProfileServiceRollbackTest)
        $result = (new ProfileService($this->makeUserRepository(), null, $resetRepository))
            ->changeEmail(1, 'new@example.com', 'OldPass123');

        $this->assertFalse($result['success']);
    }

    /** ทางปกติยังต้อง commit ตามเดิม */
    public function testASuccessfulRevocationStillCommits(): void
    {
        $resetRepository = $this->createStub(PasswordResetRepository::class);
        $resetRepository->method('deleteByUserId')->willReturn(true);

        $result = (new ProfileService($this->makeUserRepository(), null, $resetRepository))
            ->changePassword(1, 'OldPass123', 'BrandNew456', 'BrandNew456');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }
}
