<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use AuthService;
use ShopRepository;
use UserRepository;

/**
 * ⚠️ AuthService->register() เรียก establishSession() → session_regenerate_id(true)
 * ซึ่งอาจ warn/fail ใน CLI (ไม่มี active session) — จุดนี้เป็น decision point
 * เทสต์นี้ assert เฉพาะผลฝั่ง DB (user/shop/password)
 */
final class AuthServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); // ต่อ DB + truncate (หรือ skip ถ้าไม่มี test DB)

        // ตัวเลือก 2: ให้มี active session ก่อนเรียก register() เพื่อให้
        // AuthService->establishSession() → session_regenerate_id(true) ทำงานปกติ ไม่ warn
        // (ไม่แตะ base class / ไม่แตะ AuthService / ไม่ใช้ @ / ไม่ปิด failOnWarning)
        if ((string)ini_get('session.save_path') === '') {
            ini_set('session.save_path', sys_get_temp_dir());
        }
        if (session_status() === PHP_SESSION_NONE) {
            // use_cookies=0 + cache_limiter='' กัน "headers already sent" ใน CLI
            // (PHPUnit พิมพ์ progress ออก stdout ไปก่อนหน้าแล้ว)
            session_start(['use_cookies' => '0', 'cache_limiter' => '']);
        }
    }

    public function testRegisterCreatesUserAndDefaultShop(): void
    {
        $userRepository = new UserRepository($this->pdo);
        $shopRepository = new ShopRepository($this->pdo);
        $authService = new AuthService($this->pdo, $userRepository, $shopRepository);

        $result = $authService->register('newuser@example.com', 'password123', 'password123', '127.0.0.1');

        $this->assertTrue($result['success'] ?? false);

        // user ถูกสร้าง + password hash verify ผ่าน
        $user = $userRepository->findByEmail('newuser@example.com');
        $this->assertNotNull($user);
        $this->assertTrue(password_verify('password123', (string)$user['password_hash']));

        // default shop ถูกสร้างให้ user นี้
        $this->assertSame(1, $this->countRows('shops'));
        $shop = $shopRepository->getFirstByUserId((int)$user['id']);
        $this->assertNotNull($shop);
        $this->assertSame('ร้านค้าของฉัน', $shop['name']); // AuthService::DEFAULT_SHOP_NAME
    }
}
