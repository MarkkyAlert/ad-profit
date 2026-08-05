<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;

final class RecordRepositoryTest extends IntegrationTestCase
{
    public function testUpsertSameShopAndDateUpdatesInsteadOfInserting(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $repo = new RecordRepository($this->pdo);
        $repo->upsert($shopId, '2024-01-15', 1000.0, 200.0, 'first');
        $repo->upsert($shopId, '2024-01-15', 1500.0, 300.0, 'updated'); // shop + date เดิม → ต้องเป็น UPDATE

        // unique(shop_id, record_date) → ยังมีแถวเดียว
        $this->assertSame(1, $this->countRows('daily_records'));

        $stmt = $this->pdo->prepare(
            'SELECT revenue, ad_cost, note FROM daily_records WHERE shop_id = ? AND record_date = ?'
        );
        $stmt->execute([$shopId, '2024-01-15']);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame(1500.0, (float)$row['revenue']); // ค่าถูกอัปเดต
        $this->assertSame(300.0, (float)$row['ad_cost']);
        $this->assertSame('updated', $row['note']);
    }

    /**
     * ⭐⭐ ยอดรายเดือนต้องตัดวันที่ยังมาไม่ถึงออกได้
     *
     * ⚠️ ระบบอนุญาตให้ลงข้อมูลวันล่วงหน้า · หน้ารายปี ไฟล์ Excel และกราฟ 6 เดือน
     * ใช้ query นี้ ถ้าไม่ตัด การ์ดเป้าหมายจะขึ้น "ถึงเป้าแล้ว 100%" ขณะที่แดชบอร์ด
     * ขึ้น 40% จากข้อมูลชุดเดียวกัน (เกิดขึ้นจริง)
     *
     * ⚠️ ต้องเทสต์ที่นี่ ไม่ใช่ที่ชั้น service — เทสต์ชั้นบนใช้ตัวจำลอง repository
     * ซึ่งเขียนกติกาการตัดวันซ้ำไว้เอง จึงพิสูจน์ SQL จริงไม่ได้เลย
     */
    public function testMonthlyTotalsCanExcludeDaysThatHaveNotArrivedYet(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $repo = new RecordRepository($this->pdo);
        $repo->upsert($shopId, '2026-08-01', 1000.0, 0.0, null);
        $repo->upsert($shopId, '2026-08-04', 1000.0, 0.0, null);
        $repo->upsert($shopId, '2026-08-20', 6000.0, 0.0, null); // วันล่วงหน้า

        $wholeMonth = $repo->getMonthlyTotalsByMonthRange($shopId, '2026-08', '2026-08');
        $this->assertSame(8000.0, (float)$wholeMonth[0]['total_revenue'], 'ทั้งเดือนต้องรวมทุกแถว');
        $this->assertSame(3, (int)$wholeMonth[0]['days_count']);

        $upToToday = $repo->getMonthlyTotalsByMonthRange($shopId, '2026-08', '2026-08', '2026-08-04');
        $this->assertSame(
            2000.0,
            (float)$upToToday[0]['total_revenue'],
            'ยอด "ถึงวันนี้" ยังรวมวันที่ยังมาไม่ถึงอยู่'
        );
        $this->assertSame(2, (int)$upToToday[0]['days_count']);
    }

    /** ⭐ วันตัดที่เลยสิ้นเดือนไปแล้วต้องไม่ทำอะไร (เดือนในอดีตต้องได้ทั้งเดือน) */
    public function testACutoffAfterTheMonthEndsChangesNothing(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $repo = new RecordRepository($this->pdo);
        $repo->upsert($shopId, '2026-07-31', 500.0, 0.0, null);

        $totals = $repo->getMonthlyTotalsByMonthRange($shopId, '2026-07', '2026-07', '2026-08-04');

        $this->assertSame(500.0, (float)$totals[0]['total_revenue'], 'เดือนในอดีตถูกตัดทิ้งไปด้วย');
    }
}
