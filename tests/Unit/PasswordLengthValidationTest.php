<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * validate_password_length() — helper ที่ไม่เคยมีเทสต์ครอบ
 *
 * ใช้เฉพาะตอนตั้งรหัสผ่านใหม่ (register / resetPassword / changePassword)
 * ไม่ได้ถูกเรียกตอน login → ผู้ใช้เดิมที่รหัสสั้นกว่าเกณฑ์ยังเข้าระบบได้
 */
final class PasswordLengthValidationTest extends TestCase
{
    public function testAcceptsPasswordAtExactlyTheMinimumLength(): void
    {
        $this->assertNull(validate_password_length(str_repeat('a', PASSWORD_MIN_LENGTH)));
    }

    public function testRejectsPasswordShorterThanTheMinimum(): void
    {
        $error = validate_password_length(str_repeat('a', PASSWORD_MIN_LENGTH - 1));

        $this->assertIsString($error);
        $this->assertStringContainsString((string)PASSWORD_MIN_LENGTH, $error);
    }

    /**
     * นับเป็น "ตัวอักษร" ไม่ใช่ byte
     *
     * เดิมใช้ strlen: อักษรไทย 1 ตัว = 3 byte → รหัสผ่าน 3 ตัวอักษร (9 byte) ผ่านเกณฑ์
     * 8 ตัวอักษรไปได้ ทั้งที่ฝั่ง UI (minlength) นับเป็นตัวอักษรและปฏิเสธ
     */
    public function testShortThaiPasswordIsRejectedEvenThoughItsByteLengthIsLarge(): void
    {
        $thaiPassword = 'รหัส'; // 4 ตัวอักษร แต่ 12 byte

        $this->assertGreaterThanOrEqual(PASSWORD_MIN_LENGTH, strlen($thaiPassword));
        $this->assertLessThan(PASSWORD_MIN_LENGTH, mb_strlen($thaiPassword));
        $this->assertIsString(validate_password_length($thaiPassword));
    }

    public function testLongEnoughThaiPasswordIsAccepted(): void
    {
        $this->assertNull(validate_password_length(str_repeat('ก', PASSWORD_MIN_LENGTH)));
    }

    public function testFieldLabelAppearsInTheMessage(): void
    {
        $error = validate_password_length('sh', 'รหัสผ่านใหม่');

        $this->assertIsString($error);
        $this->assertStringStartsWith('รหัสผ่านใหม่', $error);
    }
}
