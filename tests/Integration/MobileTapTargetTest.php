<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ **ปุ่มในหัวเว็บต้องกดโดนบนมือถือ เท่ากับปุ่มที่เหลือของระบบ**
 *
 * ⚠️⚠️ วัดจากหน้าจริงที่ความกว้าง 375px ก่อนแก้:
 *   · ปุ่มบัญชีผู้ใช้ (ที่ซ่อน "ออกจากระบบ" ไว้) — สูง **30px**
 *   · ตัวเลือกร้าน — สูง **38px**
 *   · ปุ่ม "จัดการร้าน" — สูง **28px**
 * ขณะที่ปุ่มอื่นทั้งระบบใช้คลาส `tap-target` ให้ได้ 44px ตามเกณฑ์
 *
 * ⚠️ ตัวเลือกร้านคือปุ่มที่กดผิดแล้วเจ็บที่สุด — สลับร้านพลาดแล้วกรอกยอดลงร้านผิด
 *
 * ⚠️⚠️ `<select>` ใช้ `tap-target` ไม่ได้ เพราะเบราว์เซอร์วาด element นี้เอง `::after`
 * จึงไม่ถูกเรนเดอร์ — ต้องเพิ่มความสูงจริงด้วย `tap-height` แทน
 */
final class MobileTapTargetTest extends ControllerTestCase
{
    private function headerOf(string $page = '/dashboard.php'): string
    {
        $userId = $this->createUser('tap@example.com', 'TapPass1234567');
        $shopId = $this->createShop($userId, 'ร้านหนึ่ง');
        $this->createShop($userId, 'ร้านสอง');   // ต้องมี ≥ 2 ร้าน ตัวเลือกร้านถึงจะโผล่

        return (string)$this->get($page, $this->startSession($userId, $shopId))['body'];
    }

    /** ⭐ ปุ่มที่กดได้ในหัวเว็บทุกตัวต้องมีพื้นที่กดขนาดมาตรฐาน */
    public function testEveryHeaderControlIsBigEnoughToTap(): void
    {
        $body = $this->headerOf();

        $checks = [
            'ปุ่มบัญชีผู้ใช้ (ซ่อนปุ่มออกจากระบบไว้)' => ['/<button[^>]*id="profile-menu-button"[^>]*>/', 'tap-target'],
            'ตัวเลือกร้าน (กดผิดแล้วกรอกยอดลงร้านผิด)' => ['/<select[^>]*name="shop_id"[^>]*>/', 'tap-height'],
        ];

        foreach ($checks as $label => [$pattern, $needed]) {
            $this->assertSame(1, preg_match($pattern, $body, $tag), 'ไม่พบ ' . $label);
            $this->assertStringContainsString(
                $needed,
                $tag[0],
                $label . ' ยังไม่มีพื้นที่กดขนาดมาตรฐาน (ต้องมีคลาส ' . $needed . ')'
            );
        }

        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="[^"]*tap-target[^"]*"[^>]*>🏪 จัดการร้าน<\/a>/u',
            (string)preg_replace('/\s+/', ' ', $body),
            'ปุ่ม "จัดการร้าน" ยังไม่มีพื้นที่กดขนาดมาตรฐาน'
        );
    }

    /** ⭐ กติกาความสูงต้องมีอยู่จริงใน CSS ไม่ใช่แค่ใส่ชื่อคลาสไว้เฉย ๆ */
    public function testTheTapRulesActuallyExistInTheStylesheet(): void
    {
        $header = (string)file_get_contents(dirname(__DIR__, 2) . '/includes/header.php');

        $this->assertMatchesRegularExpression(
            '/\.tap-height\s*\{[^}]*min-height:\s*44px/s',
            $header,
            'คลาส tap-height ไม่มีกติกาความสูงจริง'
        );
        $this->assertMatchesRegularExpression(
            '/\.tap-target::after\s*\{[^}]*height:\s*max\(100%,\s*44px\)/s',
            $header,
            'คลาส tap-target ไม่มีกติกาพื้นที่กดจริง'
        );
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*1023px\)/',
            $header,
            'กติกาพื้นที่กดต้องอยู่ในช่วงจอแคบ ไม่ใช่บังคับทุกขนาด'
        );
    }
}
