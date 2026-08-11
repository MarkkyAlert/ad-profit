<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * ⭐⭐ ไฟล์ CSV ที่ดาวน์โหลดไป ต้อง **อ่านกลับได้ตรงทุกตัวอักษร** แม้โน้ตจะมีจุลภาค/ขึ้นบรรทัด
 *
 * ⚠️⚠️ **ตาข่ายเดิมพิสูจน์เรื่องนี้ไม่ได้เลย** — helper ของเทสต์ที่มีอยู่แยกไฟล์ด้วย
 * `explode("\n")` ก่อนแล้วค่อย parse ทีละบรรทัด · โน้ตที่มีขึ้นบรรทัดใหม่อยู่ข้างในเครื่องหมาย
 * คำพูดจะถูกหั่นกลาง แล้วเทสต์จะอ่านเป็นสองแถวโดยไม่มีอะไรฟ้อง
 * · และ fixture เดิมไม่เคยมีโน้ตที่มีจุลภาคหรือขึ้นบรรทัดเลย จึงไม่มีอะไรให้หั่นตั้งแต่แรก
 *
 * ⚠️ โปรเจกต์นี้ระบุไว้ว่าโน้ตขึ้นบรรทัดใหม่ได้จริง (คู่มืออธิบายว่าทำไมต้องนับ "แถวที่ N"
 * ไม่ใช่ "บรรทัดที่ N" ตอน import) — ทางออกจึงต้องรองรับเหมือนกัน
 *
 * ⚠️⚠️ เทสต์นี้อ่านด้วย `fgetcsv()` จาก stream จริง ซึ่งเป็นตัวเดียวกับที่ Excel/LibreOffice
 * ใช้ตีความ ไม่ใช่การหั่นบรรทัดเอง
 */
final class CsvArtifactRoundTripTest extends ControllerTestCase
{
    /** โน้ตที่ตั้งใจให้ทำลาย parser ที่หั่นบรรทัดเอง */
    private const TRICKY_NOTES = [
        '2026-08-01' => 'โปร,ใหม่',
        '2026-08-02' => "บรรทัดแรก\nบรรทัดสอง",
        '2026-08-03' => 'ลูกค้าบอกว่า "คุ้มมาก"',
        '2026-08-04' => "ครบทุกแบบ: จุลภาค, \"คำพูด\"\nและขึ้นบรรทัด",
    ];

