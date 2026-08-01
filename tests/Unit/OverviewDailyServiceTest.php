<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewDailyService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

final class OverviewDailyServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $shops
     */
    private function makeService(array $shops): OverviewDailyService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        // getDailyTotalsByShopIdsAndDateRange / getTotalsByShopIdsAndDateRange → stub คืน [] อัตโนมัติ

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops);

        return new OverviewDailyService($recordRepository, $shopRepository);
    }

    public function testCannotViewWithOnlyOneShop(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
        ]);

        $result = $service->buildDailyOverview(1, '2024-01');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['can_view']);
        $this->assertSame(1, $result['data']['shops_count']);
        // shop < 2 → short-circuit ไม่มี payload
        $this->assertArrayNotHasKey('days', $result['data']);
    }

    public function testCanViewWithTwoOrMoreShops(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        $result = $service->buildDailyOverview(1, '2024-01');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['can_view']);
        $this->assertSame(2, $result['data']['shops_count']);
        $this->assertArrayHasKey('days', $result['data']);
        $this->assertArrayHasKey('summary', $result['data']);
    }
}
