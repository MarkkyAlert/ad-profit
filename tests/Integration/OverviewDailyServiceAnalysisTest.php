<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewDailyService;
use RecordRepository;
use ShopRepository;

/**
 * integration test ของมุมวันรวมร้าน — DB จริง 2 ร้าน กรอกไม่เท่ากันบางวัน
 */
final class OverviewDailyServiceAnalysisTest extends IntegrationTestCase
{
    private const MONTH = '2026-06';

    private function makeService(): OverviewDailyService
    {
        return new OverviewDailyService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    public function testIncompleteDaysFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // 1 มิ.ย. ครบ 2 ร้าน
        $this->createRecord($shopA, '2026-06-01', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-06-01', 2000.0, 500.0);
        // 2 มิ.ย. กรอกร้านเดียว
        $this->createRecord($shopA, '2026-06-02', 1000.0, 500.0);
        // 3 มิ.ย. กรอกร้านเดียว (อีกร้าน)
        $this->createRecord($shopB, '2026-06-03', 1500.0, 500.0);

        $data = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data'];

        $this->assertSame(2, $data['summary']['total_shops']);
        $this->assertSame(2, $data['summary']['incomplete_days']);

        $byDate = [];
        foreach ($data['days'] as $row) {
            $byDate[(string)$row['record_date']] = $row;
        }

        $this->assertTrue($byDate['2026-06-01']['is_complete']);
        $this->assertSame(2, $byDate['2026-06-01']['shops_count']);
        $this->assertFalse($byDate['2026-06-02']['is_complete']);
        $this->assertSame(1, $byDate['2026-06-02']['shops_count']);
        $this->assertFalse($byDate['2026-06-03']['is_complete']);
    }

    public function testAverageProfitAndBestWorstDayFromRealData(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // 1 มิ.ย. รายได้สูงสุดของเดือน แต่แอดหนัก → กำไรแค่ 500
        $this->createRecord($shopA, '2026-06-01', 20000.0, 19500.0);
        // 2 มิ.ย. กำไรดีสุด 3000
        $this->createRecord($shopA, '2026-06-02', 2000.0, 500.0);
        $this->createRecord($shopB, '2026-06-02', 3000.0, 1500.0);
        // 3 มิ.ย. ขาดทุนจริง
        $this->createRecord($shopB, '2026-06-03', 500.0, 2000.0);

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        // ยอดรวมยังนับทุกวันตามเดิม: 500 + 3000 - 1500 = 2000 · 3 วัน
        $this->assertSame(2000.0, $summary['profit']);
        $this->assertSame(3, $summary['days_count']);

        // การจัดอันดับและค่าเฉลี่ยนับเฉพาะวันที่ "ทุกร้านที่กำลังติดตามอยู่" กรอกครบ
        //  1 มิ.ย. — ร้าน B ยังไม่เริ่มกรอก (วันแรกของ B คือ 2 มิ.ย.) → ครบสำหรับวันนั้น
        //  2 มิ.ย. — ทั้งสองร้านกรอก → ครบ
        //  3 มิ.ย. — B กรอกฝ่ายเดียวทั้งที่ A เริ่มไปแล้ว → ไม่ครบ
        // (เดิม "วันแย่สุด" คือ 3 มิ.ย. ซึ่งยอดต่ำเพราะกรอกไม่ครบ ไม่ใช่เพราะผลงานแย่)
        $this->assertSame(1, $summary['incomplete_days']);
        $this->assertSame(2, $summary['complete_days_count']);
        $this->assertSame(1750.0, $summary['avg_profit_per_day']);  // (500 + 3000) / 2

        $this->assertSame('2026-06-02', $summary['best_day']['record_date']);
        $this->assertSame(3000.0, $summary['best_day']['profit']);
        $this->assertSame('2026-06-01', $summary['worst_day']['record_date']);
        $this->assertSame(500.0, $summary['worst_day']['profit']);
    }

    public function testAllShopsLoggedEveryDayGivesZeroIncomplete(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-06-01', 1000.0, 100.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 100.0);
        $this->createRecord($shopA, '2026-06-02', 1000.0, 100.0);
        $this->createRecord($shopB, '2026-06-02', 1000.0, 100.0);

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        $this->assertSame(0, $summary['incomplete_days']);
    }

    public function testAnotherUsersRecordsDoNotAffectCompleteness(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopA = $this->createShop($ownerId, 'ร้าน A');
        $shopB = $this->createShop($ownerId, 'ร้าน B');
        $this->createRecord($shopA, '2026-06-01', 1000.0, 100.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 100.0);

        $otherId = $this->createUser('other@example.com');
        $otherShopA = $this->createShop($otherId, 'ร้านคนอื่น A');
        $this->createShop($otherId, 'ร้านคนอื่น B');
        $this->createRecord($otherShopA, '2026-06-01', 90000.0, 0.0);

        $data = $this->makeService()->buildDailyOverview($ownerId, self::MONTH)['data'];

        $this->assertSame(2, $data['summary']['total_shops']);
        $this->assertSame(0, $data['summary']['incomplete_days']);
        $this->assertSame(1800.0, $data['summary']['profit']);   // ไม่รวมร้านคนอื่น
    }

    /**
     * ⭐ เพิ่มร้านใหม่ต้องไม่ทำให้สถิติของประวัติเก่าหายไป
     *
     * is_complete เคยเทียบกับ "จำนวนร้าน ณ ปัจจุบัน" → สร้างร้านที่ 3 วันนี้ แล้ววันในอดีต
     * ที่ 2 ร้านกรอกครบ กลายเป็น "ไม่ครบ" ทั้งหมด → avg/best/worst เป็น null ทุกเดือนย้อนหลัง
     */
    public function testAddingAShopLaterDoesNotInvalidateHistory(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        foreach (['2026-06-01', '2026-06-02'] as $date) {
            $this->createRecord($shopA, $date, 1000.0, 200.0);
            $this->createRecord($shopB, $date, 1000.0, 200.0);
        }

        $before = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];
        $this->assertSame(0, $before['incomplete_days']);
        $this->assertSame(1600.0, $before['avg_profit_per_day']);

        // ร้านที่ 3 เพิ่งสร้างวันนี้ — ไม่เคยมีอยู่ตอน มิ.ย.
        $this->createShop($userId, 'ร้าน C');

        $after = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        $this->assertSame(0, $after['incomplete_days'], 'ร้านใหม่ทำให้วันในอดีตกลายเป็นไม่ครบ');
        $this->assertSame(1600.0, $after['avg_profit_per_day']);
        $this->assertSame('2026-06-01', $after['best_day']['record_date']);
    }

