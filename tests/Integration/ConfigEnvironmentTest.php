<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `includes/config.php` — ไฟล์ที่อยู่ใต้ทุกอย่าง แต่ไม่มีเทสต์ตัวไหนโหลดมันเลย
 *
 * `tests/bootstrap.php` define ค่าคงที่ทั้ง 25 ตัวเอง แล้ว include แค่ `functions.php`
 * กับ `auth.php` — `config.php` จึงไม่เคยถูกรันในเทสต์ ทั้งที่มันเป็นตัวตัดสิน
 * **ความยาวรหัสผ่านขั้นต่ำ · เวลาหมดอายุ session · เพดานการเดารหัส · และการ์ดโครงสร้าง DB**
 *
 * ที่ต้องกัน: พิมพ์ผิดใน `.env` แค่ตัวเดียวแล้วค่าเปลี่ยนไปเงียบ ๆ — เคยเกิดจริง
 * `PASSWORD_MIN_LENGTH=eight` ทำให้ความยาวขั้นต่ำเหลือ 4 โดยไม่มี log อะไรเลย
 *
 * ⚠️ ต้องรัน `config.php` ใน process แยก เพราะค่าคงที่ define แล้วเปลี่ยนไม่ได้
 */
final class ConfigEnvironmentTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../includes/config.php';

    /** @return array{value:string,log:string} */
    private function load(string $key, string $rawValue): array
    {
        $script = sprintf(
            'require %s; $v = constant(%s); echo $v === true ? "true" : ($v === false ? "false" : $v);',
            var_export(realpath(self::CONFIG), true),
            var_export($key, true)
        );

        $command = sprintf(
            '%s=%s php -d error_log= -r %s 2>&1',
            $key,
            escapeshellarg($rawValue),
            escapeshellarg($script)
        );

        $output = (string)shell_exec($command);
        $lines = array_values(array_filter(explode("\n", trim($output)), static fn(string $l): bool => $l !== ''));

        $value = array_pop($lines) ?? '';
        return ['value' => $value, 'log' => implode("\n", $lines)];
    }

    /**
     * ⭐ ค่าที่พิมพ์ผิดต้องกลับไปใช้ค่าปริยาย **และเขียน log** ทุกตัว
     *
     * ⚠️ 5 ตัวนี้เคยข้าม `config_positive_int()` แล้วใช้ `(int)(getenv() ?: default)`
     * ซึ่ง `(int)"eight"` = 0 · `(int)"-5"` = -5 — ผ่านเข้าไปเงียบ ๆ ทั้งคู่
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function malformedValueProvider(): array
    {
        return [
            'ความยาวรหัสผ่าน: ไม่ใช่ตัวเลข' => ['PASSWORD_MIN_LENGTH', 'eight', '8'],
            'ความยาวรหัสผ่าน: ติดลบ' => ['PASSWORD_MIN_LENGTH', '-5', '8'],
            'ความยาวรหัสผ่าน: ศูนย์' => ['PASSWORD_MIN_LENGTH', '0', '8'],
            'อายุลิงก์รีเซ็ต: ไม่ใช่ตัวเลข' => ['PASSWORD_RESET_TOKEN_TTL_HOURS', 'หนึ่ง', '1'],
            'อายุลิงก์รีเซ็ต: ศูนย์' => ['PASSWORD_RESET_TOKEN_TTL_HOURS', '0', '1'],
            'พอร์ตอีเมล: ไม่ใช่ตัวเลข' => ['MAIL_PORT', 'abc', '587'],
            'เวลารอของอีเมล: ติดลบ' => ['MAIL_TIMEOUT_SECONDS', '-1', '15'],
            'จำนวนครั้งที่ลองส่งซ้ำ: ขยะ' => ['MAIL_RETRY_ATTEMPTS', 'สองครั้ง', '1'],
            'เพดานการเดารหัส: ขยะ' => ['RATE_LIMIT_MAX_ATTEMPTS', '5 ครั้ง', '5'],
            'cooldown เปลี่ยนอีเมล: ขยะ' => ['EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS', '1 นาที', '60'],
            'เพดานส่งลิงก์เปลี่ยนอีเมล: ศูนย์' => ['EMAIL_CHANGE_RESEND_MAX_ATTEMPTS', '0', '5'],
            'หน้าต่างส่งลิงก์เปลี่ยนอีเมล: ขยะ' => ['EMAIL_CHANGE_RESEND_WINDOW_SECONDS', 'หนึ่งชั่วโมง', '3600'],
            'เวลาหมดอายุ session: ขยะ' => ['SESSION_IDLE_TIMEOUT_SECONDS', '4h', '14400'],
        ];
    }

    #[DataProvider('malformedValueProvider')]
    public function testAMalformedValueFallsBackAndIsLogged(string $key, string $raw, string $expected): void
    {
        $result = $this->load($key, $raw);

        $this->assertSame($expected, $result['value'], "{$key}=\"{$raw}\" ไม่ได้กลับไปใช้ค่าปริยาย");
        $this->assertStringContainsString(
            $key,
            $result['log'],
            "{$key}=\"{$raw}\" ถูกปฏิเสธเงียบ ๆ — ไม่มี log ให้ตามหาสาเหตุ"
        );
    }

    /** ⭐ ค่าที่ถูกต้องต้องถูกใช้จริง (กันเทสต์ข้างบนผ่านเพราะ "ใช้ค่าปริยายเสมอ") */
    public function testAValidValueIsActuallyUsed(): void
    {
        $this->assertSame('12', $this->load('PASSWORD_MIN_LENGTH', '12')['value']);
        $this->assertSame('2525', $this->load('MAIL_PORT', '2525')['value']);
        $this->assertSame('3', $this->load('PASSWORD_RESET_TOKEN_TTL_HOURS', '3')['value']);
    }

    /**
     * ⭐ การ์ดโครงสร้าง DB: "0" ต้องแปลว่าปิด และค่าขยะต้องไม่ปิดมันเงียบ ๆ
     *
     * ⚠️ เดิมใช้ `getenv() ?: 'true'` ซึ่ง **กลับหัวความหมายพอดี** — "0" เป็น falsy
     * จึงกลายเป็น 'true' (เปิด) ส่วน "disabled" กลายเป็น false (ปิดเงียบ ๆ)
     * การ์ดตัวนี้คือสิ่งที่กันไม่ให้ระบบรันบน DB ที่โครงสร้างไม่ตรง
     */
    public function testTheSchemaGuardReadsItsSwitchTheRightWayRound(): void
    {
        $this->assertSame('false', $this->load('SCHEMA_GUARD_ENABLED', '0')['value'], '"0" ไม่ได้ปิดการ์ด');
        $this->assertSame('false', $this->load('SCHEMA_GUARD_ENABLED', 'false')['value']);
        $this->assertSame('true', $this->load('SCHEMA_GUARD_ENABLED', 'true')['value']);

        $garbage = $this->load('SCHEMA_GUARD_ENABLED', 'disabled');
        $this->assertSame('true', $garbage['value'], 'ค่าขยะปิดการ์ดโครงสร้าง DB เงียบ ๆ');
        $this->assertStringContainsString('SCHEMA_GUARD_ENABLED', $garbage['log']);
    }

    /**
     * ⭐ ทุกค่าที่ระบบอ่านจาก env ต้องมีอยู่ใน `.env.example`
     *
     * ไม่งั้นคนตั้งเซิร์ฟเวอร์ไม่มีทางรู้ว่ามีค่านี้ให้ตั้ง — และค่าที่ลืมตั้ง
     * จะเงียบไปเลยเพราะทุกตัวมีค่าปริยาย
     */
    public function testEveryEnvKeyTheCodeReadsIsDocumented(): void
    {
        $root = dirname(__DIR__, 2);
        $example = (string)file_get_contents($root . '/.env.example');

        $used = [];
        foreach (['app', 'includes', 'api', 'cron'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $file) {
                if (!str_ends_with((string)$file, '.php')) {
                    continue;
                }

                preg_match_all(
                    '/getenv\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/',
                    (string)file_get_contents((string)$file),
                    $matched
                );
                foreach ($matched[1] as $key) {
                    $used[$key] = true;
                }
            }
        }

        $this->assertNotSame([], $used, 'หา getenv() ในโค้ดไม่เจอเลย — ตัวสแกนน่าจะเสีย');

        $missing = [];
        foreach (array_keys($used) as $key) {
            if (preg_match('/^\s*#?\s*' . preg_quote($key, '/') . '\s*=/m', $example) !== 1) {
                $missing[] = $key;
            }
        }
        sort($missing);

        $this->assertSame([], $missing, 'โค้ดอ่านค่าที่ไม่ได้เขียนไว้ใน .env.example: ' . implode(', ', $missing));
    }

    /**
     * รัน bootstrap ในโปรเซสใหม่แล้วอ่านค่า ini ที่ตั้งไว้จริง
     *
     * ⚠️ ต้องเป็นโปรเซสแยก — `session_start()` เรียกซ้ำในโปรเซสเดียวไม่ได้
     */
    private function bootstrapIniValue(string $setting): string
    {
        $script = 'require ' . var_export(realpath(__DIR__ . '/../../includes/bootstrap.php'), true)
            . '; echo "RESULT:", ini_get(' . var_export($setting, true) . ');';

        $command = sprintf(
            'APP_ENV=production SCHEMA_GUARD_ENABLED=0'
            . ' DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s php -r %s 2>&1',
            escapeshellarg((string)(getenv('TEST_DB_HOST') ?: '127.0.0.1')),
            escapeshellarg((string)(getenv('TEST_DB_PORT') ?: '3306')),
            escapeshellarg((string)(getenv('TEST_DB_NAME') ?: 'ad_profit_test')),
            escapeshellarg((string)(getenv('TEST_DB_USER') ?: 'root')),
            escapeshellarg((string)(getenv('TEST_DB_PASS') ?: '')),
            escapeshellarg($script)
        );

        $output = (string)shell_exec($command);
        $this->assertStringContainsString('RESULT:', $output, "bootstrap ไม่ถึงบรรทัดที่วัด:\n" . $output);

        return trim(substr($output, strpos($output, 'RESULT:') + 7));
    }

    /**
     * ⭐⭐ ตัวเก็บกวาด session ของ PHP ต้องไม่ลบไฟล์ก่อนที่แอปจะหมดเวลาเอง
     *
     * ⚠️ ค่าปริยายของ PHP คือ 1440 วินาที (24 นาที) · ถ้าไม่ตั้งเอง ผู้ใช้จะหลุด
     * จากระบบตั้งแต่พักไป ~24 นาที ทั้งที่แอปตั้งไว้ 4 ชั่วโมง (บั๊กเดิม พิสูจน์แล้ว)
     *
     * ⚠️ `SessionLifetimeTest` จับไม่ได้ เพราะมันปลอมเวลาลงไฟล์ session เอง
     * ตัวเก็บกวาดของ PHP จึงไม่เคยเข้ามาเกี่ยว — ลบบรรทัดนี้ทิ้งแล้วเทสต์ยังเขียวหมด
     */
    public function testPhpDoesNotSweepSessionsBeforeTheAppTimesOut(): void
    {
        $lifetime = (int)$this->bootstrapIniValue('session.gc_maxlifetime');

        $this->assertGreaterThan(
            (int)SESSION_IDLE_TIMEOUT_SECONDS,
            $lifetime,
            'PHP จะลบไฟล์ session ทิ้งก่อนที่แอปจะถือว่าหมดเวลา — ผู้ใช้หลุดเร็วกว่าที่ตั้งไว้'
        );
        $this->assertGreaterThan(
            (int)SESSION_ABSOLUTE_TIMEOUT_SECONDS,
            $lifetime,
            'สั้นกว่าเวลาหมดอายุแบบเด็ดขาด'
        );
    }

    /**
     * ⭐ หน้าที่ต้องล็อกอินต้องไม่ถูกเก็บไว้ในแคช
     *
     * ⚠️ โฮสต์ที่ตั้ง `session.cache_limiter=` ว่างไว้ จะไม่ส่ง `Cache-Control` เลย
     * ยอดขาย/กำไร/โปรไฟล์จึงถูกเก็บไว้ในเครื่องหรือ proxy ระหว่างทาง
     * ⚠️ ไม่มีเทสต์ไหนตรวจ header พวกนี้เลย — ลบบรรทัดที่ตั้งค่าแล้วยังเขียวทั้งชุด
     */
    public function testPagesBehindLoginAreNotCacheable(): void
    {
        $this->assertSame(
            'nocache',
            $this->bootstrapIniValue('session.cache_limiter'),
            'หน้าที่ต้องล็อกอินอาจถูกเก็บไว้ในแคชของเบราว์เซอร์หรือ proxy'
        );
    }

    /**
     * ⭐ ไฟล์ log ที่เขียนไม่ได้ ต้องถูกตรวจเจอ ไม่ใช่เขียนลงไปแล้วหายเงียบ
     *
     * ⚠️ เงื่อนไขเดิมถามแค่ว่า **โฟลเดอร์** เขียนได้ไหม · บนโฮสต์จริงไฟล์ log
     * มักถูกสร้างโดยผู้ใช้คนอื่น (root ตอน deploy, ตัวหมุน log ของ cPanel) แล้วเหลือ
     * สิทธิ์อ่านอย่างเดียว โฟลเดอร์ยังเขียนได้อยู่ เงื่อนไขเดิมจึงผ่าน แล้วชี้
     * `error_log` ไปที่ไฟล์นั้น — ข้อความของทั้งระบบหายเงียบตั้งแต่นั้น
     */
    public function testAnUnwritableLogFileFallsBackInsteadOfSwallowingEveryMessage(): void
    {
        $logDir = sys_get_temp_dir() . '/adprofit-logprobe-' . bin2hex(random_bytes(4)) . '/logs';
        $this->assertTrue(mkdir($logDir, 0775, true), 'สร้างโฟลเดอร์ทดสอบไม่ได้');
        $logFile = $logDir . '/php-error.log';
        touch($logFile);
        chmod($logFile, 0444);

        try {
            if (is_writable($logFile)) {
                // รันด้วยสิทธิ์ root — chmod ไม่มีผล วัดอะไรไม่ได้
                $this->markTestSkipped('ผู้ใช้ปัจจุบันเขียนไฟล์ 0444 ได้ (น่าจะเป็น root)');
            }

            $script = 'require ' . var_export(realpath(__DIR__ . '/../../includes/bootstrap.php'), true)
                . '; echo "RESULT:", ini_get("error_log");';

            // ⚠️ bootstrap ต่อฐานข้อมูลด้วย · ต้องส่ง DB_* ของ test DB เข้าไปด้วย
            // ไม่งั้นบนเครื่อง CI (รหัส root ไม่ว่าง) มันจะตายก่อนถึงบรรทัดที่วัด
            // แล้วเทสต์นี้จะแดงด้วยเหตุผลที่ไม่เกี่ยวกับสิ่งที่ตั้งใจตรวจเลย
            $command = sprintf(
                'LOG_FILE=%s APP_ENV=production SCHEMA_GUARD_ENABLED=0'
                . ' DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s php -r %s 2>&1',
                escapeshellarg($logFile),
                escapeshellarg((string)(getenv('TEST_DB_HOST') ?: '127.0.0.1')),
                escapeshellarg((string)(getenv('TEST_DB_PORT') ?: '3306')),
                escapeshellarg((string)(getenv('TEST_DB_NAME') ?: 'ad_profit_test')),
                escapeshellarg((string)(getenv('TEST_DB_USER') ?: 'root')),
                escapeshellarg((string)(getenv('TEST_DB_PASS') ?: '')),
                escapeshellarg($script)
            );
            $output = (string)shell_exec($command);

            $this->assertStringContainsString(
                'RESULT:',
                $output,
                "bootstrap ไม่ถึงบรรทัดที่วัด — ผลที่ได้:\n" . $output
            );
            $resolved = substr($output, strpos($output, 'RESULT:') + 7);

            $this->assertNotSame(
                $logFile,
                $resolved,
                'ยังชี้ error_log ไปที่ไฟล์ที่เขียนไม่ได้ — ข้อความของทั้งระบบจะหายเงียบ'
            );
        } finally {
            chmod($logFile, 0644);
            @unlink($logFile);
            @rmdir($logDir);
            @rmdir(dirname($logDir));
        }
    }
}
