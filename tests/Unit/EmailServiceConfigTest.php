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
        // ตรวจจากโค้ดจริง เพราะ constructor อ่าน constant โดยตรง จึงสลับค่าในเทสต์ไม่ได้
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/EmailService.php');
        $method = substr($source, (int)strpos($source, 'public function isEnabled'));
        $method = substr($method, 0, (int)strpos($method, '}') + 1);

        foreach (['enabled', 'username', 'password', 'fromAddress'] as $property) {
            $this->assertStringContainsString(
                '$this->' . $property,
                $method,
                "isEnabled() ไม่ได้ตรวจ {$property}"
            );
        }
    }
}
