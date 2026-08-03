<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use XlsxReportService;
use ZipArchive;

/**
 * unit test ของ sheet "รายเดือน" + กราฟที่ฝังในไฟล์
 * ตรวจถึงระดับ artifact ในไฟล์จริง เพราะกราฟ "หายเงียบ" ได้ถ้าลืม setIncludeCharts
 */
final class XlsxReportServiceChartTest extends TestCase
{
    /**
     * @param array<int,array{0:int,1:string,2:float,3:float}> $rows [เดือน, ชื่อไทย, รายได้, ค่าแอด]
     * @return array<string,mixed>
     */
    private function monthlyPayload(array $rows): array
    {
        $months = array_map(
            static fn(array $row): array => [
                'month' => $row[0],
                'month_label' => $row[1],
                'revenue' => $row[2],
                'ad_cost' => $row[3],
                'profit' => $row[2] - $row[3],
                'roas' => $row[3] > 0 ? round($row[2] / $row[3], 2) : null,
            ],
            $rows
        );

        return ['year' => 2026, 'last_month' => count($months), 'months' => $months];
    }

    /**
     * @return array<string,mixed>
     */
    private function dailyPayload(): array
    {
        return [
            'year' => 2026,
            'shop_name' => 'ร้านคอร์ส',
            'rows' => [
                ['record_date' => '2026-01-05', 'revenue' => 100.0, 'ad_cost' => 10.0, 'roas' => 10.0, 'note' => ''],
            ],
            'totals' => ['revenue' => 100.0, 'ad_cost' => 10.0, 'profit' => 90.0, 'roas' => 10.0],
            'note_column_index' => 6,
        ];
    }

    /**
     * @param array<int,array{0:int,1:string,2:float,3:float}> $rows
     */
    private function buildWorkbook(array $rows): Spreadsheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildMonthlySheet($spreadsheet, $this->monthlyPayload($rows));

        return $spreadsheet;
    }

    /**
     * บันทึกไฟล์จริง (เปิด charts) แล้วคืนรายชื่อ part ในแพ็กเกจ + เนื้อ chart xml
     *
     * @return array{names:array<int,string>,chartXml:string}
     */
    private function saveAndUnzip(Spreadsheet $spreadsheet, bool $includeCharts = true): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx-chart-') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts($includeCharts);
        $writer->save($file);
        $spreadsheet->disconnectWorksheets();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true, 'เปิดไฟล์ xlsx ไม่ได้');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
        }
        $chartXml = (string)$zip->getFromName('xl/charts/chart1.xml');
        $zip->close();
        unlink($file);

        return ['names' => $names, 'chartXml' => $chartXml];
    }

    public function testSavedFileContainsChartPart(): void
    {
        $result = $this->saveAndUnzip($this->buildWorkbook([
            [1, 'ม.ค.', 5000.0, 1000.0],
            [2, 'ก.พ.', 3000.0, 1000.0],
        ]));

        // ถ้าลืม setIncludeCharts หรือ addChart ไม่ทำงาน part นี้จะไม่มี
        $this->assertContains('xl/charts/chart1.xml', $result['names']);
        $this->assertNotSame('', $result['chartXml']);
    }

    public function testChartIsMissingWhenIncludeChartsIsOff(): void
    {
        $result = $this->saveAndUnzip(
            $this->buildWorkbook([[1, 'ม.ค.', 5000.0, 1000.0], [2, 'ก.พ.', 3000.0, 1000.0]]),
            false
        );

        // ยืนยันว่าเทสต์ข้างบนจับของจริง ไม่ใช่ผ่านเพราะบังเอิญ
        $this->assertNotContains('xl/charts/chart1.xml', $result['names']);
    }

    public function testChartReferencesTheProfitColumnRange(): void
    {
        $result = $this->saveAndUnzip($this->buildWorkbook([
            [1, 'ม.ค.', 5000.0, 1000.0],
            [2, 'ก.พ.', 3000.0, 1000.0],
            [3, 'มี.ค.', 4000.0, 1000.0],
        ]));

        // values = คอลัมน์กำไร (D) แถว 2..4 · categories = ชื่อเดือน (A)
        $this->assertStringContainsString("'รายเดือน'!\$D\$2:\$D\$4", $result['chartXml']);
        $this->assertStringContainsString("'รายเดือน'!\$A\$2:\$A\$4", $result['chartXml']);
        $this->assertStringContainsString('barChart', $result['chartXml']);
    }

    public function testMonthlySheetIsTheFirstTab(): void
    {
        $spreadsheet = $this->buildWorkbook([[1, 'ม.ค.', 5000.0, 1000.0]]);

        // workbook ยังไม่ได้เพิ่ม sheet รายปี — ลำดับสุดท้ายของจริงดู XlsxReportServiceAnnualTest
        $this->assertSame(['รายเดือน', 'รายวัน'], $spreadsheet->getSheetNames());
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());

        $spreadsheet->disconnectWorksheets();
    }

    public function testMonthlyCellsHoldNumbersIncludingLosses(): void
    {
        $spreadsheet = $this->buildWorkbook([
            [1, 'ม.ค.', 5000.0, 1000.0],   // +4000
            [2, 'ก.พ.', 1000.0, 2500.0],   // -1500 ← แท่งลงล่าง
        ]);

        $sheet = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $this->assertSame('ม.ค.', $sheet->getCell('A2')->getValue());
        $this->assertSame(4000.0, $sheet->getCell('D2')->getValue());
        $this->assertSame('ก.พ.', $sheet->getCell('A3')->getValue());
        $this->assertSame(-1500.0, $sheet->getCell('D3')->getValue());
        $this->assertSame(0.4, $sheet->getCell('E3')->getValue());
        $this->assertSame('#,##0.00', $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());

        $spreadsheet->disconnectWorksheets();
    }

    public function testMonthlyHeaderIsStyledAndFrozen(): void
    {
        $spreadsheet = $this->buildWorkbook([[1, 'ม.ค.', 100.0, 10.0]]);
        $sheet = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $this->assertSame('เดือน', $sheet->getCell('A1')->getValue());
        $this->assertSame('กำไร', $sheet->getCell('D1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('FF1F4E79', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        $this->assertSame('A2', $sheet->getFreezePane());

        $spreadsheet->disconnectWorksheets();
    }

    public function testEmptyMonthlyPayloadAddsSheetWithoutChart(): void
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet($this->dailyPayload());
        $service->buildMonthlySheet($spreadsheet, ['year' => 2027, 'last_month' => 0, 'months' => []]);

        $sheet = $spreadsheet->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);
        // ปีอนาคต — ไม่มีข้อมูลก็ไม่ควรพยายามวาดกราฟจาก range ว่าง
        $this->assertSame(0, $sheet->getChartCount());

        $result = $this->saveAndUnzip($spreadsheet);
        $this->assertNotContains('xl/charts/chart1.xml', $result['names']);
    }

    public function testDailySheetIsUntouchedByPhaseTwo(): void
    {
        $spreadsheet = $this->buildWorkbook([[1, 'ม.ค.', 100.0, 10.0]]);
        $sheet = $spreadsheet->getSheetByName('รายวัน');
        $this->assertNotNull($sheet);

        $this->assertSame('วันที่', $sheet->getCell('A1')->getValue());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame(0, $sheet->getChartCount());

        $spreadsheet->disconnectWorksheets();
    }
}
