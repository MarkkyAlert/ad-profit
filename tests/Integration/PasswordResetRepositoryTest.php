<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use PasswordResetRepository;

/**
 * PasswordResetRepository ไม่เคยมีเทสต์ครอบเลย ทั้งที่เป็นทางเข้าระบบทางหนึ่ง
 *
 * โฟกัสที่เรื่องที่ดูจากโค้ดอย่างเดียวไม่พอ: TTL ถูกคิดด้วยนาฬิกาไหน และ
 * token เก่าถูกแทนที่จริงไหมเมื่อขอลิงก์ใหม่
 */
final class PasswordResetRepositoryTest extends IntegrationTestCase
{
    private function repository(): PasswordResetRepository
    {
        return new PasswordResetRepository($this->pdo);
    }

    /**
     * expires_at ต้องถูกคำนวณด้วยนาฬิกาของ MySQL ตัวเดียวกับที่ใช้ตรวจ (NOW())
     *
     * เดิมคำนวณด้วย strtotime() ของ PHP แล้วส่งเป็น string — ถ้า PHP กับ MySQL คนละโซน
     * (เช่น host ตั้ง MySQL เป็น UTC ส่วนแอปเป็น Asia/Bangkok) token อายุ 1 ชม.
     * จะอยู่ได้ 8 ชม. หรือหมดอายุตั้งแต่เกิด ขึ้นกับทิศทางที่เอียง
     */
    public function testExpiryIsMeasuredOnTheDatabaseClock(): void
    {
        $userId = $this->createUser('reset@example.com');

        $this->repository()->createToken($userId, hash('sha256', 'token-a'), 1);

        $row = $this->pdo->query(
            'SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) AS minutes_left FROM password_reset_tokens'
        )->fetch();

        // 1 ชั่วโมงพอดีตามนาฬิกา DB (เผื่อวินาทีคาบเกี่ยว)
        $this->assertGreaterThanOrEqual(59, (int)$row['minutes_left']);
        $this->assertLessThanOrEqual(60, (int)$row['minutes_left']);
    }

    /**
     * TTL ต้องไม่ขึ้นกับ timezone ของ PHP — บังคับให้คนละโซนกับ DB เสมอ
     *
     * ถ้าโค้ดกลับไปคำนวณ expires_at ด้วย strtotime() ของ PHP token จะหมดอายุตั้งแต่
     * ยังไม่ทันได้กดลิงก์ (หรืออยู่นานเกินตั้งใจ) ขึ้นกับทิศทางที่โซนต่างกัน
     */
    public function testExpiryIsUnaffectedByPhpTimezone(): void
    {
        $userId = $this->createUser('reset@example.com');
        $hash = hash('sha256', 'token-a');

        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14

        try {
            $this->repository()->createToken($userId, $hash, 1);

            $this->assertIsArray(
                $this->repository()->findByTokenHashForUpdate($hash),
                'token หมดอายุทันทีเพราะ expires_at ถูกคิดด้วยนาฬิกา PHP'
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testFreshTokenIsFoundAndCarriesUserEmail(): void
    {
        $userId = $this->createUser('reset@example.com');
        $hash = hash('sha256', 'token-a');

        $this->repository()->createToken($userId, $hash, 1);
        $found = $this->repository()->findByTokenHashForUpdate($hash);

        $this->assertIsArray($found);
        $this->assertSame($userId, (int)$found['user_id']);
        $this->assertSame('reset@example.com', $found['email']);
    }

    public function testExpiredTokenIsNotFound(): void
    {
        $userId = $this->createUser('reset@example.com');
        $hash = hash('sha256', 'token-a');

        $this->repository()->createToken($userId, $hash, 1);
        $this->pdo->exec('UPDATE password_reset_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE');

        $this->assertNull($this->repository()->findByTokenHashForUpdate($hash));
    }

    /** ขอลิงก์ใหม่ต้องทำให้ลิงก์เก่าใช้ไม่ได้ (unique key ที่ user_id + ON DUPLICATE KEY) */
    public function testRequestingAgainInvalidatesThePreviousToken(): void
    {
        $userId = $this->createUser('reset@example.com');
        $oldHash = hash('sha256', 'token-old');
        $newHash = hash('sha256', 'token-new');

        $this->repository()->createToken($userId, $oldHash, 1);
        $this->repository()->createToken($userId, $newHash, 1);

        $this->assertSame(1, $this->countRows('password_reset_tokens'));
        $this->assertNull($this->repository()->findByTokenHashForUpdate($oldHash));
        $this->assertIsArray($this->repository()->findByTokenHashForUpdate($newHash));
    }

    public function testDeleteByTokenHashReportsWhetherARowWasRemoved(): void
    {
        $userId = $this->createUser('reset@example.com');
        $hash = hash('sha256', 'token-a');
        $this->repository()->createToken($userId, $hash, 1);

        $this->assertTrue($this->repository()->deleteByTokenHash($hash));
        // ลบซ้ำต้องคืน false — ใช้ rowCount() ไม่ใช่ผลของ execute()
        $this->assertFalse($this->repository()->deleteByTokenHash($hash));
    }

    public function testDeleteExpiredRemovesOnlyExpiredRows(): void
    {
        $liveUser = $this->createUser('live@example.com');
        $staleUser = $this->createUser('stale@example.com');

        $this->repository()->createToken($liveUser, hash('sha256', 'live'), 1);
        $this->repository()->createToken($staleUser, hash('sha256', 'stale'), 1);
        $this->pdo->exec(
            'UPDATE password_reset_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = ' . $staleUser
        );

        $this->assertSame(1, $this->repository()->deleteExpired());
        $this->assertSame(1, $this->countRows('password_reset_tokens'));
    }

    public function testTokensAreRemovedWhenTheUserIsDeleted(): void
    {
        $userId = $this->createUser('reset@example.com');
        $this->repository()->createToken($userId, hash('sha256', 'token-a'), 1);

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $userId);

        $this->assertSame(0, $this->countRows('password_reset_tokens'));
    }
}
