<?php

declare(strict_types=1);

namespace Tests\Integration;

use DashboardService;
use GoalRepository;
use OverviewService;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ "เทียบเดือนก่อน" ต้องเทียบ **จำนวนวันเท่ากันสองฝั่ง** แม้เดือนก่อนจะสั้นกว่า
 *
 * ⚠️⚠️ **วัดจริงก่อนแก้** (ร้านที่ทำกำไรวันละ ฿1,000 เท่ากันเป๊ะทุกวัน 3 ปี กรอกครบทุกวัน):
 *   28 มี.ค. → 0.0%   (28 วัน เทียบ 28 วัน)
 *   29 มี.ค. → +3.6%  ← ป้ายบอกว่า "ถึงวันที่ 29" ซึ่ง ก.พ. ไม่มีวันนั้น
 *   30 มี.ค. → +7.1%
 *   31 มี.ค. → +10.7% ← ป้ายบอกว่า "ถึงวันที่ 31" ซึ่ง ก.พ. ไม่มีวันนั้น
 *    1 เม.ย. → 0.0%   (กลับมาเอง)
 *
 * สามวันสุดท้ายของเดือนที่ยาวกว่าเดือนก่อน ป้ายบอกว่ากำไรโตขึ้นทั้งที่ธุรกิจไม่เปลี่ยนเลย
 * — และวันสิ้นเดือนคือวันที่คนเปิดแดชบอร์ดมากที่สุด
 *
 * ⚠️⚠️ **เทสต์เดิมแยกพฤติกรรมนี้ไม่ได้เลย** — `DashboardServiceComparisonTest` ยืนยันแค่
 * `compared_up_to_day = 3` (กลางเดือน) กับ `null` (เดือนที่จบแล้ว) ซึ่งเป็นสองเคสที่
 * ความยาวเดือนไม่มีผล · ต้องใช้ **วันสิ้นเดือนที่ยาวกว่าเดือนก่อน** เท่านั้นถึงจะเห็น
 * (หลักเดียวกับที่คู่มือเตือนไว้ว่าเทสต์เดิมปักวันที่ 4 ส.ค. แล้วแยก `min(4,31)` กับ `4` ไม่ออก)
 */
final class MonthOverMonthCutoffTest extends IntegrationTestCase
{
    /**
     * ร้านที่ผลงานคงที่สนิท — ตัวเลขเทียบเดือนก่อนต้องเป็น 0% ทุกวันของปี
     *
     * @return array{0:int,1:int}
     */
    private function seedSteadyShop(): array
    {
        $userId = $this->createUser('steady@example.com', 'SteadyPass123');
        $shopId = $this->createShop($userId, 'ร้านที่ผลงานนิ่งสนิท');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, 3000, 2000, \'\', NOW(), NOW())'
        );

        // ครอบ ก.พ. ปีปกติและปีอธิกสุรทิน เพื่อให้เคสเดือนสั้นถูกเดินผ่านจริง
        $day = new \DateTimeImmutable('2024-01-01');
        $end = new \DateTimeImmutable('2026-12-31');
        while ($day <= $end) {
            $insert->execute(['shop' => $shopId, 'date' => $day->format('Y-m-d')]);
            $day = $day->modify('+1 day');
        }

