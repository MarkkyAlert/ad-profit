<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * รายการที่ลงล่วงหน้าต้องไม่ทำให้ตัวเลขในหน้าเดียวกันขัดกันเอง
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
        $repository->method('getMonthlyTotalsByMonthRange')->willReturn([]);
        $repository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturn([]);

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
