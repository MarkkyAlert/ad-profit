<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use DateTimeImmutable;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ getWeekdayBreakdown — DB จริง
 * today = 2026-08-02 (อาทิตย์) → window 8 สัปดาห์ = 2026-06-08 .. 2026-08-02
 */
final class RecordServiceWeekdayBreakdownTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-02';

    private function makeService(): RecordService
    {
        return new RecordService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            $this->pdo
        );
    }

    /**
     * @param array<int,array<string,mixed>> $weekdays
     * @return array<string,mixed>
     */
    private function pick(array $weekdays, int $weekday): array
    {
        foreach ($weekdays as $row) {
            if ((int)$row['weekday'] === $weekday) {
                return $row;
            }
        }

        self::fail('ไม่พบ weekday ' . $weekday);
    }

    public function testAggregatesEightWeeksOfDataPerWeekday(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // จันทร์ 8 ครั้ง เริ่ม 2026-06-08 (ต้นของ window พอดี)
        for ($index = 0; $index < 8; $index++) {
            $date = (new DateTimeImmutable('2026-06-08'))->modify('+' . ($index * 7) . ' days')->format('Y-m-d');
            $this->createRecord($shopId, $date, 1000.0 + $index * 100, 200.0);
        }

        // ศุกร์ 2 ครั้ง — ขาดทุน
        $this->createRecord($shopId, '2026-07-31', 100.0, 500.0);
        $this->createRecord($shopId, '2026-07-24', 100.0, 300.0);

        $data = $this->makeService()->getWeekdayBreakdown($userId, $shopId, 8, self::TODAY)['data'];

        $this->assertTrue($data['has_data']);
        $this->assertSame('2026-06-08', $data['start_date']);
        $this->assertSame('2026-08-02', $data['end_date']);

        $monday = $this->pick($data['weekdays'], 1);
        $this->assertSame(8, $monday['sample_count']);
        $this->assertSame(1150.0, $monday['avg_profit']);
        $this->assertSame(6.75, $monday['avg_roas']);

        $friday = $this->pick($data['weekdays'], 5);
        $this->assertSame(2, $friday['sample_count']);
        $this->assertSame(-300.0, $friday['avg_profit']);   // ((-400)+(-200))/2

        $wednesday = $this->pick($data['weekdays'], 3);
        $this->assertSame(0, $wednesday['sample_count']);
        $this->assertNull($wednesday['avg_profit']);
    }

    public function testShopsAreIsolated(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // ร้าน B มีจันทร์เยอะ — ต้องไม่ปนเข้าร้าน A
        $this->createRecord($shopB, '2026-07-27', 5000.0, 100.0);
        $this->createRecord($shopB, '2026-07-20', 6000.0, 100.0);

        $this->createRecord($shopA, '2026-07-27', 1000.0, 200.0);

        $dataA = $this->makeService()->getWeekdayBreakdown($userId, $shopA, 8, self::TODAY)['data'];
        $mondayA = $this->pick($dataA['weekdays'], 1);

        $this->assertSame(1, $mondayA['sample_count']);
        $this->assertSame(800.0, $mondayA['avg_profit']);
    }

    public function testEmptyShopHasNoData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $data = $this->makeService()->getWeekdayBreakdown($userId, $shopId, 8, self::TODAY)['data'];

        $this->assertFalse($data['has_data']);
        $this->assertCount(7, $data['weekdays']);
    }
}
