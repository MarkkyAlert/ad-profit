<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ข้อยกเว้น legacy future row ต้องอิงวันที่ที่ล็อกอยู่จริง ไม่ใช่ snapshot ก่อน transaction
 */
final class RecordServiceLegacyFutureRaceTest extends TestCase
{
    public function testStaleLegacyDateCannotRecreateAFutureRecordAfterAnotherUpdate(): void
    {
        $records = $this->createStub(RecordRepository::class);
        $records->method('findByIdAndShopId')->willReturn([
            'id' => 7,
            'shop_id' => 3,
            'record_date' => '2026-08-31', // snapshot ก่อนอีกแท็บย้ายแถวกลับมาแล้ว
        ]);
        $records->method('findByIdAndShopIdForUpdate')->willReturn([
            'id' => 7,
            'shop_id' => 3,
            'record_date' => '2026-08-10', // แถวจริงเมื่อได้ lock
        ]);
        $records->method('findByShopIdAndRecordDateForUpdate')->willReturn(null);
        $records->method('updateByIdAndShopId')->willReturn(true);

        $shops = $this->createStub(ShopRepository::class);
        $shops->method('userCanAccessShop')->willReturn(true);

        $result = (new RecordService($records, $shops))
            ->updateRecord(1, 3, 7, '2026-08-31', 1000.0, 200.0, 'ค่าที่ค้าง', '2026-08-11');

        $this->assertFalse($result['success']);
        $this->assertSame('วันที่ต้องไม่อยู่ในอนาคต', $result['error']);
    }
}
