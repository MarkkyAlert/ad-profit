<?php

declare(strict_types=1);

namespace Tests\Unit;

use EmailService;
use PHPUnit\Framework\TestCase;

/**
 * EmailService::isEnabled() — คลาสนี้ไม่เคยมีเทสต์ครอบเลย
 *
 * ทดสอบได้เฉพาะการอ่านคอนฟิก เพราะ constructor อ่านจาก constant โดยตรงและการส่งจริง
 * ต้องมี SMTP — การส่งอีเมลถึงจริงยัง **ต้อง verify มือ**
 */
final class EmailServiceConfigTest extends TestCase
{
    /**
     * ค่าจาก tests/bootstrap.php ไม่ได้ตั้ง MAIL_* ไว้ → config.php ใช้ default
     * ซึ่ง MAIL_ENABLED = false → ต้องถือว่ายังส่งไม่ได้
     */
    public function testDisabledByDefaultSoNothingIsSentSilently(): void
    {
        $this->assertFalse((new EmailService())->isEnabled());
    }

    /**
     * เอกสารกฎว่า "พร้อมส่ง" ต้องครบทั้ง 4 อย่าง ไม่ใช่แค่ MAIL_ENABLED
     *
     * fromAddress default เป็นค่าว่าง ถ้าไม่นับรวมจะผ่านด่านนี้แล้วไปล้มที่ setFrom('')
     * กลายเป็น "ขอลิงก์รีเซ็ตแล้วเงียบ" ซึ่งเป็นบั๊กแบบเดียวกับตอนไม่ได้ตั้ง SMTP เลย
     */
    public function testEnabledRequiresEveryCredentialIncludingFromAddress(): void
    {
        $complete = ['user@example.com', 'secret', 'noreply@example.com'];

        $this->assertTrue(
            (new EmailService(true, 'smtp.example.com', 587, ...$complete))->isEnabled(),
            'ตั้งค่าครบแล้วยังบอกว่าส่งไม่ได้'
        );

        // ขาดอะไรไปสักอย่าง = ยังส่งไม่ได้ · ถ้าปล่อยผ่านจะไปล้มตอนส่งจริงแบบเงียบ ๆ
        // ผู้ใช้กด "ลืมรหัสผ่าน" แล้วไม่มีอีเมลมา โดยที่หน้าจอบอกว่าส่งแล้ว
        $missing = [
            'ปิดสวิตช์' => [false, 'smtp.example.com', 587, 'user@example.com', 'secret', 'noreply@example.com'],
            'ไม่มีชื่อผู้ใช้' => [true, 'smtp.example.com', 587, '', 'secret', 'noreply@example.com'],
            'ไม่มีรหัสผ่าน' => [true, 'smtp.example.com', 587, 'user@example.com', '', 'noreply@example.com'],
            'ไม่มีอีเมลผู้ส่ง' => [true, 'smtp.example.com', 587, 'user@example.com', 'secret', ''],
        ];

        foreach ($missing as $label => $arguments) {
            $this->assertFalse(
                (new EmailService(...$arguments))->isEnabled(),
                $label . ': บอกว่าพร้อมส่งทั้งที่ตั้งค่าไม่ครบ'
            );
        }
    }
}
