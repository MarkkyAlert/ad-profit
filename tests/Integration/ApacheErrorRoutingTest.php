<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * ⭐⭐⭐ **ลิงก์ที่ไม่มีอยู่จริงต้องเข้าหน้าแจ้งข้อผิดพลาดของเรา ไม่ว่าติดตั้งไว้ตรงไหน**
 *
 * ⚠️⚠️ `ErrorPageTest` พิสูจน์แค่ว่า "ถ้าเปิด `error.php` ตรง ๆ แล้วหน้าตาถูก" — ซึ่ง
 * **ข้ามคำถามที่สำคัญกว่าไปทั้งข้อ**: ผู้ใช้ไม่ได้พิมพ์ `error.php` เอง เขาคลิกลิงก์เก่า
 * แล้วเว็บเซิร์ฟเวอร์ต้องเป็นคนพาไปหน้านั้น · การพาไปนั้นอยู่ใน `.htaccess` ซึ่ง
 * `php -S` **ไม่อ่านเลย** ตาข่ายเดิมจึงมองไม่เห็นอะไรเลยสักอย่าง
 *
 * ⚠️⚠️ บั๊กที่วัดได้จริงด้วย Apache จริง ก่อนแก้:
 *   · ติดตั้งที่รากโดเมน (แบบ production) → 404 + หน้าไทย ✅
 *   · ติดตั้งในโฟลเดอร์ย่อย `/ad-profit/` (แบบที่คู่มือบอกให้ทำตอนพัฒนา) →
 *     **หน้า "404 Not Found" ภาษาอังกฤษของ Apache** เพราะ `ErrorDocument 404 /error.php`
 *     นับจากรากโดเมน จึงชี้ออกไปนอกแอป
 *
 * ⚠️ ทดลองกับ Apache จริงแล้วว่า **แก้ที่ `ErrorDocument` ไม่ได้**: ค่าที่ไม่ขึ้นต้นด้วย `/`
 * (`error.php` · `./error.php`) ถูกตีความเป็น **ข้อความ** แล้วพิมพ์คำว่า "error.php"
 * ออกมาเป็นเนื้อหาทั้งหน้า → จึงต้องใช้ `mod_rewrite` ซึ่งพาธสัมพัทธ์กับโฟลเดอร์ของ
 * `.htaccess` เอง ทำให้ถูกต้องทั้งสองแบบโดยไม่ต้องแก้ไฟล์ตามที่ติดตั้ง
 *
 * ⚠️ เทสต์นี้ต้องมี Apache ในเครื่อง — บนเครื่องที่ไม่มีจะข้าม (CI ของ GitHub ไม่มี XAMPP)
 * `testTheRoutingRulesStayRelative()` จึงเป็นด่านที่รันได้ทุกที่คู่กันไว้
 */
final class ApacheErrorRoutingTest extends TestCase
{
    private static ?string $httpd = null;
    private static string $configPath = '';
    private static int $port = 0;

