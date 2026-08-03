<?php

declare(strict_types=1);

namespace Tests\Unit;

use ExportService;
use PHPUnit\Framework\TestCase;
use RecordService;
use ShopRepository;

final class ExportServiceTest extends TestCase
{
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
            ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบเมื่อวาน', 'โน้ต'],
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
        $this->assertSame('', $data['rows'][0][5]);   // เทียบเมื่อวาน
        $this->assertSame('', $data['totals_row'][4]); // avg_roas null
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
