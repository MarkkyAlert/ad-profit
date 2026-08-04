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

    /**
     * bcrypt ตัดที่ 72 byte — รหัสยาวกว่านั้นส่วนเกินไม่ถูกใช้ตรวจเลย
     *
     * พิสูจน์แล้วว่า password_verify() ผ่านทั้ง "72 ตัว" และ "72 ตัว + ต่อท้ายอะไรก็ได้"
     * กับ hash เดียวกัน → ต้องปฏิเสธตั้งแต่ตอนตั้งรหัส ไม่ให้ผู้ใช้เข้าใจผิดว่าปลอดภัยกว่า
     */
    public function testRejectsPasswordBeyondBcryptLimit(): void
    {
        $this->assertNull(validate_password_length(str_repeat('a', PASSWORD_MAX_BYTES)));
        $this->assertIsString(validate_password_length(str_repeat('a', PASSWORD_MAX_BYTES + 1)));
    }

    /** อักษรไทย 1 ตัว = 3 byte → 24 ตัวคือเพดานจริง */
    public function testThaiPasswordIsMeasuredInBytesAgainstTheCeiling(): void
    {
        $this->assertNull(validate_password_length(str_repeat('ก', 24)));      // 72 byte
        $this->assertIsString(validate_password_length(str_repeat('ก', 25)));  // 75 byte
    }

    public function testFieldLabelAppearsInTheMessage(): void
    {
        $error = validate_password_length('sh', 'รหัสผ่านใหม่');

        $this->assertIsString($error);
        $this->assertStringStartsWith('รหัสผ่านใหม่', $error);
    }
}
