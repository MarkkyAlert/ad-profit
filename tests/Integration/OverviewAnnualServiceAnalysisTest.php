<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewAnnualService;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของสัดส่วนกำไร + วันที่กรอก + เดือนดี/แย่สุด (มุมปีรวมร้าน) — DB จริง
 */
final class OverviewAnnualServiceAnalysisTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    private function makeService(): OverviewAnnualService
    {
        return new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function byShopName(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['shop_name']] = $row;
        }

        return $result;
    }

    public function testShareAndDayCountsFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // A กรอก 3 วัน (ม.ค. 2 วัน · มิ.ย. 1 วัน) → กำไร 4000
        $this->createRecord($shopA, '2026-01-10', 2000.0, 500.0);
        $this->createRecord($shopA, '2026-01-11', 2000.0, 500.0);
        $this->createRecord($shopA, '2026-06-10', 2000.0, 1000.0);
        // B กรอก 1 วัน → กำไร 1000
        $this->createRecord($shopB, '2026-08-10', 2000.0, 1000.0);

        $shops = $this->byShopName($this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['shops']);

        $this->assertSame(4000.0, $shops['ร้าน A']['profit']);
        $this->assertSame(1000.0, $shops['ร้าน B']['profit']);
        $this->assertSame(80.0, $shops['ร้าน A']['profit_share']);
        $this->assertSame(20.0, $shops['ร้าน B']['profit_share']);

        $this->assertSame(3, $shops['ร้าน A']['days_count']);
        $this->assertSame(1, $shops['ร้าน B']['days_count']);
    }

    public function testBestAndWorstMonthUseProfitAndSkipEmptyMonths(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // มี.ค. รายได้สูงสุดแต่แอดหนัก → กำไรน้อย
        $this->createRecord($shopA, '2026-03-10', 20000.0, 19500.0);
        // พ.ค. กำไรดีสุด
        $this->createRecord($shopB, '2026-05-10', 4000.0, 1000.0);
        // ก.ค. ขาดทุนจริง
        $this->createRecord($shopA, '2026-07-10', 1000.0, 2500.0);

        $summary = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(5, $summary['best_month']['month']);
        $this->assertSame(3000.0, $summary['best_month']['profit']);
        $this->assertSame(7, $summary['worst_month']['month']);
        $this->assertSame(-1500.0, $summary['worst_month']['profit']);
    }

    public function testNegativeTotalProfitGivesNullShares(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-01-10', 1000.0, 2000.0);
        $this->createRecord($shopB, '2026-01-10', 1000.0, 3000.0);

        $rows = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['shops'];

        foreach ($rows as $row) {
            $this->assertNull($row['profit_share']);
        }
    }

    public function testAnotherUsersShopsAreNotIncluded(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopA = $this->createShop($ownerId, 'ร้าน A');
        $shopB = $this->createShop($ownerId, 'ร้าน B');
        $this->createRecord($shopA, '2026-01-10', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-01-10', 2000.0, 1000.0);

        $otherId = $this->createUser('other@example.com');
        $otherShopA = $this->createShop($otherId, 'ร้านคนอื่น A');
        $this->createShop($otherId, 'ร้านคนอื่น B');
        $this->createRecord($otherShopA, '2026-01-10', 99999.0, 0.0);

        $data = $this->makeService()->buildYearlyOverview($ownerId, 2026, self::TODAY)['data'];

        $this->assertCount(2, $data['shops']);
        foreach ($data['shops'] as $row) {
            $this->assertStringNotContainsString('คนอื่น', (string)$row['shop_name']);
        }

        // สัดส่วนคิดจากฐานของ user ตัวเองเท่านั้น (2000 + 1000 = 3000)
        $shops = $this->byShopName($data['shops']);
        $this->assertSame(66.7, $shops['ร้าน A']['profit_share']);
        $this->assertSame(33.3, $shops['ร้าน B']['profit_share']);
    }

    public function testFutureDatedRecordsDoNotAffectShareOrDays(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-08-10', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-08-10', 2000.0, 1000.0);
        // ล่วงหน้า — ต้องไม่ดันสัดส่วนหรือจำนวนวันของ B
        $this->createRecord($shopB, '2026-11-10', 90000.0, 0.0);

        $shops = $this->byShopName($this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data']['shops']);

        $this->assertSame(1, $shops['ร้าน B']['days_count']);
        $this->assertSame(1000.0, $shops['ร้าน B']['profit']);
        $this->assertSame(66.7, $shops['ร้าน A']['profit_share']);
        $this->assertSame(33.3, $shops['ร้าน B']['profit_share']);
    }
}
