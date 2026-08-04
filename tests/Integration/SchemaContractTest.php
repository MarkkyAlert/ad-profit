<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use GoalService;
use PHPUnit\Framework\Attributes\DataProvider;
use RecordService;
use ShopService;

/**
 * โครงสร้างฐานข้อมูลต้องตรงกับกฎในโค้ด — ไม่มีอะไรบังคับไว้เลยจนถึงตอนนี้
 *
 * ตัวกันฝั่ง PHP (โน้ต ≤ 255 · ยอด ≤ 9,999,999,999.99 · ชื่อร้าน ≤ 100) ทำงานถูก
 * เพราะ "บังเอิญ" ตรงกับคอลัมน์ · ถ้าใครย่อคอลัมน์ลง **เทสต์ทุกตัวยังเขียว** แต่
 * production จะตัดข้อมูลทิ้งเงียบ ๆ บนโฮสต์ที่ไม่ได้เปิด strict mode
 *
 * และการ์ดตอนบูตเดิมดูแค่ "มีชื่อตาราง/คอลัมน์/index นี้ไหม" — ย่อ `note` เหลือ 50,
 * เปลี่ยน `revenue` เป็น DECIMAL(8,2), ลบ foreign key, เปลี่ยนเป็น MyISAM
 * **ผ่านหมดทุกอย่าง** (พิสูจน์แล้ว) ทั้งที่แต่ละอย่างทำข้อมูลหายคนละแบบ
 */
final class SchemaContractTest extends IntegrationTestCase
{
    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function columnTypeProvider(): array
    {
        $rows = [];
        foreach (schema_required_column_types() as [$table, $column, $expected]) {
            $rows[$table . '.' . $column] = [$table, $column, $expected];
        }

        return $rows;
    }

    /** ⭐ ชนิดคอลัมน์ต้องตรงกับที่ระบบคาดไว้เป๊ะ ๆ */
    #[DataProvider('columnTypeProvider')]
    public function testColumnTypesMatchWhatTheCodeAssumes(string $table, string $column, string $expected): void
    {
        $check = schema_column_type_matches($this->pdo, $table, $column, $expected);

        $this->assertTrue(
            ($check['ok'] ?? false) === true,
            sprintf('%s.%s ควรเป็น %s แต่เป็น %s', $table, $column, $expected, (string)($check['actual'] ?? '?'))
        );
    }

    /**
     * ⭐ ตัวเลขในโค้ดกับความยาวคอลัมน์ต้องเป็นเลขเดียวกัน
     *
     * เขียนคู่ไว้ตรงนี้ เพื่อให้การแก้ค่าใดค่าหนึ่งข้างเดียวทำให้เทสต์แดง
     */
    public function testTheConstantsInCodeMatchTheColumnDefinitions(): void
    {
        $this->assertSame(255, RecordService::NOTE_MAX_LENGTH, 'โน้ต: ค่าคงที่ไม่ตรงกับ varchar(255)');
        $this->assertSame(9999999999.99, RecordService::MAX_AMOUNT, 'ยอด: ไม่ตรงกับ decimal(12,2)');
        $this->assertSame(2, RecordService::AMOUNT_DECIMALS, 'ทศนิยม: ไม่ตรงกับ decimal(12,2)');
        $this->assertSame(RecordService::MAX_AMOUNT, GoalService::MAX_AMOUNT, 'เพดานเป้ากับเพดานรายวันไม่ตรงกัน');
        $this->assertSame(100, ShopService::MAX_SHOP_NAME_LENGTH, 'ชื่อร้าน: ไม่ตรงกับ varchar(100)');
        $this->assertSame(120, \ProfileService::MAX_DISPLAY_NAME_LENGTH, 'ชื่อที่แสดง: ไม่ตรงกับ varchar(120)');

        // และ decimal(12,2) ต้องเก็บ MAX_AMOUNT ได้พอดี ไม่ล้น
        $digits = strlen(str_replace(['.', '-'], '', (string)RecordService::MAX_AMOUNT));
        $this->assertLessThanOrEqual(12, $digits, 'MAX_AMOUNT ยาวเกินที่ decimal(12,2) เก็บได้');
    }

    /**
     * ⭐ การเชื่อมต่อที่แอปใช้จริงต้องเป็น strict mode
     *
     * ⚠️ เทสต์ integration ต่อ PDO เอง (ไม่ผ่าน `db()`) จึงไม่ได้ครอบเรื่องนี้เลย
     * ต้องเรียก `db()` ตรง ๆ · ถ้าไม่ strict MySQL จะตัดข้อมูลให้พอดีแทนที่จะปฏิเสธ
     */
    public function testTheApplicationConnectionUsesStrictMode(): void
    {
        // ⚠️ ต้องรันใน process แยกและเรียก `db()` ตัวจริง — `tests/bootstrap.php`
        // ไม่ได้ include `includes/database.php` และเทสต์ integration ต่อ PDO เอง
        // จึงไม่มีทางเห็นการตั้งค่าที่ `db()` ทำเลย
        $root = dirname(__DIR__, 2);
        $script = sprintf(
            'require %s; require %s; echo (string)db()->query("SELECT @@session.sql_mode")->fetchColumn();',
            var_export($root . '/includes/config.php', true),
            var_export($root . '/includes/database.php', true)
        );

        $mode = (string)shell_exec(sprintf(
            'DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=%s php -d error_log= -r %s 2>&1',
            escapeshellarg((string)(getenv('TEST_DB_HOST') ?: '127.0.0.1')),
            escapeshellarg((string)(getenv('TEST_DB_PORT') ?: '3306')),
            escapeshellarg((string)(getenv('TEST_DB_NAME') ?: 'ad_profit_test')),
            escapeshellarg((string)(getenv('TEST_DB_USER') ?: 'root')),
            escapeshellarg((string)(getenv('TEST_DB_PASS') ?: '')),
            escapeshellarg($script)
        ));

        $this->assertStringContainsString('STRICT_ALL_TABLES', $mode, 'การเชื่อมต่อของแอปไม่ได้เป็น strict: ' . $mode);
        $this->assertStringContainsString('NO_ZERO_DATE', $mode);
    }

