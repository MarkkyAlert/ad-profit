<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * unit test ของ RecordService::parseImportCsv (pure — ไม่แตะ DB)
 */
final class RecordServiceImportCsvTest extends TestCase
{
    /**
     * ⭐ จุลภาคเป็น "ทศนิยม" ต้องไม่ถูกลบทิ้งเหมือนตัวคั่นหลักพัน
     *
     * ไฟล์ที่ออกจาก Excel ของยุโรป (เยอรมัน/ฝรั่งเศส/ไทยบางเครื่อง) ใช้ `1234,56`
     * เดิมระบบลบจุลภาคทิ้งเสมอ → 1,234.56 บาท กลายเป็น 123,456 บาท (100 เท่า)
     * แล้วนำเข้าสำเร็จโดยไม่มีคำเตือน
     *
     * @return array<string,array{0:string,1:string}> ชื่อเคส => [ค่าที่พิมพ์, ค่าที่ต้องได้]
     */
    public static function amountFormatProvider(): array
    {
        return [
            'จุดเป็นทศนิยม' => ['1234.56', '1234.56'],
            'จุลภาคคั่นหลักพัน' => ['1,234.56', '1234.56'],
            'จุลภาคเป็นทศนิยม' => ['1234,56', '1234.56'],
            'จุลภาคเป็นทศนิยม 1 ตำแหน่ง' => ['1234,5', '1234.5'],
            'ยุโรปเต็มรูปแบบ' => ['1.234,56', '1234.56'],
            'อังกฤษเต็มรูปแบบ' => ['1,234,567.89', '1234567.89'],
            'เว้นวรรคคั่นหลักพัน + จุลภาคทศนิยม' => ['1 234,56', '1234.56'],
            'มีสัญลักษณ์เงิน' => ['฿1,234.56', '1234.56'],
            // ⚠️ `1,234` ถูกย้ายไปอยู่ในกลุ่ม "กำกวม" แล้ว (ดู MoneyInputParsingTest)
            // เพราะอ่านได้ทั้ง 1234 (คั่นหลักพัน) และ 1.234 (ทศนิยม 3 ตำแหน่ง)
            'ไม่มีตัวคั่นเลย' => ['1234', '1234'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('amountFormatProvider')]
    public function testAmountFormatsAreReadCorrectly(string $typed, string $expected): void
    {
        $result = $this->makeService()->parseImportCsv("date,revenue,ad_cost\n2026-08-01,\"{$typed}\",100\n");

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame($expected, (string)$result['rows'][0]['revenue'], "ค่าที่พิมพ์: {$typed}");
    }

    private function makeService(): RecordService
    {
        return new RecordService(
            $this->createStub(RecordRepository::class),
            $this->createStub(ShopRepository::class),
            null
        );
    }

    /** service ที่ผ่าน ownership check — ใช้เฉพาะเคสที่ต่อท่อไปถึง upsertManyRecords */
    private function makeSavingService(): RecordService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);

        return new RecordService($this->createStub(RecordRepository::class), $shopRepository, null);
    }

