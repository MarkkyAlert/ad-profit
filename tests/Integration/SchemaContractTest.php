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
