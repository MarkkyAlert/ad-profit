<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

final class OverviewServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $shops
     */
    private function makeService(array $shops): OverviewService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        // getTotalsByShopIdsAndDateRange / getMonthlyTotalsByShopIdsAndMonthRange
        // มี return type : array → stub คืน [] อัตโนมัติ (ไม่ต้อง stub เอง)

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops);

        return new OverviewService($recordRepository, $shopRepository);
    }

    public function testCannotViewWithOnlyOneShop(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
        ]);

        $result = $service->buildOverview(1, '2024-01');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['can_view']);
        $this->assertSame(1, $result['data']['shops_count']);
    }

    public function testCanViewWithTwoOrMoreShops(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        $result = $service->buildOverview(1, '2024-01');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['can_view']);
        $this->assertSame(2, $result['data']['shops_count']);
    }
}
