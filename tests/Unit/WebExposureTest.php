<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ไฟล์ภายในต้องโหลดผ่านเว็บไม่ได้
 *
 * ⚠️⚠️ เดิม **ไม่มี `.htaccess` เลยทั้งโปรเจกต์** — บน Hostinger รากโปรเจกต์คือ
 * `public_html/` ซึ่งเสิร์ฟตรง ๆ ใครก็ได้จึงพิมพ์ `https://โดเมน/.env` แล้วได้
 * รหัสฐานข้อมูลกับรหัสอีเมลไปเลย (พิสูจน์แล้วว่า `database/schema.sql`,
 * `composer.json`, `CLAUDE.md` โหลดได้จริงด้วยเซิร์ฟเวอร์ทดสอบ)
 *
 * ⚠️ เทสต์นี้ตรวจ "กฎในไฟล์" ไม่ใช่พฤติกรรมจริงของเซิร์ฟเวอร์ — `php -S` ที่ชุดเทสต์
 * ใช้ไม่อ่าน `.htaccess` เลย · **หลัง deploy ต้องเปิด `https://โดเมน/.env` ดูด้วยตา
 * ว่าได้ 403 จริง** (อยู่ใน docs/manual-checks.md แล้ว)
 */
final class WebExposureTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** โฟลเดอร์เดียวที่เบราว์เซอร์ต้องเข้าถึงได้ นอกจากหน้าเว็บที่ราก */
    private const PUBLIC_DIRECTORIES = ['api'];

    private function rootHtaccess(): string
    {
        $path = self::ROOT . '/.htaccess';
        $this->assertFileExists($path, 'ตัวกันการเข้าถึงผ่านเว็บหายไป — `.env` จะถูกดาวน์โหลดได้');

        return (string)file_get_contents($path);
    }

    /** ⭐ ไฟล์ที่ขึ้นต้นด้วยจุด (`.env`, `.git*`) ต้องถูกปิด */
    public function testDotFilesAreBlocked(): void
    {
        $this->assertMatchesRegularExpression(
            '/<FilesMatch\s+"\^\\\\\."/',
            $this->rootHtaccess(),
            'ไม่มีกฎปิดไฟล์ที่ขึ้นต้นด้วยจุด — `.env` (รหัส DB + รหัสอีเมล) โหลดได้ผ่านเว็บ'
        );
    }

    /**
     * ⭐ นามสกุลที่ไม่ควรเสิร์ฟต้องถูกปิดครบ
     *
     * @return array<string,array{0:string}>
     */
    public static function riskyExtensionProvider(): array
    {
        return [
            'ค่าตั้งค่าและรหัสผ่าน' => ['env'],
            'โครงสร้างฐานข้อมูล' => ['sql'],
            'รายการไลบรารี (ไว้หาช่องโหว่)' => ['json'],
            'เวอร์ชันไลบรารีที่ตรึงไว้' => ['lock'],
            'เอกสารภายใน' => ['md'],
            'บันทึกของระบบ' => ['log'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('riskyExtensionProvider')]
    public function testRiskyExtensionsAreBlocked(string $extension): void
    {
        $this->assertMatchesRegularExpression(
            '/<FilesMatch\s+"[^"]*\b' . preg_quote($extension, '/') . '\b[^"]*\$"/',
            $this->rootHtaccess(),
            "ไฟล์ .{$extension} ยังโหลดผ่านเว็บได้"
        );
    }

    /**
     * ⭐⭐ โฟลเดอร์ภายใน **ทุกอัน** ต้องถูกปิดในไฟล์ที่ราก
     *
     * ⚠️ ตรวจที่ไฟล์รากเท่านั้น ไม่นับ `.htaccess` ในแต่ละโฟลเดอร์ — `vendor/`
     * `logs/` `uploads/` ถูก .gitignore ไว้ ไฟล์ข้างในจึงอาจไม่ขึ้นเซิร์ฟเวอร์ไปด้วย
     *
     * ⚠️ เทสต์นี้กวาดโฟลเดอร์จริงมาเทียบ (แบบเดียวกับ `testEveryApiFileIsAccountedFor`)
     * เพิ่มโฟลเดอร์ใหม่แล้วลืมปิด ชุดเทสต์จะแดงทันที ไม่ใช่รู้ตอนข้อมูลรั่วแล้ว
     */
    public function testEveryInternalDirectoryIsBlockedAtTheRoot(): void
    {
        $htaccess = $this->rootHtaccess();

        $directories = [];
        foreach ((array)glob(self::ROOT . '/*', GLOB_ONLYDIR) as $path) {
            $name = basename((string)$path);
            if (!in_array($name, self::PUBLIC_DIRECTORIES, true)) {
                $directories[] = $name;
            }
        }
        sort($directories);
        $this->assertNotSame([], $directories, 'หาโฟลเดอร์ในโปรเจกต์ไม่เจอเลย — ตัวสแกนน่าจะเสีย');

        $unblocked = [];
        foreach ($directories as $name) {
            if (preg_match('/RewriteRule\s+\^\([^)]*\b' . preg_quote($name, '/') . '\b[^)]*\)\//', $htaccess) !== 1) {
                $unblocked[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unblocked,
            'โฟลเดอร์ที่ยังโหลดผ่านเว็บได้: ' . implode(', ', $unblocked)
        );
    }

    /** ⭐ ปิดการแสดงรายชื่อไฟล์ในโฟลเดอร์ (ไม่งั้นเห็นชื่อไฟล์ทั้งหมด) */
    public function testDirectoryListingIsOff(): void
    {
        $this->assertStringContainsString(
            'Options -Indexes',
            $this->rootHtaccess(),
            'ยังเปิดให้เห็นรายชื่อไฟล์ในโฟลเดอร์'
        );
    }
}
