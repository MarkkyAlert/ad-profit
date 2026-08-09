<?php

declare(strict_types=1);

namespace Tests\Integration;

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * ⭐⭐ คอลัมน์ "เทียบครั้งก่อน" ต้องหมายถึงรายการก่อนหน้า **จริง ๆ** ไม่ใช่ "ในเดือนนี้"
 *
 * ⚠️⚠️ **วัดจริงก่อนแก้** (ข้อมูลต่อเนื่องทุกวันตั้งแต่ ม.ค. 2567):
 *     1 มิ.ย. 2569 → –     1 ก.ค. 2569 → –     1 ส.ค. 2569 → –
 * ทั้งสามเดือนมีรายการของวันสุดท้ายเดือนก่อนอยู่ในฐานข้อมูลครบ · ตัวแปรที่เก็บ
 * "ยอดของรายการก่อนหน้า" ถูกตั้งใหม่เป็น null ทุกเดือน แถวแรกจึงขึ้นขีดเสมอ
 *
 * ⚠️ ขีดในคอลัมน์นี้ที่แถวอื่นแปลว่า **"ไม่มีรายการก่อนหน้า"** จริง ๆ — ปล่อยไว้จะมี
 * สัญลักษณ์เดียวที่สื่อสองความหมาย · เป็นบทเรียนเดียวกับที่เปลี่ยน "เทียบเมื่อวาน"
 * เป็น "เทียบครั้งก่อน" (ชื่อคอลัมน์ต้องตรงกับสิ่งที่คำนวณ)
 *
 * ⚠️⚠️ เทสต์นี้ต้องมี **ทั้งสองทาง** — ถ้าล็อกแต่ทางที่ต้องมีค่า คนแก้ทีหลังอาจเผลอ
 * ทำให้ทุกแถวมีค่าเสมอ (เช่นใส่ 0 แทน null) แล้ว "ไม่มีรายการก่อนหน้า" จะหายไป
 */
