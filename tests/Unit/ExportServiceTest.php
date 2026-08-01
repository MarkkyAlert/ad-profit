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
        $this->assertSame('10 ม.ค. 2567', $row[0]);   // formatThaiDate (พ.ศ.)
        $this->assertSame('1000.00', $row[1]);          // revenue
        $this->assertSame('200.00', $row[2]);           // ad_cost
        $this->assertSame('800.00', $row[3]);           // profit
        $this->assertSame('5.00', $row[4]);             // ROAS
        $this->assertSame('–', $row[5]);                // compare (null → '–')
        $this->assertSame('ปกติ', $row[6]);             // note

        // แถวรวม
        $this->assertSame('รวม', $data['totals_row'][0]);
        $this->assertSame('1000.00', $data['totals_row'][1]);
        $this->assertSame('800.00', $data['totals_row'][3]);
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
