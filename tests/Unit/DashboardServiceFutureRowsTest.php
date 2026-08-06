<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * การ์ดสรุปต้องไม่นับวันที่ยังมาไม่ถึง
 *
 * หากมี legacy future rows หลุดมา การ์ด "เดือนนี้" ต้องไม่รวมยอดของวันอนาคต
 * เข้าไปด้วย ขณะที่ป้าย "เทียบเดือนก่อน" ใต้การ์ดเดียวกันตัดที่วันนี้ — การ์ดเดียวจึง
 * ขึ้นกำไร ฿9,000 คู่กับป้าย 0% และ "วันกำไรดีสุด" เป็นวันที่ยังมาไม่ถึง
 *
 * ตัดสินแล้ว: นับเฉพาะวันที่ถึงแล้ว
 */
final class DashboardServiceFutureRowsTest extends TestCase
{
    private const TODAY = '2026-08-04';

    private function makeService(): DashboardService
    {
        // ส.ค. 1–4 กำไรวันละ 1,000 · ส.ค. 20 (อนาคต) กำไร 5,000 · ก.ค. 1–4 กำไรวันละ 1,000
        $rows = [];
        for ($day = 1; $day <= 4; $day++) {
            $rows[] = ['record_date' => sprintf('2026-07-%02d', $day), 'revenue' => 2000.0, 'ad_cost' => 1000.0];
            $rows[] = ['record_date' => sprintf('2026-08-%02d', $day), 'revenue' => 2000.0, 'ad_cost' => 1000.0];
        }
        $rows[] = ['record_date' => '2026-08-20', 'revenue' => 5000.0, 'ad_cost' => 0.0];

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['record_date'] >= $start && $row['record_date'] <= $end
            ))
        );
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn([]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new DashboardService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));
    }

    /**
     * @return array<string,mixed>
     */
    private function dashboard(string $rangeType = 'month_this', ?string $month = null): array
    {
        return $this->makeService()
            ->buildDashboard(1, 1, $rangeType, null, null, $month, self::TODAY)['data'];
    }

    /** ⭐ ยอดในการ์ดต้องเป็นของ 4 วันที่ผ่านมาแล้วเท่านั้น */
    public function testSummaryExcludesFutureDatedRows(): void
    {
        $data = $this->dashboard();

        $this->assertSame(4000.0, (float)$data['summary']['profit']);
        $this->assertSame(4, (int)$data['statistics']['days_count']);
    }

    /** ⭐ การ์ดกับป้ายใต้การ์ดต้องคิดจากช่วงเดียวกัน */
    public function testTheCardAndItsBadgeAgree(): void
    {
        $data = $this->dashboard();

        // ก.ค. 1–4 = 4,000 เท่ากับ ส.ค. 1–4 → ต้องเป็น 0%
        $this->assertSame(0.0, (float)$data['comparison']['change']['profit']);
        $this->assertSame(4000.0, (float)$data['summary']['profit']);
    }

    /** "วันกำไรดีสุด" ต้องไม่ใช่วันที่ยังมาไม่ถึง */
    public function testBestDayIsNeverInTheFuture(): void
    {
        $bestDay = $this->dashboard()['statistics']['best_day'];

        $this->assertNotNull($bestDay);
        $this->assertLessThanOrEqual(self::TODAY, (string)$bestDay['record_date']);
    }

    /** เลือกเดือนที่จบไปแล้วยังได้ทั้งเดือนเหมือนเดิม */
    public function testAPastMonthStillUsesTheWholeMonth(): void
    {
        $data = $this->dashboard('month_pick', '2026-07');

        $this->assertSame('2026-07-31', (string)$data['range']['end_date']);
    }

    /** เลือกเดือนปัจจุบันเองก็ต้องตัดที่วันนี้เหมือนกัน */
    public function testPickingTheCurrentMonthIsAlsoClamped(): void
    {
        $data = $this->dashboard('month_pick', '2026-08');

        $this->assertSame(self::TODAY, (string)$data['range']['end_date']);
        $this->assertSame(4000.0, (float)$data['summary']['profit']);
    }

    /** ช่วงที่ผู้ใช้เลือกเองไม่ถูกตัด — ตั้งใจดูวันอนาคตก็ต้องดูได้ */
    public function testACustomRangeIsNotClamped(): void
    {
        $data = $this->makeService()
            ->buildDashboard(1, 1, 'custom', '2026-08-01', '2026-08-31', null, self::TODAY)['data'];

        $this->assertSame('2026-08-31', (string)$data['range']['end_date']);
        $this->assertSame(9000.0, (float)$data['summary']['profit']);
    }
}