final class PreviousRecordComparisonTest extends IntegrationTestCase
{
    private function service(): RecordService
    {
        return new RecordService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            $this->pdo
        );
    }

    private function insert(int $shopId, string $date, float $revenue, float $adCost = 2000.0): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'date' => $date, 'revenue' => $revenue, 'ad' => $adCost]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rowsFor(int $userId, int $shopId, string $month): array
    {
        $result = $this->service()->getMonthlyRecords($userId, $shopId, $month);
        $this->assertTrue($result['success'] ?? false, 'ดึงรายการรายเดือนไม่สำเร็จ');

        return (array)($result['data']['records'] ?? []);
    }

    /**
     * ⭐ แถวแรกของเดือนต้องเทียบกับรายการสุดท้ายของเดือนก่อนได้
     */
    public function testTheFirstRowOfAMonthComparesWithTheLastRecordOfThePreviousMonth(): void
    {
        $userId = $this->createUser('prev@example.com', 'PrevPass123');
        $shopId = $this->createShop($userId, 'ร้านทดสอบการเทียบข้ามเดือน');

        $this->insert($shopId, '2026-07-31', 3000.0);
        $this->insert($shopId, '2026-08-01', 4500.0);

        $rows = $this->rowsFor($userId, $shopId, '2026-08');
        $this->assertCount(1, $rows, 'ตารางเดือน ส.ค. ต้องมีแถวเดียว');

        $this->assertNotNull(
            $rows[0]['compare_revenue_percent'] ?? null,
            'แถวแรกของเดือนขึ้นขีด ทั้งที่วันสุดท้ายของเดือนก่อนมีรายการอยู่'
        );
        $this->assertEqualsWithDelta(
            50.0,
            (float)$rows[0]['compare_revenue_percent'],
            0.05,
            '฿3,000 → ฿4,500 ต้องได้ +50.0%'
        );
    }

    /**
     * ⭐ ระยะห่างจะกี่วันก็ได้ — "ครั้งก่อน" ไม่ใช่ "เมื่อวาน" (บทเรียน H2)
     */
    public function testItComparesWithThePreviousRecordEvenAcrossALongGap(): void
    {
        $userId = $this->createUser('gap@example.com', 'GapPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เว้นช่วงยาว');

        // เว้นไปเกือบสามเดือน
        $this->insert($shopId, '2026-05-20', 2000.0);
        $this->insert($shopId, '2026-08-10', 5000.0);

        $rows = $this->rowsFor($userId, $shopId, '2026-08');

        $this->assertEqualsWithDelta(
            150.0,
            (float)($rows[0]['compare_revenue_percent'] ?? 0),
            0.05,
            '฿2,000 → ฿5,000 ต้องได้ +150.0% แม้ห่างกันเกือบสามเดือน'
        );
    }

    /**
     * ⭐⭐ ทางตรงข้าม: ไม่มีรายการก่อนหน้า **จริง ๆ** ต้องยังเป็น null
     *
     * ⚠️ ถ้าขาดเทสต์ตัวนี้ การ "แก้" ให้ทุกแถวมีค่าเสมอจะผ่านหน้าตาเฉย
     */
    public function testTheVeryFirstRecordEverStillHasNothingToCompareWith(): void
    {
        $userId = $this->createUser('first@example.com', 'FirstPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เพิ่งเริ่ม');

        $this->insert($shopId, '2026-01-15', 1000.0);
        $this->insert($shopId, '2026-01-16', 2000.0);

        $rows = $this->rowsFor($userId, $shopId, '2026-01');

        /* ⚠️ ห้ามใช้ `?? 'ไม่มีคีย์'` แล้ว assertNull — `??` ถือว่า null คือ "ไม่มีค่า"
           จึงคืนตัวสำรองแทน null ที่กำลังจะตรวจพอดี (เทสต์เวอร์ชันแรกพลาดตรงนี้เอง) */
        $this->assertArrayHasKey('compare_revenue_percent', $rows[0]);
        $this->assertNull(
            $rows[0]['compare_revenue_percent'],
            'รายการแรกสุดของร้านไม่มีอะไรให้เทียบ ต้องเป็น null'
        );
        $this->assertEqualsWithDelta(
            100.0,
            (float)($rows[1]['compare_revenue_percent'] ?? 0),
            0.05,
            'แถวที่สองต้องเทียบกับแถวแรกได้ตามปกติ'
        );
    }

    /**
     * ⭐ รายการก่อนหน้าของ **ร้านอื่น** ต้องไม่ถูกหยิบมาเทียบ
     */
    public function testAnotherShopsRecordIsNeverUsedAsThePreviousOne(): void
    {
        $userId = $this->createUser('twoshops@example.com', 'TwoShopPass123');
        $shopA = $this->createShop($userId, 'ร้านเอ');
        $shopB = $this->createShop($userId, 'ร้านบี');

        // ร้านบีมีรายการก่อนหน้า แต่ร้านเอไม่มี
        $this->insert($shopB, '2026-07-31', 9000.0);
        $this->insert($shopA, '2026-08-01', 4500.0);

        $rows = $this->rowsFor($userId, $shopA, '2026-08');

        $this->assertArrayHasKey('compare_revenue_percent', $rows[0]);
        $this->assertNull(
            $rows[0]['compare_revenue_percent'],
            'หยิบรายการของร้านอื่นมาเป็น "ครั้งก่อน"'
        );
    }

    /**
     * ⭐ ยอดของรายการก่อนหน้าเป็น ฿0 → หารไม่ได้ ต้องเป็น null ไม่ใช่ 0%
     */
    public function testAZeroRevenuePreviousRecordGivesNullNotZeroPercent(): void
    {
        $userId = $this->createUser('zero@example.com', 'ZeroPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เดือนก่อนรายได้ศูนย์');

        $this->insert($shopId, '2026-07-31', 0.0, 0.0);
        $this->insert($shopId, '2026-08-01', 5000.0);

        $rows = $this->rowsFor($userId, $shopId, '2026-08');

        $this->assertArrayHasKey('compare_revenue_percent', $rows[0]);
        $this->assertNull(
            $rows[0]['compare_revenue_percent'],
            'เทียบกับศูนย์ไม่ได้ ต้องเป็น null (หลัก null ≠ 0 ของทั้งระบบ)'
        );
    }
}
