<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use ReflectionMethod;
use ShopRepository;
use UserRepository;

/**
 * ช่องเวลาที่บอกใบ้ว่าอีเมลไหนมีในระบบ
 *
 * ⚠️ บทเรียน: รอบก่อน hardcode dummy hash เป็น cost 12 แล้ววัดบนเครื่อง dev (PHP 8.5,
 * PASSWORD_DEFAULT = cost 12) ได้ผลเท่ากันจึงสรุปว่าปิดช่องแล้ว — แต่ production เป็น
 * PHP 8.3 ที่ default = cost 10 ทำให้เส้นทาง "ไม่มีอีเมลนี้" ช้ากว่า 4 เท่า = ช่องยังเปิด
 * แค่กลับทิศ · เทสต์นี้จึงตรวจ "cost ตรงกับที่ระบบใช้จริง" ไม่ใช่วัดเวลาบนเครื่องเดียว
 */
final class AuthServiceTimingTest extends IntegrationTestCase
{
    private function dummyHash(): string
    {
        $method = new ReflectionMethod(AuthService::class, 'dummyPasswordHash');

        return (string)$method->invoke(null);
    }

    /** ⭐ cost ของ dummy ต้องเท่ากับ cost ที่ระบบใช้สร้าง hash จริง ไม่ว่ารันบน PHP เวอร์ชันใด */
    public function testDummyHashCostMatchesWhateverPasswordDefaultProduces(): void
    {
        $realHash = password_hash('any-password', PASSWORD_DEFAULT);

        $this->assertSame(
            password_get_info($realHash)['options']['cost'] ?? null,
            password_get_info($this->dummyHash())['options']['cost'] ?? null,
            'cost ของ dummy ไม่ตรงกับ PASSWORD_DEFAULT — เส้นทางไม่มีอีเมลจะเร็ว/ช้ากว่าที่ควร'
        );
    }

    public function testDummyHashIsARealBcryptHashThatNoPasswordMatches(): void
    {
        $dummy = $this->dummyHash();

        $this->assertSame('bcrypt', password_get_info($dummy)['algoName']);
        $this->assertFalse(password_verify('any-password', $dummy));
        $this->assertFalse(password_verify('', $dummy));
    }

    /**
     * วัดเวลาจริงเพิ่มอีกชั้น — ต้องอยู่ในระยะที่แยกไม่ออก
     *
     * ใช้ ratio ไม่ใช่ค่าสัมบูรณ์ เพราะเครื่อง CI ช้ากว่าเครื่อง dev
     */
    public function testUnknownEmailTakesComparableTimeToWrongPassword(): void
    {
        $this->createUser('owner@example.com', 'password123');
        $service = new AuthService($this->pdo, new UserRepository($this->pdo), new ShopRepository($this->pdo));

        $measure = function (string $email) use ($service): float {
            $this->pdo->exec('TRUNCATE TABLE auth_rate_limits');
            $start = microtime(true);
            $service->login($email, 'wrong-password', '203.0.113.' . random_int(1, 254));

            return microtime(true) - $start;
        };

        $unknown = min($measure('nobody@example.com'), $measure('nobody2@example.com'));
        $known = min($measure('owner@example.com'), $measure('owner@example.com'));

        // ต่างกันได้ไม่เกิน 2 เท่า (bcrypt cost ต่างกัน 1 ระดับ = ~2 เท่า)
        $ratio = max($unknown, $known) / max(0.000001, min($unknown, $known));
        $this->assertLessThan(
            2.0,
            $ratio,
            sprintf('เวลาต่างกัน %.1f เท่า — ยังบอกใบ้ได้ว่าอีเมลไหนมีในระบบ', $ratio)
        );
    }
}
