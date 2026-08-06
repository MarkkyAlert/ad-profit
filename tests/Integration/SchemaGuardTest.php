<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * unique index ที่ระบบพึ่งพา — ถ้าหายไปแล้วระบบ "พังเงียบ" ไม่ใช่พังดัง
 *
 * ทดสอบสองชั้น:
 *  1. ชื่อ index ในรายการตรงกับ schema จริง (ถ้าพิมพ์ผิด guard จะเตือนตลอดเวลา)
 *  2. ถ้า index หายจริง ตัวตรวจจับได้ (ไม่ใช่ guard ที่ไม่มีใครพิสูจน์ว่าทำงาน)
 */
final class SchemaGuardTest extends IntegrationTestCase
{
    public function testEveryRequiredIndexExistsInTheRealSchema(): void
    {
        $required = schema_required_unique_indexes();
        $this->assertNotEmpty($required);

        foreach ($required as [$tableName, $indexName]) {
            $this->assertTrue(
                schema_unique_index_exists($this->pdo, $tableName, $indexName),
                "schema.sql ไม่มี unique index {$tableName}.{$indexName} ที่ guard ต้องการ"
            );
        }
    }

    /**
     * ⭐⭐ กวาดจาก **ฐานข้อมูลจริง** ไม่ใช่จากรายชื่อที่พิมพ์ไว้ในเทสต์
     *
     * ⚠️ นี่คือเหตุผลที่ต้องมีตัวนี้: `email_change_requests` ถูกเพิ่มเข้ามาโดยทำตามแบบของ
     * `password_reset_tokens` ทุกอย่าง — unique key คู่เดียวกัน, FK cascade เหมือนกัน,
     * แม้แต่คอมเมนต์ "ขอใหม่ทับของเดิม" ก็เหมือนกัน — **ยกเว้นการมาลงทะเบียนกับ guard**
     * และไม่มีอะไรทักเลย เพราะตัวกวาดเดิมเทียบกับรายชื่อที่พิมพ์ไว้ตายตัว
     *
     * ผลที่วัดจริงตอนกุญแจ `uq_email_change_user` หายไป (ทำ migration มือแล้วพิมพ์
     * `KEY` แทน `UNIQUE KEY`): ขอเปลี่ยนอีเมล 2 ครั้ง แล้ว **ลิงก์ทั้งสองใบยังใช้ได้**
     * ทั้งที่กติกาคือขอใหม่ต้องทำให้ของเก่าใช้ไม่ได้ทันที
     */
    public function testEveryUniqueIndexInTheDatabaseIsRegisteredWithTheGuard(): void
    {
        $registered = array_map(
            static fn(array $entry): string => $entry[0] . '.' . $entry[1],
            schema_required_unique_indexes()
        );

        $rows = $this->pdo->query(
            "SELECT DISTINCT TABLE_NAME, INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'"
        )->fetchAll(\PDO::FETCH_NUM);

        $this->assertNotEmpty($rows, 'อ่าน index จากฐานข้อมูลไม่ได้ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');

        foreach ($rows as [$tableName, $indexName]) {
            $this->assertContains(
                $tableName . '.' . $indexName,
                $registered,
                "ฐานข้อมูลมี unique index {$tableName}.{$indexName} แต่ guard ไม่รู้จัก — "
                . 'ถ้ามันหายไปบนเซิร์ฟเวอร์จริง ระบบจะพังเงียบ ๆ · เพิ่มใน schema_required_unique_indexes()'
            );
        }
    }

    /** ตารางใหม่ที่ลืมใส่ใน guard = MyISAM แล้ว rollBack() ไม่ทำอะไรเลยโดยไม่มีใครรู้ */
    public function testEveryTableInTheDatabaseIsRegisteredAsTransactional(): void
    {
        $registered = schema_transactional_tables();

        $tables = $this->pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotEmpty($tables, 'อ่านรายชื่อตารางไม่ได้ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');

        foreach ($tables as $tableName) {
            $this->assertContains(
                $tableName,
                $registered,
                "ฐานข้อมูลมีตาราง {$tableName} แต่ guard ไม่ได้ตรวจว่าเป็น InnoDB — "
                . 'เพิ่มใน schema_transactional_tables()'
            );
        }
    }

    /** FK ที่ลบตามแม่ต้องอยู่ในรายการทุกตัว ไม่งั้นลบผู้ใช้แล้วข้อมูลลูกค้างพร้อม token ที่ยังใช้ได้ */
    public function testEveryCascadeInTheDatabaseIsRegisteredWithTheGuard(): void
    {
        $registered = array_map(
            static fn(array $entry): string => $entry[0] . '.' . $entry[1],
            schema_required_cascades()
        );

        $rows = $this->pdo->query(
            "SELECT TABLE_NAME, CONSTRAINT_NAME
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND DELETE_RULE = 'CASCADE'"
        )->fetchAll(\PDO::FETCH_NUM);

        $this->assertNotEmpty($rows, 'อ่าน foreign key ไม่ได้ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');

        foreach ($rows as [$tableName, $constraintName]) {
            $this->assertContains(
                $tableName . '.' . $constraintName,
                $registered,
                "ฐานข้อมูลมี cascade {$tableName}.{$constraintName} แต่ guard ไม่รู้จัก — "
                . 'เพิ่มใน schema_required_cascades()'
            );
        }
    }

    /**
     * key ที่กันข้อมูลซ้ำต้องอยู่ในรายการ — เป็นกลไกเดียวที่เหลือหลังเลิกใช้ idempotency
     *
     * ถ้า uq_daily_records_shop_date หาย: ON DUPLICATE KEY UPDATE กลายเป็น INSERT ธรรมดา
     * → กรอกวันเดิมซ้ำได้หลายแถว ยอดรวมทุกหน้ารายงานบวมโดยไม่มีสัญญาณเตือน
     */
    public function testIndexesThatPreventDuplicateDataAreCovered(): void
    {
        $covered = array_map(
            static fn(array $entry): string => $entry[0] . '.' . $entry[1],
            schema_required_unique_indexes()
        );

        foreach ([
            'daily_records.uq_daily_records_shop_date',
            'monthly_goals.uq_monthly_goals_shop_month',
            'auth_rate_limits.uq_auth_rate_limits_bucket',
            'users.uq_users_email',
        ] as $critical) {
            $this->assertContains($critical, $covered, "guard ไม่ได้ตรวจ {$critical}");
        }
    }

    /** ตัวตรวจต้องจับได้จริงเมื่อ index หาย ไม่ใช่คืน true ตลอด */
    public function testDetectorReportsAMissingIndex(): void
    {
        $this->pdo->exec('ALTER TABLE daily_records DROP INDEX uq_daily_records_shop_date');
        try {
            $this->assertFalse(
                schema_unique_index_exists($this->pdo, 'daily_records', 'uq_daily_records_shop_date')
            );
        } finally {
            $this->pdo->exec(
                'ALTER TABLE daily_records ADD UNIQUE KEY uq_daily_records_shop_date (shop_id, record_date)'
            );
        }

        $this->assertTrue(
            schema_unique_index_exists($this->pdo, 'daily_records', 'uq_daily_records_shop_date')
        );
    }

    /** index ที่ไม่ใช่ unique ต้องไม่ถูกนับว่าผ่าน */
    public function testNonUniqueIndexDoesNotCount(): void
    {
        $this->assertFalse(
            schema_unique_index_exists($this->pdo, 'daily_records', 'idx_daily_records_record_date')
        );
    }
}
