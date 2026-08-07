<?php

declare(strict_types=1);

namespace Tests\Unit;

use DashboardService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ กราฟ "แนวโน้มย้อนหลัง 6 เดือน" ต้องไม่ยื่นไปข้างหน้า
 *
 * ⚠️ ช่วง "กำหนดเอง" ตั้งใจไม่ถูกตัดที่วันนี้ (ผู้ใช้เลือกเองย่อมตั้งใจ) แต่ค่านั้นถูกส่ง
 * ไปเป็นจุดสิ้นสุดของกราฟด้วย ทั้งที่กราฟมีป้ายของตัวเองว่า "ย้อนหลัง 6 เดือน"
 *
 * อาการที่วัดได้จริงก่อนแก้ (วันนี้ 7 ส.ค. 2026 · เลือกช่วง 1 ก.ค. – 31 ธ.ค.):
 *   · แท่งของกราฟกลายเป็น ก.ค. ส.ค. ก.ย. ต.ค. พ.ย. ธ.ค. — **4 ใน 6 เดือนยังมาไม่ถึง**
 *     บนเครื่องจริงที่ยังไม่มีข้อมูลเดือนหน้า แท่งพวกนั้นเป็น ฿0 กราฟจึงดิ่งลงศูนย์
 *     ซึ่งอ่านแล้วเหมือนธุรกิจกำลังพัง
 *   · **แท่งของเดือนปัจจุบันไม่ถูกตัดที่วันนี้ด้วย** (ได้ยอดทั้งเดือน) จึงขัดกับการ์ด
 *     สรุปที่อยู่เหนือกราฟบนจอเดียวกัน
 */
final class DashboardServiceSixMonthChartTest extends TestCase
{
    private const TODAY = '2026-08-07';
    private const PROFIT_PER_DAY = 1000.0;

    private function makeService(): DashboardService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturn([]);

