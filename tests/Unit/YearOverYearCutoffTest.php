<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use OverviewAnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ "เทียบปีก่อน (ช่วงเดียวกัน)" ต้องตัดถึง **วันเดียวกัน** ไม่ใช่แค่เดือนเดียวกัน
 *
 * ⚠️ อาการที่วัดได้จริงก่อนแก้ (วันนี้ 7 ส.ค. 2026 · กำไรวันละ ฿1,000 เท่ากันเป๊ะ
 * ทุกวันทั้งปี 2025 และ 2026 · กรอกครบทุกวัน):
 *   · กำไรสะสมปีนี้      = ฿219,000  (1 ม.ค. – 7 ส.ค. = 219 วัน)
 *   · "ปีก่อนช่วงเดียวกัน" = ฿243,000  (1 ม.ค. – **31** ส.ค. 2025 = 243 วัน)
 *   · ป้ายที่ผู้ใช้เห็น    = **กำไร ↓ 9.9% (ตามอยู่ ฿24,000)**
 *
 * ร้านที่ทำผลงานเท่ากันเป๊ะ 2 ปีติดถูกรายงานว่าแย่ลง และตัวเลขนี้ "ดีขึ้นเอง" ทุกวัน
 * ที่ผ่านไป จนกลายเป็น 0.0% เฉพาะวันสุดท้ายของเดือนเท่านั้น
 *
 * ⚠️ ขัดกับแดชบอร์ดที่ตัดวันถูกอยู่แล้ว — ข้อมูลชุดเดียวกัน แดชบอร์ดบอก 0.0%
 */
final class YearOverYearCutoffTest extends TestCase
{
    private const TODAY = '2026-08-07';
    private const PROFIT_PER_DAY = 1000.0;

