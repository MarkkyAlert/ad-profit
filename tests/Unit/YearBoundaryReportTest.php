<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ รอยต่อปี (31 ธ.ค. → 1 ม.ค.) และปีอธิกสุรทิน
 *
 * ⚠️ เทสต์เดิมของรายงานปักวันไว้กลางปีเกือบทั้งหมด (4 ส.ค. / 7 ส.ค.) จุดที่ต้อง
 * ข้ามปีหรือเจอ ก.พ. 29 วันจึงไม่เคยถูกเดินผ่านเลย — ทั้งที่เป็นวันที่ตัวเลข
 * "ปีนี้เทียบปีก่อน" กับ "ย้อนหลัง 6 เดือน" ต้องข้ามเส้นปีพอดี
 */
final class YearBoundaryReportTest extends TestCase
{
    private const PROFIT_PER_DAY = 1000.0;

    /**
     * รายเดือนแบบจำลอง query จริง — เคารพวันตัดเหมือน SQL
     * ⚠️ stub ที่ละเลย $notAfterDate จะให้ผลเหมือนกันทั้งก่อนและหลังแก้
     */
    private function monthlyTotals(string $start, string $end, ?string $notAfter): array
    {
        $rows = [];
        $cursor = new \DateTimeImmutable($start . '-01');
        $stop = new \DateTimeImmutable($end . '-01');

        while ($cursor <= $stop) {
            $monthKey = $cursor->format('Y-m');
            $days = (int)$cursor->format('t');

            if ($notAfter !== null) {
                if ($notAfter < $monthKey . '-01') {
                    $cursor = $cursor->modify('+1 month');
                    continue;
                }
                if (substr($notAfter, 0, 7) === $monthKey) {
                    $days = min($days, (int)substr($notAfter, 8, 2));
                }
            }

            $rows[] = [
                'month_key' => $monthKey,
                'total_revenue' => $days * 3000.0,
                'total_ad_cost' => $days * (3000.0 - self::PROFIT_PER_DAY),
                'days_count' => $days,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $rows;
    }

    private function dashboard(string $today): DashboardService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn([]);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            fn(int $shopId, string $start, string $end, ?string $notAfter = null): array
                => $this->monthlyTotals($start, $end, $notAfter)
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new DashboardService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));
    }

    /**
     * ⚠️ 1 ม.ค. — กราฟ 6 เดือนต้องย้อนข้ามเส้นปีไปถึง ส.ค. ของปีก่อน
     * ไม่ใช่เริ่มนับใหม่ที่เดือนแรกของปีนี้ (จะเหลือแท่งเดียว)
     */
    public function testTheSixMonthChartCrossesIntoLastYearOnNewYearsDay(): void
    {
        $data = $this->dashboard('2027-01-01')
            ->buildDashboard(1, 1, 'month_this', null, null, null, '2027-01-01')['data'];

        $chart = $data['charts']['six_months'] ?? [];
        $months = array_map('strval', (array)($chart['labels'] ?? $chart['months'] ?? []));

        $this->assertCount(6, $months, 'จำนวนแท่งเปลี่ยนไปจาก 6 ที่รอยต่อปี');
        $this->assertSame('2026-08', $months[0], 'กราฟไม่ย้อนข้ามเส้นปี');
        $this->assertSame('2027-01', end($months));
    }

    /** ⚠️ วันแรกของปี แท่งเดือนปัจจุบันต้องเป็นยอดของ 1 วัน ไม่ใช่ทั้งเดือน */
    public function testTheCurrentMonthBarOnNewYearsDayCountsOneDay(): void
    {
        $data = $this->dashboard('2027-01-01')
            ->buildDashboard(1, 1, 'month_this', null, null, null, '2027-01-01')['data'];

        $chart = $data['charts']['six_months'] ?? [];
        $months = array_map('strval', (array)($chart['labels'] ?? $chart['months'] ?? []));
        $profits = (array)($chart['profit'] ?? []);
        $index = array_search('2027-01', $months, true);

        $this->assertNotFalse($index, 'ไม่มีแท่งของเดือนปัจจุบัน');
        $this->assertSame(self::PROFIT_PER_DAY, (float)($profits[$index] ?? 0));
    }

    /** ⚠️ 31 ธ.ค. เดือนนี้จบพอดี — แท่งสุดท้ายต้องเป็นยอดเต็มเดือน */
    public function testTheLastDayOfTheYearCountsTheWholeMonth(): void
    {
        $data = $this->dashboard('2026-12-31')
            ->buildDashboard(1, 1, 'month_this', null, null, null, '2026-12-31')['data'];

        $chart = $data['charts']['six_months'] ?? [];
        $months = array_map('strval', (array)($chart['labels'] ?? $chart['months'] ?? []));
        $profits = (array)($chart['profit'] ?? []);
        $index = array_search('2026-12', $months, true);

        $this->assertNotFalse($index);
        $this->assertSame(31 * self::PROFIT_PER_DAY, (float)($profits[$index] ?? 0));
    }

    /**
     * ⚠️⚠️ ปีอธิกสุรทิน: "เทียบปีก่อนช่วงเดียวกัน" ต้องนับ ก.พ. คนละจำนวนวัน
     *
     * ⚠️ **ห้ามปักวันไว้ที่ 29 ก.พ.** — วันตัดของปีก่อน (29) มากกว่าจำนวนวันของ
     * ก.พ. ปีก่อน (28) อยู่แล้ว การตัดวันจึงไม่เปลี่ยนคำตอบ **เทสต์จะเขียวแม้ถอด
     * การตัดวันของปีก่อนออกทั้งหมด** (ลองแล้ว — เป็นบั๊กที่เคยเกิดจริงในโปรเจกต์นี้)
     * ต้องเลือกวันหลังจาก ก.พ. ผ่านไปแล้ว ผลต่าง 29 vs 28 จึงจะปรากฏ
     *
     * 15 มี.ค. 2571: ปีนี้ 31 + 29 + 15 = 75 วัน · ปีก่อน 31 + 28 + 15 = 74 วัน
     * ถ้าไม่ตัดวันของปีก่อนจะได้ 31 + 28 + 31 = 90 วัน
     */
    public function testALeapYearComparesAgainstAShorterFebruaryLastYear(): void
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn([]);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            fn(int $shopId, string $start, string $end, ?string $notAfter = null): array
                => $this->monthlyTotals($start, $end, $notAfter)
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'name' => 'ร้านทดสอบ']);

        $goalRepository = $this->createStub(GoalRepository::class);
        $goalRepository->method('getByShopAndMonthRange')->willReturn([]);

        $service = new AnnualService($recordRepository, $shopRepository, $goalRepository);
        $result = $service->buildYearlySummary(1, 1, 2028, '2028-03-15');

        $this->assertTrue((bool)($result['success'] ?? false), (string)($result['error'] ?? ''));
        $data = (array)($result['data'] ?? []);
        $summary = (array)($data['summary'] ?? []);

        $this->assertSame(
            75 * self::PROFIT_PER_DAY,
            (float)($summary['profit'] ?? 0),
            'ยอดปีนี้ไม่ได้นับ ก.พ. เป็น 29 วัน หรือไม่ได้ตัดที่วันนี้'
        );
        $this->assertSame(
            74 * self::PROFIT_PER_DAY,
            (float)($summary['prev_year_profit'] ?? 0),
            'ช่วงเทียบของปีก่อนไม่ได้ตัดที่วันเดียวกัน (ได้ทั้งเดือน มี.ค. มาด้วย)'
        );
    }
}
