<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

use AnnualService;
use GoalRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐⭐ กติกา "ยังไม่เคยกรอก ≠ ทำได้ ฿0" — ปิดจุดที่เหลือทั้งหมดพร้อมกัน
 *
 * ⚠️⚠️ กติกานี้ถูกแก้มาแล้วหลายรอบและ **ไปไม่ถึงที่เหลือทุกครั้ง** เทสต์นี้จึงรวมทุกจุด
 * ที่ยังเถียงกันอยู่ไว้ในไฟล์เดียว เพื่อให้แก้ครั้งเดียวแล้วเห็นพร้อมกันว่าครบหรือยัง
 *
 * ⚠️⚠️ **ความต่างที่สำคัญที่สุดและพลาดกันบ่อยที่สุด**:
 *   · "ร้านนี้ไม่เคยกรอกอะไรเลย"  → เงียบ (ยังไม่ได้เริ่ม)
 *   · "ปี/เดือนที่เลือกไม่มีข้อมูล" → ตอบ ฿0 (นั่นคือคำตอบของคำถามที่ผู้ใช้ถาม)
 * สองอย่างนี้หน้าตาเหมือนกันมากในโค้ด แต่ตรงข้ามกันสำหรับผู้ใช้
 */
final class EmptyIsNotZeroEverywhereTest extends ControllerTestCase
{
    private function insert(int $shopId, string $date, float $revenue, float $adCost): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'date' => $date, 'revenue' => $revenue, 'ad' => $adCost]);
    }

    /** @return array<string,\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet> */
    private function workbook(string $session, int $yearBe): array
    {
        $response = $this->get('/api/export-xlsx.php?year=' . $yearBe, $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ');

        $path = tempnam(sys_get_temp_dir(), 'emptyzero') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $book = IOFactory::load($path);
            $sheets = [];
            foreach ($book->getSheetNames() as $index => $name) {
                $sheets[$name] = $book->getSheet($index);
            }

            return $sheets;
        } finally {
            @unlink($path);
        }
    }

    /**
     * ⭐⭐ ร้านมีข้อมูลปีหนึ่ง แล้วเลือกดูอีกปี → **ไฟล์ต้องตอบ ฿0 เหมือนหน้าจอ ไม่ใช่ขีด**
     *
     * ⚠️ ไฟล์เคยตัดสินจาก "ปีที่เลือกมีข้อมูลไหม" ซึ่งเป็นคนละคำถามกับ "ร้านนี้เคยเริ่มไหม"
     * → ผู้ใช้เลือกดูปีเก่าแล้วได้ไฟล์ที่ไม่ตอบอะไรเลย ขณะที่หน้าจอตอบ ฿0
     */
    public function testAnEmptyYearOfAnActiveShopStillReportsZeroInTheFile(): void
    {
        $userId = $this->createUser('emptyyear@example.com', 'EmptyYearPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เริ่มปีนี้');
        $this->insert($shopId, '2026-08-01', 5000.55, 3000.25);

        $session = $this->startSession($userId, $shopId);

        // ปี 2568 (2025) ไม่มีข้อมูล — แต่ร้านนี้เริ่มใช้ระบบแล้ว
        $annual = $this->workbook($session, 2568)['รายปี'] ?? $this->fail('ไม่พบชีตรายปี');

        foreach (['A5' => 'กำไรทั้งปี', 'C5' => 'ยอดขาย', 'E5' => 'ค่าแอด'] as $cell => $label) {
            $this->assertSame(
                0.0,
                $annual->getCell($cell)->getValue(),
                "การ์ด \"{$label}\" ของปีที่ไม่มีข้อมูล ต้องเป็น ฿0 (คำตอบของคำถาม) ไม่ใช่ขีด"
            );
        }

        // และหน้าจอต้องพูดแบบเดียวกัน
        $body = $this->get('/annual.php?year=2568', $session)['body'];
        $this->assertStringContainsString(
            '฿0',
            (string)preg_replace('/\s+/u', ' ', strip_tags($body)),
            'หน้าจอของปีที่ไม่มีข้อมูลต้องแสดง ฿0 ด้วย'
        );
    }

    /**
     * ⭐⭐ แถวร้านที่ไม่เคยกรอกในชีตเทียบร้าน — **B ถึง G ต้องว่างทั้งหมด**
     *
     * ⚠️ เดิม B/C/D ว่างแล้ว แต่ **สัดส่วนกำไร (G) ยังเขียน 0%** เพราะกำไร 0 หารด้วย
     * ยอดรวมที่เป็นบวกได้ 0.0 ซึ่งไม่ใช่ null จึงลอดกิ่งเดิมไปได้
     * → แถวเดียวกันในไฟล์ยังใช้กติกาสองแบบ และไม่ตรงกับหน้าจอที่เว้นขีดทั้งแถว
     *
     * ⚠️ คอลัมน์ H ("วันที่กรอก") ยังเป็น 0 ตามนโยบายที่ตัดสินไว้ — มันคือหลักฐาน
     * ว่าทำไมช่องอื่นถึงว่าง ไม่ใช่ผลงาน
     */
    public function testAnUntouchedShopRowIsBlankAcrossEveryMetricColumn(): void
    {
        $userId = $this->createUser('blankrow@example.com', 'BlankRowPass123');
        $activeShop = $this->createShop($userId, 'ร้านที่กรอกแล้ว');
        $this->createShop($userId, 'ร้านที่ไม่เคยกรอก');
        $this->insert($activeShop, '2026-08-01', 9000.75, 4000.25);

        $portfolio = $this->workbook($this->startSession($userId, $activeShop), 2569)['เทียบร้าน']
            ?? $this->fail('ไม่พบชีตเทียบร้าน');

        $targetRow = null;
        foreach ($portfolio->getRowIterator() as $row) {
            $index = $row->getRowIndex();
            if (trim((string)$portfolio->getCell('A' . $index)->getValue()) === 'ร้านที่ไม่เคยกรอก') {
                $targetRow = $index;
            }
        }

        $this->assertNotNull($targetRow, 'ไม่พบแถวของร้านที่ไม่เคยกรอก');

        foreach (['B' => 'ยอดขาย', 'C' => 'ค่าแอด', 'D' => 'กำไร', 'E' => 'ROAS',
                  'F' => 'อัตรากำไร', 'G' => 'สัดส่วนกำไร'] as $column => $label) {
            $this->assertNull(
                $portfolio->getCell($column . $targetRow)->getValue(),
                "ช่อง \"{$label}\" ของร้านที่ไม่เคยกรอก ต้องเว้นว่าง"
            );
        }

        $this->assertSame(
            0,
            $portfolio->getCell('H' . $targetRow)->getValue(),
            'ช่อง "วันที่กรอก" ต้องเป็น 0 ต่อไป — มันคือหลักฐานว่าทำไมช่องอื่นถึงว่าง'
        );
    }

    /**
     * ⭐⭐ เดือนที่ปีนี้ยังไม่ได้กรอก แต่ปีก่อนมีข้อมูล → **ห้ามเขียน −100%**
     *
     * ⚠️ `change_percent(0, ปีก่อน)` ให้ −100 ซึ่งอ่านว่า "ยอดหายไปหมด" ทั้งที่แค่ยังไม่บันทึก
     * · ร้านที่เริ่มใช้ระบบกลางปีจะเห็นครึ่งปีแรกในไฟล์เป็น "ตก 100%" ทุกเดือน
     * · หน้าจอเว้นเป็นขีดอยู่แล้ว
     */
    public function testAMonthNotYetFilledNeverReportsMinusOneHundredPercent(): void
    {
        $userId = $this->createUser('yoyblank@example.com', 'YoyBlankPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เริ่มกลางปี');

        // ปีก่อนกรอก ม.ค. · ปีนี้เริ่ม มี.ค. → ม.ค. ปีนี้ว่างแต่ปีก่อนมี
        $this->insert($shopId, '2025-01-10', 20000.55, 5000.25);
        $this->insert($shopId, '2026-03-10', 15000.45, 4000.15);

        $session = $this->startSession($userId, $shopId);
        $sheet = $this->workbook($session, 2569)['รายเดือน'] ?? $this->fail('ไม่พบชีตรายเดือน');

        $januaryRow = null;
        foreach ($sheet->getRowIterator(2) as $row) {
            $index = $row->getRowIndex();
            if (trim((string)$sheet->getCell('A' . $index)->getValue()) === 'ม.ค.') {
                $januaryRow = $index;
            }
        }

        $this->assertNotNull($januaryRow, 'ไม่พบแถวเดือน ม.ค.');
        $this->assertNull(
            $sheet->getCell('H' . $januaryRow)->getValue(),
            'เดือนที่ปีนี้ยังไม่ได้กรอก ต้องไม่มี % เทียบปีก่อน — ไม่ใช่ −100%'
        );

        // หน้าจอต้องพูดแบบเดียวกัน (แถว ม.ค. ต้องไม่มีตัวเลข % อยู่เลย)
        $body = $this->get('/annual.php?year=2569', $session)['body'];
        $this->assertStringNotContainsString(
            '100.0%',
            (string)preg_replace('/\s+/u', ' ', strip_tags($body)),
            'หน้าจอไม่ควรมี "ตก 100%" ของเดือนที่ยังไม่ได้กรอก'
        );
    }

    /**
     * ⭐⭐⭐ กราฟหน้ารายปี: เดือนที่ยังไม่ได้กรอกต้องเป็น `null` · เดือนที่เท่าทุนต้องเป็น `0.0`
     *
     * ⚠️⚠️ ต้องตรวจ **ทั้งสองทาง** — ถ้าตรวจแค่ทางแรก การ "แก้" ให้ทุกศูนย์กลายเป็น null
     * จะผ่านหน้าตาเฉย แล้วเดือนที่ทำงานจริงจนเท่าทุนจะหายไปจากกราฟ (กลับหัวกับปัญหาเดิม)
     *
     * ⚠️⚠️ และต้องตรวจถึง **JSON ที่ฝังอยู่ในหน้าเว็บจริง** ไม่ใช่แค่ค่าที่ Service คืน —
     * บั๊กเดียวกันนี้เคยเกิดที่ `overview.php`/`dashboard.php`: แก้ที่ Service แล้วกราฟ
     * ยังลากผ่านศูนย์เหมือนเดิม เพราะชั้นหน้าเว็บ `(float)` ทับ null กลับเป็น 0
     */
    public function testTheAnnualChartLeavesGapsForMonthsWithNoDataButKeepsRealZeros(): void
    {
        $userId = $this->createUser('chartgap@example.com', 'ChartGapPass123');
        $shopId = $this->createShop($userId, 'ร้านที่มีเดือนเท่าทุน');

        $this->insert($shopId, '2026-01-10', 10000.50, 2000.25);
        // ก.พ. เท่าทุนพอดี — กรอกจริง ต้องเป็น 0.0 ไม่ใช่ null
        $this->insert($shopId, '2026-02-10', 4000.00, 4000.00);
        // มี.ค. ไม่กรอกเลย → ต้องเป็น null

        $service = new AnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            new GoalRepository($this->pdo)
        );
        $chart = (array)($service->buildYearlySummary($userId, $shopId, 2026, '2026-08-20')['data']['chart'] ?? []);
        $profits = (array)($chart['profit'] ?? []);

        $this->assertArrayHasKey(1, $profits, 'กราฟไม่มีข้อมูลเดือน ก.พ.');
        $this->assertSame(0.0, $profits[1], 'เดือนที่กรอกแล้วเท่าทุนพอดี ต้องเป็น 0.0 ไม่ใช่ช่องว่าง');

        $this->assertArrayHasKey(2, $profits, 'กราฟไม่มีช่องของเดือน มี.ค.');
        $this->assertNull($profits[2], 'เดือนที่ยังไม่ได้กรอกต้องเป็นช่องว่าง ไม่ใช่ ฿0');

        // ── และค่าเดียวกันต้องรอดไปถึงหน้าเว็บจริง
        $body = $this->get('/annual.php?year=2569', $this->startSession($userId, $shopId))['body'];
        $this->assertSame(
            1,
            preg_match('/const chartPayload = (\{.*?\});/s', $body, $matches),
            'อ่าน chartPayload จากหน้าเว็บไม่ได้'
        );

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $onPage = (array)($payload['profit'] ?? []);

        /* ⚠️ JSON เขียน 0.0 เป็น `0` แล้ว `json_decode` คืนเป็นจำนวนเต็ม — เทียบชนิดตรง ๆ
           จะแดงทั้งที่ค่าถูก · สิ่งที่ต้องพิสูจน์คือ "ไม่ใช่ช่องว่าง และเท่ากับศูนย์" */
        $this->assertArrayHasKey(1, $onPage);
        $this->assertNotNull($onPage[1], 'เดือนเท่าทุนหายไปจากกราฟบนหน้าเว็บ');
        $this->assertSame(0.0, (float)$onPage[1], 'เดือนเท่าทุนบนหน้าเว็บไม่ใช่ศูนย์');
        $this->assertArrayHasKey(2, $onPage);
        $this->assertNull(
            $onPage[2],
            'หน้าเว็บ cast null กลับเป็น 0 — ตัวแก้ที่ Service ถูกทับทั้งหมด'
        );
    }

    /**
     * ⭐⭐⭐ มุมรายปีของหน้ารวมร้าน — เดือนที่ยังไม่มีร้านไหนกรอกต้องเป็นช่องว่าง
     *
     * ⚠️⚠️ **ที่สุดท้ายที่ตกสำรวจ** — กติกา "กราฟต้องส่ง null" ลงให้แดชบอร์ด · มุมเดือน ·
     * หน้ารายปี ไปแล้วทั้งหมด แต่มุมรายปีของหน้ารวมร้านสร้างแถวเดือนขึ้นมาครบทุกเดือน
     * ด้วยศูนย์ตั้งแต่ต้น ตัวเลขจึงแยก "ไม่มีใครกรอก" ออกจาก "กรอกแล้วเท่าทุน" ไม่ได้เลย
     *
     * ⚠️ ต้องตรวจถึง JSON ที่ฝังอยู่ในหน้าเว็บจริง — ชั้นหน้าเว็บเคย cast null กลับเป็น 0
     * ทับตัวแก้ที่ Service มาแล้วทุกหน้า
     */
    public function testTheYearlyOverviewChartLeavesGapsForMonthsNobodyFilled(): void
    {
        $userId = $this->createUser('yearchart@example.com', 'YearChartPass123');
        $shopA = $this->createShop($userId, 'ร้านหนึ่ง');
        $shopB = $this->createShop($userId, 'ร้านสอง');

        // ม.ค. มีข้อมูล · ก.พ. กรอกแล้วเท่าทุนพอดี · มี.ค. ไม่มีใครกรอกเลย
        $this->insert($shopA, '2026-01-10', 5000.50, 2000.25);
        $this->insert($shopB, '2026-02-10', 3000.00, 3000.00);

        $session = $this->startSession($userId, $shopA);
        $body = $this->get('/overview.php?view=year&year=2569', $session)['body'];

        // ⚠️ ตัวแปรฝั่ง PHP ชื่อ `$yearChartPayload` แต่ตอนฝังลงหน้าใช้ชื่อ `chartPayload`
        $this->assertSame(
            1,
            preg_match('/const chartPayload = (\{.*?\});/s', $body, $matches),
            'อ่านข้อมูลกราฟของมุมรายปีจากหน้าเว็บไม่ได้'
        );

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $profits = (array)($payload['profit'] ?? []);

        $this->assertArrayHasKey(1, $profits, 'กราฟไม่มีช่องของเดือน ก.พ.');
        $this->assertNotNull($profits[1], 'เดือนที่กรอกแล้วเท่าทุนพอดีต้องเป็น 0 ไม่ใช่ช่องว่าง');
        $this->assertSame(0.0, (float)$profits[1], 'เดือนเท่าทุนต้องเป็นศูนย์');

        $this->assertArrayHasKey(2, $profits, 'กราฟไม่มีช่องของเดือน มี.ค.');
        $this->assertNull($profits[2], 'เดือนที่ยังไม่มีใครกรอกต้องเป็นช่องว่าง ไม่ใช่ ฿0');

        /* ⚠️ ตารางใต้กราฟต้องพูดตรงกัน — กราฟเว้นช่องแต่ตารางพิมพ์ ฿0 คือหน้าเดียวกัน
           บอกสองอย่าง ซึ่งเป็นอาการเดิมของบั๊กคลาสนี้ */
        $tableText = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $body
        )));
        $this->assertStringContainsString(
            'ยังไม่มีข้อมูล',
            $tableText,
            'ตารางรายเดือนไม่ได้ทำเครื่องหมายเดือนที่ยังไม่มีใครกรอก'
        );
    }

    /**
     * ⭐⭐ คอลัมน์ "วันที่กรอก" ของมุมรายปี ต้องตรงกับชีต "เทียบร้าน" ที่เป็นสำเนาของมัน
     *
     * ⚠️ จอเคยเขียนขีดขณะที่ไฟล์เขียน `0` — ไม่ทำให้ยอดเงินผิด แต่ฐาน "จากกี่วัน"
     * ต่างกันระหว่างสองที่ที่ควรเป็นสำเนากัน · และแท็บรายเดือนของหน้าเดียวกัน
     * ก็เขียน "0 วัน" อยู่แล้ว — จอจึงขัดกับตัวเองด้วย
     */
    public function testTheYearlyOverviewCountsDaysTheSameWayAsTheWorkbook(): void
    {
        $userId = $this->createUser('daysparity@example.com', 'DaysParityPass123');
        $activeShop = $this->createShop($userId, 'ร้านที่กรอกแล้ว');
        $this->createShop($userId, 'ร้านที่ไม่เคยกรอก');
        $this->insert($activeShop, '2026-08-01', 9000.75, 4000.25);

        $session = $this->startSession($userId, $activeShop);

        $text = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $this->get('/overview.php?view=year&year=2569', $session)['body']
        )));

        $this->assertStringContainsString(
            '0 วัน',
            $text,
            'ร้านที่ไม่เคยกรอกต้องเขียน "0 วัน" บนจอ เหมือนที่ไฟล์เขียน 0'
        );

        $portfolio = $this->workbook($session, 2569)['เทียบร้าน'] ?? $this->fail('ไม่พบชีตเทียบร้าน');
        $emptyRow = null;
        foreach ($portfolio->getRowIterator() as $row) {
            $index = $row->getRowIndex();
            if (trim((string)$portfolio->getCell('A' . $index)->getValue()) === 'ร้านที่ไม่เคยกรอก') {
                $emptyRow = $index;
            }
        }

        $this->assertNotNull($emptyRow);
        $this->assertSame(
            0,
            $portfolio->getCell('H' . $emptyRow)->getValue(),
            'ไฟล์ต้องเขียน 0 — ถ้าเปลี่ยนข้างนี้ต้องเปลี่ยนหน้าจอด้วย'
        );
    }

    /**
     * ⭐⭐⭐ ปีที่ยังไม่มีใครกรอก **ห้ามรายงานว่า "ตก 100%"** — และห้ามพิมพ์ ฿0 บนจอ
     *
     * ⚠️⚠️ วัดจริง: ปีก่อนกำไร ฿4,000 · ปีนี้ยังไม่มีใครกรอก → หน้าเดียวกันขึ้น
     * "ปี … ยังไม่มีข้อมูลยอดขายของทุกร้าน" คู่กับ "กำไรรวม ↓ 100.0% (-฿4,000)"
     * และการ์ดสามใบที่เขียน ฿0 ขณะที่ไฟล์ Excel เว้นว่างไปแล้ว
     *
     * ⚠️⚠️ ต้องมี **ทางตรงข้าม** ด้วย (เทสต์ถัดไป) — ปีที่กรอกแล้วและเท่าทุนจริง
     * ยังต้องได้ −100% ตามปกติ ไม่งั้นการ "แก้" จะกลบข้อมูลจริงไปด้วย
     */
    public function testAYearNobodyFilledNeverReportsAHundredPercentDrop(): void
    {
        $userId = $this->createUser('yoyempty@example.com', 'YoyEmptyPass123');
        $shopA = $this->createShop($userId, 'ร้านหนึ่ง');
        $this->createShop($userId, 'ร้านสอง');

        // ปีก่อนมีกำไร · ปีนี้ยังไม่มีใครกรอกเลย
        $this->insert($shopA, '2025-06-10', 9000.00, 5000.00);

        $session = $this->startSession($userId, $shopA);
        $text = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $this->get('/overview.php?view=year&year=2569', $session)['body']
        )));

        $this->assertStringContainsString('ยังไม่มีข้อมูล', $text, 'หน้าไม่ได้บอกว่าปีนี้ยังไม่มีข้อมูล');
        $this->assertStringNotContainsString(
            '100.0%',
            $text,
            'ปีที่ยังไม่มีใครกรอก ถูกรายงานว่า "ตก 100%" ทั้งที่แค่ยังไม่ได้เริ่มบันทึก'
        );
        $this->assertStringNotContainsString(
            '฿0',
            $text,
            'ปีที่ยังไม่มีใครกรอก แต่การ์ด/แถวรวมยังพิมพ์ ฿0 — ขัดกับข้อความบนจอเดียวกัน'
        );
    }

    /**
     * ⭐⭐ ทางตรงข้าม: กรอกจริงแล้วเท่าทุนพอดี → **ยังต้องได้ −100%**
     *
     * ⚠️ ถ้าขาดเทสต์ตัวนี้ การ "แก้" ให้เงียบทุกครั้งที่กำไรเป็นศูนย์จะผ่านหน้าตาเฉย
     * แล้วปีที่ทำงานจริงจนเท่าทุนจะกลายเป็น "ไม่มีข้อมูล"
     */
    public function testAYearThatWasFilledAndBrokeEvenStillReportsTheDrop(): void
    {
        $userId = $this->createUser('yoyeven@example.com', 'YoyEvenPass123');
        $shopA = $this->createShop($userId, 'ร้านหนึ่ง');
        $this->createShop($userId, 'ร้านสอง');

        $this->insert($shopA, '2025-06-10', 9000.00, 5000.00);
        // ปีนี้กรอกจริง แต่เท่าทุนพอดี
        $this->insert($shopA, '2026-06-10', 5000.00, 5000.00);

        $session = $this->startSession($userId, $shopA);

        $service = new \OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
        $summary = (array)($service->buildYearlyOverview($userId, 2026, '2026-08-20')['data']['summary'] ?? []);

        $this->assertArrayHasKey('yoy_profit_change_percent', $summary);
        $this->assertSame(
            -100.0,
            $summary['yoy_profit_change_percent'],
            'ปีที่กรอกจริงแล้วเท่าทุน ต้องยังรายงานว่าตก 100% — นั่นคือความจริง'
        );
    }

    /**
     * ⭐⭐⭐ หน้ารวมร้านมุมเดือน: ร้านที่เดือนนี้ยังไม่มี record ห้ามขึ้น ↓100%
     *
     * ⚠️⚠️ หน้าเว็บ *รู้อยู่แล้ว* ว่าแถวนั้นไม่มีข้อมูล (เว้นช่องเงินเป็นขีด) แต่ยังพิมพ์
     * ป้าย ↓100.0% ต่อ — **แถวเดียวพูดสองอย่าง** · กติกาถูกลงให้มุมรายปีไปแล้ว
     * แต่มุมเดือนตกสำรวจ (รูปแบบเดิมของโปรเจกต์นี้)
     */
    public function testAShopWithNoRecordsThisMonthNeverShowsAHundredPercentDrop(): void
    {
        $userId = $this->createUser('monthdrop@example.com', 'MonthDropPass123');
        $shopA = $this->createShop($userId, 'ร้านที่หยุดกรอก');
        $shopB = $this->createShop($userId, 'ร้านที่ยังกรอกอยู่');

        // เดือนก่อนร้าน A มีกำไร · เดือนนี้ไม่กรอกเลย
        $this->insert($shopA, '2026-07-10', 9000.00, 5000.00);
        $this->insert($shopB, '2026-08-10', 3000.00, 1000.00);

        $session = $this->startSession($userId, $shopA);
        $text = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $this->get('/overview.php?month=2026-08', $session)['body']
        )));

        $this->assertStringNotContainsString(
            '100.0%',
            $text,
            'ร้านที่เดือนนี้ยังไม่มี record ถูกรายงานว่า "ตก 100%" ทั้งที่แค่ยังไม่ได้กรอก'
        );
    }

    /**
     * ⭐⭐ payload ดิบของ API รายปี ต้องไม่พก −100% ของเดือนที่ยังไม่มี record
     *
     * ⚠️ หน้าจอและไฟล์เว้นขีดให้แล้ว แต่ `api/annual-data.php` ส่งค่าที่ Service คำนวณ
     * ออกไปตรง ๆ — ใครอ่าน API จะได้ตัวเลขที่หน้าเว็บตั้งใจไม่แสดง
     * · กติกาต้องอยู่ที่ Service ไม่ใช่ให้ผู้แสดงผลแต่ละคนกันเอง
     */
    public function testTheAnnualApiNeverShipsAHundredPercentDropForUnfilledMonths(): void
    {
        $userId = $this->createUser('apidrop@example.com', 'ApiDropPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เริ่มกลางปี');

        $this->insert($shopId, '2025-01-10', 20000.00, 5000.00);
        $this->insert($shopId, '2026-03-10', 15000.00, 4000.00);

        $session = $this->startSession($userId, $shopId);
        $response = $this->getJson('/api/annual-data.php?year=2569', $session);
        $this->assertSame(200, $response['status'], 'เรียก API รายปีไม่สำเร็จ');

        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);

        foreach ((array)($payload['data']['months'] ?? []) as $month) {
            if ((int)($month['days_count'] ?? 0) > 0) {
                continue;
            }

            /* ⚠️⚠️ ห้ามเขียน `?? 'ไม่มีคีย์'` แล้ว assertNull — `??` ถือว่า null คือ "ไม่มีค่า"
               จึงคืนตัวสำรองแทน null ที่กำลังจะตรวจพอดี (พลาดซ้ำมาแล้วหลายรอบ) */
            $this->assertArrayHasKey('yoy_change_percent', $month);
            $this->assertNull(
                $month['yoy_change_percent'],
                'เดือนที่ยังไม่มี record ยังส่ง % เทียบปีก่อนออกไปกับ API'
            );
        }
    }

    /**
     * ⭐⭐ "ปีนี้ยังไม่มีข้อมูล" ต้องไม่ถูกอธิบายว่า "ปีก่อนเท่าทุนพอดี"
     *
     * ⚠️⚠️ % ที่เป็น null มีได้ **3 สาเหตุ** — ปีก่อนไม่มีข้อมูล · ปีก่อนเท่าทุนพอดี ·
     * **ปีนี้ยังไม่มี record** · พอเพิ่มสาเหตุที่ 3 เข้ามาโดยไม่บอกหน้าเว็บ
     * มันจึงพิมพ์คำอธิบายของสาเหตุที่ 2 ทั้งที่ปีก่อนมีกำไรจริง
     * · หลักเดียวกับ `extremes_not_comparable_text()` — ห้ามเดาสาเหตุแทนข้อมูล
     */
    public function testTheReasonForAMissingYoyIsTheRealOne(): void
    {
        $userId = $this->createUser('yoyreason@example.com', 'YoyReasonPass123');
        $shopId = $this->createShop($userId, 'ร้านที่หยุดไปทั้งปี');

        // ปีก่อนมีกำไรจริง ๆ (ไม่ใช่เท่าทุน) · ปีนี้ยังไม่กรอกเลย
        $this->insert($shopId, '2025-06-10', 9000.00, 5000.00);

        $session = $this->startSession($userId, $shopId);
        $text = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $this->get('/annual.php?year=2569', $session)['body']
        )));

        $this->assertStringNotContainsString(
            'ปีก่อนเท่าทุนพอดี',
            $text,
            'บอกว่า "ปีก่อนเท่าทุนพอดี" ทั้งที่ปีก่อนมีกำไรจริง — สาเหตุจริงคือปีนี้ยังไม่มีข้อมูล'
        );
        $this->assertStringContainsString(
            'ปีนี้ยังไม่มีข้อมูล',
            $text,
            'ไม่ได้บอกสาเหตุจริงว่าปีนี้ยังไม่มีข้อมูล'
        );
    }

    /**
     * ⭐⭐⭐ **หน้ารวมร้านมุมรายปี** ก็ต้องบอกสาเหตุจริงของ % ที่หายไป
     *
     * ⚠️⚠️ เทสต์ตัวก่อนหน้าคุมเฉพาะ `annual.php` — พอแก้ `overview.php` ผิด
     * (อ่านค่าจากสรุปก่อนที่สรุปจะถูกโหลด ค่าจึงเป็น "มีข้อมูล" เสมอ) **ตาข่ายทั้งชุดยังเขียว**
     * · รูปแบบเดิมของโปรเจกต์นี้: กติกาถูกบังคับใช้ที่หนึ่งแต่ไปไม่ถึงอีกที่หนึ่ง
     *   และตาข่ายก็ครอบแค่ที่เดียวเหมือนกัน
     */
    public function testTheOverviewYearAlsoNamesTheRealReasonForAMissingYoy(): void
    {
        $userId = $this->createUser('overviewreason@example.com', 'OverviewReasonPass123');
        $shopA = $this->createShop($userId, 'ร้านที่หยุดไปทั้งปี');
        $this->createShop($userId, 'ร้านที่สอง');

        // ปีก่อนมีกำไรจริง (ไม่ใช่เท่าทุน) · ปีนี้ยังไม่มีใครกรอกเลย
        $this->insert($shopA, '2025-06-10', 9000.00, 5000.00);

        $session = $this->startSession($userId, $shopA);
        $text = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $this->get('/overview.php?view=year&year=2569', $session)['body']
        )));

        $this->assertStringNotContainsString(
            'ปีก่อนเท่าทุนพอดี',
            $text,
            'บอกว่า "ปีก่อนเท่าทุนพอดี" ทั้งที่ปีก่อนมีกำไรจริง — สาเหตุจริงคือปีนี้ยังไม่มีข้อมูล'
        );
        $this->assertStringContainsString(
            'ปีนี้ยังไม่มีข้อมูล',
            $text,
            'หน้ารวมร้านไม่ได้บอกสาเหตุจริงว่าปีนี้ยังไม่มีข้อมูล'
        );
    }

    /**
     * อ่านแถวของร้านหนึ่งจากตาราง "เปรียบเทียบระหว่างร้าน" ของหน้ารวมร้าน
     *
     * ⚠️ หน้านี้มีหลายตาราง — เลือกตัวที่หัวตารางมีทั้ง "อันดับ" และ "ร้าน"
     * (ตารางรายวันมีหัว "วันที่" ไม่ใช่ "อันดับ")
     *
     * @return array<string,string> หัวคอลัมน์ => ข้อความในช่อง
     */
    private function shopRowOnOverview(string $session, string $url, string $shopName): array
    {
        $response = $this->get($url, $session);
        $this->assertSame(200, $response['status'], 'เปิดหน้ารวมร้านไม่สำเร็จ: ' . $url);

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $response['body']);
        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//table') as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }

            $headings = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headings[] = trim((string)preg_replace('/\s+/u', ' ', $th->textContent));
            }

            if (!in_array('อันดับ', $headings, true) || !in_array('ร้าน', $headings, true)) {
                continue;
            }

            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $node) {
                    if ($node->nodeName === 'td') {
                        $cells[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
                    }
                }

                if (count($cells) < 3 || ($cells[1] ?? '') !== $shopName) {
                    continue;
                }

                $row = [];
                foreach ($headings as $index => $heading) {
                    if (isset($cells[$index])) {
                        $row[$heading] = $cells[$index];
                    }
                }

                return $row;
            }
        }

        $this->fail('ไม่พบแถวของร้าน "' . $shopName . '" ในตารางเทียบร้านของ ' . $url);
    }

    /**
     * ⭐⭐⭐ ทั้งสามแท็บของหน้ารวมร้านต้องพูดตรงกัน — ร้านที่ยังไม่ได้กรอกในช่วงนั้น
     * ต้องเป็น **ขีดทั้งแถว** ไม่ใช่ ฿0
     *
     * ⚠️⚠️ แท็บ **รายวัน** เคยตกสำรวจอยู่แท็บเดียว: ROAS กับอัตรากำไรตอบ `–` ถูกแล้ว
     * แต่ยอดขาย/ค่าแอด/กำไร พิมพ์ ฿0 และกำไรยังเป็น **สีเขียว** ด้วย
     * · กดสลับแท็บเฉย ๆ ร้านเดียวกันเปลี่ยนจาก "ยังไม่รู้" เป็น "เท่าทุน"
     * · ตารางนี้คือเครื่องมือตัดสินว่าร้านไหนคุ้ม — ร้านที่ยังไม่กรอกจะดูดีกว่าร้านที่ขาดทุนจริง
     *
     * ⚠️ ต้องไล่ **ทั้งสามแท็บในเทสต์เดียว** ไม่ใช่เขียนเฉพาะแท็บที่เพิ่งแก้ — รูปแบบเดิม
     * ของโปรเจกต์นี้คือตาข่ายครอบแค่ที่ที่เพิ่งแก้ แล้วอีกที่พังเงียบ
     */
    public function testEveryOverviewTabBlanksAShopThatRecordedNothing(): void
    {
        $userId = $this->createUser('tabsagree@example.com', 'TabsAgreePass123');
        $active = $this->createShop($userId, 'ร้านที่กรอกแล้ว');
        $idle = 'ร้านที่ยังไม่ได้กรอก';
        $this->createShop($userId, $idle);

        // ร้านแรกกรอกจริงในเดือน/ปีที่กำลังดู · อีกร้านไม่เคยกรอกอะไรเลย
        $this->insert($active, '2026-08-01', 9000.75, 4000.25);

        $session = $this->startSession($userId, $active);

        $tabs = [
            'รายวัน' => '/overview.php?view=day&month=2026-08',
            'รายเดือน' => '/overview.php?view=month&month=2026-08',
            'รายปี' => '/overview.php?view=year&year=2569',
        ];

        foreach ($tabs as $tabName => $url) {
            $row = $this->shopRowOnOverview($session, $url, $idle);

            foreach (['ยอดขาย', 'ค่าแอด', 'กำไร'] as $column) {
                $this->assertArrayHasKey($column, $row, "แท็บ {$tabName} ไม่มีคอลัมน์ \"{$column}\"");
                $this->assertSame(
                    no_value_text(),
                    $row[$column],
                    "แท็บ {$tabName}: ช่อง \"{$column}\" ของร้านที่ยังไม่ได้กรอก ต้องเป็นขีด ไม่ใช่ \"{$row[$column]}\""
                );
            }

            // ⚠️ คอลัมน์นี้คือ *หลักฐาน* ว่าทำไมทั้งแถวถึงเป็นขีด — ถ้าไม่มี ผู้ใช้ไม่มีทางรู้
            $this->assertArrayHasKey(
                'วันที่กรอก',
                $row,
                "แท็บ {$tabName} ไม่มีคอลัมน์ \"วันที่กรอก\" ที่อธิบายว่าทำไมทั้งแถวเป็นขีด"
            );
            $this->assertSame('0 วัน', $row['วันที่กรอก'], "แท็บ {$tabName}: ช่อง \"วันที่กรอก\" ต้องเป็น 0 วัน");
        }
    }
}
