<?php

declare(strict_types=1);

namespace Tests\Integration;

use OverviewAnnualService;
use OverviewService;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ หน้ารวมร้าน: จัดกลุ่มแบบไหน ผลรวมต้องเท่ากัน
 *
 * ⚠️ `XlsxAnnualParityTest` ล็อก invariant นี้ไว้แล้วสำหรับ **ร้านเดียว**
 * (กำไรทั้งปี = Σ รายเดือน = Σ รายวัน) แต่ฝั่ง **รวมหลายร้าน** ไม่เคยมีตาข่ายเลย
 * ทั้งที่มันคือหน้าที่ใช้ตัดสินว่า "ร้านไหนคุ้ม ร้านไหนควรปิด"
 *
 * ⚠️⚠️ และต้องพิสูจน์ **พร้อมกัน** ว่าข้อมูลของผู้ใช้อื่นไม่รั่วเข้ามา — ยอดรวมที่ถูก
 * กับยอดรวมที่รั่วต่างกันแค่ "มีร้านของคนอื่นบวกเข้ามาไหม" ถ้าเทสต์ดูแค่ว่า
 * "รวม = Σ ที่แสดง" มันจะเขียวทั้งที่รั่ว เพราะทั้งสองฝั่งรั่วเท่ากัน
 * → ต้องยึดกับยอดที่คำนวณจาก SQL ของร้านที่เป็นของผู้ใช้คนนั้นจริง ๆ
 */
