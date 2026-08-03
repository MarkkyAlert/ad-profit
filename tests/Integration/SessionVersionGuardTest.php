<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/GlobalDbFunction.php';

use TestDbFunctionState;

/**
 * isSessionVersionValid() — guard ที่รันทุก request แต่ไม่เคยมีเทสต์ครอบ
 *
 * เป็น integration เพราะต้องมี DB จริงให้ query ล้มแบบที่เกิดขึ้นได้จริง
 * (ตารางหาย / คอลัมน์หาย) ไม่ใช่ mock ที่โยน exception ตามสั่ง
 */
final class SessionVersionGuardTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestDbFunctionState::$pdo = $this->pdo;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        TestDbFunctionState::$pdo = null;
        parent::tearDown();
    }

    public function testValidWhenSessionVersionMatchesDatabase(): void
    {
        $userId = $this->createUser('guard@example.com');
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = 1;

        $this->assertTrue(isSessionVersionValid());
    }

    /** เปลี่ยน/รีเซ็ตรหัสผ่าน → version ใน DB เดินหน้า → session เก่าต้องใช้ไม่ได้ */
    public function testInvalidWhenDatabaseVersionMovedAhead(): void
    {
        $userId = $this->createUser('guard@example.com');
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = 1;

        $this->pdo->exec('UPDATE users SET session_version = 2 WHERE id = ' . $userId);

        $this->assertFalse(isSessionVersionValid());
    }

    public function testInvalidWhenUserNoLongerExists(): void
    {
        $_SESSION['user_id'] = 999999;
        $_SESSION['session_version'] = 1;

        $this->assertFalse(isSessionVersionValid());
    }

    public function testInvalidWhenSessionHasNoUser(): void
    {
        $this->assertFalse(isSessionVersionValid());
    }

    /**
     * DB สะดุด (ตารางหาย/ล่ม) ต้อง fail-closed
     *
     * เดิมคืน true ทุก exception — session ที่ถูกยกเลิกด้วยการรีเซ็ตรหัสผ่านจะกลับมา
     * ใช้ได้ทันทีที่ DB มีปัญหาชั่วขณะ
     */
    public function testFailsClosedWhenTheQueryErrors(): void
    {
        $userId = $this->createUser('guard@example.com');
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = 1;

        $this->pdo->exec('RENAME TABLE users TO users_hidden');
        try {
            $this->assertFalse(isSessionVersionValid());
        } finally {
            $this->pdo->exec('RENAME TABLE users_hidden TO users');
        }
    }

    /**
     * ข้อยกเว้นเดียวที่ยังต้อง fail-open: schema เก่าที่ยังไม่มีคอลัมน์ session_version
     * ไม่งั้นตอนอัปเกรดผู้ใช้ทุกคนถูกเตะออกพร้อมกัน
     */
    public function testStaysOpenWhenTheColumnIsMissingFromAnOlderSchema(): void
    {
        $userId = $this->createUser('guard@example.com');
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = 1;

        $this->pdo->exec('ALTER TABLE users DROP COLUMN session_version');
        try {
            $this->assertTrue(isSessionVersionValid());
        } finally {
            // นิยามตรงตาม database/schema.sql เพื่อคืนสภาพให้เหมือนเดิมเป๊ะ
            $this->pdo->exec(
                'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER display_name'
            );
        }
    }
}
