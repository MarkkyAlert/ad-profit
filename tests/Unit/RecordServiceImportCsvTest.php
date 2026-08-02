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
    private function makeService(): RecordService
    {
        return new RecordService(
            $this->createStub(RecordRepository::class),
            $this->createStub(ShopRepository::class),
            null
        );
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

    public function testRoundTripsExportedFileWithBomThaiDatesAndTotalsRow(): void
    {
        // เลียนแบบไฟล์ที่ export ออกจากระบบนี้ทุกอย่าง
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
            ['03/08/2026', '1', '0'],       // DD/MM/YYYY
            ['4/8/2026', '1', '0'],         // D/M/YYYY
            ['05/08/2569', '1', '0'],       // พ.ศ.
            ['6 ส.ค. 2569', '1', '0'],      // ไทย
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'],
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
            ['เมื่อวานนี้', '1000', '200'],   // บรรทัดที่ 3
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('บรรทัดที่ 3', $result['error']);
    }

    public function testImpossibleDateIsRejected(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-02-31', '1000', '200'],   // 31 ก.พ. ไม่มีจริง
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('บรรทัดที่ 2', $result['error']);
    }

    public function testNonNumericRevenueReportsLineNumber(): void
    {
        $csv = $this->toCsv([
            ['วันที่', 'รายได้', 'ค่าแอด'],
            ['2026-08-02', 'มากมาย', '200'],
        ]);

        $result = $this->makeService()->parseImportCsv($csv);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('บรรทัดที่ 2', $result['error']);
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
}
