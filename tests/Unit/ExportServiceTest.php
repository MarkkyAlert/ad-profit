<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExportService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

final class ExportServiceTest extends TestCase
{
    /**
     * ⭐ ขอไฟล์ CSV ของร้านที่ไม่ใช่ของตัวเอง ต้องไม่ได้ข้อมูล
     *
     * ⚠️ ด่านนี้เทสต์ที่ระดับ endpoint ไม่ได้ — `api/export.php` เรียก
     * `resolve_current_shop_id()` ซึ่งซ่อม session ให้กลับไปร้านของเจ้าตัวก่อนเสมอ
     * คำขอจึงไม่มีวันเดินมาถึงตรงนี้ผ่านหน้าเว็บ · แต่มันคือด่านสุดท้ายที่กันข้อมูล
     * ข้ามผู้ใช้ในชั้น Service และเดิม **ไม่มีเทสต์ตัวไหนแตะเลย**
     * (ฝาแฝดฝั่ง xlsx มี `ExportServiceXlsxTest` คุมอยู่แล้ว)
     */
    public function testCsvExportRefusesAShopTheUserDoesNotOwn(): void
    {
        $recordService = $this->createMock(RecordService::class);
        // ต้องไม่แม้แต่จะไปอ่านข้อมูลของร้านนั้น
        $recordService->expects($this->never())->method('getMonthlyRecords');

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(null);

        $result = (new ExportService($recordService, $shopRepository))
            ->buildMonthlyCsvPayload(1, 999, '2024-01');

        $this->assertFalse($result['success'], 'ดาวน์โหลดข้อมูลของร้านคนอื่นได้');
        $this->assertStringContainsString('ไม่มีสิทธิ์', (string)$result['error']);
        $this->assertArrayNotHasKey('data', $result, 'มีข้อมูลติดออกมาด้วยทั้งที่ไม่มีสิทธิ์');
    }

    public function testBuildMonthlyCsvPayloadProducesHeaderAndRows(): void
    {
        // ⚠️ ExportService รับ RecordService (ไม่ใช่ repo) → ต้อง stub RecordService
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('getMonthlyRecords')->willReturn([
            'success' => true,
            'data' => [
                'records' => [
                    [
                        'record_date' => '2024-01-10',
                        'revenue' => 1000.0,
                        'ad_cost' => 200.0,
                        'profit' => 800.0,
                        'roas' => 5.0,
                        'compare_revenue_percent' => null,
                        'note' => 'ปกติ',
                    ],
                ],
                'totals' => [
                    'total_revenue' => 1000.0,
                    'total_ad_cost' => 200.0,
                    'total_profit' => 800.0,
                    'avg_roas' => 5.0,
                ],
            ],
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'name' => 'ร้าน A',
        ]);

        $service = new ExportService($recordService, $shopRepository);

        $result = $service->buildMonthlyCsvPayload(1, 1, '2024-01');

        $this->assertTrue($result['success']);
        $data = $result['data'];

        // header ตรงตามที่ ExportService กำหนด
        $this->assertSame(
            ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบครั้งก่อน', 'โน้ต'],
            $data['headers']
        );
        $this->assertSame('ร้าน A', $data['shop_name']);
        $this->assertSame('2024-01', $data['month']);

        // แถวข้อมูล 1 แถว จัดรูปถูกต้อง
        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertSame('2024-01-10', $row[0]);       // ISO — Excel sort/filter ได้ตรง
        $this->assertSame('1000.00', $row[1]);          // revenue
        $this->assertSame('200.00', $row[2]);           // ad_cost
        $this->assertSame('800.00', $row[3]);           // profit
        $this->assertSame('5.00', $row[4]);             // ROAS
        $this->assertSame('', $row[5]);                 // compare null → เซลล์ว่าง
        $this->assertSame('ปกติ', $row[6]);             // note

        // แถวรวม
        $this->assertSame('รวม', $data['totals_row'][0]);
        $this->assertSame('1000.00', $data['totals_row'][1]);
        $this->assertSame('800.00', $data['totals_row'][3]);

        // metadata ให้ controller: sanitize เฉพาะคอลัมน์โน้ต + เว้นบรรทัดก่อนแถวรวม
        $this->assertSame(6, $data['note_column_index']);
        $this->assertTrue($data['blank_row_before_totals']);
    }

