<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use XlsxReportService;
use ZipArchive;

/**
 * unit test ของเฟส 5C — คอลัมน์กำไรปีก่อน/สะสม และกราฟ 2 ตัวที่อ้างคอลัมน์เหล่านั้น
 */
final class XlsxReportServiceTrendChartTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        return [
            'months' => [
                [
                    'month' => 1, 'total_revenue' => 9000.0, 'total_ad_cost' => 3000.0,
                    'profit' => 6000.0, 'roas' => 3.0, 'days_count' => 3,
                    'profit_per_day' => 2000.0, 'yoy_change_percent' => 50.0,
                    'prev_year_profit' => 4000.0,
                ],
                [
                    'month' => 2, 'total_revenue' => 0.0, 'total_ad_cost' => 0.0,
                    'profit' => 0.0, 'roas' => null, 'days_count' => 0,
                    'profit_per_day' => null, 'yoy_change_percent' => null,
                    'prev_year_profit' => 0.0,
                ],
                [
                    'month' => 3, 'total_revenue' => 1000.0, 'total_ad_cost' => 3500.0,
                    'profit' => -2500.0, 'roas' => 0.29, 'days_count' => 1,
                    'profit_per_day' => -2500.0, 'yoy_change_percent' => -80.0,
                    'prev_year_profit' => 1000.0,
                ],
            ],
            'chart' => [
                'cumulative_profit' => [6000.0, 6000.0, 3500.0],
                'prev_cumulative_profit' => [4000.0, 4000.0, 5000.0],
            ],
        ];
    }

    private function buildWorkbook(?array $payload = null): Spreadsheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildMonthlySheet($spreadsheet, $payload ?? $this->payload());

        return $spreadsheet;
    }

    public function testTrendColumnsAreWritten(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $this->assertSame('กำไรปีก่อน', $sheet->getCell('I1')->getValue());
        $this->assertSame('กำไรสะสม', $sheet->getCell('J1')->getValue());
        $this->assertSame('สะสมปีก่อน', $sheet->getCell('K1')->getValue());

        $this->assertSame(4000.0, $sheet->getCell('I2')->getValue());
        $this->assertSame(6000.0, $sheet->getCell('J2')->getValue());
        $this->assertSame(4000.0, $sheet->getCell('K2')->getValue());

        // เส้นสะสมต้องลดลงตอนเดือนขาดทุน (6000 → 3500)
        $this->assertSame(3500.0, $sheet->getCell('J4')->getValue());
        $this->assertSame(5000.0, $sheet->getCell('K4')->getValue());

        $this->assertSame('"฿"#,##0', $sheet->getStyle('I2')->getNumberFormat()->getFormatCode());
    }

    public function testNegativeCumulativeIsRed(): void
    {
        $payload = $this->payload();
        $payload['chart']['cumulative_profit'] = [-100.0, -200.0, -300.0];

        $sheet = $this->buildWorkbook($payload)->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $this->assertSame('FFC00000', $sheet->getStyle('J2')->getFont()->getColor()->getARGB());
        $this->assertNotSame('FFC00000', $sheet->getStyle('K2')->getFont()->getColor()->getARGB());
    }

    public function testMissingCumulativeSeriesLeavesCellsEmpty(): void
    {
        $payload = $this->payload();
        unset($payload['chart']);

        $sheet = $this->buildWorkbook($payload)->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        // ไม่มีซีรีส์สะสมส่งมา → เว้นว่าง ไม่ใช่ 0 (และต้องไม่ fatal)
        $this->assertNull($sheet->getCell('J2')->getValue());
        $this->assertNull($sheet->getCell('K2')->getValue());
        // คอลัมน์กำไรปีก่อนมาจากแถวเดือนโดยตรง จึงยังมี
        $this->assertSame(4000.0, $sheet->getCell('I2')->getValue());
    }

    public function testProfitChartCombinesBarsAndPrevYearLine(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $chart = $sheet->getChartByIndex(0);
        $this->assertNotNull($chart);

        $groups = $chart->getPlotArea()->getPlotGroup();
        $this->assertCount(2, $groups);
        $this->assertSame(DataSeries::TYPE_BARCHART, $groups[0]->getPlotType());
        $this->assertSame(DataSeries::TYPE_LINECHART, $groups[1]->getPlotType());

        // แท่ง = คอลัมน์กำไร (D) · เส้น = คอลัมน์กำไรปีก่อน (I)
        $this->assertSame(
            "'รายเดือน'!\$D\$2:\$D\$4",
            $groups[0]->getPlotValues()[0]->getDataSource()
        );
        $this->assertSame(
            "'รายเดือน'!\$I\$2:\$I\$4",
            $groups[1]->getPlotValues()[0]->getDataSource()
        );
    }

    public function testCumulativeChartPlotsBothSeries(): void
    {
        $sheet = $this->buildWorkbook()->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $chart = $sheet->getChartByIndex(1);
        $this->assertNotNull($chart);

        $groups = $chart->getPlotArea()->getPlotGroup();
        $this->assertCount(1, $groups);
        $this->assertSame(DataSeries::TYPE_LINECHART, $groups[0]->getPlotType());

        $sources = array_map(
            static fn($values): string => (string)$values->getDataSource(),
            $groups[0]->getPlotValues()
        );
        $this->assertSame(
            ["'รายเดือน'!\$J\$2:\$J\$4", "'รายเดือน'!\$K\$2:\$K\$4"],
            $sources
        );
    }

    public function testBothChartsSurviveToTheSavedFile(): void
    {
        $spreadsheet = $this->buildWorkbook();

        $file = tempnam(sys_get_temp_dir(), 'xlsx-trend-') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->setIncludeCharts(true);
        $writer->save($file);
        $spreadsheet->disconnectWorksheets();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
        }
        $chart1 = (string)$zip->getFromName('xl/charts/chart1.xml');
        $chart2 = (string)$zip->getFromName('xl/charts/chart2.xml');
        $zip->close();
        unlink($file);

        // 2 กราฟ = 2 part
        $this->assertContains('xl/charts/chart1.xml', $names);
        $this->assertContains('xl/charts/chart2.xml', $names);

        // กราฟแรกต้องมีทั้งแท่งและเส้นอยู่ใน part เดียว
        $this->assertStringContainsString('barChart', $chart1);
        $this->assertStringContainsString('lineChart', $chart1);
        $this->assertStringContainsString("'รายเดือน'!\$I\$2:\$I\$4", $chart1);

        $this->assertStringContainsString('lineChart', $chart2);
        $this->assertStringNotContainsString('barChart', $chart2);
        $this->assertStringContainsString("'รายเดือน'!\$K\$2:\$K\$4", $chart2);
    }

    public function testEmptyMonthsAddsNoChart(): void
    {
        $sheet = $this->buildWorkbook(['months' => []])->getSheetByName('รายเดือน');
        $this->assertNotNull($sheet);

        $this->assertSame(0, $sheet->getChartCount());
    }
}
