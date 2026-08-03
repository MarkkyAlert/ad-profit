<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewAnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * unit test ของ YoY รวมทุกร้าน (เทียบช่วงเดียวกันปีก่อน) — มุมปีของหน้ารวมร้าน
 * today คงที่ = 2026-08-15 (cutoff = ส.ค.)
 */
final class OverviewAnnualServiceYoyTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals แถวของทุกปีรวมกัน
     */
    private function makeService(array $monthlyTotals = []): OverviewAnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end) use ($monthlyTotals): array {
                // จำลอง BETWEEN — คืนเฉพาะเดือนในช่วงที่ service ขอ
                // (สำคัญกับ same-period: ถ้า service ขอปีก่อนแค่ ม.ค.-ส.ค. ก็จะไม่เห็น ก.ย.-ธ.ค.)
                return array_values(array_filter(
                    $monthlyTotals,
                    static fn(array $row): bool => (string)$row['month_key'] >= $start
                        && (string)$row['month_key'] <= $end
                ));
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
        ]);

        return new OverviewAnnualService($recordRepository, $shopRepository);
    }

    /**
     * @param array<int,array{0:int,1:int,2:float,3:float}> $rows [shopId, เดือน, รายได้, ค่าแอด]
     * @return array<int,array<string,mixed>>
     */
    private function totalsFor(int $year, array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'shop_id' => $row[0],
                'month_key' => sprintf('%04d-%02d', $year, $row[1]),
                'total_revenue' => $row[2],
                'total_ad_cost' => $row[3],
                'days_count' => 1,
            ],
            $rows
        );
    }

    public function testYearOverYearComparesSamePeriodOnly(): void
    {
        $service = $this->makeService(array_merge(
            // ปีนี้ ม.ค. + ส.ค. รวม 2 ร้าน → กำไร 4000 + 2000 = 6000
            $this->totalsFor(2026, [
                [1, 1, 5000.0, 1000.0],   // +4000
                [2, 8, 3000.0, 1000.0],   // +2000
            ]),
            // ปีก่อน ม.ค.-ส.ค. → 1000 + 2000 = 3000
            $this->totalsFor(2025, [
                [1, 1, 2000.0, 1000.0],   // +1000
                [2, 6, 3000.0, 1000.0],   // +2000
            ]),
            // ปีก่อน ต.ค./ธ.ค. — เกิน lastMonth ต้องไม่ถูกนับ (พิสูจน์ same-period)
            $this->totalsFor(2025, [
                [1, 10, 90000.0, 0.0],
                [2, 12, 90000.0, 0.0],
            ])
        ));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(6000.0, $summary['profit']);
        // ถ้านับปีก่อนเต็ม 12 เดือนจะได้ 183000 — ต้องเป็น 3000 เท่านั้น
        $this->assertSame(3000.0, $summary['prev_year_profit']);
        $this->assertSame(2025, $summary['prev_year']);
        $this->assertSame(3000.0, $summary['yoy_profit_change']);
        $this->assertSame(100.0, $summary['yoy_profit_change_percent']);
    }

    public function testNoPreviousYearDataGivesNullPercent(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 1, 5000.0, 1000.0]]));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        // ฐาน 0 หารไม่ได้ → null (ต้องไม่กลายเป็น 0% หรือ inf)
        $this->assertNull($summary['yoy_profit_change_percent']);
        $this->assertSame(4000.0, $summary['yoy_profit_change']);
    }

    public function testPreviousYearBreakEvenGivesNullPercent(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 1, 5000.0, 1000.0]]),
            // ปีก่อน 2 ร้านหักล้างกันพอดี → รวม 0
            $this->totalsFor(2025, [
                [1, 1, 3000.0, 1000.0],   // +2000
                [2, 1, 1000.0, 3000.0],   // -2000
            ])
        ));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(0.0, $summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }

    public function testPreviousYearLossKeepsSignDirection(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 1, 1500.0, 1000.0]]),   // +500
            $this->totalsFor(2025, [[1, 1, 1000.0, 2000.0]])    // -1000
        ));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(-1000.0, $summary['prev_year_profit']);
        // ขาดทุน -1000 → กำไร +500 คือ "ดีขึ้น" ต้องเป็นบวก (หารด้วย abs)
        $this->assertSame(150.0, $summary['yoy_profit_change_percent']);
    }

    public function testProfitDropGivesNegativePercent(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 1, 2000.0, 1000.0]]),   // +1000
            $this->totalsFor(2025, [[1, 1, 5000.0, 1000.0]])    // +4000
        ));

        $summary = $service->buildYearlyOverview(1, 2026, self::TODAY)['data']['summary'];

        $this->assertSame(-3000.0, $summary['yoy_profit_change']);
        $this->assertSame(-75.0, $summary['yoy_profit_change_percent']);
    }

    public function testFutureYearHasNoComparison(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 8, 9999.0, 0.0]]));

        $summary = $service->buildYearlyOverview(1, 2027, self::TODAY)['data']['summary'];

        // ปีอนาคต lastMonth = 0 → ไม่ดึงปีก่อนเลย
        $this->assertNull($summary['prev_year_profit']);
        $this->assertNull($summary['yoy_profit_change']);
        $this->assertNull($summary['yoy_profit_change_percent']);
    }

    public function testPastYearComparesFullTwelveMonths(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2025, [[1, 12, 3000.0, 1000.0]]),   // +2000 (ธ.ค.)
            $this->totalsFor(2024, [[1, 12, 2000.0, 1000.0]])    // +1000 (ธ.ค.)
        ));

        $data = $service->buildYearlyOverview(1, 2025, self::TODAY)['data'];

        // ปีอดีต cutoff = 12 → ธ.ค. ของปีก่อนต้องถูกนับด้วย
        $this->assertCount(12, $data['months']);
        $this->assertSame(1000.0, $data['summary']['prev_year_profit']);
        $this->assertSame(100.0, $data['summary']['yoy_profit_change_percent']);
    }

    public function testYoyDoesNotDisturbExistingSummaryFields(): void
    {
        $service = $this->makeService(array_merge(
            $this->totalsFor(2026, [[1, 3, 20000.0, 19500.0], [2, 5, 4000.0, 1000.0]]),
            $this->totalsFor(2025, [[1, 1, 2000.0, 1000.0]])
        ));

        $data = $service->buildYearlyOverview(1, 2026, self::TODAY)['data'];

        // cutoff / best-worst / share ที่ทำไปก่อนหน้าต้องไม่ถูกกระทบ
        $this->assertSame(8, $data['last_month']);
        $this->assertCount(8, $data['months']);
        $this->assertSame(5, $data['summary']['best_month']['month']);
        $this->assertSame(3, $data['summary']['worst_month']['month']);
        $this->assertNotNull($data['shops'][0]['profit_share']);
    }
}
