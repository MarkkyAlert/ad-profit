<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use OverviewDailyService;
use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * รายการล่วงหน้าที่หลุดมาจากข้อมูลเก่าหรือ fixture ต้องไม่ทำให้ตัวเลขขัดกันเอง
 *
 * รอบก่อนตัดวันอนาคตออกจาก "การ์ดสรุป" แต่ลืม 3 ที่:
 *  · การ์ดเป้าหมายใต้การ์ดสรุป → การ์ดสรุปขึ้น ฿4,000 แต่การ์ดเป้าบอก "ถึงเป้าแล้ว 100%"
 *  · คอลัมน์กำไรของหน้ารวมร้าน → แถวขึ้น ฿9,000 แต่ป้ายในแถวเดียวกันคิดจาก ฿4,000
 *  · เดือนอนาคตใน month_pick → ช่วงกลับหัว (start > end) ทุกการ์ดเป็น ฿0
 */
final class FutureRowsConsistencyTest extends TestCase
{
    private const TODAY = '2026-08-04';

    /**
     * ส.ค. 1–4 กำไรวันละ 1,000 (รวม 4,000) · ส.ค. 20 = อนาคต กำไร 6,000
     * ก.ค. 1–4 กำไรวันละ 1,000 (รวม 4,000)
     *
     * @return array<int,array<string,mixed>>
     */
    private function records(): array
    {
        $rows = [];
        for ($day = 1; $day <= 4; $day++) {
            $rows[] = ['record_date' => sprintf('2026-07-%02d', $day), 'revenue' => 1000.0, 'ad_cost' => 0.0];
            $rows[] = ['record_date' => sprintf('2026-08-%02d', $day), 'revenue' => 1000.0, 'ad_cost' => 0.0];
        }
        $rows[] = ['record_date' => '2026-08-20', 'revenue' => 6000.0, 'ad_cost' => 0.0];

        return $rows;
    }

