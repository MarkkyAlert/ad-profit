<?php

declare(strict_types=1);

namespace Tests\Integration;

use EmailService;

/**
 * ⭐ ระบบอีเมลที่ "ตั้งค่าครบและส่งผ่าน" สำหรับเทสต์ที่สนใจตรรกะ token/โควตา
 *
 * ⚠️⚠️ ส่ง `null` แทนไม่ได้อีกแล้ว — `AuthService::requestPasswordReset()` ปฏิเสธ
 * ตั้งแต่ต้นเมื่อระบบอีเมลยังไม่พร้อม (ก่อนจองโควตา ก่อนสร้าง token) เพราะกดอีกกี่ครั้ง
 * ก็ไม่มีทางสำเร็จ · ถ้าเทสต์ยังส่ง null ต่อไป มันจะไปตกทางที่ถูกปฏิเสธแล้ว
 * **เขียวโดยไม่ได้ทดสอบสิ่งที่ชื่อเทสต์บอกเลย** (เจอมาแล้วกับ
 * `testRepeatedPasswordResetRequestsAreThrottled` ซึ่งเขียวเพราะทั้งสองครั้งถูกปฏิเสธ
 * ด้วยเหตุผลคนละเรื่องกับที่เทสต์ตั้งใจตรวจ)
 *
 * ⚠️ ไม่ override `isEnabled()` โดยตั้งใจ — ปล่อยให้กติกาตัวจริงตัดสินจากค่าที่ส่งเข้าไป
 * ถ้าวันหนึ่งกติกา "ตั้งค่าครบหรือยัง" เข้มขึ้น (เช่นบังคับ MAIL_HOST) เทสต์ต้องแดง
 * ไม่ใช่ถูกกลบด้วยการ override
 */
final class StubEmailService extends EmailService
{
    public function __construct()
    {
        parent::__construct(
            true,
            'smtp.example.invalid',
            587,
            'test@example.invalid',
            'not-a-real-password',
            'no-reply@example.invalid',
            'Ad Profit Test'
        );
    }

    public function sendPasswordResetEmail(string $toEmail, string $resetLink): bool
    {
        return true;
    }

    public function sendEmailChangeVerification(string $toEmail, string $verifyLink): bool
    {
        return true;
    }
}
