<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ getWeekdayContext — DB จริง
 * ส.ค. 2026: 3, 10, 17, 24 = วันจันทร์
 */
final class RecordServiceWeekdayContextTest extends IntegrationTestCase
{
    private function makeService(): RecordService
    {
        return new RecordService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            $this->pdo
        );
    }

    public function testAveragesSameWeekdaysAndExcludesTarget(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-03', 1000.0, 200.0);  // จันทร์
        $this->createRecord($shopId, '2026-08-10', 2000.0, 400.0);  // จันทร์
        $this->createRecord($shopId, '2026-08-17', 3000.0, 600.0);  // จันทร์
        $this->createRecord($shopId, '2026-08-24', 900.0, 300.0);   // จันทร์ = target
        $this->createRecord($shopId, '2026-08-04', 5000.0, 100.0);  // อังคาร

        $data = $this->makeService()->getWeekdayContext($userId, $shopId, '2026-08-24')['data'];

        $this->assertTrue($data['has_data']);
        $this->assertTrue($data['comparable']);
        $this->assertSame(1, $data['weekday']);
        $this->assertSame(3, $data['sample_count']);      // target ถูกตัดออกจริง
        $this->assertSame(2000.0, $data['avg_revenue']);
        $this->assertSame(5.0, $data['avg_roas']);        // 6000/1200
        $this->assertSame(900.0, $data['target_revenue']);
    }

    public function testUsesLatestRecordWhenNoTargetDateGiven(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-03', 1000.0, 200.0);
        $this->createRecord($shopId, '2026-08-10', 2000.0, 400.0);

        // ส่ง $today เอง — ไม่งั้นผลขึ้นกับนาฬิกาเครื่อง (08-10 เป็นอนาคตเมื่อรันก่อนวันนั้น)
        $data = $this->makeService()->getWeekdayContext($userId, $shopId, null, '2026-08-31')['data'];

        $this->assertSame('2026-08-10', $data['target_date']);
        $this->assertSame(1, $data['sample_count']);
        $this->assertSame(1000.0, $data['avg_revenue']);
    }

    /** ⭐ วันอนาคตต้องไม่ถูกหยิบมาเป็น "วันล่าสุด" — เกณฑ์เดียวกับ getDaysSinceLastRecord */
    public function testFutureDatedRecordIsNotTreatedAsTheLatestDay(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-03', 1000.0, 200.0);
        $this->createRecord($shopId, '2026-08-10', 2000.0, 400.0);
        $this->createRecord($shopId, '2026-12-25', 9999.0, 1.0);   // ลงล่วงหน้า/พิมพ์เดือนผิด

        $data = $this->makeService()->getWeekdayContext($userId, $shopId, null, '2026-08-11')['data'];

        $this->assertSame('2026-08-10', $data['target_date']);
        $this->assertSame(2000.0, $data['target_revenue']);
    }

    /**
     * getWeekdayContext กับ getDaysSinceLastRecord ต้องเห็น "วันล่าสุด" เป็นวันเดียวกัน
     *
     * สองเมธอดนี้เคยใช้คนละเกณฑ์ (อันหนึ่งกัน อีกอันไม่กัน) ทั้งที่โชว์อยู่บนหน้าจอเดียวกัน
     */
    public function testLatestDayAgreesWithDaysSinceLastRecord(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->createRecord($shopId, '2026-08-03', 1000.0, 200.0);
        $this->createRecord($shopId, '2026-12-25', 9999.0, 1.0);

        $service = $this->makeService();

        $this->assertSame(
            $service->getDaysSinceLastRecord($userId, $shopId, '2026-08-11')['data']['last_record_date'],
            $service->getWeekdayContext($userId, $shopId, null, '2026-08-11')['data']['target_date'],
            'สองการ์ดบนหน้าเดียวกันชี้วันล่าสุดคนละวัน'
        );
    }

    public function testShopsAreIsolated(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // ร้าน B มีจันทร์อื่นเยอะ — ต้องไม่ปนเข้าร้าน A
        $this->createRecord($shopB, '2026-08-03', 8000.0, 100.0);
        $this->createRecord($shopB, '2026-08-10', 9000.0, 100.0);

        $this->createRecord($shopA, '2026-08-24', 900.0, 300.0);

        $dataA = $this->makeService()->getWeekdayContext($userId, $shopA, '2026-08-24')['data'];

        $this->assertTrue($dataA['has_data']);
        $this->assertSame(0, $dataA['sample_count']);
        $this->assertFalse($dataA['comparable']);
        $this->assertNull($dataA['avg_revenue']);
    }

    public function testEmptyShopHasNoData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $data = $this->makeService()->getWeekdayContext($userId, $shopId, null, '2026-08-31')['data'];

        $this->assertFalse($data['has_data']);
        $this->assertNull($data['target_date']);
    }
}
