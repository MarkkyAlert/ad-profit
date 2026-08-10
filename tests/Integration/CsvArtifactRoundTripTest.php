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
}
