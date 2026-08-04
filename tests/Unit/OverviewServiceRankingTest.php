<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * อันดับร้านในหน้ารวมร้าน
 *
 * ร้านที่ยังไม่มีข้อมูลเลยได้กำไร 0.0 ซึ่ง "มากกว่า" ร้านที่ขาดทุนจริง → เดือนที่ทุกร้าน
 * ขาดทุน ร้านที่ไม่ได้กรอกอะไรเลยจะขึ้นอันดับ 1 พร้อมเลข ฿0 ทั้งแถว
 * ร้านที่ไม่มีข้อมูลไม่ใช่ "ร้านที่ดีที่สุด" แต่คือ "ยังไม่รู้" — ต้องอยู่ท้ายตาราง
 */
final class OverviewServiceRankingTest extends TestCase
{
    /**
     * @param array<int,array{0:int,1:string}> $shops [id, ชื่อ]
     * @param array<int,array{0:int,1:float,2:float,3:int}> $totals [shop_id, revenue, ad_cost, days]
     */
    private function rank(array $shops, array $totals): array
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getTotalsByShopIdsAndDateRange')->willReturn(array_map(
            static fn(array $row): array => [
                'shop_id' => $row[0],
                'total_revenue' => $row[1],
                'total_ad_cost' => $row[2],
                'days_count' => $row[3],
            ],
            $totals
        ));

        $service = new OverviewService(
            $recordRepository,
            $this->createStub(ShopRepository::class)
        );

        $rows = $service->buildShopComparison(
            array_map(static fn(array $shop): array => ['id' => $shop[0], 'name' => $shop[1]], $shops),
            '2026-08-01',
            '2026-08-31'
        );

        return array_column($rows, 'shop_name');
    }

    /** ⭐ ทุกร้านขาดทุน — ร้านที่ยังไม่ได้กรอกต้องไม่ขึ้นอันดับ 1 */
    public function testShopWithNoDataDoesNotOutrankShopsThatActuallyTraded(): void
    {
        $order = $this->rank(
            [[1, 'ขาดทุนน้อย'], [2, 'ขาดทุนเยอะ'], [3, 'ยังไม่กรอก']],
            [
                [1, 1000.0, 1500.0, 20],   // กำไร -500
                [2, 1000.0, 3000.0, 20],   // กำไร -2000
            ]
        );

        $this->assertSame(['ขาดทุนน้อย', 'ขาดทุนเยอะ', 'ยังไม่กรอก'], $order);
    }

    /** ร้านที่มีข้อมูลยังเรียงตามกำไรเหมือนเดิม */
    public function testShopsWithDataStillRankByProfit(): void
    {
        $order = $this->rank(
            [[1, 'กลาง'], [2, 'สูง'], [3, 'ต่ำ']],
            [
                [1, 5000.0, 2000.0, 20],   // 3000
                [2, 9000.0, 2000.0, 20],   // 7000
                [3, 3000.0, 2000.0, 20],   // 1000
            ]
        );

        $this->assertSame(['สูง', 'กลาง', 'ต่ำ'], $order);
    }

    /**
     * กำไรเท่ากันเป๊ะ ๆ ต้องเรียงแบบคาดเดาได้ ไม่ใช่ตามลำดับที่ query คืนมา
     * เกณฑ์รอง: กรอกครบกว่าอยู่บน · เท่ากันอีกเรียงตามชื่อ
     */
    public function testTiesBreakByDaysFilledThenName(): void
    {
        $order = $this->rank(
            [[1, 'ค ครบน้อย'], [2, 'ก ครบมาก'], [3, 'ข ครบมาก']],
            [
                [1, 3000.0, 1000.0, 5],    // 2000 · 5 วัน
                [2, 3000.0, 1000.0, 20],   // 2000 · 20 วัน
                [3, 3000.0, 1000.0, 20],   // 2000 · 20 วัน
            ]
        );

        $this->assertSame(['ก ครบมาก', 'ข ครบมาก', 'ค ครบน้อย'], $order);
    }

    /** ร้านไม่มีข้อมูลหลายร้าน — เรียงตามชื่อ ไม่ใช่สุ่ม */
    public function testShopsWithNoDataAreOrderedByName(): void
    {
        $order = $this->rank([[1, 'ข'], [2, 'ก'], [3, 'ค']], []);

        $this->assertSame(['ก', 'ข', 'ค'], $order);
    }
}
