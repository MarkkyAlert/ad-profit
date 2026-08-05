<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use PasswordResetRepository;
use PDO;
use PDOException;
use ShopRepository;
use UserRepository;

/**
 * การลองใหม่ตอนฐานข้อมูลชนกัน ต้องไม่นับความพยายามซ้ำ
 *
 * การจอง 1 ครั้งมี 2 ขั้น: เพิ่มเลข → อ่านเลขที่ได้
 * ⚠️ ถ้าขั้นแรกสำเร็จแล้วขั้นสองล้ม การวนกลับไปทำใหม่ทั้งคู่จะเพิ่มเลขเป็นครั้งที่สอง
 * ผู้ใช้ที่พิมพ์รหัสผิดครั้งเดียวถูกนับ 2 ครั้ง → ชนเพดานเร็วกว่าที่ควรเท่าตัว
 *
 * อาการที่เห็นตอนแรกคือเทสต์ตัวนับ "เดี๋ยวเขียวเดี๋ยวแดง" เพราะการลองใหม่
 * เกิดเฉพาะตอนที่ฐานข้อมูลมีคนแย่งกันจริง (รันชุดเทสต์รวมกันทั้งชุด)
 */
final class RateLimitRetryTest extends IntegrationTestCase
{
    /** ⭐ อ่านค่าล้มครั้งแรก → ลองใหม่ → ต้องยังนับเป็น 1 ครั้ง */
    public function testRetryingAfterAFailedReadDoesNotCountTwice(): void
    {
        $this->pdo
            ->prepare('INSERT INTO users (email, password_hash, display_name, session_version) VALUES (?, ?, ?, 1)')
            ->execute(['owner@example.com', password_hash('CorrectPass123', PASSWORD_DEFAULT), 'ผู้ใช้']);

        $service = new class (
            $this->pdo,
            new UserRepository($this->pdo),
            new ShopRepository($this->pdo),
            new PasswordResetRepository($this->pdo)
        ) extends AuthService {
            /** @var array<string,int> นับแยกตามถัง — login() จองถึง 3 ถังต่อการเรียก 1 ครั้ง */
            public array $markCalls = [];
            private bool $readFailedOnce = false;

            protected function markFailedAttemptInDatabase(
                string $action,
                string $clientIp,
                string $subject = ''
            ): void {
                $this->markCalls[$action] = ($this->markCalls[$action] ?? 0) + 1;
                parent::markFailedAttemptInDatabase($action, $clientIp, $subject);
            }

            protected function currentAttemptsInDatabase(
                string $action,
                string $clientIp,
                string $subject = ''
            ): int {
                // จำลอง "ฐานข้อมูลตัดคำขอทิ้งเพราะชนกัน" ครั้งแรกครั้งเดียว
                if (!$this->readFailedOnce) {
                    $this->readFailedOnce = true;
                    $exception = new PDOException('SQLSTATE[40001]: Deadlock found');
                    $exception->errorInfo = ['40001', 1213, 'Deadlock found'];

                    throw $exception;
                }

                return parent::currentAttemptsInDatabase($action, $clientIp, $subject);
            }
        };

        $service->login('owner@example.com', 'รหัสผิด', '203.0.113.77');

        $attempts = (int)$this->pdo
            ->query("SELECT attempts FROM auth_rate_limits WHERE action_type = 'login' LIMIT 1")
            ->fetchColumn();

        $this->assertSame(
            1,
            $service->markCalls['login'] ?? 0,
            'ลองใหม่แล้วสั่งนับซ้ำอีกครั้ง — ผู้ใช้จะชนเพดานเร็วกว่าที่ควรเท่าตัว'
        );
        $this->assertSame(
            1,
            $attempts,
            'พิมพ์รหัสผิดครั้งเดียวถูกนับ 2 ครั้ง — ผู้ใช้จะชนเพดานเร็วกว่าที่ควรเท่าตัว'
        );
    }
}
