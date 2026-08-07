<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProfileService;
use UserRepository;

final class ProfileServiceTest extends TestCase
{
    public function testUpdateProfileFailsWhenDisplayNameExceeds120Chars(): void
    {
        $userRepository = $this->createStub(UserRepository::class);

        $service = new ProfileService($userRepository, null);

        $longName = str_repeat('a', 121); // 121 ตัวอักษร > 120

        $result = $service->updateProfile(1, $longName);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('120', $result['error']);
    }

    /**
     * ⭐ ชื่อที่แสดงว่างเปล่า ต้องถูกปฏิเสธ
     *
     * ⚠️ coverage gap จาก logic review 2026-08-07 — เดิมมีเทสต์เฉพาะ "ยาวเกิน 120"
     * ส่วนกิ่ง "ว่างเปล่า" ไม่เคยถูกแตะ · ชื่อนี้ขึ้นบนแถบด้านบนทุกหน้า ถ้าปล่อยว่างได้
     * ผู้ใช้จะเห็นช่องว่างแทนชื่อตัวเองโดยไม่รู้ว่าทำไม
     */
    public function testAnEmptyDisplayNameIsRejected(): void
    {
        $service = new ProfileService($this->createStub(UserRepository::class), null);

        foreach (['', '   ', "\t"] as $blank) {
            $result = $service->updateProfile(1, $blank);

            $this->assertFalse($result['success'], 'ยอมรับชื่อที่แสดงว่างเปล่า: ' . var_export($blank, true));
            $this->assertStringContainsString('ชื่อที่แสดง', (string)$result['error']);
        }
    }

    /** ขอบเขตพอดี 120 ตัวอักษร ต้องยังผ่าน — ไม่ใช่ปฏิเสธเกินจำเป็น */
    public function testExactlyTheMaximumDisplayNameLengthPasses(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findProfileById')->willReturn([
            'id' => 1, 'email' => 'a@example.com', 'display_name' => 'เดิม', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $userRepository->method('updateDisplayName')->willReturn(true);

        $service = new ProfileService($userRepository, null);
        $result = $service->updateProfile(1, str_repeat('ก', ProfileService::MAX_DISPLAY_NAME_LENGTH));

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }
}
