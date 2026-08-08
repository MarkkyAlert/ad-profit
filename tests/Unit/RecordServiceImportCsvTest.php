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

    /**
     * ⭐⭐ ไฟล์ที่หัวตารางติดช่องว่างที่มองไม่เห็น ต้องยังอ่านออก
     *
     * ⚠️ เดิม normalize หัวตารางด้วย `trim()` ธรรมดา ซึ่งไม่ตัด NBSP / ช่องว่างญี่ปุ่น /
     * zero-width · หัวตาราง "วันที่ " (ติด NBSP จากการก๊อปวางใน Excel/Google Sheets)
     * จึงไม่ถูกจับคู่ แล้ว **ทั้งไฟล์ถูกปฏิเสธ** ด้วยข้อความว่า
     * 'ไฟล์ต้องมีคอลัมน์ "วันที่" (ไม่พบในหัวตาราง)'
     * ซึ่ง **ขัดกับสิ่งที่ผู้ใช้เห็นในไฟล์ตัวเอง** (คอลัมน์นั้นอยู่ตรงนั้นชัด ๆ)
     *
     * ตัวอ่านจำนวนเงินรองรับ NBSP อยู่แล้ว (`normalize_money_string`) หัวตารางกับวันที่
     * จึงเป็นสองที่ที่ตกสำรวจ — รูปแบบเดิมที่โปรเจกต์นี้เจอซ้ำ ๆ
     */
    public function testAHeaderWithAnInvisibleSpaceIsStillRecognised(): void
    {
        $csv = $this->toCsv([
            ["วันที่\u{00A0}", 'รายได้', "ค่าแอด\u{3000}", 'โน้ต'],
            ['2026-08-02', '1000', '200', 'ปกติ'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue(
            $result['success'],
            'หัวตารางติดช่องว่างที่มองไม่เห็น แล้วทั้งไฟล์ถูกปฏิเสธ: ' . (string)$result['error']
        );
        $this->assertSame('2026-08-02', $result['rows'][0]['record_date']);
        $this->assertSame('1000', $result['rows'][0]['revenue']);
        $this->assertSame('200', $result['rows'][0]['ad_cost']);
    }

    /** ⭐ ช่องวันที่ที่ติดช่องว่างมองไม่เห็นก็ต้องอ่านออกเหมือนช่องจำนวนเงิน */
    public function testADateCellWithAnInvisibleSpaceIsStillRead(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด', 'โน้ต'],
            ["2026-08-02\u{00A0}", '1000', '200', 'ปกติ'],
            ["\u{200B}2026-08-03", '1500', '300', 'ปกติ'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success'], (string)$result['error']);
        $this->assertSame('2026-08-02', $result['rows'][0]['record_date']);
        $this->assertSame('2026-08-03', $result['rows'][1]['record_date']);
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
     * ⭐⭐ [เจ้าของระบบตัดสิน 2026-08-07] ช่องยอดที่เว้นว่าง = **ปฏิเสธ** ไม่ใช่ 0
     *
     * ⚠️ เดิมแปลงเป็น 0 ให้เอง โดยให้เหตุผลในคอมเมนต์ว่า "UI ก็ปล่อยว่างได้" ซึ่งไม่จริง —
     * ฟอร์มวันเดียว (`required` + `parse_decimal_input`) และตารางกรอกหลายวัน
     * (`upsertManyRecords`) บังคับกรอกทั้งคู่ · CSV เป็นทางเดียวที่เดาแทนผู้ใช้
     *
     * ความเสียหายสองฝั่งไม่เท่ากัน: เดาเป็น 0 แล้วผิด = ได้ "วันที่รายได้ ฿0 แต่จ่ายค่าแอดจริง"
     * คือวันขาดทุนปลอมที่ไหลเข้าทุกรายงาน (กำไรเฉลี่ยต่อวัน · วันแย่สุด · ROAS · เป้า · กราฟ)
     * โดยผู้ใช้ไม่รู้ตัว · ปฏิเสธแล้วผิด = ผู้ใช้พิมพ์ 0 เอง
     */
    public function testEmptyNumericCellIsRejected(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-08-01,1000,\n");

        $this->assertFalse($result['success'], 'ยังเดาช่องว่างเป็น 0 อยู่');
        $this->assertStringContainsString('แถวที่ 2', (string)$result['error']);
        $this->assertStringContainsString('ค่าแอด', (string)$result['error'], 'ไม่ได้บอกว่าช่องไหน');
        $this->assertStringContainsString('ใส่ 0', (string)$result['error'], 'ไม่ได้บอกว่าต้องทำอะไรต่อ');
    }

    /** ⚠️ ช่องรายได้ว่างต้องบอกว่า "รายได้" ไม่ใช่บอกชื่อช่องผิด */
    public function testABlankRevenueNamesTheRevenueColumn(): void
    {
        $result = $this->makeService()->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-08-01,,500\n");

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('รายได้', (string)$result['error']);
    }

    /** ใส่ 0 มาเองยังผ่านตามปกติ — ไม่ใช่ปฏิเสธทุกอย่างที่เป็นศูนย์ */
    public function testAnExplicitZeroStillPasses(): void
    {
        $service = $this->makeSavingService();
        $parsed = $service->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-08-01,1000,0\n");

        $this->assertTrue($parsed['success'], (string)($parsed['error'] ?? ''));
        $this->assertSame('0', $parsed['rows'][0]['ad_cost']);

        $result = $service->upsertManyRecords(1, 1, $parsed['rows'], RecordService::IMPORT_MAX_ROWS);
        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
    }

    /**
     * ⭐ ทั้ง 3 ทางที่เขียนข้อมูลต้องตีความ "ช่องว่าง" เหมือนกัน
     *
     * เดิม CSV เป็นทางเดียวที่หลวมกว่าเพื่อน — ผู้ใช้คนเดียวกันทำสิ่งเดียวกันผ่านคนละประตู
     * ได้ผลคนละอย่าง
     */
    public function testAllThreeWritePathsRejectABlankAmount(): void
    {
        $service = $this->makeSavingService();

        // ทาง 1: ไฟล์ CSV
        $csv = $service->parseImportCsv("วันที่,รายได้,ค่าแอด\n2026-08-01,1000,\n");
        $this->assertFalse($csv['success'], 'CSV ยังยอมให้เว้นช่องยอด');

        // ทาง 2: ตารางกรอกหลายวัน (controller ส่งสตริงดิบมาเมื่อ parse ไม่ผ่าน)
        $bulk = $service->upsertManyRecords(1, 1, [
            ['row_number' => 1, 'record_date' => '2026-08-01', 'revenue' => '1000', 'ad_cost' => '', 'note' => null],
        ], RecordService::IMPORT_MAX_ROWS);
        $this->assertFalse($bulk['success'], 'ตารางกรอกหลายวันยังยอมให้เว้นช่องยอด');

        // ทาง 3: ฟอร์มวันเดียว — ด่านอยู่ที่ `parse_decimal_input()` ตัวเดียวกับที่ controller ใช้
        $this->assertFalse((bool)(parse_decimal_input('', false)['valid'] ?? false));
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

    /**
     * ⭐ เส้นแบ่งของเพดานจำนวนแถว — ไฟล์ที่พอดีเพดานต้องผ่าน เกินไป 1 แถวต้องถูกปฏิเสธ
     *
     * ตัวอ่านไฟล์เพิ่งมาหยุดเองตอนแถวเกินเพดาน (เดิมปล่อยให้อ่านจนจบแล้วค่อยนับตอนบันทึก
     * ซึ่งหน่วยความจำหมดก่อน) · เทสต์นี้กันไม่ให้การหยุดนั้น **หยุดเร็วไปหนึ่งแถว**
     * ซึ่งจะทำให้ไฟล์ที่เคยนำเข้าได้พอดีเพดานกลับถูกปฏิเสธเงียบ ๆ
     *
     * ⚠️ ใช้เพดานเล็ก ๆ ที่ส่งเข้าไปเอง ไม่ใช่ค่าจริง 1000 — จุดที่ต้องพิสูจน์คือ
     * "หยุดที่แถวไหน" ไม่ใช่ตัวเลข และไฟล์ 1000 แถวทำให้เทสต์ช้าโดยไม่ได้อะไรเพิ่ม
     */
    public function testTheRowLimitCutsAtExactlyTheLimit(): void
    {
        $service = $this->makeService();
        $header = "วันที่,รายได้,ค่าแอด\n";

        $atLimit = $service->parseImportCsv(
            $header . "2026-08-01,100,10\n2026-08-02,100,10\n",
            2
        );
        $this->assertTrue($atLimit['success'], 'ไฟล์ที่พอดีเพดานถูกปฏิเสธ');
        $this->assertCount(2, $atLimit['rows']);

        $overLimit = $service->parseImportCsv(
            $header . "2026-08-01,100,10\n2026-08-02,100,10\n2026-08-03,100,10\n",
            2
        );
        $this->assertFalse($overLimit['success'], 'ไฟล์ที่เกินเพดานกลับผ่านได้');
        $this->assertStringContainsString(
            'กรอกได้สูงสุด 2 แถวต่อครั้ง',
            (string)$overLimit['error'],
            'ข้อความต้องเป็นตัวเดียวกับตอนบันทึก ไม่งั้นไฟล์เดียวกันได้คำอธิบายคนละแบบ'
        );
    }
}
