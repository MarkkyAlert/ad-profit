<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * ⭐ สคริปต์ `cron/` ต้องรันได้จริงและตอบรหัสจบให้ถูก
 *
 * ⚠️ coverage gap จาก logic review 2026-08-07: เดิมมีเทสต์เฉพาะ **ระดับ repository**
 * (`LogCleanupRepositoryTest`, `PasswordResetRepositoryTest`) ส่วน **ตัวสคริปต์** ไม่เคยถูกรันเลย
 * — ทั้งที่มันคือสิ่งที่ cron ของโฮสต์เรียกจริง และมี logic ของตัวเองอยู่ 2 อย่าง:
 *   1. ด่าน `PHP_SAPI !== 'cli'` (กันไม่ให้เรียกผ่านเว็บ)
 *   2. รหัสจบ 0/1 — cron ของโฮสต์ใช้ตัวนี้ตัดสินว่าจะส่งเมลแจ้งเตือนไหม
 *
 * ⚠️ สคริปต์ `require includes/bootstrap.php` ซึ่งเปิด session + ต่อ DB + schema guard
 * จึงรันในโปรเซสของ PHPUnit ไม่ได้ ต้องยิงเป็นโปรเซสแยกเหมือนที่ cron ทำ
 */
final class CronScriptTest extends IntegrationTestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function cronScriptProvider(): array
    {
        return [
            'ล้าง log เก่า' => ['cron/cleanup-logs.php'],
            'ล้าง token รีเซ็ตรหัสผ่านที่หมดอายุ' => ['cron/cleanup-password-reset-tokens.php'],
        ];
    }

    /**
     * @return array{status:int,stdout:string,stderr:string}
     */
    private function runScript(string $relativePath, bool $asWeb = false): array
    {
        $root = dirname(__DIR__, 2);
        $credentials = self::testDatabaseCredentials();
        if ($credentials === null) {
            $this->markTestSkipped('ไม่มีค่าเชื่อมต่อ test DB');
        }

        $environment = array_merge($_ENV, getenv(), [
            'DB_HOST' => $credentials['host'],
            'DB_PORT' => $credentials['port'],
            'DB_NAME' => $credentials['name'],
            'DB_USER' => $credentials['user'],
            'DB_PASS' => $credentials['pass'],
        ]);

        // `-r` จำลอง "ถูกเรียกผ่านเว็บ" ไม่ได้ — PHP_SAPI ของ CLI คือ 'cli' เสมอ
        // จึงตรวจด่านนี้ด้วยการอ่านโค้ดแทน (ดู testTheScriptRefusesToRunFromTheWeb)
        $command = sprintf('php %s 2>&1', escapeshellarg($root . '/' . $relativePath));

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $root, $environment);
        $this->assertIsResource($process, 'เรียกสคริปต์ไม่ขึ้น: ' . $relativePath);

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * ⭐ รันแล้วต้องจบด้วยรหัส 0 และพิมพ์สรุปออกมา — ไม่ใช่ fatal เงียบ ๆ
     *
     * ⚠️ รหัสจบคือสิ่งเดียวที่ cron ของโฮสต์ใช้ตัดสินว่างานสำเร็จไหม
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cronScriptProvider')]
    public function testTheScriptRunsCleanlyFromCli(string $relativePath): void
    {
        $result = $this->runScript($relativePath);

        $this->assertSame(
            0,
            $result['status'],
            $relativePath . ' จบด้วยรหัส ' . $result['status'] . ' — ผลลัพธ์: ' . $result['stdout'] . $result['stderr']
        );
        $this->assertNotSame('', trim($result['stdout']), $relativePath . ' ไม่พิมพ์อะไรเลย');
        $this->assertStringNotContainsStringIgnoringCase('fatal', $result['stdout'] . $result['stderr']);
    }

    /**
     * ⚠️ ต้องกันไม่ให้เรียกผ่านเว็บ — ไฟล์ทุกไฟล์ในโปรเจกต์เสิร์ฟผ่านเว็บได้โดยปริยาย
     * (ดูหัวข้อ "การเข้าถึงผ่านเว็บ" ใน CLAUDE.md) · `.htaccess` ปิดโฟลเดอร์ไว้อีกชั้น
     * แต่ด่านในตัวสคริปต์คือชั้นที่ไม่พึ่งการตั้งค่าของเซิร์ฟเวอร์
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cronScriptProvider')]
    public function testTheScriptRefusesToRunFromTheWeb(string $relativePath): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

        $this->assertMatchesRegularExpression(
            "/PHP_SAPI\s*!==\s*'cli'/",
            $source,
            $relativePath . ' ไม่มีด่านกันการเรียกผ่านเว็บ'
        );
        $this->assertStringContainsString(
            'http_response_code(403)',
            $source,
            $relativePath . ' ไม่ได้ตอบ 403 เมื่อถูกเรียกผ่านเว็บ'
        );
    }

    /** ⭐ ล้าง token ที่หมดอายุจริง — แถวที่ยังไม่หมดอายุต้องอยู่ครบ */
    public function testExpiredResetTokensAreDeletedButLiveOnesSurvive(): void
    {
        $userId = $this->createUser();

        $insert = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :hash, :expires_at, NOW())'
        );
        $insert->execute([
            ':user_id' => $userId,
            ':hash' => str_repeat('a', 64),
            ':expires_at' => date('Y-m-d H:i:s', time() - 3600),   // หมดอายุไปแล้ว 1 ชม.
        ]);

        $userTwo = $this->createUser('live@example.com');
        $insert->execute([
            ':user_id' => $userTwo,
            ':hash' => str_repeat('b', 64),
            ':expires_at' => date('Y-m-d H:i:s', time() + 3600),   // ยังใช้ได้อีก 1 ชม.
        ]);

        $result = $this->runScript('cron/cleanup-password-reset-tokens.php');
        $this->assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);

        $remaining = $this->pdo
            ->query('SELECT token_hash FROM password_reset_tokens')
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(
            [str_repeat('b', 64)],
            $remaining,
            'ล้าง token ผิดตัว — ต้องเหลือเฉพาะตัวที่ยังไม่หมดอายุ'
        );
    }
}