    /**
     * สร้าง CSV จาก array ด้วย fputcsv (quoting ถูกต้องเหมือนไฟล์จริง)
     *
     * @param array<int,array<int,string>> $lines
     */
    private function toCsv(array $lines, bool $withBom = false): string
    {
        $handle = fopen('php://memory', 'r+');
        if ($withBom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }
        foreach ($lines as $line) {
            fputcsv($handle, $line, ',', '"', '');
        }
        rewind($handle);
        $content = (string)stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    public function testParsesThaiHeaderCsv(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด', 'โน้ต'],
            ['2026-08-02', '1000', '200', 'คอร์ส A'],
            ['2026-08-03', '1500.50', '0', ''],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('2026-08-02', $result['rows'][0]['record_date']);
        $this->assertSame('1000', $result['rows'][0]['revenue']);
        $this->assertSame('200', $result['rows'][0]['ad_cost']);
        $this->assertSame('คอร์ส A', $result['rows'][0]['note']);
        $this->assertNull($result['rows'][1]['note']);   // โน้ตว่าง → null
    }

    public function testParsesEnglishHeaderCsv(): void
    {
        $csv = $this->toCsv([
            ['date', 'revenue', 'ad_cost', 'note'],
            ['2026-08-02', '1000', '200', 'x'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertSame('2026-08-02', $result['rows'][0]['record_date']);
    }

    public function testRoundTripsCurrentExportFormat(): void
    {
        // รูปแบบ export ปัจจุบัน: BOM · วันที่ ISO · เซลล์ว่างแทน '–' ·
        // sanitize เฉพาะโน้ต · แถวว่างคั่นก่อนแถวรวม
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบครั้งก่อน', 'โน้ต'],
            ['2026-08-02', '1000.00', '200.00', '800.00', '5.00', '', 'คอร์ส A'],
            ['2026-08-03', '1500.50', '0.00', '1500.50', '', '+50.0%', "'=SUM(A1:A9)"],
            ['2026-08-04', '100.00', '800.00', '-700.00', '0.13', '-11.1%', ''],
            [''],                                   // แถวว่างคั่น
            ['รวม', '2600.50', '1000.00', '1600.50', '2.60', '', ''],
        ], true);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['rows']);      // แถวว่าง + "รวม" ถูกข้าม
        $this->assertSame(
            ['2026-08-02', '2026-08-03', '2026-08-04'],
            array_column($result['rows'], 'record_date')
        );
        $this->assertSame('1500.50', $result['rows'][1]['revenue']);
        // แถวขาดทุน: import อ่านเฉพาะรายได้/ค่าแอด (คอลัมน์กำไรเป็นค่าที่คำนวณ ถูกเพิกเฉย)
        $this->assertSame('100.00', $result['rows'][2]['revenue']);
        $this->assertSame('800.00', $result['rows'][2]['ad_cost']);
        // โน้ตที่ถูก guard ตอน export → ถอด ' ออกได้เนื้อความเดิม
        $this->assertSame('=SUM(A1:A9)', $result['rows'][1]['note']);
        $this->assertNull($result['rows'][2]['note']);
    }

    public function testRoundTripsLegacyThaiDateExportFile(): void
    {
        // ไฟล์รูปแบบเก่า (วันที่ไทย + '–' + หัวคอลัมน์ "เทียบเมื่อวาน" ที่เปลี่ยนชื่อไปแล้ว)
        // export ไม่ผลิตแล้ว แต่ import ต้องยังอ่านได้ — อย่า "แก้ให้ตรงของใหม่" หัวเก่าคือประเด็นของเทสต์นี้
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบเมื่อวาน', 'โน้ต'],
            ['2 ส.ค. 2569', '1000.00', '200.00', '800.00', '5.00', '–', 'คอร์ส A'],
            ['3 ส.ค. 2569', '1500.50', '0.00', '1500.50', '–', '+50.0%', "'-ขึ้นต้นด้วยลบ"],
            ['รวม', '2500.50', '200.00', '2300.50', '5.00', '–', '–'],
        ], true);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['rows']);            // แถว "รวม" ถูกข้าม
        $this->assertSame('2026-08-02', $result['rows'][0]['record_date']);  // วันที่ไทย + พ.ศ.
        $this->assertSame('2026-08-03', $result['rows'][1]['record_date']);
        $this->assertSame('1500.50', $result['rows'][1]['revenue']);
        $this->assertSame('-ขึ้นต้นด้วยลบ', $result['rows'][1]['note']);      // ถอด leading '
    }

    public function testParsesVariousDateFormats(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-08-02', '1', '0'],       // ISO
            ['13/08/2026', '1', '0'],       // DD/MM/YYYY — วัน > 12 จึงไม่กำกวม
            ['25/8/2026', '1', '0'],        // D/M/YYYY — วัน > 12 จึงไม่กำกวม
            ['25/08/2569', '1', '0'],       // พ.ศ. — วัน > 12 จึงไม่กำกวม
            ['6 ส.ค. 2569', '1', '0'],      // ไทย — ระบุเดือนด้วยชื่อ ไม่มีทางกำกวม
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['2026-08-02', '2026-08-13', '2026-08-25', '2026-08-25', '2026-08-06'],
            array_column($result['rows'], 'record_date')
        );
    }

    public function testCleansNumbersWithCommaBahtAndLeadingQuote(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-08-02', '1,500.50', '฿300'],
            ['2026-08-03', "'2000", '1 000'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertSame('1500.50', $result['rows'][0]['revenue']);
        $this->assertSame('300', $result['rows'][0]['ad_cost']);
        $this->assertSame('2000', $result['rows'][1]['revenue']);
        $this->assertSame('1000', $result['rows'][1]['ad_cost']);
    }

    public function testMissingRequiredColumnFails(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้'],       // ขาด ค่าแอด
            ['2026-08-02', '1000'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ค่าแอด', $result['error']);
        $this->assertSame([], $result['rows']);
    }

    public function testUnparsableDateReportsLineNumber(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-08-02', '1000', '200'],
            ['เมื่อวานนี้', '1000', '200'],   // แถวที่ 3
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 3', $result['error']);
    }

    public function testImpossibleDateIsRejected(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-02-31', '1000', '200'],   // 31 ก.พ. ไม่มีจริง
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', $result['error']);
    }

    public function testNonNumericRevenueReportsLineNumber(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-08-02', 'มากมาย', '200'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('แถวที่ 2', $result['error']);
    }

    public function testEmptyFileFails(): void
    {
        $result = $this->makeService()->parseImportCsv('   ');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ว่าง', $result['error']);
    }

    public function testHeaderOnlyFileFails(): void
    {
        $csv = $this->toCsv([['วันที่', 'รายได้', 'ค่าแอด']]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่พบแถวข้อมูล', $result['error']);
    }

    public function testBlankLinesAreSkipped(): void
    {
        $csv = "วันที่,รายได้,ค่าแอด\n2026-08-02,1000,200\n\n\n2026-08-03,500,100\n\n";

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['rows']);
    }

    public function testQuotedFieldWithCommaIsPreserved(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด', 'โน้ต'],
            ['2026-08-02', '1000', '200', 'ยิง FB, TikTok'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertSame('ยิง FB, TikTok', $result['rows'][0]['note']);
    }

    // ── Batch 2: ข้อที่พบจาก logic review ──────────────────────────────────

    /**
     * ช่องตัวเลขว่าง = 0 (ตัดสินแล้ว) — วันที่ไม่ได้ยิงแอดคือเรื่องปกติ
     *
     * เดิม parser ปล่อยค่าว่างผ่านเป็น '' แล้ว upsertManyRecords ปฏิเสธ
     * → ทั้งไฟล์ไม่ถูกนำเข้า โดยข้อความไม่ได้บอกว่าปัญหาคือช่องว่าง
     */
    public function testEmptyNumericCellBecomesZero(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-08-01,1000,\n");

        $this->assertTrue($result['success']);
        $this->assertSame('1000', $result['rows'][0]['revenue']);
        $this->assertSame('0', $result['rows'][0]['ad_cost']);
    }

    /** ต่อท่อสองชั้นจริง — เคยพังตรงรอยต่อนี้เพราะแต่ละชั้นถูกเทสต์แยกกัน */
    public function testEmptyNumericCellSurvivesThroughToSaving(): void
    {
        $service = $this->makeSavingService();
        $parsed = $service->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-08-01,1000,\n");
        $result = $service->upsertManyRecords(1, 1, $parsed['rows'], RecordService::IMPORT_MAX_ROWS);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /**
     * เลขแถวใน error ต้องชี้บรรทัดจริงในไฟล์
     *
     * เดิมชั้นบันทึกนับจาก array ที่ตัดหัวตาราง/แถวว่าง/แถวรวม/แถวไม่มีวันที่ ออกแล้ว
     * → ไฟล์ 500 แถวชี้ผิดแถว
     */
    public function testErrorPointsAtTheRealFileLine(): void
    {
        $service = $this->makeSavingService();
        // บรรทัด 1 หัวตาราง · 2 ปกติ · 3 ไม่มีวันที่ (ถูกข้าม) · 4 โน้ตยาวเกิน
        $csv = "วันที่,รายได้,ค่าแอด,โน้ต\n"
            . "2026-08-01,1000,100,ok\n"
            . ",999,99,ไม่มีวันที่\n"
            . '2026-08-03,500,50,' . str_repeat('ก', 256) . "\n";

        $parsed = $service->parseImportCsv($csv);
        $result = $service->upsertManyRecords(1, 1, $parsed['rows'], RecordService::IMPORT_MAX_ROWS);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('4', $result['error']);
    }

    /**
     * วันที่ x/y/zzzz ที่เลขทั้งสองตัว <= 12 กำกวม (3/8 = 3 ส.ค. หรือ 8 มี.ค.?)
     * → ปฏิเสธ ดีกว่าเข้าผิดเดือนเงียบ ๆ
     */
    public function testAmbiguousSlashDateIsRejected(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด\n3/8/2026,100,10\n");

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('กำกวม', (string)$result['error']);
    }

    /** ปี พ.ศ. ไม่ได้ทำให้รูปแบบ x/y หายกำกวม — กฎเดียวกัน */
    public function testAmbiguousSlashDateWithBuddhistYearIsAlsoRejected(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด\n5/8/2569,100,10\n");

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('กำกวม', (string)$result['error']);
    }

    /** เลขตัวแรก > 12 ไม่กำกวม (ต้องเป็นวันแน่นอน) → ยังรับได้เหมือนเดิม */
    public function testUnambiguousSlashDateStillWorks(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด\n25/8/2026,100,10\n");

        $this->assertTrue($result['success']);
        $this->assertSame('2026-08-25', $result['rows'][0]['record_date']);
    }

    /** ISO และวันที่ไทยไม่กำกวมอยู่แล้ว ต้องไม่ได้รับผลกระทบ */
    public function testIsoAndThaiDatesAreUnaffectedByAmbiguityCheck(): void
    {
        $service = $this->makeService();

        $iso = $service->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-03-08,100,10\n");
        $thai = $service->parseImportCsv("วันที่,รายได้,ค่าแอด\n8 มี.ค. 2569,100,10\n");

        $this->assertSame('2026-03-08', $iso['rows'][0]['record_date']);
        $this->assertSame('2026-03-08', $thai['rows'][0]['record_date']);
    }

    /**
     * โน้ตที่ผู้ใช้พิมพ์ ' นำหน้าเอง ต้องไม่สูญอักขระ
     *
     * export เติม ' เฉพาะเซลล์ที่ขึ้นต้นด้วย = + - @ (api/export.php) การถอดจึงต้อง
     * ใช้เงื่อนไขเดียวกัน ไม่ใช่ถอดทุกครั้งที่เจอ '
     */
    public function testUserTypedApostropheIsPreserved(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด,โน้ต\n2026-08-01,100,10,'ของจริง\n");

        $this->assertSame("'ของจริง", $result['rows'][0]['note']);
    }

    /** guard ที่ export เติมให้ ยังต้องถูกถอดเหมือนเดิม */
    public function testExportGuardApostropheIsStillStripped(): void
    {
        $service = $this->makeService();

        foreach (['=SUM(A1:A9)', '+1+1', '-ลบ', '@cmd'] as $payload) {
            $result = $service->parseImportCsv(
                "วันที่,รายได้,ค่าแอด,โน้ต\n2026-08-01,100,10,\"'" . $payload . "\"\n"
            );

            $this->assertSame($payload, $result['rows'][0]['note'], "ไม่ได้ถอด guard ของ {$payload}");
        }
    }

    /**
     * อ่านหัวตารางไม่ออกเลย → ต้องบอกสาเหตุที่เป็นไปได้จริง
     *
     * เดิมตอบ "ไฟล์ต้องมีคอลัมน์ วันที่ (ไม่พบในหัวตาราง)" ทั้งที่ผู้ใช้เปิดไฟล์ใน Excel
     * แล้วเห็นคอลัมน์ครบทุกอัน — สาเหตุจริงคือ encoding/ตัวคั่น หรือไม่ใช่ไฟล์ CSV
     */
    public function testUnreadableHeaderExplainsEncodingAndDelimiter(): void
    {
        $service = $this->makeService();

        // ตัวคั่น semicolon (ค่าเริ่มต้นของ Excel บางเครื่อง) → ทั้งบรรทัดกลายเป็นเซลล์เดียว
        $semicolon = $service->parseImportCsv("วันที่;รายได้;ค่าแอด\n2026-08-01;100;10\n");
        // หัวตารางเป็นไบต์ TIS-620/CP874 (ไฟล์ที่ Excel ไทยบน Windows บันทึกให้)
        // เขียนเป็นไบต์ดิบเพราะ mbstring บางบิลด์ไม่มี encoding ไทยให้แปลง
        $legacyThaiHeader = hex2bin('c7d1b9b7d5e8'); // "วันที่"
        $legacy = $service->parseImportCsv($legacyThaiHeader . ",x,y\n2026-08-01,100,10\n");

        foreach (['semicolon' => $semicolon, 'legacy-thai' => $legacy] as $label => $result) {
            $this->assertFalse($result['success'], $label);
            $this->assertStringContainsString('UTF-8', (string)$result['error'], $label);
        }
    }

    /** ไฟล์ที่มีหัวตารางถูกต้องแต่ขาดคอลัมน์จริง ต้องยังได้ข้อความเดิมที่เจาะจงกว่า */
    public function testGenuinelyMissingColumnStillSaysWhichColumn(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้\n2026-08-01,100\n");

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ค่าแอด', (string)$result['error']);
    }
}