    private function recordRepository(): RecordRepository
    {
        $rows = $this->records();

        $repository = $this->createStub(RecordRepository::class);
        $repository->method('getByDateRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['record_date'] >= $start && $row['record_date'] <= $end
            ))
        );
        $repository->method('getTotalsByShopIdsAndDateRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($rows): array {
                $revenue = 0.0;
                $days = 0;
                foreach ($rows as $row) {
                    if ($row['record_date'] >= $start && $row['record_date'] <= $end) {
                        $revenue += $row['revenue'];
                        $days++;
                    }
                }

                return $days === 0
                    ? []
                    : [['shop_id' => 1, 'total_revenue' => $revenue, 'total_ad_cost' => 0.0, 'days_count' => $days]];
            }
        );
        // ⚠️⚠️ เดิม stub ตัวนี้คืน `[]` เสมอ — หน้ารายปี ไฟล์ Excel และกราฟ 6 เดือน
        // ล้วนใช้ query นี้ เทสต์ทั้งไฟล์จึงมองไม่เห็นว่าสามที่นั้นนับวันอนาคตอยู่
        // (เทสต์ชื่อ "รายการล่วงหน้าต้องไม่ทำให้ตัวเลขขัดกันเอง" แต่ครอบแค่ 2 จาก 5 จุด)
        $repository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (
                int $shopId,
                string $startMonth,
                string $endMonth,
                ?string $notAfterDate = null
            ) use ($rows): array {
                $start = $startMonth . '-01';
                $end = date('Y-m-t', (int)strtotime($endMonth . '-01'));
                if ($notAfterDate !== null && $notAfterDate < $end) {
                    $end = $notAfterDate;
                }

                $totals = [];
                foreach ($rows as $row) {
                    if ($row['record_date'] < $start || $row['record_date'] > $end) {
                        continue;
                    }
                    $key = substr((string)$row['record_date'], 0, 7);
                    $totals[$key] ??= ['month_key' => $key, 'total_revenue' => 0.0, 'total_ad_cost' => 0.0, 'days_count' => 0];
                    $totals[$key]['total_revenue'] += $row['revenue'];
                    $totals[$key]['total_ad_cost'] += $row['ad_cost'];
                    $totals[$key]['days_count']++;
                }
                ksort($totals);

                return array_values($totals);
            }
        );
        $repository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturn([]);
        $repository->method('getDailyTotalsByShopIdsAndDateRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($rows): array {
                $daily = [];
                foreach ($rows as $row) {
                    if ($row['record_date'] < $start || $row['record_date'] > $end) {
                        continue;
                    }
                    $date = (string)$row['record_date'];
                    $daily[$date] ??= [
                        'record_date' => $date,
                        'total_revenue' => 0.0,
                        'total_ad_cost' => 0.0,
                        'shops_count' => 1,
                    ];
                    $daily[$date]['total_revenue'] += $row['revenue'];
                    $daily[$date]['total_ad_cost'] += $row['ad_cost'];
                }
                ksort($daily);

                return array_values($daily);
            }
        );
        $repository->method('getFirstRecordDateByShopIds')->willReturn([1 => '2026-07-01']);

        return $repository;
    }

    private function shopRepository(): ShopRepository
    {
        $repository = $this->createStub(ShopRepository::class);
        $repository->method('userCanAccessShop')->willReturn(true);
        $repository->method('listByUserId')->willReturn([['id' => 1, 'name' => 'ร้านเดียว']]);

        return $repository;
    }

    private function goalRepository(): GoalRepository
    {
        $repository = $this->createStub(GoalRepository::class);
        $repository->method('findByShopAndMonth')->willReturn([
            'target_revenue' => 10000.0,
            'target_profit' => null,
        ]);
        // ⚠️ หน้ารายปีใช้เมธอดนี้ ไม่ใช่ `findByShopAndMonth` — เดิมไม่ได้ stub ไว้
        // การ์ดเป้าหมายของหน้ารายปีจึงว่างเปล่าในเทสต์ และไม่มีใครเห็นว่ามันขัดกับแดชบอร์ด
        $repository->method('getByShopAndMonthRange')->willReturn([
            ['goal_month' => '2026-08', 'target_revenue' => 10000.0, 'target_profit' => null],
        ]);

        return $repository;
    }

    /**
     * @return array<string,mixed>
     */
    private function dashboard(string $rangeType = 'month_this', ?string $month = null): array
    {
        return (new DashboardService($this->recordRepository(), $this->shopRepository(), $this->goalRepository()))
            ->buildDashboard(1, 1, $rangeType, null, null, $month, self::TODAY)['data'];
    }

    /**
     * @return array<string,mixed>
     */
    private function annual(): array
    {
        return (new \AnnualService($this->recordRepository(), $this->shopRepository(), $this->goalRepository()))
            ->buildYearlySummary(1, 1, 2026, self::TODAY)['data'];
    }

    /**
     * ⭐⭐ การ์ดเป้าหมายของหน้ารายปี (และไฟล์ Excel) ต้องบอกตรงกับแดชบอร์ด
     *
     * ⚠️ เกิดขึ้นจริง: แดชบอร์ดบอก "ทำได้ ฿4,000 จาก ฿10,000 (40%) ยังไม่ถึงเป้า"
     * ขณะที่หน้ารายปีบอก "✓ ถึงเป้าแล้ว 100%" เดือนเดียวกัน ข้อมูลชุดเดียวกัน
     * เพราะหน้ารายปีนับรวมวันที่ 20 ส.ค. ซึ่งยังมาไม่ถึง
     *
     * รายงานปีปัจจุบันทั้งหมดต้องตัดที่วันนี้เหมือนกัน เพื่อให้ Excel/annual/dashboard
     * อยู่บนฐาน actuals ชุดเดียวกัน
     */
    public function testTheAnnualGoalCardAgreesWithTheDashboard(): void
    {
        $dashboardGoal = (array)$this->dashboard()['goal'];

        $annualGoal = null;
        foreach ((array)($this->annual()['goal_progress'] ?? []) as $row) {
            if ((string)($row['month_key'] ?? '') === '2026-08') {
                $annualGoal = $row;
            }
        }

        $this->assertNotNull($annualGoal, 'หน้ารายปีไม่มีการ์ดเป้าหมายของเดือนนี้');
        $this->assertSame(
            (float)$dashboardGoal['actual_revenue'],
            (float)$annualGoal['actual_revenue'],
            'การ์ดเป้าหมายสองหน้านับวันไม่เท่ากัน'
        );
        $this->assertSame(
            (bool)$dashboardGoal['revenue_reached'],
            (bool)$annualGoal['revenue_reached'],
            'หน้าหนึ่งบอกถึงเป้าแล้ว อีกหน้าบอกยังไม่ถึง'
        );
        $this->assertFalse(
            (bool)$annualGoal['revenue_reached'],
            'ยังทำได้แค่ ฿4,000 จาก ฿10,000 แต่บอกว่าถึงเป้าแล้ว'
        );
    }

    /**
     * ⭐ การ์ด "เดือนกำไรแย่สุด" ต้องไม่หยิบเดือนปัจจุบันที่ยังไม่จบ
     *
     * ⚠️ เกิดขึ้นจริง: ร้านทำกำไรวันละ ฿1,000 เท่ากันทุกวันตั้งแต่ ม.ค. ถึงวันนี้
     * การ์ดขึ้นว่า "เดือนแย่สุด: ส.ค. (฿3,000)" เพราะเพิ่งกรอกไป 3 วัน
     * ขณะที่ตารางในหน้าเดียวกันแสดงกำไรต่อวันของ ส.ค. เท่ากับเดือนอื่นเป๊ะ
     *
     * ยังไม่จบเดือน = ยังตัดสินไม่ได้ ไม่ใช่ "แย่"
     *
     * ⚠️ ต้องสร้างข้อมูลเองที่นี่ ไม่ใช้ชุดที่ใช้ร่วมกัน — ชุดนั้น ส.ค. มียอดสูงกว่า
     * เดือนอื่นอยู่แล้ว เดือนปัจจุบันจึงไม่มีทางเป็น "แย่สุด" ไม่ว่าโค้ดจะถูกหรือผิด
     */
    public function testTheWorstMonthCardSkipsTheUnfinishedCurrentMonth(): void
    {
        $records = $this->createStub(RecordRepository::class);
        $records->method('getMonthlyTotalsByMonthRange')->willReturn([
            // ม.ค.–ก.ค. กรอกครบ กำไรวันละ 1,000
            ['month_key' => '2026-05', 'total_revenue' => 31000.0, 'total_ad_cost' => 0.0, 'days_count' => 31],
            ['month_key' => '2026-06', 'total_revenue' => 30000.0, 'total_ad_cost' => 0.0, 'days_count' => 30],
            ['month_key' => '2026-07', 'total_revenue' => 31000.0, 'total_ad_cost' => 0.0, 'days_count' => 31],
            // ส.ค. วันนี้คือวันที่ 4 → กรอกได้ 4 วัน · ยอดสะสมน้อยสุดโดยธรรมชาติ
            ['month_key' => '2026-08', 'total_revenue' => 4000.0, 'total_ad_cost' => 0.0, 'days_count' => 4],
        ]);

        $summary = (new \AnnualService($records, $this->shopRepository(), $this->createStub(GoalRepository::class)))
            ->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'];

        $this->assertNotNull($summary['worst_month'] ?? null, 'ไม่มีการ์ดเดือนแย่สุด');
        $this->assertNotSame(
            '2026-08',
            (string)($summary['worst_month']['month_key'] ?? ''),
            'หยิบเดือนปัจจุบันที่กรอกไปแค่ 4 วันมาเป็นเดือนแย่สุด'
        );
        $this->assertSame(
            '2026-06',
            (string)($summary['worst_month']['month_key'] ?? ''),
            'ควรเป็น มิ.ย. ซึ่งเป็นเดือนที่จบแล้วและยอดต่ำสุดในบรรดาเดือนที่จบ'
        );
    }

    /**
     * ⭐⭐ แท็บ "รายวัน" กับแท็บ "เดือน" ของหน้ารวมร้าน ต้องบอกยอดเท่ากัน
     *
     * ⚠️ เกิดขึ้นจริง: กดสลับแท็บเฉย ๆ ยอดรวมเปลี่ยนจาก ฿8,000 เป็น ฿14,000
     * และรายการวันสุดท้ายเป็นวันที่ยังมาไม่ถึง — เพราะแท็บรายวันไล่ทั้งเดือนเสมอ
     * ขณะที่แท็บเดือนตัดที่วันนี้ (คลาสนั้นไม่มี seam วันที่เลยด้วยซ้ำ)
     */
    public function testBothOverviewTabsReportTheSameTotal(): void
    {
        // หน้ารวมร้านเปิดได้เมื่อมี ≥ 2 ร้านเท่านั้น
        $shops = $this->createStub(ShopRepository::class);
        $shops->method('userCanAccessShop')->willReturn(true);
        $shops->method('listByUserId')->willReturn([
            ['id' => 1, 'name' => 'ร้านหนึ่ง'],
            ['id' => 2, 'name' => 'ร้านสอง'],
        ]);

        $monthTab = (new OverviewService($this->recordRepository(), $shops))
            ->buildOverview(1, '2026-08', self::TODAY)['data'];
        $dayTab = (new OverviewDailyService($this->recordRepository(), $shops))
            ->buildDailyOverview(1, '2026-08', self::TODAY)['data'];

        $this->assertSame(
            (float)$monthTab['comparison']['totals']['total_revenue'],
            (float)$dayTab['summary']['total_revenue'],
            'สองแท็บของหน้าเดียวกันบอกยอดรวมไม่เท่ากัน'
        );

        $dates = array_column((array)($dayTab['days'] ?? []), 'record_date');
        $this->assertNotSame([], $dates, 'ไม่มีแถวรายวันเลย — เทียบแล้วไม่ได้พิสูจน์อะไร');
        $this->assertLessThanOrEqual(
            self::TODAY,
            (string)max($dates),
            'ตารางรายวันโชว์วันที่ยังมาไม่ถึง'
        );
    }

    /**
     * ⭐ กราฟ 6 เดือนกับการ์ดสรุปอยู่บนจอเดียวกัน ต้องบอกตรงกัน
     *
     * ⚠️ เกิดขึ้นจริง: การ์ดขึ้น ฿4,000 แต่แท่งสุดท้ายของกราฟสูง ฿10,000
     */
    public function testTheSixMonthChartAgreesWithTheSummaryCard(): void
    {
        $data = $this->dashboard();
        $cardRevenue = (float)$data['summary']['total_revenue'];

        $chart = (array)($data['charts']['six_months'] ?? []);
        $index = array_search('2026-08', (array)($chart['months'] ?? []), true);

        $this->assertIsInt($index, 'ไม่มีแท่งของเดือนนี้ในกราฟ 6 เดือน');
        $this->assertSame(
            $cardRevenue,
            (float)$chart['revenue'][$index],
            'แท่งสุดท้ายของกราฟนับวันไม่เท่าการ์ดสรุปที่อยู่เหนือมัน'
        );
    }

    /** ⭐ การ์ดสรุปกับการ์ดเป้าหมายต้องนับช่วงเดียวกัน */
    public function testTheGoalCardCountsTheSameDaysAsTheSummaryCard(): void
    {
        $data = $this->dashboard();

        $this->assertSame(4000.0, (float)$data['summary']['total_revenue']);
        $this->assertSame(
            (float)$data['summary']['total_revenue'],
            (float)$data['goal']['actual_revenue'],
            'การ์ดเป้าหมายนับรายการล่วงหน้าเข้าไปด้วย'
        );
        $this->assertFalse($data['goal']['revenue_reached'], 'บอกว่าถึงเป้าเพราะนับวันที่ยังมาไม่ถึง');
    }

    /** ⭐ แถวในหน้ารวมร้านต้องสอดคล้องกับตัวเอง: กำไร = เดือนก่อน + ส่วนต่าง */
    public function testTheOverviewRowAddsUp(): void
    {
        $row = (new OverviewService($this->recordRepository(), $this->shopRepository()))
            ->buildOverview(1, '2026-08', self::TODAY)['data']['comparison']['rows'][0];

        $this->assertSame(4000.0, (float)$row['profit']);
        $this->assertEqualsWithDelta(
            (float)$row['profit'],
            (float)$row['prev_profit'] + (float)$row['profit_change'],
            0.001,
            'ตัวเลขในแถวเดียวกันคิดคนละช่วง'
        );
        $this->assertSame(0.0, (float)$row['profit_change_percent']);
    }

    /** ⭐ เลือกเดือนอนาคต → หดมาเป็นเดือนปัจจุบัน ไม่ใช่ช่วงกลับหัว */
    public function testAFutureMonthPickFallsBackToTheCurrentMonth(): void
    {
        $range = $this->dashboard('month_pick', '2027-01')['range'];

        $this->assertLessThanOrEqual(
            $range['end_date'],
            $range['start_date'],
            'ช่วงกลับหัว (เริ่มหลังจบ)'
        );
        $this->assertSame('2026-08', (string)$range['selected_month']);
        $this->assertSame(self::TODAY, (string)$range['end_date']);
    }

    /** เดือนที่จบไปแล้วยังใช้ทั้งเดือนเหมือนเดิม */
    public function testAPastMonthIsUnaffected(): void
    {
        $range = $this->dashboard('month_pick', '2026-07')['range'];

        $this->assertSame('2026-07-01', (string)$range['start_date']);
        $this->assertSame('2026-07-31', (string)$range['end_date']);
    }
}
