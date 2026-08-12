<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ **ทุกฟอร์มต้องกันการกดส่งซ้ำ — รวมหน้าที่ไม่ได้ใช้ footer.php ร่วม**
 *
 * ⚠️⚠️ หน้าเข้าสู่ระบบ · ลืมรหัสผ่าน · ตั้งรหัสใหม่ เป็นหน้าเดี่ยว (มี `<head>` ของตัวเอง)
 * จึงไม่เคยได้ตัวกันกดซ้ำเลย ขณะที่ **ทุกฟอร์มของหน้าที่ล็อกอินแล้วมี**
 *
 * ผลที่เกิดจริง: เน็ตช้าแล้วกดปุ่มซ้ำ → ส่งคำขอซ้ำหลายครั้ง ซึ่ง**กินโควตาตัวจำกัด
 * จำนวนครั้ง** (ขอลิงก์รีเซ็ตมีเพดาน 1 ครั้ง/นาที) คนที่แค่ใจร้อนจึงถูกกัน
 * และไม่มีอะไรบอกว่ากำลังส่งอยู่
 */
final class AuthFormGuardTest extends ControllerTestCase
{
    /**
     * ⭐ ทั้งสามหน้าต้องมีตัวกันตัวเดียวกัน — ไล่จากรายชื่อที่ประกาศไว้ ไม่ใช่สุ่มดู
     *
     * ⚠️ ตรวจว่า **require ไฟล์กลาง** ไม่ใช่แค่ "มีคำว่า data-submitted อยู่ในหน้า" —
     * ถ้าใครก๊อปโค้ดไปวางซ้ำ กติกาจะเพี้ยนคนละหน้าในวันที่มีคนแก้ที่เดียว
     */
    public function testEveryStandalonePageHasTheSharedSubmitGuard(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['login.php', 'forgot-password.php', 'reset-password.php'] as $page) {
            $source = (string)file_get_contents($root . '/' . $page);
            $this->assertStringContainsString(
                "includes/auth-form-guard.php",
                $source,
                $page . ' ไม่ได้ใช้ตัวกันกดส่งซ้ำที่ใช้ร่วมกัน'
            );
        }
    }

    /**
     * ⭐⭐ ตัวกันต้องไปถึงเบราว์เซอร์จริง — ตรวจจากหน้าเว็บที่เสิร์ฟออกมา ไม่ใช่จากไฟล์
     */
    public function testTheGuardReachesTheBrowserOnEveryAuthPage(): void
    {
        $guest = $this->startSession(0, 0);

        $pages = [
            '/login.php' => 'เข้าสู่ระบบ',
            '/forgot-password.php' => 'ลืมรหัสผ่าน',
            '/reset-password.php?token=' . str_repeat('a', 64) => 'ตั้งรหัสใหม่',
        ];

        foreach ($pages as $url => $label) {
            $body = (string)$this->get($url, $guest)['body'];

            // หน้าตั้งรหัสใหม่ที่ token ใช้ไม่ได้จะเด้งออก — ข้ามไป ไม่ใช่ความผิดของตัวกัน
            if (!str_contains($body, '<form')) {
                continue;
            }

            $this->assertStringContainsString(
                "data-submitted",
                $body,
                'หน้า "' . $label . '" ยังกดส่งซ้ำได้ ไม่มีอะไรกัน'
            );
            $this->assertStringContainsString(
                'กำลังดำเนินการ',
                $body,
                'หน้า "' . $label . '" ไม่มีสัญญาณบอกว่ากำลังส่ง — คนบนเน็ตช้าจะกดซ้ำ'
            );
        }
    }

    /**
     * ⚠️ ด้านตรงข้าม — ฟอร์มต้องยังส่งได้ตามปกติ
     * (ตัวกันที่ปิดปุ่มเร็วเกินจะทำให้เข้าระบบไม่ได้เลย ซึ่งแย่กว่าปัญหาเดิมมาก)
     */
    public function testLoggingInStillWorksWithTheGuardInPlace(): void
    {
        $this->createUser('guard@example.com', 'GuardPass12345');

        $guest = $this->startSession(0, 0);
        $page = (string)$this->get('/login.php', $guest)['body'];
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page, $matched);

        $response = $this->post('/api/auth.php', [
            'action' => 'login', 'csrf_token' => $matched[1] ?? '',
            'email' => 'guard@example.com', 'password' => 'GuardPass12345',
        ], $guest);

        $this->assertSame(302, (int)$response['status'], 'เข้าสู่ระบบไม่ผ่านหลังใส่ตัวกัน');
        $this->assertStringNotContainsString(
            'login.php',
            (string)($response['headers']['location'] ?? ''),
            'เข้าสู่ระบบสำเร็จแต่ถูกส่งกลับหน้าเข้าสู่ระบบ'
        );
    }
}
