<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ ธงความปลอดภัยของคุกกี้ session ต้องถูกส่งจริง
 *
 * ⚠️ ตั้งไว้ใน `includes/bootstrap.php` (`session_start([...])`) แต่ **ไม่เคยมีเทสต์
 * คุมเลย** — ลบทิ้งทีละธงแล้วชุดเทสต์ทั้ง 1,200 ตัวยังเขียวหมด · แต่ละธงกันคนละเรื่อง:
 *   · `HttpOnly` — สคริปต์ในหน้าอ่านคุกกี้ไม่ได้ (ถ้ามี XSS หลุด จะขโมย session ไม่ได้)
 *   · `SameSite=Lax` — เว็บอื่นส่งคำขอข้ามเว็บมาพร้อมคุกกี้ไม่ได้ (ชั้นเสริมของ CSRF)
 *   · `secure` — เบราว์เซอร์ส่งคุกกี้เฉพาะบน HTTPS (คุกกี้ไม่วิ่งบน http ให้ใครดักได้)
 *
 * ⚠️⚠️ คลาสนี้ต้องรันในโหมด **production** เพราะ `secure` ผูกกับ `APP_ENV`
 * (ในโหมด development จะเปิดเฉพาะตอนต่อผ่าน HTTPS จริง ซึ่งเทสต์ทำไม่ได้)
 *
 * ⚠️ ตรวจจาก header `Set-Cookie` ของคำขอแรกเท่านั้น ไม่ได้เดินต่อทั้ง flow —
 * เพราะคุกกี้ที่ติดธง `secure` จะไม่ถูกส่งกลับมาบน http ทำให้ session ไม่ต่อเนื่อง
 */
final class SessionCookieFlagsTest extends ControllerTestCase
{
    /**
     * @return array<string,string>
     */
    protected static function serverEnvironmentOverrides(): array
    {
        return ['APP_ENV' => 'production'];
    }

    private function setCookieHeader(): string
    {
        $response = $this->get('/login.php');
        $header = (string)($response['headers']['set-cookie'] ?? '');

        $this->assertNotSame('', $header, 'เซิร์ฟเวอร์ไม่ได้ส่งคุกกี้ session ออกมาเลย');
        $this->assertStringContainsString('PHPSESSID', $header, 'คุกกี้ที่ส่งมาไม่ใช่คุกกี้ session');

        return $header;
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function cookieFlagProvider(): array
    {
        return [
            'สคริปต์อ่านคุกกี้ไม่ได้' => ['HttpOnly', 'ถ้ามี XSS หลุด จะขโมย session ได้ทันที'],
            'เว็บอื่นส่งคุกกี้ข้ามมาไม่ได้' => ['SameSite=Lax', 'ชั้นเสริมของ CSRF หายไป'],
            'ส่งเฉพาะบน HTTPS' => ['secure', 'คุกกี้วิ่งบน http ให้คนดักกลางทางเก็บไปได้'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cookieFlagProvider')]
    public function testTheSessionCookieCarriesItsSecurityFlags(string $flag, string $why): void
    {
        $this->assertStringContainsStringIgnoringCase(
            $flag,
            $this->setCookieHeader(),
            "คุกกี้ session ไม่มีธง {$flag} — {$why}"
        );
    }

    /**
     * ⭐ ล็อกอินสำเร็จต้องเปลี่ยนรหัส session (กัน session fixation)
     *
     * ⚠️ ทดสอบที่ระดับ header เพราะคุกกี้ `secure` เดินต่อบน http ไม่ได้ —
     * ดูแค่ว่าเซิร์ฟเวอร์ออกรหัสใหม่ให้หลังล็อกอิน ไม่ได้ใช้ session นั้นต่อ
     */
    public function testLoggingInIssuesANewSessionId(): void
    {
        $this->createUser('owner@example.com', 'OwnerPass123');

        $guest = $this->startBlankSession();
        $token = $this->csrfTokenForGuest($guest);

        $response = $this->post('/api/auth.php', [
            'csrf_token' => $token,
            'action' => 'login',
            'email' => 'owner@example.com',
            'password' => 'OwnerPass123',
        ], $guest);

        $newId = $this->sessionIdFrom($response);

        $this->assertNotSame('', $newId, 'ล็อกอินแล้วไม่ได้ออกรหัส session ใหม่');
        $this->assertNotSame($guest, $newId, 'ใช้รหัส session เดิมต่อหลังล็อกอิน — เปิดช่อง session fixation');
    }

    private function csrfTokenForGuest(string $sessionId): string
    {
        $response = $this->get('/login.php', $sessionId);
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $response['body'], $matched) === 1) {
            return $matched[1];
        }

        $this->fail('หา csrf_token ในหน้าเข้าสู่ระบบไม่เจอ');
    }
}