    private function seed(): array
    {
        $userId = $this->createUser('csv@example.com', 'CsvPass123');
        $shopId = $this->createShop($userId, 'ร้านโน้ตยาก');

        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, 3000, 2000, :note, NOW(), NOW())'
        );
        foreach (self::TRICKY_NOTES as $date => $note) {
            $statement->execute(['shop' => $shopId, 'date' => $date, 'note' => $note]);
        }

        return [$userId, $shopId];
    }

    /**
     * อ่านไฟล์ด้วย `fgetcsv()` จาก stream — ไม่หั่นบรรทัดเอง
     *
     * @return list<list<string>>
     */
    private function parseWithRealCsvReader(string $body): array
    {
        $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $body);

        $handle = fopen('php://temp', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }

            $rows[] = array_map(static fn($cell): string => (string)$cell, $cells);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * ⭐ โน้ตทุกแบบต้องกลับมาเหมือนเดิมทุกตัวอักษร และจำนวนแถวต้องไม่บานปลาย
     */
    public function testTrickyNotesSurviveTheDownloadUnchanged(): void
    {
        [$userId, $shopId] = $this->seed();
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/api/export.php?month=2026-08', $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลด CSV ไม่สำเร็จ');

        $rows = $this->parseWithRealCsvReader($response['body']);
        $headings = array_shift($rows);
        $noteIndex = array_search('โน้ต', $headings, true);
        $dateIndex = array_search('วันที่', $headings, true);
        $this->assertIsInt($noteIndex, 'ไม่พบคอลัมน์โน้ต');
        $this->assertIsInt($dateIndex, 'ไม่พบคอลัมน์วันที่');

        $byDate = [];
        foreach ($rows as $cells) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cells[$dateIndex] ?? '') === 1) {
                $byDate[$cells[$dateIndex]] = $cells;
            }
        }

        $this->assertCount(
            count(self::TRICKY_NOTES),
            $byDate,
            'จำนวนแถวข้อมูลไม่ตรง — โน้ตที่ขึ้นบรรทัดใหม่น่าจะถูกหั่นเป็นหลายแถว'
        );

        foreach (self::TRICKY_NOTES as $date => $note) {
            $this->assertArrayHasKey($date, $byDate, "แถววันที่ {$date} หายไปจากไฟล์");
            $this->assertSame(
                $note,
                $byDate[$date][$noteIndex] ?? '',
                "โน้ตของวันที่ {$date} เปลี่ยนไประหว่างเขียนไฟล์"
            );
            $this->assertCount(
                count($headings),
                $byDate[$date],
                "แถววันที่ {$date} มีจำนวนช่องไม่ตรงกับหัวตาราง — ตัวคั่นรั่วออกมา"
            );
        }
    }

    /**
     * ⭐ โน้ตที่ขึ้นต้นด้วยอักขระสูตร **และ** มีจุลภาค ต้องถูกทำให้ปลอดภัยโดยไม่เสียเนื้อหา
     *
     * ⚠️ สองกติกานี้ทำงานพร้อมกันได้ — ตัวกันสูตรเติม `'` นำหน้า ส่วนตัวคั่นถูกคุมด้วย
     * เครื่องหมายคำพูดของ CSV · เทสต์เดิมทดสอบทีละอย่าง ไม่เคยรวมกัน
     */
    public function testAFormulaNoteWithCommasStaysBothSafeAndIntact(): void
    {
        $userId = $this->createUser('formula@example.com', 'FormulaPass123');
        $shopId = $this->createShop($userId, 'ร้านโน้ตอันตราย');

        $note = '=SUM(A1,A2),ยอดรวม';
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, \'2026-08-05\', 1000, 500, :note, NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'note' => $note]);

        $response = $this->get('/api/export.php?month=2026-08', $this->startSession($userId, $shopId));
        $rows = $this->parseWithRealCsvReader($response['body']);
        $headings = array_shift($rows);
        $noteIndex = (int)array_search('โน้ต', $headings, true);

        $found = null;
        foreach ($rows as $cells) {
            if (($cells[0] ?? '') === '2026-08-05') {
                $found = $cells[$noteIndex] ?? '';
            }
        }

        $this->assertNotNull($found, 'ไม่พบแถวของโน้ตอันตราย');
        $this->assertStringStartsNotWith('=', $found, 'โน้ตยังขึ้นต้นด้วย = — Excel จะตีความเป็นสูตร');
        $this->assertStringContainsString('ยอดรวม', $found, 'เนื้อหาหลังจุลภาคหายไป — ตัวคั่นรั่ว');
        $this->assertStringContainsString('SUM(A1,A2)', $found, 'เนื้อหาเดิมของโน้ตถูกตัดทิ้ง');
    }

    /**
     * ⭐⭐⭐ **โหลดไฟล์ออกไป แล้วนำเข้ากลับผ่านแอปจริง ต้องได้ข้อมูลเดิมเป๊ะ**
     *
     * ⚠️⚠️ เทสต์อีกสองตัวในไฟล์นี้ตรวจแค่ว่า **ไฟล์ที่ดาวน์โหลดอ่านกลับได้** ด้วยตัวอ่าน CSV
     * — ไม่มีตัวไหน **นำเข้ากลับผ่าน endpoint จริง** เลย · ชื่อไฟล์บอกว่า "round trip"
     * แต่ครึ่งหลังของวงกลมไม่เคยถูกเดิน
     *
     * ทางนี้ข้าม **สองระบบที่เขียนคนละที่**: ตัวเขียนไฟล์ (`ExportService` + `api/export.php`)
     * กับตัวอ่านไฟล์ (`RecordService::parseImportCsv`) ซึ่งเป็นรูปแบบที่โปรเจกต์นี้พังซ้ำ
     * ที่สุด — "กติกาถูกบังคับใช้ที่หนึ่งแต่ไปไม่ถึงอีกที่หนึ่ง"
     *
     * สิ่งที่ต้องรอดข้ามวงกลม (ทุกอย่างเป็นกติกาที่เขียนไว้คนละฝั่ง):
     *   · BOM ที่ตัวเขียนใส่ไว้ → ตัวอ่านต้องตัดทิ้ง
     *   · `'` ที่ตัวเขียนเติมกันสูตร Excel → ตัวอ่านต้องถอดออกให้พอดี
     *   · คอลัมน์ที่คำนวณเอง (กำไร/ROAS/เทียบครั้งก่อน) → ตัวอ่านต้องเพิกเฉย
     *   · แถว "รวม" ท้ายตาราง + บรรทัดว่างก่อนหน้า → ตัวอ่านต้องข้าม
     *   · โน้ตที่มีจุลภาค/ขึ้นบรรทัด/เครื่องหมายคำพูด → ต้องกลับมาครบทุกตัวอักษร
     *
     * ⚠️ นำเข้าลง **อีกร้าน** เพื่อให้เทียบได้ว่า "ข้อมูลที่ไปถึงปลายทาง" ตรงกับต้นทางจริง
     * ไม่ใช่แค่ทับของเดิมแล้วดูเหมือนไม่มีอะไรเปลี่ยน
     */
    public function testTheDownloadedFileImportsBackWithoutLosingAnything(): void
    {
        $userId = $this->createUser('roundtrip@example.com', 'RoundPass123');
        $source = $this->createShop($userId, 'ร้านต้นทาง');
        $target = $this->createShop($userId, 'ร้านปลายทาง');

        /* ⚠️ ต้องมีทุกสภาพที่ทำให้สองฝั่งเถียงกันได้ — ยอดมีเศษสตางค์ · ค่าแอด ฿0
           (ROAS ว่าง) · ยอด ฿0 ทั้งคู่ · โน้ตว่าง · โน้ตขึ้นต้นด้วยอักขระสูตร ·
           โน้ตที่มีจุลภาค/ขึ้นบรรทัด/เครื่องหมายคำพูด · และวันที่ไม่ติดกัน */
        $rows = [
            ['2026-04-01', '5000.55', '1200.25', 'เปิดตัว'],
            ['2026-04-02', '6200.00', '1500.00', 'โน้ตมี, จุลภาค'],
            ['2026-04-03', '0.00', '0.00', "หลาย\nบรรทัด"],
            ['2026-04-05', '9876.54', '0.00', '=SUM(A1)'],
            ['2026-04-08', '1234.56', '9999.99', 'เขาว่า "ดี" มาก'],
            ['2026-04-10', '7777.77', '2222.22', ''],
        ];

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:s, :d, :r, :a, :n, NOW(), NOW())'
        );
        foreach ($rows as [$date, $revenue, $adCost, $note]) {
            $insert->execute(['s' => $source, 'd' => $date, 'r' => $revenue, 'a' => $adCost, 'n' => $note]);
        }

        // ── ครึ่งแรกของวงกลม: โหลดไฟล์จากหน้าประวัติ
        $download = $this->get('/api/export.php?month=2026-04', $this->startSession($userId, $source));
        $this->assertSame(200, $download['status'], 'ดาวน์โหลด CSV ไม่สำเร็จ');

        // ── ครึ่งหลัง: นำเข้ากลับผ่าน endpoint จริง ลงอีกร้าน
        $targetSession = $this->startSession($userId, $target);
        $import = $this->postFile(
            '/api/records.php',
            [
                'action' => 'import_csv',
                'csrf_token' => $this->csrfTokenFor($targetSession),
                'shop_context_id' => (string)$target,
            ],
            'csv',
            'กลับเข้า.csv',
            (string)$download['body'],
            $targetSession
        );

        $this->assertSame(302, $import['status'], (string)$import['body']);

        $read = $this->pdo->prepare(
            'SELECT record_date, revenue, ad_cost, note FROM daily_records
             WHERE shop_id = :s ORDER BY record_date'
        );

        $keyed = static function (array $list): array {
            $map = [];
            foreach ($list as $row) {
                $map[(string)$row['record_date']] = [
                    'revenue' => (string)$row['revenue'],
                    'ad_cost' => (string)$row['ad_cost'],
                    'note' => (string)$row['note'],
                ];
            }

            return $map;
        };

        $read->execute(['s' => $source]);
        $before = $keyed($read->fetchAll());
        $read->execute(['s' => $target]);
        $after = $keyed($read->fetchAll());

        /* ⚠️ เทียบทั้งก้อนทีเดียว ไม่ใช่ไล่ทีละช่องแล้ว `continue` เมื่อไม่เจอ —
           แถวที่ **หายไป** กับแถวที่ **โผล่มาเกิน** ต้องทำให้เทสต์แดงทั้งคู่
           (เทสต์ที่ข้ามแถวที่ฝั่งหนึ่งไม่มี คือเทสต์ที่ข้ามบั๊กชนิดที่ตั้งใจจับพอดี) */
        $this->assertSame(
            $before,
            $after,
            'ข้อมูลหลังนำเข้ากลับไม่ตรงกับต้นทาง — ตัวเขียนไฟล์กับตัวอ่านไฟล์ตีความไม่ตรงกัน'
        );

        // ยืนยันว่าฉากทดสอบ "ยาก" จริง ไม่ใช่ผ่านเพราะข้อมูลง่ายเกินไป
        $this->assertCount(count($rows), $before, 'ข้อมูลต้นทางไม่ครบตั้งแต่แรก');
        $this->assertStringContainsString("\n", $before['2026-04-03']['note'], 'ไม่มีโน้ตที่ขึ้นบรรทัดใหม่ในฉากทดสอบ');
        $this->assertStringStartsWith('=', $before['2026-04-05']['note'], 'ไม่มีโน้ตที่ขึ้นต้นด้วยอักขระสูตร');
    }
}
