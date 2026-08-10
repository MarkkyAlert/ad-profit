<?php

declare(strict_types=1);

namespace Tests\Integration;

use AnnualService;
use DashboardService;
use GoalRepository;
use OverviewAnnualService;
use OverviewDailyService;
use OverviewService;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ⭐⭐ ยอดเดือนเดียวกัน ต้องได้เลขเดียวกัน **ทุกหน้าที่พูดถึงมัน** และตรงกับฐานข้อมูล
 *
 * ⚠️⚠️ บั๊กรายงานเกือบทั้งหมดของโปรเจกต์นี้เป็นรูปแบบเดียวกัน: **กติกาถูกบังคับใช้ที่หนึ่ง
 * แต่ไปไม่ถึงอีกที่หนึ่ง** — แดชบอร์ดตัดที่วันนี้แต่หน้ารวมร้านไม่ตัด · แท็บรายเดือน
 * เรียงร้านแบบหนึ่งแต่แท็บรายวันอีกแบบ · หน้าจอเว้นว่างแต่ไฟล์เขียน 0
 *
 * ตาข่ายที่มีอยู่ตรวจ "แต่ละหน้าคำนวณถูกไหม" ทีละหน้า — ซึ่งจับรูปแบบนี้ไม่ได้เลย
 * เพราะทุกหน้า *ถูกตามสูตรของตัวเอง* พร้อมกันได้ ทั้งที่ตอบไม่ตรงกัน
 *
 * เทสต์นี้ถามคำถามเดียว **"เดือน ส.ค. ร้านนี้กำไรเท่าไหร่"** แล้วถามซ้ำผ่านทุกหน้า
 * ที่ตอบคำถามนี้ได้ พร้อมถาม MySQL ตรง ๆ เป็นตัวตัดสิน
 *
 * ⚠️ ยอดตั้งต้นมีเศษสตางค์ที่บวกกันแล้ว **ไม่ลงตัวในฐานสอง** — เลขกลม ๆ จะทำให้
 * ทุกฝั่งตรงกันโดยบังเอิญ แล้วเทสต์เขียวทั้งที่ไม่ได้พิสูจน์อะไร (เทสต์ยืนยันข้อนี้เองก่อน)
 */