    /**
     * สร้าง payload จาก record ที่กำหนดเอง (ใช้กับเคสรูปแบบเซลล์)
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function payloadForRecord(array $record): array
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('getMonthlyRecords')->willReturn([
            'success' => true,
            'data' => [
                'records' => [$record],
                'totals' => [
                    'total_revenue' => 0.0,
                    'total_ad_cost' => 0.0,
                    'total_profit' => 0.0,
                    'avg_roas' => null,
                ],
            ],
        ]);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'user_id' => 1, 'name' => 'ร้าน A']);

        $result = (new ExportService($recordService, $shopRepository))->buildMonthlyCsvPayload(1, 1, '2024-01');
        $this->assertTrue($result['success']);

        return (array)$result['data'];
    }

    public function testNullRoasAndCompareBecomeEmptyCells(): void
    {
        $data = $this->payloadForRecord([
            'record_date' => '2024-01-10',
            'revenue' => 1000.0,
            'ad_cost' => 0.0,
            'profit' => 1000.0,
            'roas' => null,                      // ad_cost = 0
            'compare_revenue_percent' => null,
            'note' => '',
        ]);

        $this->assertSame('', $data['rows'][0][4]);   // ROAS
        $this->assertSame('', $data['rows'][0][5]);   // เทียบครั้งก่อน
        $this->assertSame('', $data['totals_row'][4]); // avg_roas null
    }

    /**
     * ⭐ คอลัมน์ "เทียบครั้งก่อน" ต้องไม่มีตัวคั่นหลักพัน และห้ามขึ้นต้นด้วย +
     *
     * คอลัมน์ตัวเลขอื่นส่ง '' เป็น separator อยู่แล้ว เฉพาะช่องนี้ที่เคยใช้ค่า default
     * ทำให้ค่าที่โตมาก (รายได้กระโดดจาก 10 เป็นล้าน) ออกมาเป็น "+9,999,900.0%"
     * ซึ่ง Excel อ่านเป็นสูตรที่ผิดไวยากรณ์ แทนที่จะเป็นตัวเลข
     *
     * เอาจุลภาคออกแล้วยังเหลือ "+" ซึ่ง Excel แปลงเป็นสูตรอยู่ดี (มรดก Lotus 1-2-3) —
     * ช่องนี้จึงเป็นสูตรช่องเดียวในไฟล์ และพังบนเครื่องที่ตั้งทศนิยมเป็นจุลภาค
     */
    public function testLargeComparePercentIsPlainNumberText(): void
    {
        $payload = $this->payloadForRecord([
            'record_date' => '2024-01-10',
            'revenue' => 1000000.0,
            'ad_cost' => 1.0,
            'profit' => 999999.0,
            'roas' => 1000000.0,
            'compare_revenue_percent' => 9999900.0,
            'note' => '',
        ]);

        $this->assertSame('9999900.0%', $payload['rows'][0][5]);
        $this->assertStringNotContainsString(',', $payload['rows'][0][5]);
    }

    /**
     * ⭐ ไม่มีเซลล์ไหนในไฟล์ที่ระบบสร้างเองขึ้นต้นด้วยอักขระที่ Excel ถือว่าเป็นสูตร
     *
     * (โน้ตของผู้ใช้เป็นคนละเรื่อง — controller เติม ' ให้เอง)
     */
    public function testNoSystemGeneratedCellStartsAFormula(): void
    {
        $payload = $this->payloadForRecord([
            'record_date' => '2024-01-10',
            'revenue' => 1000000.0,
            'ad_cost' => 1.0,
            'profit' => -999999.5,
            'roas' => 1000000.0,
            'compare_revenue_percent' => 9999900.0,
            'note' => '',
        ]);

        $noteIndex = (int)$payload['note_column_index'];
        foreach ([...$payload['rows'], $payload['totals_row'], $payload['headers']] as $row) {
            foreach ((array)$row as $index => $cell) {
                if ($index === $noteIndex) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/^[=+@\t\r]/',
                    (string)$cell,
                    'เซลล์ที่ระบบสร้างเองกลายเป็นสูตรใน Excel: ' . (string)$cell
                );
            }
        }
    }

    /** ค่าปกติยังอ่านง่ายเหมือนเดิม */
    public function testOrdinaryComparePercentKeepsItsSign(): void
    {
        foreach ([[12.5, '12.5%'], [-11.1, '-11.1%'], [0.0, '0.0%']] as [$percent, $expected]) {
            $data = $this->payloadForRecord([
                'record_date' => '2024-01-10',
                'revenue' => 100.0,
                'ad_cost' => 10.0,
                'profit' => 90.0,
                'roas' => 10.0,
                'compare_revenue_percent' => $percent,
                'note' => '',
            ]);

            $this->assertSame($expected, $data['rows'][0][5]);
        }
    }

    public function testSystemGeneratedNegativeValuesAreNotQuoted(): void
    {
        // ค่าติดลบมาจากระบบ ไม่ใช่ user input → ต้องออกดิบ ไม่มี ' นำหน้า
        $data = $this->payloadForRecord([
            'record_date' => '2024-01-10',
            'revenue' => 100.0,
            'ad_cost' => 800.0,
            'profit' => -700.0,
            'roas' => 0.13,
            'compare_revenue_percent' => -11.1,
            'note' => '',
        ]);

        $row = $data['rows'][0];
        $this->assertSame('-700.00', $row[3]);
        $this->assertSame('-11.1%', $row[5]);
        $this->assertStringStartsNotWith("'", $row[3]);
        $this->assertStringStartsNotWith("'", $row[5]);
    }

    public function testNoteIsLeftRawInPayloadForControllerToSanitize(): void
    {
        // payload คืนโน้ตดิบ — การเติม ' เป็นหน้าที่ของ controller ตอนเขียน CSV
        $data = $this->payloadForRecord([
            'record_date' => '2024-01-10',
            'revenue' => 100.0,
            'ad_cost' => 10.0,
            'profit' => 90.0,
            'roas' => 10.0,
            'compare_revenue_percent' => null,
            'note' => '=SUM(A1:A9)',
        ]);

        $this->assertSame('=SUM(A1:A9)', $data['rows'][0][6]);
    }

    public function testBuildMonthlyCsvFilenameSanitizesShopName(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $shopRepository = $this->createStub(ShopRepository::class);
        $service = new ExportService($recordService, $shopRepository);

        // อักขระต้องห้ามในชื่อไฟล์ (/ \ : * ? " < > |) ถูกแทนด้วย _
        $filename = $service->buildMonthlyCsvFilename('ร้าน/A:B', '2024-01');

        $this->assertStringEndsWith('_2024-01.csv', $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString(':', $filename);
    }
}
