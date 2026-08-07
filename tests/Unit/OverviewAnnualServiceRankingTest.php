<?php

declare(strict_types=1);

namespace Tests\Unit;

use OverviewAnnualService;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐ ตารางจัดอันดับรายปี: ร้านที่ยังไม่มีข้อมูลต้องไปท้ายตารางเสมอ
 *
 * ⚠️ กำไร `฿0` ของร้านที่ไม่ได้กรอก "มากกว่า" ร้านที่ขาดทุนจริง มันจึงขึ้นอันดับ 1
 * วัดจริงก่อนแก้ (ร้าน A/B บันทึกทุกวันตั้งแต่ 1 ม.ค. ถึง 7 ส.ค. แต่ขาดทุน · ร้าน C
 * ไม่เคยกรอกเลย):
 *   อันดับ 1  ร้าน C (ไม่เคยกรอก)  ฿0        กรอก — วัน
 *   อันดับ 2  ร้าน A               ฿-21,900  กรอก 219 วัน
 *   อันดับ 3  ร้าน B               ฿-65,700  กรอก 219 วัน
 *
 * ขณะที่แท็บ "รายเดือน" ของหน้าเดียวกัน ข้อมูลชุดเดียวกัน ตอบตรงกันข้าม
 * และไฟล์ Excel ชีต "เทียบร้าน" ใช้ลำดับนี้จึงผิดตามไปด้วย
 *
 * กติกามีอยู่แล้วใน `OverviewService::buildShopComparison` (มุมเดือน) พร้อมคอมเมนต์
 * อธิบายอาการนี้เป๊ะ ๆ — แต่มุมปีตกสำรวจ · ร้านที่ไม่มีข้อมูล = "ยังไม่รู้" ไม่ใช่ "ดีที่สุด"
 */
final class OverviewAnnualServiceRankingTest extends TestCase
{
    private const TODAY = '2026-08-07';

    /**
     * @param array<int,array<string,mixed>> $shops
     */
    private function makeService(array $shops, float $shopOneProfitPerDay, float $shopTwoProfitPerDay): OverviewAnnualService
    {
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getMonthlyTotalsByShopIdsAndMonthRange')->willReturnCallback(
            static function (array $shopIds, string $start, string $end, ?string $notAfter = null)
                use ($shopOneProfitPerDay, $shopTwoProfitPerDay): array {
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

                    // ร้าน 3 ไม่มีแถวเลย — ยังไม่เคยกรอกอะไร
                    foreach ([1 => $shopOneProfitPerDay, 2 => $shopTwoProfitPerDay] as $shopId => $perDay) {
                        $rows[] = [
                            'shop_id' => $shopId,
                            'month_key' => $monthKey,
                            'total_revenue' => $days * 1000.0,
                            'total_ad_cost' => $days * (1000.0 - $perDay),
                            'days_count' => $days,
                        ];
                    }

                    $cursor = $cursor->modify('+1 month');
                }

                return $rows;
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('listByUserId')->willReturn($shops);

        return new OverviewAnnualService($recordRepository, $shopRepository);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function ranking(float $shopOnePerDay, float $shopTwoPerDay): array
    {
        $service = $this->makeService([
            ['id' => 1, 'name' => 'ร้าน A'],
            ['id' => 2, 'name' => 'ร้าน B'],
            ['id' => 3, 'name' => 'ร้าน C'],
        ], $shopOnePerDay, $shopTwoPerDay);

        return (array)($service->buildYearlyOverview(1, 2026, self::TODAY)['data']['shops'] ?? []);
    }

    public function testAShopWithNoDataNeverOutranksAShopThatIsLosingMoney(): void
    {
        // ทั้งสองร้านที่กรอกจริงขาดทุน — ร้านที่ไม่เคยกรอกมีกำไร 0 ซึ่ง "มากกว่า"
        $rows = $this->ranking(-100.0, -300.0);

        $this->assertNotEmpty($rows, 'อ่านตารางจัดอันดับไม่ได้ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');
        $this->assertSame(
            'ร้าน C',
            (string)($rows[count($rows) - 1]['shop_name'] ?? ''),
            'ร้านที่ยังไม่เคยกรอกไม่ได้อยู่ท้ายตาราง — กำไร ฿0 ของมันชนะร้านที่ขาดทุนจริง'
        );
        $this->assertSame('ร้าน A', (string)($rows[0]['shop_name'] ?? ''), 'ร้านที่ขาดทุนน้อยกว่าควรอยู่บน');
    }

    /** ร้านที่มีข้อมูลยังต้องเรียงตามกำไรตามปกติ */
    public function testShopsWithDataAreStillRankedByProfit(): void
    {
        $rows = $this->ranking(100.0, 300.0);

        $this->assertSame('ร้าน B', (string)($rows[0]['shop_name'] ?? ''), 'ร้านกำไรมากกว่าไม่ได้อยู่บน');
        $this->assertSame('ร้าน A', (string)($rows[1]['shop_name'] ?? ''));
        $this->assertSame('ร้าน C', (string)($rows[2]['shop_name'] ?? ''), 'ร้านที่ไม่มีข้อมูลไม่ได้อยู่ท้าย');
    }
}
