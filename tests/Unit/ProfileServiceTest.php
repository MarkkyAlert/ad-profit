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

    /**
     * ⭐⭐ ช่องว่างที่ "มองไม่เห็น" ก็คือชื่อว่าง — ต้องถูกปฏิเสธเหมือนกัน
     *
     * ⚠️ `trim()` ของ PHP ไม่ตัด NBSP / ช่องว่างญี่ปุ่น / zero-width · ด่าน "ชื่อว่าง"
     * จึงถูกข้ามได้ด้วยตัวอักษรที่ผู้ใช้มองไม่เห็น แล้วชื่อในหน้าโปรไฟล์กลายเป็นช่องเปล่า
     * โดยไม่มีอะไรบอกว่าเกิดอะไรขึ้น · กติกาเดียวกับชื่อร้าน (`trim_unicode_whitespace()`)
     */
    public function testADisplayNameMadeOnlyOfInvisibleSpacesIsRejected(): void
    {
        $service = new ProfileService($this->createStub(UserRepository::class), null);

        $invisible = [
            'NBSP' => "\u{00A0}",
            'ช่องว่างญี่ปุ่น' => "\u{3000}",
            'zero-width' => "\u{200B}",
            'ผสมกัน' => "\u{00A0}\u{3000}\u{FEFF}",
        ];

        foreach ($invisible as $label => $blank) {
            $result = $service->updateProfile(1, $blank);

            $this->assertFalse($result['success'], "ยอมรับชื่อที่แสดงที่เป็น {$label} ล้วน");
            $this->assertStringContainsString('ชื่อที่แสดง', (string)$result['error']);
        }
    }

    /**
     * ⭐⭐ ชื่อเก่าที่ติดช่องว่างซ่อนอยู่ ต้องล้างออกได้
     *
     * ⚠️⚠️ กับดักที่คู่มือเตือนไว้แล้วสำหรับชื่อร้าน และใช้กับที่นี่เหมือนกัน:
     * **ต้องเทียบกับค่าที่เก็บอยู่จริง ไม่ใช่ค่าที่ normalize แล้ว** · ถ้าเทียบแบบ
     * normalize ทั้งสองฝั่ง ชื่อเก่าที่ติด NBSP จะ "เท่ากับ" ชื่อสะอาดที่ผู้ใช้พิมพ์เสมอ
     * → ตอบว่าสำเร็จโดยไม่ `UPDATE` อะไรเลย ผู้ใช้ล้างช่องว่างนั้นออกไม่ได้ตลอดกาล
     */
    public function testAnOldNameWithAHiddenSpaceCanStillBeCleanedUp(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findProfileById')->willReturn([
            'id' => 1,
            'email' => 'a@example.com',
            'display_name' => "เจ้าของร้าน\u{00A0}",   // ค่าที่ค้างอยู่ในฐานข้อมูล
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $userRepository->expects($this->once())
            ->method('updateDisplayName')
            ->with(1, 'เจ้าของร้าน')
            ->willReturn(true);

        $service = new ProfileService($userRepository, null);
        $result = $service->updateProfile(1, 'เจ้าของร้าน');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /** ⭐ ส่วนชื่อที่ไม่ได้เปลี่ยนจริง ๆ ต้องไม่ยิง UPDATE ฟรี ๆ */
    public function testAnUnchangedNameDoesNotHitTheDatabase(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findProfileById')->willReturn([
            'id' => 1,
            'email' => 'a@example.com',
            'display_name' => 'เจ้าของร้าน',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $userRepository->expects($this->never())->method('updateDisplayName');

        $service = new ProfileService($userRepository, null);
        $result = $service->updateProfile(1, 'เจ้าของร้าน');

        $this->assertTrue($result['success']);
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