    /**
     * จำลอง query จริง: รวมยอดรายเดือน โดยตัดที่ `$notAfterDate` เหมือน SQL
     *
     * ⚠️ ต้องเคารพ `$notAfterDate` จริง ๆ — stub ที่ละเลยพารามิเตอร์นี้จะให้ผล
     * เหมือนกันทั้งก่อนและหลังแก้ แล้วเทสต์จะเขียวโดยไม่ได้พิสูจน์อะไรเลย
     *
     * @return array<int,array<string,mixed>>
     */
    private static function totalsBetween(string $startMonth, string $endMonth, ?string $notAfterDate): array
    {
        $rows = [];
        $cursor = new \DateTimeImmutable($startMonth . '-01');
        $stop = new \DateTimeImmutable($endMonth . '-01');

        while ($cursor <= $stop) {
            $monthKey = $cursor->format('Y-m');
            $days = (int)$cursor->format('t');

            if ($notAfterDate !== null) {
                if ($notAfterDate < $monthKey . '-01') {
                    $cursor = $cursor->modify('+1 month');
                    continue;
                }
                if (substr($notAfterDate, 0, 7) === $monthKey) {
                    $days = min($days, (int)substr($notAfterDate, 8, 2));
                }
            }

            $rows[] = [
                'shop_id' => 1,
                'month_key' => $monthKey,
                'total_revenue' => $days * 3000.0,
                'total_ad_cost' => $days * (3000.0 - self::PROFIT_PER_DAY),
                'days_count' => $days,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $rows;
    }

    private function recordRepository(): RecordRepository
    {
        $repository = $this->createStub(RecordRepository::class);
        $repository->method('getMonthlyTotalsByMonthRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end, ?string $notAfter = null): array
                => self::totalsBetween($start, $end, $notAfter)
        );
        $repository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturnCallback(
            static fn(array $shopIds, string $start, string $end, ?string $notAfter = null): array
                => self::totalsBetween($start, $end, $notAfter)
        );

        return $repository;
    }

    private function shopRepository(): ShopRepository
    {
        $repository = $this->createStub(ShopRepository::class);
        $repository->method('userCanAccessShop')->willReturn(true);
        $repository->method('listByUserId')->willReturn([
            ['id' => 1, 'name' => 'ร้านหนึ่ง'],
            ['id' => 2, 'name' => 'ร้านสอง'],
        ]);

        return $repository;
    }

    /** หน้ารายปีของร้านเดียว */
    public function testTheAnnualPageComparesTheSameNumberOfDays(): void
    {
        $service = new AnnualService(
            $this->recordRepository(),
            $this->shopRepository(),
            $this->createStub(GoalRepository::class)
        );

        $summary = $service->buildYearlySummary(1, 1, 2026, self::TODAY)['data']['summary'] ?? [];

        $this->assertSame(
            (float)($summary['profit'] ?? -1),
            (float)($summary['prev_year_profit'] ?? -2),
            'ทำกำไรเท่ากันเป๊ะทุกวันทั้งสองปี แต่ระบบบอกว่าไม่เท่ากัน — '
            . 'ปีก่อนถูกนับทั้งเดือน ขณะที่ปีนี้ตัดที่วันนี้'
        );
    }

    /** หน้ารวมร้าน มุมรายปี — ต้องใช้กติกาเดียวกัน */
    public function testTheOverviewPageComparesTheSameNumberOfDays(): void
    {
        $service = new OverviewAnnualService($this->recordRepository(), $this->shopRepository());

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'] ?? [];

        $this->assertSame(
            (float)($summary['profit'] ?? -1),
            (float)($summary['prev_year_profit'] ?? -2),
            'หน้ารวมร้านเทียบคนละจำนวนวันเหมือนกัน'
        );
    }

    /**
     * ⚠️ วันตัดต้องหดให้พอดีเดือนที่สั้นกว่า
     *
     * 31 มี.ค. 2026 → ปีก่อนต้องเทียบถึง 31 มี.ค. 2025 (มี 31 วันเท่ากัน) แต่ถ้าวันนี้
     * เป็น 30 ม.ค. แล้วเดือนเป้าหมายมี 28 วัน การส่งวันที่ไม่มีอยู่จริงจะได้ช่วงว่าง
     * เทสต์นี้ยืนยันว่าปีอธิกสุรทินไม่ทำให้ผลเพี้ยน (29 ก.พ. 2028 เทียบ ก.พ. 2027)
     */
    public function testALeapDayDoesNotBreakTheComparison(): void
    {
        $service = new AnnualService(
            $this->recordRepository(),
            $this->shopRepository(),
            $this->createStub(GoalRepository::class)
        );

        $summary = $service->buildYearlySummary(1, 1, 2028, '2028-02-29')['data']['summary'] ?? [];

        // ปีนี้ ม.ค.(31) + ก.พ.(29) = 60 วัน · ปีก่อน ม.ค.(31) + ก.พ.(28) = 59 วัน
        // ต่างกัน 1 วันตามปฏิทินจริง ไม่ใช่ต่างกันเพราะนับคนละช่วง
        $this->assertSame(60 * self::PROFIT_PER_DAY, (float)($summary['profit'] ?? 0));
        $this->assertSame(59 * self::PROFIT_PER_DAY, (float)($summary['prev_year_profit'] ?? 0));
    }

    /** ปีที่จบไปแล้วต้องเทียบเต็มปีทั้งคู่ ไม่ใช่ถูกตัดด้วยวันของวันนี้ */
    public function testAFinishedYearIsComparedInFull(): void
    {
        $service = new AnnualService(
            $this->recordRepository(),
            $this->shopRepository(),
            $this->createStub(GoalRepository::class)
        );

        $summary = $service->buildYearlySummary(1, 1, 2025, self::TODAY)['data']['summary'] ?? [];

        $this->assertSame(365 * self::PROFIT_PER_DAY, (float)($summary['profit'] ?? 0), 'ปีที่จบแล้วถูกตัดทิ้ง');
        // 2024 เป็นปีอธิกสุรทิน จึงมี 366 วัน — ต่างกันตามปฏิทินจริง ไม่ใช่เพราะนับคนละช่วง
        $this->assertSame(366 * self::PROFIT_PER_DAY, (float)($summary['prev_year_profit'] ?? 0));
    }
}