final class CrossReportDimensionParityTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-20';
    private const MONTH = '2026-08';
    private const YEAR = 2026;

    /** @return list<array{0:string,1:float,2:float}> */
    private function rows(): array
    {
        return [
            ['2026-08-01', 12345.67, 2345.89],
            ['2026-08-02', 9876.54, 1234.56],
            ['2026-08-05', 23456.78, 8765.43],
            ['2026-08-11', 7654.32, 9876.54],
            ['2026-08-19', 34567.02, 1111.11],
            // นอกเดือน — ต้องไม่ถูกนับที่ไหนเลย
            ['2026-07-31', 99999.99, 11111.11],
            // ในเดือนแต่ยังมาไม่ถึง (แถวเก่าจากยุคที่ลงล่วงหน้าได้) — ต้องไม่ถูกนับเช่นกัน
            ['2026-08-25', 88888.88, 22222.22],
        ];
    }

    private function insert(int $shopId, string $date, float $revenue, float $adCost): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'date' => $date, 'revenue' => $revenue, 'ad' => $adCost]);
    }

    /** คำตอบของ MySQL — ตัวตัดสิน (ตัดที่วันนี้เหมือนกติกาของแอป) */
    private function truthFromDatabase(int $shopId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS days, SUM(revenue) AS revenue, SUM(ad_cost) AS ad_cost,
                    SUM(revenue - ad_cost) AS profit
             FROM daily_records
             WHERE shop_id = :shop AND record_date BETWEEN :start AND :end'
        );
        $statement->execute(['shop' => $shopId, 'start' => '2026-08-01', 'end' => self::TODAY]);
        $row = (array)$statement->fetch();

        return [
            'days' => (int)$row['days'],
            'revenue' => round((float)$row['revenue'], 2),
            'ad_cost' => round((float)$row['ad_cost'], 2),
            'profit' => round((float)$row['profit'], 2),
        ];
    }

    private function recordRepository(): RecordRepository
    {
        return new RecordRepository($this->pdo);
    }

    private function shopRepository(): ShopRepository
    {
        return new ShopRepository($this->pdo);
    }

    /**
     * ⭐⭐ แดชบอร์ด · หน้ารายปี · หน้าประวัติ · หน้ารวมร้าน ต้องตอบเลขเดียวกันกับฐานข้อมูล
     */
    public function testEveryReportAnswersTheSameMonthWithTheSameNumbers(): void
    {
        $userId = $this->createUser('crossreport@example.com', 'CrossPass123');
        $shopId = $this->createShop($userId, 'ร้านที่ถูกถามซ้ำทุกหน้า');
        // ร้านที่สองมีไว้ให้หน้ารวมร้านเปิดได้ (กติกา: ต้องมี ≥ 2 ร้าน)
        $otherShopId = $this->createShop($userId, 'ร้านประกอบฉาก');
        $this->insert($otherShopId, '2026-08-03', 1000.0, 400.0);

        foreach ($this->rows() as [$date, $revenue, $adCost]) {
            $this->insert($shopId, $date, $revenue, $adCost);
        }

        $truth = $this->truthFromDatabase($shopId);

        /* ⚠️ ยืนยันก่อนว่าข้อมูลตั้งต้น "ยาก" จริง — ถ้าผลบวกแบบดิบ ๆ ของ PHP เท่ากับ
           ค่าที่ปัดสตางค์แล้วพอดี แสดงว่าชุดตัวเลขนี้พิสูจน์เรื่องการปัดไม่ได้ */
        $naive = 0.0;
        foreach ($this->rows() as [$date, $revenue, $adCost]) {
            if ($date >= '2026-08-01' && $date <= self::TODAY) {
                $naive += $revenue - $adCost;
            }
        }
        /* ⚠️⚠️ เทียบกับ `money_total()` ไม่ใช่เทียบกับคำตอบของฐานข้อมูล — ผลบวกดิบของ PHP
           อาจ "ตกลงบนค่าเดียวกัน" กับค่าที่ปัดแล้วโดยบังเอิญ (ลองชุดแรกแล้วเป็นแบบนั้นจริง)
           สิ่งที่ต้องยืนยันคือ **การปัดเปลี่ยนค่าจริง** สำหรับชุดตัวเลขนี้ */
        $this->assertNotSame(
            money_total($naive),
            $naive,
            'ยอดตั้งต้นบวกกันลงตัวพอดี — เทสต์นี้จะไม่ได้พิสูจน์เรื่องการปัดเศษเลย'
        );

        $answers = [];

        // ── 1. แดชบอร์ด (ช่วง "เดือนนี้")
        $dashboard = (new DashboardService(
            $this->recordRepository(),
            $this->shopRepository(),
            new GoalRepository($this->pdo)
        ))->buildDashboard($userId, $shopId, 'month_this', null, null, null, self::TODAY);
        $summary = (array)($dashboard['data']['summary'] ?? []);
        // จำนวนวันของแดชบอร์ดอยู่ในบล็อก statistics ไม่ใช่ summary
        $statistics = (array)($dashboard['data']['statistics'] ?? []);
        $answers['แดชบอร์ด'] = [
            'days' => (int)($statistics['days_count'] ?? -1),
            'revenue' => round((float)($summary['total_revenue'] ?? -1), 2),
            'ad_cost' => round((float)($summary['total_ad_cost'] ?? -1), 2),
            'profit' => round((float)($summary['profit'] ?? -1), 2),
        ];

        // ── 2. หน้ารายปี (แถวเดือน ส.ค. ในตารางรายเดือน)
        $annual = (new AnnualService(
            $this->recordRepository(),
            $this->shopRepository(),
            new GoalRepository($this->pdo)
        ))->buildYearlySummary($userId, $shopId, self::YEAR, self::TODAY);
        $augustRow = [];
        foreach ((array)($annual['data']['months'] ?? []) as $month) {
            if ((int)($month['month'] ?? 0) === 8) {
                $augustRow = (array)$month;
            }
        }
        $this->assertNotSame([], $augustRow, 'หน้ารายปีไม่มีแถวของเดือน ส.ค. เลย');
        $answers['หน้ารายปี'] = [
            'days' => (int)($augustRow['days_count'] ?? -1),
            'revenue' => round((float)($augustRow['total_revenue'] ?? -1), 2),
            'ad_cost' => round((float)($augustRow['total_ad_cost'] ?? -1), 2),
            'profit' => round((float)($augustRow['profit'] ?? -1), 2),
        ];

        // ── 3. หน้าประวัติ (รวมแถวที่แสดงจริงในเดือนนั้น)
        $history = (new RecordService($this->recordRepository(), $this->shopRepository(), $this->pdo))
            ->getMonthlyRecords($userId, $shopId, self::MONTH);
        $historyRevenue = 0.0;
        $historyAdCost = 0.0;
        $historyDays = 0;
        foreach ((array)($history['data']['records'] ?? []) as $record) {
            // หน้าประวัติแสดงแถวเก่าที่เป็นวันอนาคตด้วย (แก้/ลบได้) — เทียบเฉพาะช่วงเดียวกัน
            if ((string)($record['record_date'] ?? '') > self::TODAY) {
                continue;
            }

            $historyRevenue += (float)($record['revenue'] ?? 0);
            $historyAdCost += (float)($record['ad_cost'] ?? 0);
            $historyDays++;
        }
        $answers['หน้าประวัติ'] = [
            'days' => $historyDays,
            'revenue' => money_total($historyRevenue),
            'ad_cost' => money_total($historyAdCost),
            'profit' => money_total($historyRevenue - $historyAdCost),
        ];

        // ── 4. หน้ารวมร้าน แท็บรายเดือน (แถวของร้านนี้)
        $overview = (new OverviewService($this->recordRepository(), $this->shopRepository()))
            ->buildOverview($userId, self::MONTH, self::TODAY);
        $overviewRow = [];
        foreach ((array)($overview['data']['comparison']['rows'] ?? []) as $row) {
            if ((int)($row['shop_id'] ?? 0) === $shopId) {
                $overviewRow = (array)$row;
            }
        }
        $this->assertNotSame([], $overviewRow, 'หน้ารวมร้านไม่มีแถวของร้านนี้');
        $answers['หน้ารวมร้าน (เดือน)'] = [
            'days' => (int)($overviewRow['days_count'] ?? -1),
            'revenue' => round((float)($overviewRow['total_revenue'] ?? -1), 2),
            'ad_cost' => round((float)($overviewRow['total_ad_cost'] ?? -1), 2),
            'profit' => round((float)($overviewRow['profit'] ?? -1), 2),
        ];

        foreach ($answers as $where => $answer) {
            $this->assertSame(
                $truth,
                $answer,
                "\"{$where}\" ตอบเดือน ส.ค. ไม่ตรงกับฐานข้อมูล\n"
                . 'ฐานข้อมูล: ' . json_encode($truth, JSON_UNESCAPED_UNICODE) . "\n"
                . "{$where}: " . json_encode($answer, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * ⭐⭐ แท็บ "รายเดือน" กับ "รายวัน" ของหน้ารวมร้าน ต้องบวกได้เท่ากัน
     *
     * ⚠️ เคยพังจริง: กดสลับแท็บเฉย ๆ ยอดรวมเปลี่ยนจาก ฿8,000 เป็น ฿14,000 เพราะแท็บรายวัน
     * ไล่ถึงสิ้นเดือนขณะที่อีกแท็บตัดที่วันนี้ · และร้านที่ยังไม่กรอกหายไปทั้งแถวในแท็บเดียว
     */
    public function testTheMonthlyTabAndTheDailyTabAddUpToTheSameTotal(): void
    {
        $userId = $this->createUser('twotabs@example.com', 'TwoTabsPass123');
        $shopIds = [];
        foreach (['ร้านหนึ่ง', 'ร้านสอง', 'ร้านสาม'] as $name) {
            $shopIds[$name] = $this->createShop($userId, $name);
        }

        foreach ($this->rows() as [$date, $revenue, $adCost]) {
            $this->insert($shopIds['ร้านหนึ่ง'], $date, $revenue, $adCost);
            $this->insert($shopIds['ร้านสอง'], $date, $revenue / 3, $adCost / 7);
        }
        // ร้านสามไม่กรอกเลย — ต้องมีแถวครบทั้งสองแท็บ

        $monthly = (new OverviewService($this->recordRepository(), $this->shopRepository()))
            ->buildOverview($userId, self::MONTH, self::TODAY);
        $daily = (new OverviewDailyService($this->recordRepository(), $this->shopRepository()))
            ->buildDailyOverview($userId, self::MONTH, self::TODAY);

        $monthlyRows = (array)($monthly['data']['comparison']['rows'] ?? []);
        $dailyRows = (array)($daily['data']['shops'] ?? []);

        $this->assertCount(3, $monthlyRows, 'แท็บรายเดือนมีร้านไม่ครบ');
        $this->assertCount(3, $dailyRows, 'แท็บรายวันมีร้านไม่ครบ — ร้านที่ยังไม่กรอกหายไป');

        $this->assertSame(
            array_map(static fn(array $row): string => (string)($row['shop_name'] ?? ''), $monthlyRows),
            array_map(static fn(array $row): string => (string)($row['shop_name'] ?? ''), $dailyRows),
            'ลำดับร้านของสองแท็บไม่ตรงกัน — กดสลับแท็บแล้วอันดับเปลี่ยน'
        );

        $profitOf = static function (array $rows): array {
            $found = [];
            foreach ($rows as $row) {
                $found[(string)($row['shop_name'] ?? '')] = round((float)($row['profit'] ?? 0), 2);
            }

            return $found;
        };

        $this->assertSame(
            $profitOf($monthlyRows),
            $profitOf($dailyRows),
            'กำไรรายร้านของสองแท็บไม่ตรงกัน ทั้งที่เป็นเดือนเดียวกันและข้อมูลชุดเดียวกัน'
        );

        // ── รวมทุกร้านของแท็บรายวัน (ไล่จากแถววันจริง) ต้องเท่ากับผลบวกของแท็บรายเดือน
        $dailyTotal = 0.0;
        foreach ((array)($daily['data']['days'] ?? []) as $day) {
            $dailyTotal += (float)($day['profit'] ?? 0);
        }

        $monthlyTotal = 0.0;
        foreach ($monthlyRows as $row) {
            $monthlyTotal += (float)($row['profit'] ?? 0);
        }

        $this->assertSame(
            money_total($monthlyTotal),
            money_total($dailyTotal),
            'ยอดรวมของตารางรายวัน ไม่เท่ากับผลบวกของตารางรายเดือนในหน้าเดียวกัน'
        );
    }

    /**
     * ⭐⭐ มุมรายปีของหน้ารวมร้าน = ผลบวกของหน้ารายปีทีละร้าน
     *
     * ⚠️ สองที่นี้คำนวณคนละทางกันสนิท (`OverviewAnnualService` ยิงรวมทุกร้าน ส่วน
     * `AnnualService` ยิงทีละร้าน) เคยเรียงอันดับด้วยกติกาคนละแบบมาแล้ว
     */
    public function testTheYearlyOverviewMatchesTheAnnualPageOfEachShop(): void
    {
        $userId = $this->createUser('yearcross@example.com', 'YearCrossPass123');
        $shopIds = [];
        foreach (['ร้านเอ', 'ร้านบี'] as $name) {
            $shopIds[$name] = $this->createShop($userId, $name);
        }

        foreach ($this->rows() as [$date, $revenue, $adCost]) {
            $this->insert($shopIds['ร้านเอ'], $date, $revenue, $adCost);
            $this->insert($shopIds['ร้านบี'], $date, $adCost, $revenue / 5);
        }

        $overview = (new OverviewAnnualService($this->recordRepository(), $this->shopRepository()))
            ->buildYearlyOverview($userId, self::YEAR, self::TODAY);

        $annualService = new AnnualService(
            $this->recordRepository(),
            $this->shopRepository(),
            new GoalRepository($this->pdo)
        );

        foreach ((array)($overview['data']['shops'] ?? []) as $shop) {
            $name = (string)($shop['shop_name'] ?? '');
            $this->assertArrayHasKey($name, $shopIds, "หน้ารวมร้านมีร้านที่ไม่รู้จัก: {$name}");

            $annual = (array)($annualService->buildYearlySummary(
                $userId,
                $shopIds[$name],
                self::YEAR,
                self::TODAY
            )['data'] ?? []);
            $summary = (array)($annual['summary'] ?? []);

            // สรุปรายปีไม่มีคีย์ "จำนวนวัน" — รวมจากตารางรายเดือนซึ่งเป็นตัวเลขที่ผู้ใช้เห็นจริง
            $annualDays = 0;
            foreach ((array)($annual['months'] ?? []) as $month) {
                $annualDays += (int)($month['days_count'] ?? 0);
            }

            $this->assertSame(
                round((float)($summary['profit'] ?? -1), 2),
                round((float)($shop['profit'] ?? -2), 2),
                "กำไรทั้งปีของ \"{$name}\" ในหน้ารวมร้าน ไม่ตรงกับหน้ารายปีของร้านเดียวกัน"
            );
            $this->assertSame(
                $annualDays,
                (int)($shop['days_count'] ?? -2),
                "จำนวนวันที่กรอกของ \"{$name}\" ไม่ตรงกันระหว่างสองหน้า"
            );
        }
    }
}
