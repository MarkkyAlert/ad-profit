<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ getUnfilledDatesForMonth — ใช้ DB จริง
 * (พิสูจน์ว่า getByDateRange + การเทียบวันที่ทำงานถูกกับข้อมูลจริง)
 */
final class RecordServiceUnfilledDatesTest extends IntegrationTestCase
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

    public function testReturnsMissingDatesForPartiallyFilledMonth(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // มี.ค. 2026 มี 31 วัน — seed ไว้ 3 วัน
        $this->createRecord($shopId, '2026-03-01', 100.0, 10.0);
        $this->createRecord($shopId, '2026-03-15', 200.0, 20.0);
        $this->createRecord($shopId, '2026-03-31', 300.0, 30.0);

        $result = $this->makeService()->getUnfilledDatesForMonth($userId, $shopId, '2026-03', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame(28, $result['data']['count']); // 31 - 3

        $missing = $result['data']['missing_dates'];
        $this->assertNotContains('2026-03-01', $missing);
        $this->assertNotContains('2026-03-15', $missing);
        $this->assertNotContains('2026-03-31', $missing);
        $this->assertContains('2026-03-02', $missing);
        $this->assertContains('2026-03-30', $missing);
    }

    public function testReturnsEmptyWhenMonthFullyFilled(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // เม.ย. 2026 มี 30 วัน — seed ครบทุกวัน
        for ($day = 1; $day <= 30; $day++) {
            $this->createRecord($shopId, sprintf('2026-04-%02d', $day), 100.0, 10.0);
        }

        $result = $this->makeService()->getUnfilledDatesForMonth($userId, $shopId, '2026-04', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['data']['missing_dates']);
        $this->assertSame(0, $result['data']['count']);
    }

    public function testOnlyCountsRecordsOfTheGivenShop(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // กรอกให้ร้าน B เท่านั้น — ร้าน A ต้องยังนับว่าขาดทุกวัน
        $this->createRecord($shopB, '2026-05-01', 100.0, 10.0);

        $result = $this->makeService()->getUnfilledDatesForMonth($userId, $shopA, '2026-05', self::TODAY);

        $this->assertTrue($result['success']);
        $this->assertContains('2026-05-01', $result['data']['missing_dates']);
        $this->assertSame(31, $result['data']['count']); // พ.ค. 31 วัน ขาดหมด
    }
}