        return [$userId, $shopId];
    }

    private function dashboard(): DashboardService
    {
        return new DashboardService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        );
    }

    /**
     * วันที่ควรเห็นความต่าง: เดือนก่อนสั้นกว่าวันปัจจุบัน
     *
     * @return array<string,array{0:string,1:int}> วันนี้ → วันตัดที่ป้ายควรประกาศ
     */
    public static function shortPreviousMonthProvider(): array
    {
        return [
            '29 มี.ค. — ก.พ. มี 28 วัน' => ['2026-03-29', 28],
            '30 มี.ค. — ก.พ. มี 28 วัน' => ['2026-03-30', 28],
            '31 มี.ค. — ก.พ. มี 28 วัน' => ['2026-03-31', 28],
            '31 พ.ค. — เม.ย. มี 30 วัน' => ['2026-05-31', 30],
            '31 ก.ค. — มิ.ย. มี 30 วัน' => ['2026-07-31', 30],
            '29 มี.ค. ปีอธิกสุรทิน — ก.พ. มี 29 วัน' => ['2024-03-29', 29],
            '31 มี.ค. ปีอธิกสุรทิน — ก.พ. มี 29 วัน' => ['2024-03-31', 29],
            'กลางเดือน — ไม่ต้องหด' => ['2026-03-15', 15],
            'เดือนก่อนยาวกว่า — ไม่ต้องหด' => ['2026-04-30', 30],
        ];
    }

    /**
     * ⭐ ป้ายต้องประกาศวันตัดที่ **มีอยู่จริงในเดือนก่อน**
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shortPreviousMonthProvider')]
    public function testTheBadgeAnnouncesADayThatExistsInThePreviousMonth(string $today, int $expectedDay): void
    {
        [$userId, $shopId] = $this->seedSteadyShop();

        $data = (array)($this->dashboard()->buildDashboard(
            $userId,
            $shopId,
            'month_this',
            null,
            null,
            null,
            $today
        )['data'] ?? []);

        $comparison = (array)($data['comparison'] ?? []);
        $this->assertSame(
            $expectedDay,
            $comparison['compared_up_to_day'] ?? null,
            'ป้าย "เทียบเดือนก่อน (ถึงวันที่ N)" ประกาศวันที่ไม่ตรงกับที่ใช้เทียบจริง'
        );

        $previousMonth = (string)($comparison['previous_month'] ?? '');
        $daysInPrevious = (int)(new \DateTimeImmutable($previousMonth . '-01'))->format('t');
        $this->assertLessThanOrEqual(
            $daysInPrevious,
            (int)($comparison['compared_up_to_day'] ?? 0),
            "ป้ายประกาศวันที่ที่เดือน {$previousMonth} ไม่มี"
        );
    }

    /**
     * ⭐⭐ ร้านที่ผลงานนิ่งสนิท → ป้ายต้องเป็น 0% ทุกวัน ไม่มีข้อยกเว้น
     *
     * เดินทีละวันตลอดปี — ธุรกิจไม่เปลี่ยนเลยแม้แต่วันเดียว ตัวเลขจึงห้ามขยับ
     */
    public function testASteadyShopNeverShowsGrowthOnAnyDayOfTheYear(): void
    {
        [$userId, $shopId] = $this->seedSteadyShop();
        $dashboard = $this->dashboard();

        $day = new \DateTimeImmutable('2026-01-01');
        $end = new \DateTimeImmutable('2026-12-31');
        $offenders = [];

        while ($day <= $end) {
            $today = $day->format('Y-m-d');
            $data = (array)($dashboard->buildDashboard(
                $userId,
                $shopId,
                'month_this',
                null,
                null,
                null,
                $today
            )['data'] ?? []);

            $percent = $data['comparison']['change']['profit'] ?? null;
            if ($percent !== null && abs((float)$percent) > 0.05) {
                $offenders[] = $today . ' = ' . number_format((float)$percent, 1) . '%';
            }

            $day = $day->modify('+1 day');
        }

        $this->assertSame(
            [],
            $offenders,
            'ร้านที่ทำกำไรวันละเท่ากันเป๊ะ แต่ป้ายเทียบเดือนก่อนขยับ: ' . implode(' · ', array_slice($offenders, 0, 8))
        );
    }

    /**
     * ⭐ หน้ารวมร้านต้องได้ผลเดียวกัน — และคอลัมน์ "กำไร" ต้องยังเป็นยอดถึงวันนี้
     *
     * ⚠️⚠️ สองคอลัมน์นี้ตอบคนละคำถาม จึงใช้คนละช่วงโดยตั้งใจ:
     * "กำไร" = เดือนนี้ถึงวันนี้ (31 วัน) · "เทียบเดือนก่อน" = ช่วงเดียวกันสองฝั่ง (28 วัน)
     * ถ้าหดคอลัมน์กำไรตามไปด้วย ผู้ใช้จะเห็นกำไรน้อยกว่าที่ตัวเองกรอกจริง
     */
    public function testTheOverviewComparesFairlyWhileStillShowingProfitUpToToday(): void
    {
        [$userId] = $this->seedSteadyShop();

        $overview = new OverviewService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );

        foreach (['2026-03-28' => 28000.0, '2026-03-31' => 31000.0] as $today => $expectedProfit) {
            $data = (array)($overview->buildOverview($userId, '2026-03', $today)['data'] ?? []);
            $row = (array)(($data['comparison']['rows'] ?? [])[0] ?? []);

            $this->assertEqualsWithDelta(
                $expectedProfit,
                (float)($row['profit'] ?? 0),
                0.005,
                "คอลัมน์กำไรของวันที่ {$today} ต้องเป็นยอดถึงวันนี้ ไม่ใช่ยอดที่ถูกหดตามช่วงเทียบ"
            );

            $this->assertEqualsWithDelta(
                0.0,
                (float)($row['profit_change_percent'] ?? 0),
                0.05,
                "คอลัมน์เทียบเดือนก่อนของวันที่ {$today} ต้องเป็น 0% สำหรับร้านที่ผลงานนิ่งสนิท"
            );
        }
    }
}