final class OverviewParityTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-09';

    /**
     * ผู้ใช้ A มี 2 ร้าน · ผู้ใช้ B มี 1 ร้านที่ยอดสูงกว่ามาก
     *
     * ⚠️ ยอดของ B ต้องสูงจนผิดสังเกต — ถ้ารั่วเข้ามาแล้วยอดต่างกันไม่กี่บาท
     * เทสต์อาจผ่านไปได้ด้วย delta ที่ตั้งไว้
     *
     * @return array{0:int,1:int,2:int}
     */
    private function seedTwoUsers(): array
    {
        $userA = $this->createUser('owner-a@example.com', 'OwnerAPass123');
        $userB = $this->createUser('owner-b@example.com', 'OwnerBPass123');

        $shopA1 = $this->createShop($userA, 'ร้านของ A หนึ่ง');
        $shopA2 = $this->createShop($userA, 'ร้านของ A สอง');
        $shopB = $this->createShop($userB, 'ร้านของ B');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );

        // ยอดมีเศษสตางค์ เพื่อให้การปัดเศษที่ต่างกันโผล่ออกมาถ้ามี
        $day = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable(self::TODAY);
        while ($day <= $end) {
            $date = $day->format('Y-m-d');
            $insert->execute(['shop' => $shopA1, 'date' => $date, 'revenue' => 3111.11, 'ad' => 2022.22]);
            $insert->execute(['shop' => $shopA2, 'date' => $date, 'revenue' => 5333.33, 'ad' => 3044.44]);
            $insert->execute(['shop' => $shopB, 'date' => $date, 'revenue' => 900000.00, 'ad' => 1.00]);
            $day = $day->modify('+1 day');
        }

        // แถวอนาคตของผู้ใช้ A — ต้องไม่ถูกนับ
        $insert->execute(['shop' => $shopA1, 'date' => '2026-12-31', 'revenue' => 777777.77, 'ad' => 0.0]);

        return [$userA, $shopA1, $shopA2];
    }

    private function profitFromDatabase(int ...$shopIds): float
    {
        $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT SUM(revenue - ad_cost) FROM daily_records
             WHERE shop_id IN ({$placeholders}) AND record_date BETWEEN '2026-01-01' AND '" . self::TODAY . "'"
        );
        $statement->execute($shopIds);

        return money_total((float)$statement->fetchColumn());
    }

    /**
     * ⭐ มุมรายปี: ยอดรวม = Σ ต่อร้าน = Σ รายเดือน = ยอดจากฐานข้อมูลของร้านตัวเองเท่านั้น
     */
    public function testTheYearlyOverviewAgreesAcrossEveryGrouping(): void
    {
        [$userA, $shopA1, $shopA2] = $this->seedTwoUsers();

        $service = new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );

        $result = $service->buildYearlyOverview($userA, 2026, self::TODAY);
        $this->assertTrue($result['success'] ?? false, 'สร้างรายงานรวมร้านไม่สำเร็จ');

        $data = (array)($result['data'] ?? []);
        $expected = $this->profitFromDatabase($shopA1, $shopA2);

        // ยืนยันก่อนว่าข้อมูลตั้งต้นทำให้ "รั่ว" กับ "ไม่รั่ว" ต่างกันชัดเจน
        $leaked = money_total((float)$this->pdo->query(
            "SELECT SUM(revenue - ad_cost) FROM daily_records
             WHERE record_date BETWEEN '2026-01-01' AND '" . self::TODAY . "'"
        )->fetchColumn());
        $this->assertGreaterThan(
            $expected * 10,
            $leaked,
            'ข้อมูลตั้งต้นไม่ได้ทำให้กรณีรั่วต่างจากกรณีปกติมากพอ — เทสต์อาจผ่านทั้งที่รั่ว'
        );

        $summaryProfit = money_total((float)($data['summary']['profit'] ?? 0));
        $this->assertEqualsWithDelta(
            $expected,
            $summaryProfit,
            0.005,
            'ยอดรวมของหน้ารวมร้านไม่ตรงกับผลรวมจริงของร้านที่ผู้ใช้เป็นเจ้าของ'
        );

        $shopSum = money_total(array_sum(array_map(
            static fn(array $shop): float => (float)($shop['profit'] ?? 0),
            (array)($data['shops'] ?? [])
        )));
        $this->assertEqualsWithDelta($expected, $shopSum, 0.005, 'ยอดรวม ≠ Σ กำไรต่อร้าน');

        $monthSum = money_total(array_sum(array_map(
            static fn(array $month): float => (float)($month['profit'] ?? 0),
            (array)($data['months'] ?? [])
        )));
        $this->assertEqualsWithDelta($expected, $monthSum, 0.005, 'ยอดรวม ≠ Σ กำไรรายเดือน');

        $names = array_map(static fn(array $shop): string => (string)($shop['shop_name'] ?? ''), (array)($data['shops'] ?? []));
        $this->assertNotContains('ร้านของ B', $names, 'ร้านของผู้ใช้อื่นโผล่เข้ามาในตารางเทียบร้าน');
    }

    /**
     * ⭐ สัดส่วนกำไรของทุกร้านต้องรวมกันได้ 100% พอดี
     *
     * ⚠️ ห้ามบวกค่าที่ปัดแล้วทีละแถวแล้วคาดหวัง 100 — ต้องเก็บทศนิยมพอที่จะรวมกันลงตัว
     * (เคยได้ 99.9% จากร้านสามร้านที่กำไรเท่ากันเป๊ะ)
     */
    public function testProfitSharesAddUpToExactlyOneHundredPercent(): void
    {
        [$userA] = $this->seedTwoUsers();

        $service = new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
        $data = (array)($service->buildYearlyOverview($userA, 2026, self::TODAY)['data'] ?? []);

        $shares = [];
        foreach ((array)($data['shops'] ?? []) as $shop) {
            if (($shop['profit_share'] ?? null) !== null) {
                $shares[] = (float)$shop['profit_share'];
            }
        }

        $this->assertNotSame([], $shares, 'ไม่มีร้านไหนมีสัดส่วนกำไรเลย');
        $this->assertEqualsWithDelta(100.0, array_sum($shares), 0.05, 'สัดส่วนกำไรรวมกันไม่ได้ 100%');
    }

    /**
     * ⭐ มุมรายเดือน: ยอดรวมของเดือน = Σ ต่อร้านในเดือนนั้น และไม่มีร้านของคนอื่นปน
     */
    public function testTheMonthlyOverviewAgreesWithItsOwnShopRows(): void
    {
        [$userA, $shopA1, $shopA2] = $this->seedTwoUsers();

        $service = new OverviewService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );

        $result = $service->buildOverview($userA, '2026-08', self::TODAY);
        $this->assertTrue($result['success'] ?? false, 'สร้างรายงานรวมร้าน (รายเดือน) ไม่สำเร็จ');

        $data = (array)($result['data'] ?? []);
        $rows = (array)($data['comparison']['rows'] ?? []);

        $statement = $this->pdo->prepare(
            'SELECT SUM(revenue - ad_cost) FROM daily_records
             WHERE shop_id IN (?, ?) AND record_date BETWEEN ? AND ?'
        );
        $statement->execute([$shopA1, $shopA2, '2026-08-01', self::TODAY]);
        $expected = money_total((float)$statement->fetchColumn());

        $rowSum = money_total(array_sum(array_map(
            static fn(array $row): float => (float)($row['profit'] ?? 0),
            $rows
        )));
        $this->assertEqualsWithDelta(
            $expected,
            $rowSum,
            0.005,
            'Σ กำไรของแถวร้านในมุมรายเดือน ไม่ตรงกับผลรวมจริง (ตัดที่วันนี้)'
        );

        $names = array_map(static fn(array $row): string => (string)($row['shop_name'] ?? ''), $rows);
        $this->assertNotContains('ร้านของ B', $names, 'ร้านของผู้ใช้อื่นโผล่เข้ามาในมุมรายเดือน');
        $this->assertCount(2, $rows, 'จำนวนร้านในตารางไม่ใช่ 2 ร้านของผู้ใช้คนนี้');
    }
}
