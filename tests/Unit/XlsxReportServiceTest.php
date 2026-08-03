<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use XlsxReportService;
use ZipArchive;

/**
 * unit test ของการประกอบ workbook — โดยเฉพาะเรื่องที่ Excel เป็นคนตีความ
 * (ชนิดเซลล์ / สูตร / date serial) ซึ่งดูจาก payload อย่างเดียวไม่พอ
 */
final class XlsxReportServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function payload(array $rows): array
    {
        return [
            'year' => 2026,
            'shop_name' => 'ร้านคอร์ส',
            'rows' => $rows,
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sheetXml(array $payload): string
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet($payload);

        $file = tempnam(sys_get_temp_dir(), 'xlsx-test-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true, 'เปิดไฟล์ xlsx ไม่ได้');
        $xml = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);

        return $xml;
    }

    public function testFormulaLikeNoteKeepsExactTextWithoutApostrophe(): void
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet($this->payload([
            ['record_date' => '2026-01-05', 'revenue' => 3000.0, 'ad_cost' => 1000.0, 'roas' => 3.0, 'note' => '=SUM(A1:A9)'],
        ]));

        $cell = $spreadsheet->getActiveSheet()->getCell('F2');

        // ตรงตามที่ผู้ใช้พิมพ์ — ไม่มี ' นำหน้า (xlsx ไม่ใช่ CSV)
        $this->assertSame('=SUM(A1:A9)', $cell->getValue());
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());

        $spreadsheet->disconnectWorksheets();
    }

    public function testFormulaLikeNoteIsNotStoredAsAFormula(): void
    {
        $xml = $this->sheetXml($this->payload([
            ['record_date' => '2026-01-05', 'revenue' => 3000.0, 'ad_cost' => 1000.0, 'roas' => 3.0, 'note' => '=SUM(A1:A9)'],
            ['record_date' => '2026-01-06', 'revenue' => 1000.0, 'ad_cost' => 500.0, 'roas' => 2.0, 'note' => '+1+1'],
            ['record_date' => '2026-01-07', 'revenue' => 1000.0, 'ad_cost' => 500.0, 'roas' => 2.0, 'note' => '@cmd'],
        ]));

        // defense จริงคือชนิดเซลล์: ไม่มี <f> = Excel ไม่มีอะไรให้ประมวลผล
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringNotContainsString('<f ', $xml);

        // เซลล์โน้ตทุกช่องต้องเป็น shared string
        preg_match_all('#<c r="F\d+"[^>]*t="(\w+)"#', $xml, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $type) {
            $this->assertSame('s', $type);
        }
    }

    public function testPlainNoteIsUnchanged(): void
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet($this->payload([
            ['record_date' => '2026-01-05', 'revenue' => 100.0, 'ad_cost' => 10.0, 'roas' => 10.0, 'note' => 'เปิดรอบใหม่'],
        ]));

        $this->assertSame('เปิดรอบใหม่', $spreadsheet->getActiveSheet()->getCell('F2')->getValue());

        $spreadsheet->disconnectWorksheets();
    }

    public function testDateIsWrittenAsWholeDaySerial(): void
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet($this->payload([
            ['record_date' => '2026-01-05', 'revenue' => 100.0, 'ad_cost' => 10.0, 'roas' => 10.0, 'note' => ''],
        ]));

        $sheet = $spreadsheet->getActiveSheet();
        $serial = $sheet->getCell('A2')->getValue();

        $this->assertIsNumeric($serial);
        // ไม่มีเศษเวลา — ไม่งั้นการกรอง/จับคู่วันที่ใน Excel จะไม่ตรง
        $this->assertSame((float)(int)$serial, (float)$serial);
        $this->assertSame('2026-01-05', ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d'));
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());

        $spreadsheet->disconnectWorksheets();
    }

    public function testHeaderIsStyledAndFrozen(): void
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet($this->payload([]));
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('รายวัน', $sheet->getTitle());
        $this->assertSame('วันที่', $sheet->getCell('A1')->getValue());
        $this->assertSame('โน้ต', $sheet->getCell('F1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('FF1F4E79', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertSame('A2', $sheet->getFreezePane());

        $spreadsheet->disconnectWorksheets();
    }

    public function testTotalsRowSitsAfterABlankRow(): void
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet([
            'year' => 2026,
            'shop_name' => 'ร้านคอร์ส',
            'rows' => [
                ['record_date' => '2026-01-05', 'revenue' => 3000.0, 'ad_cost' => 1000.0, 'roas' => 3.0, 'note' => ''],
                ['record_date' => '2026-01-06', 'revenue' => 2000.0, 'ad_cost' => 500.0, 'roas' => 4.0, 'note' => ''],
            ],
            'totals' => ['revenue' => 5000.0, 'ad_cost' => 1500.0, 'profit' => 3500.0, 'roas' => 3.33],
            'note_column_index' => 6,
        ]);

        $sheet = $spreadsheet->getActiveSheet();

        $this->assertNull($sheet->getCell('A4')->getValue());          // แถวเว้น
        $this->assertSame('รวมทั้งปี', $sheet->getCell('A5')->getValue());
        $this->assertSame(3500.0, $sheet->getCell('D5')->getValue());
        $this->assertTrue($sheet->getStyle('A5')->getFont()->getBold());

        $spreadsheet->disconnectWorksheets();
    }

    public function testMoneyCellsAreNumbersNotText(): void
    {
        $spreadsheet = (new XlsxReportService())->buildDailySheet($this->payload([
            ['record_date' => '2026-01-05', 'revenue' => 3000.0, 'ad_cost' => 4000.0, 'profit' => -1000.0, 'roas' => 0.75, 'note' => ''],
        ]));

        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame(3000.0, $sheet->getCell('B2')->getValue());
        $this->assertSame(-1000.0, $sheet->getCell('D2')->getValue());
        $this->assertSame('#,##0.00', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('0.00', $sheet->getStyle('E2')->getNumberFormat()->getFormatCode());

        $spreadsheet->disconnectWorksheets();
    }
}
