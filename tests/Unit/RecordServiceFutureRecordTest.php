<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * "วันล่าสุด" ต้องหมายถึงวันล่าสุดที่ผ่านมาแล้ว ไม่ใช่แถวที่ record_date มากที่สุด
 *
 * ระบบไม่อนุญาตให้บันทึกวันอนาคตแล้ว แต่ฐานข้อมูลเก่า/fixture ที่เขียนตรงอาจยังมีแถวล่วงหน้า
 * แถวพวกนั้นจึงยังห้ามถูกหยิบเป็น "วันล่าสุด" ของการวิเคราะห์
 * getDaysSinceLastRecord กันไว้แล้วด้วย
 * findLatestOnOrBeforeDate แต่ getWeekdayContext ยังใช้ getRecentByShopId ตรง ๆ = คู่แฝดที่ตกหล่น
 *
 * ⚠️ เทสต์นี้ยืนยัน "ความสอดคล้องข้ามจุด" ไม่ใช่แค่พฤติกรรมจุดเดียว
 */
final class RecordServiceFutureRecordTest extends TestCase
{
    /**
     * @param array<int,array{0:string,1:float,2:float}> $records [date, revenue, ad_cost]
     */
    private function makeService(array $records): RecordService
    {
        $rows = array_map(
            static fn(array $row): array => [
                'id' => 0,
                'record_date' => $row[0],
                'revenue' => $row[1],
                'ad_cost' => $row[2],
                'note' => null,
            ],
            $records
        );

        $sortDesc = static function (array $list): array {
            usort($list, static fn(array $a, array $b): int => strcmp($b['record_date'], $a['record_date']));

            return $list;
        };

        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('getByDateRange')->willReturnCallback(
            static fn(int $shopId, string $start, string $end): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => $row['record_date'] >= $start && $row['record_date'] <= $end
            ))
        );
        $recordRepository->method('getRecentByShopId')->willReturnCallback(
            static fn(int $shopId, int $limit = 7): array => array_slice($sortDesc($rows), 0, max(1, $limit))
        );
        $recordRepository->method('findLatestOnOrBeforeDate')->willReturnCallback(
            static function (int $shopId, string $cutoff) use ($rows, $sortDesc): ?array {
                $eligible = array_values(array_filter(
                    $rows,
                    static fn(array $row): bool => $row['record_date'] <= $cutoff
                ));

                return $sortDesc($eligible)[0] ?? null;
            }
        );

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    /** ⭐ วันอนาคตต้องไม่ถูกเลือกเป็น "วันล่าสุด" ที่เอาไปเทียบ */
    public function testWeekdayContextSkipsFutureRecordsWhenPickingTheLatestDay(): void
    {
        $service = $this->makeService([
            ['2026-08-03', 1000.0, 200.0],  // จันทร์
            ['2026-08-10', 2000.0, 400.0],  // จันทร์ = วันล่าสุดที่ผ่านมาแล้ว
            ['2026-12-25', 9999.0, 1.0],    // อนาคต — พิมพ์เดือนผิด หรือจองไว้ล่วงหน้า
        ]);

        $data = $service->getWeekdayContext(1, 1, null, '2026-08-11')['data'];

        $this->assertSame('2026-08-10', $data['target_date'], 'ไปหยิบวันอนาคตมาเป็นวันล่าสุด');
        $this->assertSame(2000.0, $data['target_revenue']);
    }

    /** มีแต่รายการวันอนาคต = ยังไม่มีอะไรให้เทียบ ไม่ใช่เทียบกับวันที่ยังมาไม่ถึง */
    public function testWeekdayContextHasNoDataWhenEveryRecordIsInTheFuture(): void
    {
        $service = $this->makeService([
            ['2026-12-25', 9999.0, 1.0],
        ]);

        $result = $service->getWeekdayContext(1, 1, null, '2026-08-11');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['has_data']);
    }

    /** ระบุวันอนาคตมาเองยังดูได้เฉพาะกรณี legacy data ที่มีอยู่จริง */
    public function testWeekdayContextStillHonoursAnExplicitlyRequestedFutureDate(): void
    {
        $service = $this->makeService([
            ['2026-08-03', 1000.0, 200.0],
            ['2026-12-25', 9999.0, 1.0],
        ]);

        $data = $service->getWeekdayContext(1, 1, '2026-12-25', '2026-08-11')['data'];

        $this->assertSame('2026-12-25', $data['target_date']);
    }

    /**
     * ตรงกันข้ามกับด้านบน: "รายการล่าสุด" ในหน้าบันทึกยังเห็น legacy future rows
     *
     * ใช้เพื่อให้ข้อมูลเก่าที่เคยลงไว้ยังจัดการได้ผ่านหน้าบันทึก/ประวัติ
     */
    public function testRecentRecordsListStillShowsFutureRows(): void
    {
        $service = $this->makeService([
            ['2026-08-03', 1000.0, 200.0],
            ['2026-12-25', 9999.0, 1.0],
        ]);

        $dates = array_column($service->getRecentRecords(1, 1, 7), 'record_date');

        $this->assertContains('2026-12-25', $dates, 'ซ่อนแถวที่ผู้ใช้เพิ่งบันทึกไว้เอง');
    }
}
