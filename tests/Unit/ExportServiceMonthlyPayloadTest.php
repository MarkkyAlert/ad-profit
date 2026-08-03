<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExportService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

/**
 * unit test ของ payload รายเดือนสำหรับ xlsx (cutoff เดือนอนาคต + โครง row)
 * today คงที่ = 2026-08-15
 */
final class ExportServiceMonthlyPayloadTest extends TestCase
{
    private const TODAY = '2026-08-15';

    /**
     * @param array<int,array<string,mixed>> $monthlyTotals
     * @param array<string,mixed>|null $shop
     */
    private function makeService(array $monthlyTotals = [], ?array $shop = ['id' => 1, 'name' => 'ร้านคอร์ส']): ExportService
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('getMonthlyTotals')->willReturnCallback(
            static function (int $u, int $s, string $start, string $end) use ($monthlyTotals): array {
                return [
                    'success' => true,
                    'data' => array_values(array_filter(
                        $monthlyTotals,
                        static fn(array $row): bool => (string)$row['month_key'] >= $start
                            && (string)$row['month_key'] <= $end
                    )),
                ];
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn($shop);

        return new ExportService($recordService, $shopRepository);
    }

    /**
     * @param array<int,array{0:int,1:float,2:float}> $rows [เดือน, รายได้, ค่าแอด]
     * @return array<int,array<string,mixed>>
     */
    private function totalsFor(int $year, array $rows): array
    {
        return array_map(
            static fn(array $row): array => [
                'month_key' => sprintf('%04d-%02d', $year, $row[0]),
                'total_revenue' => $row[1],
                'total_ad_cost' => $row[2],
                'days_count' => 3,
            ],
            $rows
        );
    }

    public function testMonthRowsCarryThaiLabelAndComputedProfit(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 5000.0, 1000.0],
            [2, 1000.0, 2500.0],
        ]));

        $data = $service->buildYearlyMonthlyPayload(1, 1, 2026, self::TODAY)['data'];

        $this->assertSame('ม.ค.', $data['months'][0]['month_label']);
        $this->assertSame(4000.0, $data['months'][0]['profit']);
        $this->assertSame(5.0, $data['months'][0]['roas']);

        // เดือนขาดทุน → ค่าติดลบ (กราฟจะวาดแท่งลงล่างเอง)
        $this->assertSame('ก.พ.', $data['months'][1]['month_label']);
        $this->assertSame(-1500.0, $data['months'][1]['profit']);
    }

    public function testCurrentYearStopsAtCurrentMonth(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [
            [1, 5000.0, 1000.0],
            // ธ.ค. ลงล่วงหน้า — ต้องไม่โผล่
            [12, 90000.0, 0.0],
        ]));

        $data = $service->buildYearlyMonthlyPayload(1, 1, 2026, self::TODAY)['data'];

        $this->assertSame(8, $data['last_month']);
        $this->assertCount(8, $data['months']);
        $this->assertSame('ส.ค.', end($data['months'])['month_label']);
    }

    public function testPastYearCoversTwelveMonths(): void
    {
        $service = $this->makeService($this->totalsFor(2025, [[12, 3000.0, 1000.0]]));

        $data = $service->buildYearlyMonthlyPayload(1, 1, 2025, self::TODAY)['data'];

        $this->assertSame(12, $data['last_month']);
        $this->assertCount(12, $data['months']);
        $this->assertSame(2000.0, $data['months'][11]['profit']);
    }

    public function testFutureYearGivesNoMonths(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[1, 5000.0, 1000.0]]));

        $data = $service->buildYearlyMonthlyPayload(1, 1, 2027, self::TODAY)['data'];

        $this->assertSame(0, $data['last_month']);
        $this->assertSame([], $data['months']);
    }

    public function testUnfilledMonthsAreZeroRowsNotGaps(): void
    {
        $service = $this->makeService($this->totalsFor(2026, [[3, 3000.0, 1000.0]]));

        $months = $service->buildYearlyMonthlyPayload(1, 1, 2026, self::TODAY)['data']['months'];

        // แกน x ของกราฟต้องครบทุกเดือน — เดือนยังไม่กรอกเป็น 0 ไม่ใช่หายไป
        $this->assertCount(8, $months);
        $this->assertSame(0.0, $months[0]['profit']);
        $this->assertNull($months[0]['roas']);
        $this->assertSame(2000.0, $months[2]['profit']);
    }

    public function testForeignShopIsRejected(): void
    {
        $service = $this->makeService([], null);

        $result = $service->buildYearlyMonthlyPayload(1, 999, 2026, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testInvalidYearIsRejected(): void
    {
        $service = $this->makeService();

        foreach ([1999, 2101] as $invalidYear) {
            $result = $service->buildYearlyMonthlyPayload(1, 1, $invalidYear, self::TODAY);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('ไม่ถูกต้อง', $result['error']);
        }
    }

    public function testFailureFromRecordServiceIsPropagated(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('getMonthlyTotals')->willReturn([
            'success' => false,
            'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'name' => 'ร้านคอร์ส']);

        $result = (new ExportService($recordService, $shopRepository))
            ->buildYearlyMonthlyPayload(1, 1, 2026, self::TODAY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }
}
