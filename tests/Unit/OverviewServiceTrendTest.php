<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ days_count ต่อร้าน + เทรนด์กำไร 6 เดือน
 */
final class OverviewServiceTrendTest extends TestCase
{
    private const MONTH = '2026-06';

    /**
     * @param array<int,array<string,mixed>> $shops
     * @param array<int,array<string,mixed>> $rangeTotals   ผลจาก getTotalsByShopIdsAndDateRange
     * @param array<int,array<string,mixed>> $monthlyTotals ผลจาก getMonthlyTotalsByShopIdsAndMonthRange
     */
    private function makeService(array $shops, array $rangeTotals, array $monthlyTotals = []): OverviewService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getTotalsByShopIdsAndDateRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($rangeTotals): array {
                // เดือนที่เลือกเท่านั้น (เดือนก่อน = momentum) → คืนว่าง
                return str_starts_with($start, '2026-06') ? $rangeTotals : [];
            }
        );
        $recordRepository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturn($monthlyTotals);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops);

        return new OverviewService($recordRepository, $shopRepository);
    }

    public function testDaysCountIsPerShop(): void
    {
        $service = $this->makeService(
            [
                ['id' => 1, 'name' => 'ร้านกรอกครบ'],
                ['id' => 2, 'name' => 'ร้านกรอกน้อย'],
                ['id' => 3, 'name' => 'ร้านไม่กรอกเลย'],
            ],
            [
                ['shop_id' => 1, 'total_revenue' => 3000, 'total_ad_cost' => 500, 'days_count' => 30],
                ['shop_id' => 2, 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 3],
                // ร้าน 3 ไม่มีแถวเลย
            ]
        );

        $rows = $service->buildOverview(1, self::MONTH)['data']['comparison']['rows'];

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['shop_name']] = $row;
        }

        $this->assertSame(30, $byName['ร้านกรอกครบ']['days_count']);
        $this->assertSame(3, $byName['ร้านกรอกน้อย']['days_count']);
        $this->assertSame(0, $byName['ร้านไม่กรอกเลย']['days_count']);   // ไม่มีข้อมูล → 0
    }

    public function testTrendSeriesIncludesProfitPerMonth(): void
    {
        $service = $this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 10]],
            [
                // เทรนด์ 6 เดือนของ 2026-06 = 2026-01..2026-06
                ['shop_id' => 1, 'month_key' => '2026-05', 'total_revenue' => 1000, 'total_ad_cost' => 400],
                ['shop_id' => 1, 'month_key' => '2026-06', 'total_revenue' => 2000, 'total_ad_cost' => 500],
            ]
        );

        $trend = $service->buildOverview(1, self::MONTH)['data']['charts']['trend'];
        $series = $trend['series'][0];

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], $trend['months']);
        $this->assertCount(6, $series['profit']);
        $this->assertCount(6, $series['revenue']);   // คง revenue ไว้เผื่อ toggle

        // พ.ค. = 1000-400 · มิ.ย. = 2000-500
        $this->assertSame(600.0, $series['profit'][4]);
        $this->assertSame(1500.0, $series['profit'][5]);
        $this->assertSame(1000.0, $series['revenue'][4]);

        // ⚠️⚠️ เดือนที่ยังไม่ได้กรอกเลยต้องเป็น `null` ไม่ใช่ `0`
        // เดิมเติม 0 ให้ กราฟจึงลากเส้นผ่านเหมือนเดือนนั้น "ทำได้เท่าทุนพอดี"
        // ร้านที่เริ่มใช้ระบบกลางปีจึงเห็นเส้นแบนอยู่ที่ ฿0 ยาวหลายเดือน และเส้นโค้ง
        // (`tension`) ยังทำให้เส้นตกต่ำกว่าศูนย์ทั้งที่ไม่มีเดือนไหนขาดทุนเลย
        $this->assertNull($series['profit'][0], 'เดือนที่ไม่มีข้อมูลถูกวาดเป็น ฿0');
        $this->assertNull($series['revenue'][0], 'เดือนที่ไม่มีข้อมูลถูกวาดเป็น ฿0');
    }

    /**
     * ⭐⭐ "ยังไม่ได้กรอก" กับ "กรอกแล้วได้เท่าทุนพอดี" ต้องแยกกันได้
     *
     * ⚠️ ถ้าไม่มีเทสต์ตัวนี้ ใครที่แก้ให้เดือนไม่มีข้อมูลเป็น null สามารถเผลอทำให้
     * เดือนที่กำไร ฿0 จริง ๆ กลายเป็น null ไปด้วย แล้วกราฟจะขาดตอนตรงเดือนที่
     * **มีข้อมูลอยู่จริง** — กลับหัวกับปัญหาเดิมพอดี
     */
    public function testAMonthThatTrulyBrokeEvenIsNotTreatedAsMissing(): void
    {
        $service = $this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 1000, 'total_ad_cost' => 200, 'days_count' => 10]],
            [
                // เดือนนี้กรอกแล้ว รายได้เท่าค่าแอดพอดี → กำไร ฿0 ที่เป็นความจริง
                ['shop_id' => 1, 'month_key' => '2026-05', 'total_revenue' => 700, 'total_ad_cost' => 700],
                ['shop_id' => 1, 'month_key' => '2026-06', 'total_revenue' => 2000, 'total_ad_cost' => 500],
            ]
        );

        $series = $service->buildOverview(1, self::MONTH)['data']['charts']['trend']['series'][0];

        $this->assertSame(0.0, $series['profit'][4], 'เดือนที่เท่าทุนจริงถูกนับว่าไม่มีข้อมูล');
        $this->assertSame(700.0, $series['revenue'][4]);
        $this->assertNull($series['profit'][0], 'เดือนที่ไม่มีข้อมูลกลับถูกวาดเป็นตัวเลข');
    }

    public function testTrendProfitCanBeNegative(): void
    {
        $service = $this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 100, 'total_ad_cost' => 900, 'days_count' => 5]],
            [
                // ค่าแอดมากกว่ารายได้ → เดือนนั้นขาดทุน
                ['shop_id' => 1, 'month_key' => '2026-06', 'total_revenue' => 500, 'total_ad_cost' => 1200],
            ]
        );

        $series = $service->buildOverview(1, self::MONTH)['data']['charts']['trend']['series'][0];

        $this->assertSame(-700.0, $series['profit'][5]);   // ไม่ถูก clamp เป็น 0
        $this->assertSame(500.0, $series['revenue'][5]);
    }

    public function testRevenueGrowsWhileProfitFalls(): void
    {
        // เคสที่กราฟรายได้จะดู "ดี" แต่กราฟกำไรเผยความจริง
        $service = $this->makeService(
            [['id' => 1, 'name' => 'ร้าน A']],
            [['shop_id' => 1, 'total_revenue' => 3000, 'total_ad_cost' => 2800, 'days_count' => 20]],
            [
                ['shop_id' => 1, 'month_key' => '2026-05', 'total_revenue' => 1000, 'total_ad_cost' => 200],
                ['shop_id' => 1, 'month_key' => '2026-06', 'total_revenue' => 3000, 'total_ad_cost' => 2800],
            ]
        );

        $series = $service->buildOverview(1, self::MONTH)['data']['charts']['trend']['series'][0];

        // รายได้ 1000 → 3000 (โต) แต่กำไร 800 → 200 (ตก)
        $this->assertGreaterThan($series['revenue'][4], $series['revenue'][5]);
        $this->assertLessThan($series['profit'][4], $series['profit'][5]);
        $this->assertSame(800.0, $series['profit'][4]);
        $this->assertSame(200.0, $series['profit'][5]);
    }
}