    /**
     * ⭐ รายชื่อที่การ์ดใช้ต้องไม่ว่าง
     *
     * ⚠️ `phpunit.xml` ตั้ง `failOnWarning`/`failOnNotice` แต่ไม่ได้ตั้ง `failOnRisky`
     * ถ้าใครทำรายชื่อให้ว่าง ลูปจะไม่ยืนยันอะไรเลย → PHPUnit ขึ้นว่า "risky" แต่
     * exit code ยังเป็น 0 และ CI ยังเขียว (พิสูจน์แล้ว)
     */
    public function testTheGuardListsAreNotEmpty(): void
    {
        $this->assertNotEmpty(schema_required_column_types(), 'รายชื่อชนิดคอลัมน์ว่าง');
        $this->assertNotEmpty(schema_transactional_tables(), 'รายชื่อตารางที่ต้องเป็น InnoDB ว่าง');
        $this->assertNotEmpty(schema_required_cascades(), 'รายชื่อ foreign key ว่าง');
        $this->assertNotEmpty(schema_required_collations(), 'รายชื่อ collation ว่าง');
        $this->assertNotEmpty(schema_required_unique_indexes(), 'รายชื่อ unique index ว่าง');
    }

    /** ⭐ collation ของชื่อร้านต้องเป็นตัวที่แยกอิโมจิได้ */
    public function testShopNameUsesTheCollationThatSeparatesEmoji(): void
    {
        foreach (schema_required_collations() as [$table, $column, $expected]) {
            $check = schema_collation_matches($this->pdo, $table, $column, $expected);
            $this->assertTrue(
                ($check['ok'] ?? false) === true,
                sprintf('%s.%s ควรใช้ %s แต่ใช้ %s', $table, $column, $expected, (string)($check['actual'] ?? '?'))
            );
        }
    }

    /** ⭐ ทุกตารางที่ใช้ transaction ต้องเป็น InnoDB — MyISAM ทำให้ rollBack ไม่ทำอะไรเลย */
    public function testEveryTransactionalTableUsesInnoDb(): void
    {
        foreach (schema_transactional_tables() as $table) {
            $this->assertTrue(
                schema_table_is_innodb($this->pdo, $table),
                $table . ' ไม่ใช่ InnoDB — การยกเลิกรายการ (rollback) จะไม่ทำอะไรเลย'
            );
        }
    }

    /**
     * ⭐ ลบร้านแล้วข้อมูลข้างในต้องหายตาม — `deleteShop()` พึ่ง cascade ล้วน ๆ
     *
     * ถ้า foreign key หาย การลบร้านจะ "สำเร็จ" แต่ข้อมูลรายวันและเป้าหมายค้างอยู่
     */
    public function testEveryParentChildLinkCascadesOnDelete(): void
    {
        foreach (schema_required_cascades() as [$table, $constraint]) {
            $this->assertTrue(
                schema_cascade_exists($this->pdo, $table, $constraint),
                $table . '.' . $constraint . ' ไม่ได้ตั้ง ON DELETE CASCADE'
            );
        }
    }

    /**
     * ⭐ ชื่อร้านที่ต่างกันต้องเทียบว่าต่างจริง — โดยเฉพาะอิโมจิ
     *
     * `utf8mb4_unicode_ci` ไม่ให้น้ำหนักกับอักขระนอกระนาบพื้นฐาน อิโมจิทุกตัวจึง
     * เทียบเท่ากันหมด ทำให้ผู้ใช้ถูกพาไปร้านอื่นโดยไม่รู้ตัว
     */
    public function testShopNamesCompareEmojiAsDifferent(): void
    {
        $row = $this->pdo
            ->query('SELECT "🚀" = "🎉" AS emoji, "ร้าน A" = "ร้าน a" AS same_case FROM shops LIMIT 1')
            ->fetch();

        if ($row === false) {
            $userId = $this->createUser();
            $this->createShop($userId, 'ร้านทดสอบ');
            $row = $this->pdo
                ->query('SELECT "🚀" = "🎉" AS emoji, "ร้าน A" = "ร้าน a" AS same_case FROM shops LIMIT 1')
                ->fetch();
        }

        $collation = (string)$this->pdo->query(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "shops" AND COLUMN_NAME = "name"'
        )->fetchColumn();

        $this->assertNotSame(
            'utf8mb4_unicode_ci',
            $collation,
            'shops.name ใช้ collation ที่มองอิโมจิทุกตัวเท่ากันหมด'
        );

        $emojiEqual = (int)$this->pdo
            ->query('SELECT "🚀" COLLATE ' . $collation . ' = "🎉" AS same')->fetchColumn();
        $this->assertSame(0, $emojiEqual, 'อิโมจิคนละตัวถูกมองว่าเป็นชื่อเดียวกัน');

        // แต่ยังต้องถือว่าพิมพ์ใหญ่/เล็กเป็นชื่อเดียวกัน (พฤติกรรมที่ตั้งใจ)
        $caseEqual = (int)$this->pdo
            ->query('SELECT "ร้าน A" COLLATE ' . $collation . ' = "ร้าน a" AS same')->fetchColumn();
        $this->assertSame(1, $caseEqual, 'เปลี่ยนพฤติกรรม "พิมพ์ชื่อเดิม = สลับไปร้านนั้น" โดยไม่ตั้งใจ');
    }
}
