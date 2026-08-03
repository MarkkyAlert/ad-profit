<?php

declare(strict_types=1);

/**
 * test double ของ global db() ที่ includes/auth.php เรียกใช้
 *
 * tests/bootstrap.php ตั้งใจไม่ include includes/database.php (มี side effect) และ
 * isSessionVersionValid() ก็เรียก db() ผ่าน function_exists() → ประกาศเองที่นี่ได้
 * ถ้าวันหนึ่งมีเทสต์ include includes/database.php จริง จะชนกันทันที ซึ่งเป็นสัญญาณที่ดี
 */
final class TestDbFunctionState
{
    public static ?PDO $pdo = null;
}

if (!function_exists('db')) {
    function db(): PDO
    {
        if (!TestDbFunctionState::$pdo instanceof PDO) {
            throw new RuntimeException('db() ถูกเรียกโดยที่เทสต์ยังไม่ได้เตรียม PDO ไว้');
        }

        return TestDbFunctionState::$pdo;
    }
}