    /**
     * ร้านที่ "เคยกรอกแล้ว" แต่วันนั้นไม่ได้กรอก → ยังนับว่าวันนั้นไม่ครบ
     *
     * เกณฑ์คือ "ร้านที่มีข้อมูลตั้งแต่วันนั้นหรือก่อนหน้า" ไม่ใช่ shops.created_at
     * เพราะร้านที่สร้างวันนี้แล้ว import ประวัติย้อนหลังก็ถือว่าถูกติดตามมาก่อน
     */
    public function testShopThatAlreadyStartedLoggingMakesLaterGapsIncomplete(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // ทั้งสองร้านเริ่มกรอกวันที่ 1
        $this->createRecord($shopA, '2026-06-01', 1000.0, 200.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 200.0);
        // วันที่ 2 ร้าน B ไม่ได้กรอก ทั้งที่เริ่มติดตามไปแล้ว
        $this->createRecord($shopA, '2026-06-02', 1000.0, 200.0);

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        $this->assertSame(1, $summary['incomplete_days']);
        $this->assertSame('2026-06-01', $summary['best_day']['record_date']);
    }

    /** ร้านที่ยังไม่เคยกรอกอะไรเลย ต้องไม่ทำให้วันของร้านอื่นกลายเป็นไม่ครบ */
    public function testShopThatNeverLoggedDoesNotMakeOtherDaysIncomplete(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');
        $this->createShop($userId, 'ร้าน C ที่ยังไม่เคยใช้');

        $this->createRecord($shopA, '2026-06-01', 1000.0, 200.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 200.0);

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['summary'];

        $this->assertSame(0, $summary['incomplete_days']);
    }

    /**
     * ⭐⭐ ตัวหารที่หน้าเว็บโชว์ ต้องเป็นตัวเดียวกับที่ใช้ตัดสินว่า "ครบ" ไหม
     *
     * ⚠️ อาการที่วัดได้จริง: มี 3 ร้าน · ร้าน A กรอกตั้งแต่ 1 ส.ค. · ร้าน B เริ่ม 5 ส.ค. ·
     * ร้าน C ไม่เคยกรอกเลย → คอลัมน์โชว์ **"1/3 ร้าน" โดยไม่มีสัญลักษณ์เตือน**
     * คู่กับสรุปด้านบนที่เขียนว่า **"จาก 7 วันที่กรอกครบทุกร้าน"** บนจอเดียวกัน
     *
     * [เจ้าของระบบตัดสิน 2026-08-07] "ครบ" = ทุกร้านที่เริ่มบันทึกแล้ว — ตัวเลขจึงถูก
     * ที่ผิดคือหน้าเว็บเอา "จำนวนร้านทั้งหมด" มาเป็นตัวหาร ทำให้ขัดกับคำว่าครบ
     */
    public function testTheRowShowsTheSameDenominatorThatDecidesCompleteness(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');
        $this->createShop($userId, 'ร้าน C');   // ไม่เคยกรอกเลย

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->createRecord($shopA, $date, 1000.0, 200.0);
        }
        $this->createRecord($shopB, '2026-06-03', 1000.0, 200.0);

