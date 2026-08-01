<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewAnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

final class OverviewAnnualServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $shops
     */
    private function makeService(array $shops): OverviewAnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        // getMonthlyTotalsByShopIdsAndMonthRange → stub คืน [] อัตโนมัติ

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops);

        return new OverviewAnnualService($recordRepository, $shopRepository);
    }

    public function testCannotViewWithOnlyOneShop(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
        ]);

        $result = $service->buildYearlyOverview(1, 2024);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['can_view']);
        $this->assertSame(1, $result['data']['shops_count']);
        $this->assertArrayNotHasKey('months', $result['data']);
    }

    public function testCanViewWithTwoOrMoreShops(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        $result = $service->buildYearlyOverview(1, 2024);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['can_view']);
        $this->assertSame(2, $result['data']['shops_count']);
        $this->assertArrayHasKey('months', $result['data']);
        $this->assertArrayHasKey('summary', $result['data']);
    }

    public function testInvalidYearIsRejected(): void
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        $result = $service->buildYearlyOverview(1, 1999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่ถูกต้อง', $result['error']);
    }
}
