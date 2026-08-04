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
}
