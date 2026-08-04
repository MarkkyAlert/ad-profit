<?php

declare(strict_types=1);

namespace Tests\Integration;

use GoalRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use RuntimeException;
use ShopRepository;
use Throwable;
use UserRepository;

/**
 * Base class สำหรับ integration test — ต่อ MySQL/MariaDB test DB จริง
 *
 * กฎ (ตาม CLAUDE.md > การเทสต์ > Integration):
 * - ใช้ DB แยกผ่าน env TEST_DB_* (default name = ad_profit_test) ห้ามแตะข้อมูลจริง
 * - seed schema จาก database/schema.sql ครั้งเดียว (schema เป็น DROP+CREATE อยู่แล้ว)
 * - isolate แต่ละ test ด้วย TRUNCATE (ไม่ใช่ transaction rollback — service เปิด transaction เองภายใน)
 * - ถ้าต่อ test DB ไม่ได้ → markTestSkipped (ไม่ error)
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?PDO $connection = null;
    private static ?string $skipReason = null;
    private static bool $initialised = false;

    protected PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        if (self::$initialised) {
            return; // ต่อ + โหลด schema ครั้งเดียวต่อ process (static ใช้ร่วมทุก subclass)
        }
        self::$initialised = true;

        $name = (string)(getenv('TEST_DB_NAME') ?: 'ad_profit_test');

        // SAFETY: กันรันทับฐานข้อมูลจริง — ชื่อ test DB ต้องลงท้ายด้วย _test
        if (!str_ends_with($name, '_test')) {
            throw new RuntimeException(
                'SAFETY: TEST_DB_NAME ("' . $name . '") ต้องลงท้ายด้วย "_test" เพื่อกันรันทับฐานข้อมูลจริง'
            );
        }

        $host = (string)(getenv('TEST_DB_HOST') ?: '127.0.0.1');
        $port = (string)(getenv('TEST_DB_PORT') ?: '3306');
        $user = (string)(getenv('TEST_DB_USER') ?: 'root');
        $passEnv = getenv('TEST_DB_PASS');
        $pass = $passEnv === false ? '' : (string)$passEnv;

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $exception) {
            self::$skipReason = 'ต่อ test DB ไม่ได้ (' . $name . '): ' . $exception->getMessage()
                . ' — วิธีตั้ง: CREATE DATABASE ' . $name . '; แล้ว set env TEST_DB_HOST / TEST_DB_NAME / TEST_DB_USER / TEST_DB_PASS';
            return;
        }

        try {
            self::loadSchema($pdo);
        } catch (Throwable $exception) {
            self::$skipReason = 'โหลด database/schema.sql เข้า test DB ไม่ได้: ' . $exception->getMessage();
            return;
        }

        self::$connection = $pdo;
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            $this->markTestSkipped(self::$skipReason ?? 'test DB ไม่พร้อมใช้งาน');
        }

        $this->pdo = self::$connection;
        $this->truncateAll();
    }

    /**
     * โหลด schema.sql เข้า test DB
     * ⚠️ ตัด CREATE DATABASE / USE ออก — schema.sql hardcode ชื่อ `ad_profit` (DB จริง)
     *    เราต่อ PDO ไป test DB อยู่แล้ว จึงบังคับให้ DDL ทุกคำสั่งรันบน DB ที่ต่ออยู่เท่านั้น
     */
    private static function loadSchema(PDO $pdo): void
    {
        $path = dirname(__DIR__, 2) . '/database/schema.sql';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('อ่าน schema.sql ไม่ได้: ' . $path);
        }

        // ตัด comment รายบรรทัด (schema ไม่มี ; ภายใน statement นอกจากตัวปิด)
        $lines = preg_split('/\r?\n/', $raw) ?: [];
        $filtered = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line) === 1) {
                continue;
            }
            $filtered[] = $line;
        }

        $statements = array_filter(
            array_map('trim', explode(';', implode("\n", $filtered))),
            static fn(string $statement): bool => $statement !== ''
        );

        foreach ($statements as $statement) {
            $head = strtoupper(ltrim($statement));
            if (str_starts_with($head, 'CREATE DATABASE') || str_starts_with($head, 'USE ')) {
                continue; // SAFETY: อย่าสลับ context ไป DB จริง
            }
            $pdo->exec($statement);
        }
    }

    private function truncateAll(): void
    {
        $tables = [
            'password_reset_tokens',
            'auth_rate_limits',
            'monthly_goals',
            'daily_records',
            'shops',
            'users',
        ];

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ---------- fixture helpers (ฉีด $pdo เข้า repo จริง) ----------

    protected function createUser(string $email = 'test@example.com', string $password = 'password123'): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        return (new UserRepository($this->pdo))->create($email, $hash);
    }

    protected function createShop(int $userId, string $name = 'ร้านทดสอบ'): int
    {
        return (new ShopRepository($this->pdo))->create($userId, $name);
    }

    protected function createRecord(int $shopId, string $date, float $revenue, float $adCost, ?string $note = null): void
    {
        (new RecordRepository($this->pdo))->upsert($shopId, $date, $revenue, $adCost, $note);
    }

    protected function createGoal(int $shopId, string $month, ?float $targetRevenue, ?float $targetProfit): void
    {
        (new GoalRepository($this->pdo))->upsert($shopId, $month, $targetRevenue, $targetProfit);
    }

    protected function countRows(string $table): int
    {
        // $table มาจาก whitelist ภายในเทสต์เท่านั้น (ไม่ใช่ user input)
        return (int)$this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