    /** หา Apache ในเครื่อง — XAMPP ก่อน แล้วค่อยดูใน PATH */
    private static function findHttpd(): ?string
    {
        $candidates = ['/Applications/XAMPP/xamppfiles/bin/httpd'];
        foreach (['httpd', 'apache2'] as $name) {
            $found = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($found !== '') {
                $candidates[] = $found;
            }
        }

        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * ยก Apache จริงขึ้นมาโดยชี้ราก **เหนือ** โปรเจกต์ขึ้นหนึ่งชั้น
     * แอปจึงอยู่ใต้ `/<ชื่อโฟลเดอร์>/` เหมือนติดตั้งแบบ `http://localhost/ad-profit/`
     *
     * ⚠️ ต้อง `AllowOverride All` ไม่งั้น Apache ไม่อ่าน `.htaccess` เลย แล้วเทสต์จะ
     * เขียว/แดงด้วยเหตุผลที่ไม่เกี่ยวกับสิ่งที่กำลังพิสูจน์
     */
    private static function bootApache(string $httpd): bool
    {
        $root = dirname(__DIR__, 2);
        $docRoot = dirname($root);
        $work = sys_get_temp_dir() . '/ad-profit-apache-' . getmypid();
        @mkdir($work, 0755, true);

        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return false;
        }
        $name = (string)stream_socket_get_name($socket, false);
        self::$port = (int)substr($name, (int)strrpos($name, ':') + 1);
        fclose($socket);

        $serverRoot = dirname(dirname($httpd));
        $modules = $serverRoot . '/modules';
        if (!is_dir($modules)) {
            $modules = '/usr/lib/apache2/modules';
        }

        $load = static function (string $module, string $file) use ($modules): string {
            return is_file($modules . '/' . $file) ? "LoadModule {$module} {$modules}/{$file}\n" : '';
        };

        $config = "ServerRoot \"{$serverRoot}\"\n"
            . 'Listen 127.0.0.1:' . self::$port . "\n"
            . 'ServerName localhost:' . self::$port . "\n"
            . $load('authz_core_module', 'mod_authz_core.so')
            . $load('authz_host_module', 'mod_authz_host.so')
            . $load('access_compat_module', 'mod_access_compat.so')
            . $load('mime_module', 'mod_mime.so')
            . $load('dir_module', 'mod_dir.so')
            . $load('log_config_module', 'mod_log_config.so')
            . $load('unixd_module', 'mod_unixd.so')
            . $load('alias_module', 'mod_alias.so')
            . $load('rewrite_module', 'mod_rewrite.so')
            . $load('php_module', 'libphp.so')
            . "AddType application/x-httpd-php .php\n"
            . "DirectoryIndex index.php index.html\n"
            . "PidFile \"{$work}/httpd.pid\"\n"
            . "ErrorLog \"{$work}/error.log\"\n"
            . "DocumentRoot \"{$docRoot}\"\n"
            . "<Directory />\n    AllowOverride None\n    Require all denied\n</Directory>\n"
            . "<Directory \"{$docRoot}\">\n    Options FollowSymLinks\n    AllowOverride All\n"
            . "    Require all granted\n</Directory>\n";

        self::$configPath = $work . '/httpd.conf';
        file_put_contents(self::$configPath, $config);

        exec(sprintf('%s -f %s -k start 2>&1', escapeshellarg($httpd), escapeshellarg(self::$configPath)), $out, $code);
        if ($code !== 0) {
            return false;
        }

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $probe = @file_get_contents('http://127.0.0.1:' . self::$port . '/', false, stream_context_create([
                'http' => ['ignore_errors' => true, 'timeout' => 1],
            ]));
            if ($probe !== false) {
                return true;
            }
            usleep(150000);
        }

