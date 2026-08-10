<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของรายงานวิเคราะห์ในมุมเดือน — จัดอันดับด้วยกำไร + สัดส่วนกำไร + momentum
 */
/* ⚠️⚠️ **ข้อมูลตั้งต้นต้องเป็นไปได้จริง** — แถวที่มียอดขายแต่ `days_count = 0`
   เกิดขึ้นไม่ได้ในระบบจริง (มียอด = ต้องมีวันที่กรอก) · เดิมชุดทดสอบไม่ใส่ `days_count`
   มาเลย ทำให้ทุกแถวถูกมองว่า "ยังไม่มีข้อมูล" หลังจากเพิ่มกติกา
   "เดือนที่ไม่มี record ห้ามรายงาน −100%" */
final class OverviewServiceAnalysisTest extends TestCase
{
    private const MONTH = '2026-06';

    /**
     * @param array<int,array<string,mixed>> $shops
     * @param array<int,array<string,mixed>> $currentTotals  ผลรวมของเดือนที่เลือก
     * @param array<int,array<string,mixed>> $previousTotals ผลรวมของเดือนก่อน
     */
    private function makeService(array $shops, array $currentTotals, array $previousTotals = []): OverviewService
    {
        $recordRepository = $this->createStub(RecordRepository::class);

        // เดือนที่เลือก = 2026-06-01..30 · เดือนก่อน = 2026-05-01..31
        $recordRepository->method('getTotalsByShopIdsAndDateRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($currentTotals, $previousTotals): array {
                return str_starts_with($start, '2026-06') ? $currentTotals : $previousTotals;
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops);

        return new OverviewService($recordRepository, $shopRepository);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rowsOf(OverviewService $service): array
    {
        $result = $service->buildOverview(1, self::MONTH);
        $this->assertTrue($result['success']);

        return (array)$result['data']['comparison']['rows'];
    }

    public function testRanksByProfitNotRevenue(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [
                ['id' => 1, 'name' => 'รายได้สูง แอดหนัก'],
                ['id' => 2, 'name' => 'รายได้น้อย กำไรดี'],
            ],
            [
                // ร้าน 1 รายได้สูงสุด (9000) แต่กำไรแค่ 500
                ['shop_id' => 1, 'total_revenue' => 9000, 'total_ad_cost' => 8500, 'days_count' => 5],
                // ร้าน 2 รายได้น้อยกว่า แต่กำไร 2800
                ['shop_id' => 2, 'total_revenue' => 3000, 'total_ad_cost' => 200, 'days_count' => 5],
            ]
        ));

        // อันดับ 1 ต้องเป็นร้านกำไรสูงสุด ไม่ใช่รายได้สูงสุด
        $this->assertSame('รายได้น้อย กำไรดี', $rows[0]['shop_name']);
        $this->assertSame(2800.0, $rows[0]['profit']);
        $this->assertSame('รายได้สูง แอดหนัก', $rows[1]['shop_name']);
    }

    public function testProfitShareSumsToOneHundredWhenAllPositive(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [
                ['id' => 1, 'name' => 'ร้าน A'],
                ['id' => 2, 'name' => 'ร้าน B'],
            ],
            [
                ['shop_id' => 1, 'total_revenue' => 1000, 'total_ad_cost' => 250, 'days_count' => 5],  // profit 750
                ['shop_id' => 2, 'total_revenue' => 500, 'total_ad_cost' => 250, 'days_count' => 5],   // profit 250
            ]
        ));

        // กำไรรวม 1000 → 75% / 25%
        $this->assertSame(75.0, $rows[0]['profit_share']);
        $this->assertSame(25.0, $rows[1]['profit_share']);
        $this->assertSame(100.0, array_sum(array_column($rows, 'profit_share')));
    }

    public function testLosingShopGetsNegativeShareWhileTotalPositive(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [
                ['id' => 1, 'name' => 'กำไร'],
                ['id' => 2, 'name' => 'ขาดทุน'],
            ],
            [
                ['shop_id' => 1, 'total_revenue' => 1200, 'total_ad_cost' => 200, 'days_count' => 5],  // +1000
                ['shop_id' => 2, 'total_revenue' => 100, 'total_ad_cost' => 300, 'days_count' => 5],   // -200
            ]
        ));

        // กำไรรวม 800 → ร้านกำไร 125% (เกิน 100 ได้) · ร้านขาดทุน -25%
        $this->assertSame(125.0, $rows[0]['profit_share']);
        $this->assertSame(-25.0, $rows[1]['profit_share']);
    }

    public function testShareIsNullWhenTotalProfitIsNotPositive(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [
                ['id' => 1, 'name' => 'ร้าน A'],
                ['id' => 2, 'name' => 'ร้าน B'],
            ],
            [
                ['shop_id' => 1, 'total_revenue' => 100, 'total_ad_cost' => 500, 'days_count' => 5],   // -400
                ['shop_id' => 2, 'total_revenue' => 100, 'total_ad_cost' => 300, 'days_count' => 5],   // -200
            ]
        ));

        // ทุกร้านขาดทุน → กำไรรวมติดลบ → เปอร์เซ็นต์ไม่มีความหมาย
        foreach ($rows as $row) {
            $this->assertNull($row['profit_share']);
        }
    }

    public function testMomentumComparesWithPreviousMonth(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 1500, 'total_ad_cost' => 300, 'days_count' => 5]],   // profit 1200
            [['shop_id' => 1, 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 5]]    // prev profit 800
        ));

        $this->assertSame(800.0, $rows[0]['prev_profit']);
        $this->assertSame(400.0, $rows[0]['profit_change']);
        $this->assertSame(50.0, $rows[0]['profit_change_percent']);   // 400/800
    }

    public function testMomentumIsNullForShopWithoutPreviousMonth(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [['id' => 1, 'name' => 'ร้านใหม่']],
            [['shop_id' => 1, 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 5]],
            []   // ไม่มีข้อมูลเดือนก่อน
        ));

        $this->assertSame(0.0, $rows[0]['prev_profit']);
        $this->assertSame(800.0, $rows[0]['profit_change']);
        $this->assertNull($rows[0]['profit_change_percent']);   // หารศูนย์ไม่ได้ → null
    }

    public function testMomentumSignIsCorrectWhenPreviousMonthWasLoss(): void
    {
        // เดือนก่อนขาดทุน -500 · เดือนนี้ขาดทุนน้อยลง -200 → ต้องเป็น "ดีขึ้น" (+60%)
        $rows = $this->rowsOf($this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 100, 'total_ad_cost' => 300, 'days_count' => 5]],    // -200
            [['shop_id' => 1, 'total_revenue' => 100, 'total_ad_cost' => 600, 'days_count' => 5]]     // -500
        ));

        $this->assertSame(-500.0, $rows[0]['prev_profit']);
        $this->assertSame(300.0, $rows[0]['profit_change']);
        $this->assertSame(60.0, $rows[0]['profit_change_percent']);   // 300 / abs(-500)
    }

    public function testMomentumNegativeWhenProfitDrops(): void
    {
        $rows = $this->rowsOf($this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 600, 'total_ad_cost' => 200, 'days_count' => 5]],    // 400
            [['shop_id' => 1, 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 5]]    // prev 800
        ));

        $this->assertSame(-400.0, $rows[0]['profit_change']);
        $this->assertSame(-50.0, $rows[0]['profit_change_percent']);
    }
}
