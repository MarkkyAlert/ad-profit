<?php

declare(strict_types=1);

namespace Tests\Unit;

use AnnualService;
use GoalRepository;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * กริด "ฤดูกาล" 3 ปี — มีข้อมูลปีเดียวไม่ใช่ฤดูกาล
 *
 * ร้านที่เปิดปีนี้จะได้กริด 3 แถวที่ว่าง 2 แถว ทั้งในหน้า annual.php และในชีต "ฤดูกาล"
 * ของไฟล์ xlsx ทั้งที่หัวเรื่องบอกว่า "เดือนเดียวกันเขียวหลายปีติด = ฤดูกาลขายจริง"
 * — ซึ่งอ่านจากข้อมูลปีเดียวไม่ได้เลย
 */
final class AnnualServiceHeatmapComparableTest extends TestCase
{
    /**
     * @param array<int,array{0:string,1:float}> $monthly [month_key, revenue]
     */
    private function heatmap(array $monthly): array
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByMonthRange')->willReturn(array_map(
            static fn(array $row): array => [
                'month_key' => $row[0],
                'total_revenue' => $row[1],
                'total_ad_cost' => 0.0,
            ],
            $monthly
        ));

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        $service = new AnnualService($recordRepository, $shopRepository, $this->createStub(GoalRepository::class));

        return $service->buildMonthlyHeatmap(1, 1, 2026, '2026-08-04')['data'];
    }

    /** ⭐ ข้อมูลปีเดียว = ไม่มีอะไรให้เทียบข้ามปี */
    public function testSingleYearOfDataIsNotComparable(): void
    {
        $data = $this->heatmap([['2026-06', 1000.0], ['2026-07', 2000.0]]);

        $this->assertSame(1, $data['years_with_data']);
        $this->assertFalse($data['comparable']);
    }

    public function testTwoYearsOfDataIsComparable(): void
    {
        $data = $this->heatmap([['2025-06', 1000.0], ['2026-06', 2000.0]]);

        $this->assertSame(2, $data['years_with_data']);
        $this->assertTrue($data['comparable']);
    }

    public function testNoDataAtAllIsNotComparable(): void
    {
        $data = $this->heatmap([]);

        $this->assertSame(0, $data['years_with_data']);
        $this->assertFalse($data['comparable']);
    }

    /**
     * เดือนอนาคตไม่นับเป็น "ปีที่มีข้อมูล" — รายการที่ลงล่วงหน้าไปปีหน้าต้องไม่
     * ทำให้ระบบคิดว่าเทียบฤดูกาลได้แล้ว (กริดยัง cutoff เดือนอนาคตอยู่)
     */
    public function testFutureMonthsDoNotCountAsAYearWithData(): void
    {
        $data = $this->heatmap([['2026-06', 1000.0], ['2026-12', 9999.0]]);

        $this->assertSame(1, $data['years_with_data']);
        $this->assertFalse($data['comparable']);
    }

    /** กริดยังคืนครบ 3 ปีเสมอ — แค่บอกเพิ่มว่าเทียบได้หรือยัง */
    public function testGridStillCoversThreeYears(): void
    {
        $data = $this->heatmap([['2026-06', 1000.0]]);

        $this->assertSame([2024, 2025, 2026], $data['years']);
        $this->assertCount(3, $data['grid']);
    }
}
