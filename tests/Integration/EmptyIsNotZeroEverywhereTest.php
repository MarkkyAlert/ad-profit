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
}
