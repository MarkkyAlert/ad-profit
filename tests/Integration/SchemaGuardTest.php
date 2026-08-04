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