        return false;
    }

    public static function setUpBeforeClass(): void
    {
        $httpd = self::findHttpd();
        if ($httpd === null) {
            return;
        }

        if (self::bootApache($httpd)) {
            self::$httpd = $httpd;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$httpd !== null && self::$configPath !== '') {
            exec(sprintf(
                '%s -f %s -k stop 2>/dev/null',
                escapeshellarg(self::$httpd),
                escapeshellarg(self::$configPath)
            ));
        }
        self::$httpd = null;
    }

    /**
     * @return array{status:int, body:string}
     *
     * ⚠️ ใช้ cURL ไม่ใช่ `$http_response_header` — ตัวหลังเลิกใช้แล้วใน PHP 8.5
     * และคำเตือนของมันจะไปปนกับสิ่งที่เทสต์กำลังตรวจ (บทเรียนเดียวกับ smoke test)
     * ⚠️ ไม่เรียก `curl_close()` ด้วยเหตุผลเดียวกัน
     */
    private function request(string $path): array
    {
        $handle = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 5);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    private function requireApache(): void
    {
        if (self::$httpd === null) {
            $this->markTestSkipped('เครื่องนี้ไม่มี Apache — ข้ามการตรวจการนำทางจริง');
        }
    }

    /**
     * ⭐⭐⭐ ติดตั้งในโฟลเดอร์ย่อย แล้วเปิดลิงก์ที่ไม่มีอยู่ → ต้องได้หน้าไทย + รหัส 404
     *
     * นี่คือเคสที่พังจริงก่อนแก้ และเป็นเคสที่ **ทุกคนที่ทำตามคู่มือตอนพัฒนาเจอ**
     */
    public function testAMissingLinkReachesOurOwnPageWhenInstalledInASubfolder(): void
    {
        $this->requireApache();

        $folder = basename(dirname(__DIR__, 2));
        $result = $this->request('/' . $folder . '/ลิงก์ที่ไม่มีอยู่จริง');

        $this->assertSame(
            404,
            $result['status'],
            'ลิงก์ที่ไม่มีอยู่ต้องตอบ 404 แต่ได้ ' . $result['status']
        );
        $this->assertStringContainsString(
            'ไม่พบหน้าที่ต้องการ',
            $result['body'],
            'ยังเป็นหน้า 404 ของเว็บเซิร์ฟเวอร์ ไม่ใช่หน้าภาษาไทยของแอป — '
            . 'ผู้ใช้ที่กดลิงก์เก่าจะเจอหน้าเปล่าภาษาอังกฤษที่ไม่มีทางกลับ'
        );
    }

    /**
     * ⚠️⚠️ **ด้านตรงข้าม** — หน้าที่มีอยู่จริงต้องไม่ถูกดักไปหน้า error
     *
     * ตัวกันนี้สำคัญที่สุดในไฟล์: กฎที่เขียนพลาดจะส่ง **ทุกคำขอ** ไปหน้า error
     * = เว็บพังทั้งเว็บ · การ "แก้" ที่ทำแบบนั้นต้องแดง ไม่ใช่ผ่าน
     */
    public function testPagesThatExistAreNeverHijackedByTheErrorRoute(): void
    {
        $this->requireApache();

        $folder = basename(dirname(__DIR__, 2));

        foreach (['/login.php', '/dashboard.php', '/api/records.php'] as $path) {
            $result = $this->request('/' . $folder . $path);

            $this->assertNotSame(
                404,
                $result['status'],
                $path . ': ไฟล์ที่มีอยู่จริงถูกส่งไปหน้า error'
            );
            $this->assertStringNotContainsString(
                'ไม่พบหน้าที่ต้องการ',
                $result['body'],
                $path . ': ไฟล์ที่มีอยู่จริงถูกแทนที่ด้วยหน้าแจ้งข้อผิดพลาด'
            );
        }
    }

    /**
     * ⚠️ โฟลเดอร์ภายในต้องยังถูกปิดเหมือนเดิม — ไม่ใช่กลายเป็น 404
     *
     * กฎใหม่ต้องอยู่ **หลัง** กฎปิดโฟลเดอร์ ไม่งั้นคำขอไปยังโฟลเดอร์ที่ถูก .gitignore
     * (ซึ่งอาจไม่มีอยู่บนเซิร์ฟเวอร์จริง) จะเปลี่ยนจาก 403 เป็น 404
     */
    public function testInternalFoldersAreStillBlocked(): void
    {
        $this->requireApache();

        $folder = basename(dirname(__DIR__, 2));

        foreach (['/includes/config.php', '/app/Services/RecordService.php', '/.env'] as $path) {
            $this->assertSame(
                403,
                $this->request('/' . $folder . $path)['status'],
                $path . ': ไฟล์ภายในไม่ได้ถูกปิดแล้ว'
            );
        }
    }

    /**
     * ⭐⭐ ด่านที่รันได้ทุกเครื่อง แม้ไม่มี Apache (เช่นบน CI)
     *
     * ⚠️ พาธของกฎนำทางต้อง **ไม่ขึ้นต้นด้วย `/`** — พาธที่ขึ้นต้นด้วย `/` นับจาก
     * รากโดเมน ซึ่งเป็นสาเหตุของบั๊กเดิมทั้งหมด · และเงื่อนไข "ไฟล์/โฟลเดอร์ไม่มีอยู่จริง"
     * ต้องอยู่ครบทั้งสองบรรทัด ไม่งั้นทุกคำขอถูกดักไปหน้า error
     */
    public function testTheRoutingRulesStayRelative(): void
    {
        $htaccess = (string)file_get_contents(dirname(__DIR__, 2) . '/.htaccess');

        $this->assertSame(
            1,
            preg_match('/^\s*RewriteRule\s+\^\s+(\S+)\s+\[L\]\s*$/m', $htaccess, $matched),
            'ไม่มีกฎพาลิงก์ที่ไม่มีอยู่จริงไปหน้าแจ้งข้อผิดพลาด'
        );
        $this->assertSame(
            'error.php',
            $matched[1],
            'พาธต้องเป็น "error.php" แบบสัมพัทธ์ — พาธที่ขึ้นต้นด้วย / นับจากรากโดเมน '
            . 'จะพังทันทีเมื่อติดตั้งในโฟลเดอร์ย่อย'
        );

        foreach (['!-f', '!-d'] as $guard) {
            $this->assertSame(
                1,
                preg_match('/RewriteCond\s+%\{REQUEST_FILENAME\}\s+' . preg_quote($guard, '/') . '/', $htaccess),
                'ขาดเงื่อนไข ' . $guard . ' — ทุกคำขอจะถูกส่งไปหน้า error รวมถึงหน้าที่มีอยู่จริง'
            );
        }
    }
}