        // จำลอง query จริง: รวมยอดรายเดือนโดยเคารพวันตัดเหมือน SQL
        // ⚠️ stub ที่ละเลย `$notAfterDate` จะให้ผลเหมือนกันทั้งก่อนและหลังแก้
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static function (int $shopId, string $start, string $end, ?string $notAfter = null): array {
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
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new DashboardService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));
    }

    /**
     * @return array<int,string>
     */
    private function chartMonths(string $rangeType, ?string $start, ?string $end): array
    {
        $data = $this->makeService()
            ->buildDashboard(1, 1, $rangeType, $start, $end, null, self::TODAY)['data'];

        $chart = $data['charts']['six_months'] ?? [];
        $months = $chart['labels'] ?? $chart['months'] ?? [];

        return array_map('strval', (array)$months);
    }

    public function testACustomRangeEndingInTheFutureDoesNotAddFutureMonths(): void
    {
        $months = $this->chartMonths('custom', '2026-07-01', '2026-12-31');

        $this->assertNotEmpty($months, 'อ่านเดือนของกราฟไม่ได้ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');
        $this->assertSame(
            '2026-08',
            end($months),
            'กราฟ "ย้อนหลัง 6 เดือน" ยื่นไปถึงเดือนที่ยังมาไม่ถึง ซึ่งจะเป็นแท่ง ฿0 บนเครื่องจริง'
        );
    }

    /** ⚠️ แท่งเดือนปัจจุบันต้องตัดที่วันนี้ ให้ตรงกับการ์ดสรุปที่อยู่เหนือกราฟ */
    public function testTheCurrentMonthBarStopsAtToday(): void
    {
        $data = $this->makeService()
            ->buildDashboard(1, 1, 'custom', '2026-07-01', '2026-12-31', null, self::TODAY)['data'];

        $chart = $data['charts']['six_months'] ?? [];
        $months = array_map('strval', (array)($chart['labels'] ?? $chart['months'] ?? []));
        $profits = (array)($chart['profit'] ?? []);

        $index = array_search('2026-08', $months, true);
        $this->assertNotFalse($index, 'ไม่มีแท่งของเดือนปัจจุบันในกราฟ');

        $this->assertSame(
            7 * self::PROFIT_PER_DAY,
            (float)($profits[$index] ?? 0),
            'แท่งเดือนนี้นับทั้งเดือน ขณะที่การ์ดสรุปบนจอเดียวกันตัดที่วันนี้'
        );
    }

    /** ช่วงปกติ (เดือนนี้) ต้องได้ 6 เดือนที่จบด้วยเดือนปัจจุบันเหมือนเดิม */
    public function testANormalRangeStillEndsAtTheCurrentMonth(): void
    {
        $months = $this->chartMonths('month_this', null, null);

        $this->assertCount(6, $months, 'จำนวนแท่งเปลี่ยนไปจาก 6');
        $this->assertSame('2026-03', $months[0]);
        $this->assertSame('2026-08', end($months));
    }

    /** ช่วงที่จบในอดีต ต้องยังดูย้อนหลังจากเดือนนั้นได้ ไม่ถูกดันมาเป็นเดือนปัจจุบัน */
    public function testARangeEndingInThePastKeepsItsOwnWindow(): void
    {
        $months = $this->chartMonths('custom', '2026-05-01', '2026-05-31');

        $this->assertSame('2026-05', end($months), 'ดูช่วงในอดีตแล้วกราฟกระโดดมาเดือนปัจจุบัน');
        $this->assertSame('2025-12', $months[0]);
    }

    /**
     * ⭐⭐ แต่ละแท่งแทน "ทั้งเดือน" — ปลายช่วงที่ผู้ใช้เลือกต้องไม่ไปตัดแท่งที่ควรเต็ม
     *
     * ⚠️ วัดจริงก่อนแก้ (ร้านทำกำไรวันละ ฿1,000 เท่ากันเป๊ะทุกวัน):
     *   · เลือกช่วงเอง 1–10 มี.ค. → แท่ง มี.ค. เหลือ ฿10,000 แทนที่จะเป็น ฿31,000
     *     เส้นดิ่งลง 2 ใน 3 ในเดือนสุดท้าย ทั้งที่ผลงานไม่เปลี่ยนเลย
     *   · กดสลับ "สัปดาห์นี้" → "สัปดาห์ก่อน" เฉย ๆ แท่ง ส.ค. เปลี่ยนจาก ฿7,000 เป็น ฿2,000
     */
    public function testAPastMonthBarIsNotCutByTheSelectedRangeEnd(): void
    {
        $data = $this->makeService()
            ->buildDashboard(1, 1, 'custom', '2026-03-01', '2026-03-10', null, self::TODAY)['data'];

        $chart = $data['charts']['six_months'] ?? [];
        $months = array_map('strval', (array)($chart['labels'] ?? $chart['months'] ?? []));
        $profits = (array)($chart['profit'] ?? []);

        $index = array_search('2026-03', $months, true);
        $this->assertNotFalse($index, 'ไม่มีแท่งของเดือนที่เลือกในกราฟ');
        $this->assertSame(
            31 * self::PROFIT_PER_DAY,
            (float)($profits[$index] ?? 0),
            'แท่งของเดือนที่จบไปแล้วถูกตัดตามปลายช่วงที่ผู้ใช้เลือก แทนที่จะเป็นทั้งเดือน'
        );
    }

    /** ⚠️ กดสลับช่วงเวลาเฉย ๆ แท่งของเดือนเดียวกันต้องไม่เปลี่ยนค่า */
    public function testTheCurrentMonthBarIsTheSameWhicheverRangeIsSelected(): void
    {
        $service = $this->makeService();

        $read = static function (array $data): float {
            $chart = $data['charts']['six_months'] ?? [];
            $months = array_map('strval', (array)($chart['labels'] ?? $chart['months'] ?? []));
            $profits = (array)($chart['profit'] ?? []);
            $index = array_search('2026-08', $months, true);

            return $index === false ? -1.0 : (float)($profits[$index] ?? 0);
        };

        $thisWeek = $read($service->buildDashboard(1, 1, 'week_this', null, null, null, self::TODAY)['data']);
        $lastWeek = $read($service->buildDashboard(1, 1, 'week_last', null, null, null, self::TODAY)['data']);

        $this->assertSame(7 * self::PROFIT_PER_DAY, $thisWeek, 'แท่งเดือนนี้ไม่ได้ตัดที่วันนี้');
        $this->assertSame($lastWeek, $thisWeek, 'กดสลับช่วงเวลาแล้วแท่งเดือนเดียวกันเปลี่ยนค่า');
    }
}
