<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShopRepository;
use ShopService;
use UserRepository;

final class ShopServiceTest extends TestCase
{
    public function testCreateShopFailsWhenExceedingTwentyShops(): void
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('countByUserId')->willReturn(20); // ถึงลิมิตแล้ว

        $userRepository = $this->createStub(UserRepository::class);

        $service = new ShopService($shopRepository, $userRepository, null);

        $result = $service->createShop(1, 'ร้านใหม่');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('20', $result['error']);
    }

    public function testDeleteShopFailsWhenItIsTheLastShop(): void
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'name' => 'ร้านเดียวที่มี',
        ]);
        $shopRepository->method('countByUserId')->willReturn(1); // เหลือร้านเดียว

        $userRepository = $this->createStub(UserRepository::class);

        $service = new ShopService($shopRepository, $userRepository, null);

        $result = $service->deleteShop(1, 1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ร้านสุดท้าย', $result['error']);
    }
}