        $rows = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['days'];
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string)$row['record_date']] = $row;
        }

        $this->assertSame(1, $byDate['2026-06-01']['shops_tracked'] ?? null, 'ตัวหารของวันที่ยังมีร้านเดียวผิด');
        $this->assertTrue($byDate['2026-06-01']['is_complete'], 'ควรนับว่าครบตามกติกาที่เจ้าของเลือก');
        $this->assertSame(2, $byDate['2026-06-03']['shops_tracked'] ?? null, 'ตัวหารไม่ขยับตามร้านที่เริ่มบันทึก');
    }

    /** ⚠️ อีกด้าน: ร้านที่เริ่มบันทึกแล้วขาดไปวันหนึ่ง ต้องยังขึ้นว่าไม่ครบ */
    public function testAMissingDayStillCountsAsIncomplete(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-06-01', 1000.0, 200.0);
        $this->createRecord($shopB, '2026-06-01', 1000.0, 200.0);
        $this->createRecord($shopB, '2026-06-02', 1000.0, 200.0);   // ร้าน A ขาดวันที่ 2

        $rows = $this->makeService()->buildDailyOverview($userId, self::MONTH)['data']['days'];
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string)$row['record_date']] = $row;
        }

        $this->assertSame(2, $byDate['2026-06-02']['shops_tracked'] ?? null);
        $this->assertFalse($byDate['2026-06-02']['is_complete'], 'วันที่ขาดร้านหนึ่งไปกลับนับว่าครบ');
    }

    /**
     * ⭐⭐ วันที่ **ไม่มีใครกรอกเลย** ต้องถูกนับด้วย ไม่ใช่หายไปเฉย ๆ แล้วบอกว่า "ครบทุกวัน"
     *
     * ⚠️ query คืนเฉพาะวันที่มี record วันที่ไม่มีใครกรอกจึงไม่โผล่เป็นแถวในตาราง
     * และไม่เคยถูกนับว่า "ไม่ครบ" → แถวรวมเขียนว่า "ครบทุกวัน"
     *
     * วัดจริง: 2 ร้านกรอกครบทั้งคู่แค่ 1–3 มิ.ย. แล้วหยุด (4 มิ.ย. เป็นต้นไปไม่มีใครกรอก)
     * แถวรวมบอก "ครบทุกวัน" ขณะที่แดชบอร์ดบนข้อมูลชุดเดียวกันขึ้นแถบเหลืองว่า
     * "คุณไม่ได้กรอกข้อมูลมา N วันแล้ว" — สองหน้าพูดคนละเรื่องจากข้อมูลชุดเดียวกัน
     */
    public function testDaysNobodyRecordedAreCounted(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $date) {
            $this->createRecord($shopA, $date, 1000.0, 200.0);
            $this->createRecord($shopB, $date, 1000.0, 200.0);
        }

        // เดือน มิ.ย. จบไปแล้ว (today ปักไว้หลังจากนั้น) → ผ่านไปทั้ง 30 วัน กรอกแค่ 3
        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH, '2026-08-07')['data']['summary'];

        $this->assertSame(0, $summary['incomplete_days'], 'วันที่กรอกไว้ครบทั้งสองร้านอยู่แล้ว');
        $this->assertSame(
            27,
            $summary['missing_days'] ?? -1,
            'วันที่ไม่มีใครกรอกเลยหายไปจากการนับ แถวรวมจึงบอกว่า "ครบทุกวัน"'
        );
    }

    /** กรอกครบทุกวันจริง ๆ ต้องไม่มีวันที่ขาด */
    public function testNoMissingDaysWhenEveryElapsedDayIsRecorded(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        // ต้องมี ≥ 2 ร้าน ไม่งั้น can_view เป็น false แล้วไม่มี summary ให้ตรวจ
        $shopB = $this->createShop($userId, 'ร้าน B');

        for ($day = 1; $day <= 30; $day++) {
            $this->createRecord($shopA, sprintf('2026-06-%02d', $day), 1000.0, 200.0);
            $this->createRecord($shopB, sprintf('2026-06-%02d', $day), 1000.0, 200.0);
        }

        $summary = $this->makeService()->buildDailyOverview($userId, self::MONTH, '2026-08-07')['data']['summary'];

        $this->assertSame(0, $summary['missing_days'] ?? -1, 'กรอกครบทุกวันแต่ยังบอกว่ามีวันขาด');
    }
}
