<?php

declare(strict_types=1);

namespace Tests\Integration;

use AnnualService;
use DashboardService;
use GoalRepository;
use OverviewAnnualService;
use OverviewService;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ เงินที่ "เท่ากันจริง" ต้องถูกมองว่าเท่ากันทุกที่ที่จัดอันดับ/เลือกดีสุด-แย่สุด
 *
 * ⚠️⚠️ **วัดจริงก่อนแก้** — PHP เก็บทศนิยมเป็นฐานสอง:
 *     0.30 − 0.20 = 0.09999999999999997780
 *     0.20 − 0.10 = 0.10000000000000000555
 * ทั้งคู่แสดงเป็น **฿0.10 เท่ากัน** บนหน้าจอ แต่ `>` / `<` มองว่าต่างกัน
 * → แดชบอร์ดขึ้น "วันกำไรดีสุด 2 ส.ค. (฿0.10)" คู่กับ "วันกำไรแย่สุด 1 ส.ค. (฿0.10)"
 * ตัวเลขเดียวกัน คนละวัน สีเขียวกับสีแดง — และตัวกัน `extremes_are_comparable()`
 * ก็ช่วยไม่ได้ เพราะมันเทียบค่าที่ยังไม่ปัด
 *
 * ⚠️ คู่มือมีกฎ "ยอดรวมที่บวกใน PHP ต้องผ่าน `money_total()`" อยู่แล้ว — ที่ตกสำรวจคือ
 * **กำไรรายแถว** ซึ่งเกิดจากการลบ ไม่ใช่การบวก
 *
 * ⚠️⚠️ ข้อมูลทดสอบต้องเป็นคู่ที่ float แยกกันจริง — เทสต์นี้ยืนยันเงื่อนไขนั้นเองก่อน
 * ไม่งั้นจะผ่านโดยไม่ได้พิสูจน์อะไร
 */
final class MonetaryPrecisionReportingTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-08';

    /** คู่ยอดที่กำไรเท่ากันเป๊ะ แต่ float ไม่เท่ากัน */
    private const A_REVENUE = 0.30;
    private const A_AD_COST = 0.20;
    private const B_REVENUE = 0.20;
    private const B_AD_COST = 0.10;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertNotSame(
            self::A_REVENUE - self::A_AD_COST,
            self::B_REVENUE - self::B_AD_COST,
            'ข้อมูลตั้งต้นไม่ได้ทำให้ float ต่างกัน — เทสต์นี้จะผ่านโดยไม่ได้พิสูจน์อะไร'
        );
    }

    private function insert(int $shopId, string $date, float $revenue, float $adCost): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'date' => $date, 'revenue' => $revenue, 'ad' => $adCost]);
    }

    /**
     * ⭐ วันดีสุด/แย่สุดของแดชบอร์ด ต้องชี้วันเดียวกันเมื่อกำไรเท่ากันจริง
     *
     * (ชี้วันเดียวกัน = `extremes_are_comparable()` ซ่อนการ์ดคู่ให้ ซึ่งเป็นผลที่ต้องการ)
     */
    public function testTheDashboardDoesNotSplitTwoEquallyProfitableDays(): void
    {
        $userId = $this->createUser('cent@example.com', 'CentPass123');
        $shopId = $this->createShop($userId, 'ร้านที่กำไรเท่ากันเป๊ะสองวัน');

        $this->insert($shopId, '2026-08-01', self::A_REVENUE, self::A_AD_COST);
        $this->insert($shopId, '2026-08-02', self::B_REVENUE, self::B_AD_COST);

        $data = (array)((new DashboardService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        ))->buildDashboard($userId, $shopId, 'month_this', null, null, null, self::TODAY)['data'] ?? []);

        $best = (array)($data['statistics']['best_day'] ?? []);
        $worst = (array)($data['statistics']['worst_day'] ?? []);

        $this->assertSame(
            (float)($best['profit'] ?? -1),
            (float)($worst['profit'] ?? -2),
            'กำไรของวันดีสุดกับวันแย่สุดต้องเป็นค่าเดียวกันเป๊ะ (ปัดสตางค์แล้ว)'
        );
        $this->assertSame(
            $best['record_date'] ?? 'x',
            $worst['record_date'] ?? 'y',
            'สองวันที่กำไรเท่ากันถูกแยกเป็นดีสุด/แย่สุดคนละวัน — ผู้ใช้เห็น ฿0.10 สองการ์ด เขียวกับแดง'
        );
    }

    /**
     * ⭐ เดือนดีสุด/แย่สุดของหน้ารายปี ต้องใช้กติกาเดียวกัน
     */
    public function testTheAnnualReportDoesNotSplitTwoEquallyProfitableMonths(): void
    {
        $userId = $this->createUser('centmonth@example.com', 'CentMonthPass123');
        $shopId = $this->createShop($userId, 'ร้านที่กำไรเท่ากันเป๊ะสองเดือน');

        $this->insert($shopId, '2026-06-10', self::A_REVENUE, self::A_AD_COST);
        $this->insert($shopId, '2026-07-10', self::B_REVENUE, self::B_AD_COST);

        $summary = (array)((new AnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        ))->buildYearlySummary($userId, $shopId, 2026, self::TODAY)['data']['summary'] ?? []);

        $best = (array)($summary['best_month'] ?? []);
        $worst = (array)($summary['worst_month'] ?? []);

        $this->assertSame(
            (float)($best['profit'] ?? -1),
            (float)($worst['profit'] ?? -2),
            'กำไรของเดือนดีสุดกับแย่สุดต้องเป็นค่าเดียวกันเป๊ะ'
        );
    }

    /**
     * ⭐ อันดับร้านต้องไม่สลับกันเพราะเศษ float — ต้องตัดสินด้วยชื่อตามกติกา tie-break
     */
    public function testShopRankingTreatsEqualProfitsAsATie(): void
    {
        $userId = $this->createUser('centrank@example.com', 'CentRankPass123');
        $shopFirst = $this->createShop($userId, 'ก ร้านแรกตามตัวอักษร');
        $shopSecond = $this->createShop($userId, 'ข ร้านสองตามตัวอักษร');

        // ร้านที่ float "มากกว่า" คือร้านที่ชื่อมาทีหลัง — ถ้าไม่ปัด มันจะแซงขึ้นอันดับ 1
        $this->insert($shopFirst, '2026-08-01', self::A_REVENUE, self::A_AD_COST);
        $this->insert($shopSecond, '2026-08-01', self::B_REVENUE, self::B_AD_COST);

        $rows = (array)((new OverviewService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        ))->buildOverview($userId, '2026-08', self::TODAY)['data']['comparison']['rows'] ?? []);

        $this->assertCount(2, $rows);
        $this->assertSame(
            'ก ร้านแรกตามตัวอักษร',
            (string)($rows[0]['shop_name'] ?? ''),
            'กำไรเท่ากันแล้วต้องตัดด้วยชื่อ — เศษ float ไม่ควรตัดสินอันดับ'
        );
    }

    /**
     * ⭐⭐ สัดส่วนกำไรของทุกร้านต้องรวมกันได้ **100.00 เป๊ะ** ไม่ใช่ใกล้เคียง
     *
     * ⚠️ เทสต์เดิมที่ชื่อว่า "exactly one hundred" ใช้ `assertEqualsWithDelta(..., 0.05)`
     * กับข้อมูล 2 ร้านที่หารลงตัวพอดี จึงปล่อย 99.99 ผ่านมาตลอด · ต้องใช้ 3 ร้านที่
     * หารไม่ลงตัว และเทียบแบบไม่มี delta
     *
     * @return array<string,array{0:list<float>}>
     */
    public static function unevenProfitProvider(): array
    {
        return [
            'สามร้านเท่ากัน (33.33 ×3 = 99.99)' => [[1000.0, 1000.0, 1000.0]],
            'เจ็ดร้านเท่ากัน (14.28 ×7 = 99.96)' => [[100.0, 100.0, 100.0, 100.0, 100.0, 100.0, 100.0]],
            'สัดส่วนไม่ลงตัว' => [[100.0, 50.0, 25.0]],
            'ร้านเล็กมากกับร้านใหญ่มาก' => [[0.2, 408000.0]],
        ];
    }

    /** @param list<float> $profits */
    #[\PHPUnit\Framework\Attributes\DataProvider('unevenProfitProvider')]
    public function testProfitSharesAlwaysAddUpToExactlyOneHundred(array $profits): void
    {
        $shares = distribute_profit_share($profits, array_sum($profits));

        $this->assertSame(
            100.0,
            round(array_sum(array_map(static fn($share): float => (float)$share, $shares)), 2),
            'สัดส่วนรวมกันไม่ได้ 100.00 พอดี: [' . implode(', ', array_map(
                static fn($share): string => (string)$share,
                $shares
            )) . ']'
        );
    }

    /** ⭐ ฐานไม่เป็นบวก → ทุกแถวต้องเป็น null (สัดส่วนของกำไรที่ติดลบไม่มีความหมาย) */
    public function testANonPositiveTotalGivesNullSharesEverywhere(): void
    {
        foreach ([[-5.0, 3.0], [0.0, 0.0]] as $profits) {
            $shares = distribute_profit_share($profits, array_sum($profits));
            foreach ($shares as $share) {
                $this->assertNull($share, 'ฐานไม่เป็นบวกแต่ยังคำนวณสัดส่วนออกมา');
            }
        }
    }

    /** ⭐ มุมรายปีต้องใช้กติกาเดียวกับมุมเดือน */
    public function testTheYearlyAngleUsesTheSameShareRule(): void
    {
        $userId = $this->createUser('yearshare@example.com', 'YearSharePass123');
        foreach (['ร้านหนึ่ง', 'ร้านสอง', 'ร้านสาม'] as $name) {
            $shopId = $this->createShop($userId, $name);
            for ($day = 1; $day <= 8; $day++) {
                $this->insert($shopId, sprintf('2026-08-%02d', $day), 3000.0, 2000.0);
            }
        }

        $shops = (array)((new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        ))->buildYearlyOverview($userId, 2026, self::TODAY)['data']['shops'] ?? []);

        $total = round(array_sum(array_map(
            static fn(array $shop): float => (float)($shop['profit_share'] ?? 0),
            $shops
        )), 2);

        $this->assertSame(100.0, $total, 'สัดส่วนกำไรของมุมรายปีรวมกันไม่ได้ 100.00 พอดี');
    }
}
