<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * integration test ของ bulk entry — ใช้ DB จริง เพื่อพิสูจน์ atomicity + unique(shop,date)
 */
final class RecordServiceBulkTest extends IntegrationTestCase
{
    private function makeService(): RecordService
    {
        return new RecordService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo),
            $this->pdo
        );
    }

    public function testBulkUpsertWritesAllRows(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['record_date' => '2024-01-01', 'revenue' => 1000, 'ad_cost' => 200, 'note' => 'วันแรก'],
            ['record_date' => '2024-01-02', 'revenue' => 1500, 'ad_cost' => 300, 'note' => null],
            ['record_date' => '2024-01-03', 'revenue' => 900, 'ad_cost' => 0, 'note' => null],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['saved_count']);
        $this->assertSame(3, $this->countRows('daily_records'));
    }

    public function testBulkUpsertOnExistingDateUpdatesInsteadOfInserting(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // มีข้อมูลวันที่ 2024-01-01 อยู่ก่อนแล้ว
        $this->createRecord($shopId, '2024-01-01', 100.0, 10.0, 'เดิม');
        $this->assertSame(1, $this->countRows('daily_records'));

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['record_date' => '2024-01-01', 'revenue' => 2000, 'ad_cost' => 400, 'note' => 'ทับใหม่'],
            ['record_date' => '2024-01-02', 'revenue' => 800, 'ad_cost' => 100, 'note' => null],
        ]);

        $this->assertTrue($result['success']);

        // unique(shop_id, record_date) → วันเดิมถูก UPDATE ไม่ใช่เพิ่มแถวใหม่
        $this->assertSame(2, $this->countRows('daily_records'));

        $stmt = $this->pdo->prepare('SELECT revenue, ad_cost, note FROM daily_records WHERE shop_id = ? AND record_date = ?');
        $stmt->execute([$shopId, '2024-01-01']);
        $row = $stmt->fetch();

        $this->assertSame(2000.0, (float)$row['revenue']);
        $this->assertSame(400.0, (float)$row['ad_cost']);
        $this->assertSame('ทับใหม่', $row['note']);
    }

    public function testBatchWithInvalidRowWritesNothing(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['record_date' => '2024-02-01', 'revenue' => 1000, 'ad_cost' => 200, 'note' => null],
            ['record_date' => '2024-02-02', 'revenue' => 1000, 'ad_cost' => 200, 'note' => null],
            ['record_date' => '2024-02-03', 'revenue' => -1, 'ad_cost' => 200, 'note' => null], // ผิด
        ]);

        $this->assertFalse($result['success']);

        // ATOMIC: ต้องไม่มีแถวไหนถูกเขียนเลย
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    public function testBatchWithInvalidRowDoesNotOverwriteExistingData(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2024-03-01', 111.0, 11.0, 'ของเดิม');

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, [
            ['record_date' => '2024-03-01', 'revenue' => 9999, 'ad_cost' => 1, 'note' => 'ไม่ควรถูกเขียน'],
            ['record_date' => '2024-03-02', 'revenue' => 'abc', 'ad_cost' => 1, 'note' => null], // ผิด
        ]);

        $this->assertFalse($result['success']);

        // ข้อมูลเดิมต้องไม่ถูกแตะ
        $this->assertSame(1, $this->countRows('daily_records'));
        $stmt = $this->pdo->prepare('SELECT revenue, note FROM daily_records WHERE shop_id = ? AND record_date = ?');
        $stmt->execute([$shopId, '2024-03-01']);
        $row = $stmt->fetch();

        $this->assertSame(111.0, (float)$row['revenue']);
        $this->assertSame('ของเดิม', $row['note']);
    }

    /**
     * พิสูจน์ว่า transaction rollback ทำงานจริง
     *
     * เทสต์ atomicity ตัวอื่นผ่านได้เพราะ validate ทุกแถวก่อนเขียน (แถวผิดไม่ถึง loop เขียน)
     * ซึ่งยัง "ไม่พิสูจน์" ว่า rollback ใช้ได้ → เทสต์นี้จึงให้ repository จริงเขียน 2 แถวแรกสำเร็จ
     * แล้วโยน exception กลางคัน ถ้า transaction ถูกต้อง 2 แถวแรกต้องถูก rollback หายไปด้วย
     */
    public function testTransactionRollsBackRowsAlreadyWrittenWhenWriteFailsMidBatch(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $failingRepository = new class ($this->pdo) extends RecordRepository {
            private int $calls = 0;

            public function upsert(int $shopId, string $recordDate, float $revenue, float $adCost, ?string $note): bool
            {
                $this->calls++;
                if ($this->calls === 3) {
                    throw new \RuntimeException('simulated DB failure mid-batch');
                }

                return parent::upsert($shopId, $recordDate, $revenue, $adCost, $note);
            }
        };

        $service = new RecordService($failingRepository, new ShopRepository($this->pdo), $this->pdo);

        $result = $service->upsertManyRecords($userId, $shopId, [
            ['record_date' => '2024-05-01', 'revenue' => 100, 'ad_cost' => 10, 'note' => null],
            ['record_date' => '2024-05-02', 'revenue' => 200, 'ad_cost' => 20, 'note' => null],
            ['record_date' => '2024-05-03', 'revenue' => 300, 'ad_cost' => 30, 'note' => null], // ระเบิดตรงนี้
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่สามารถบันทึกข้อมูลได้', $result['error']);

        // 2 แถวแรกเขียนไปแล้วจริงในระหว่าง transaction → ต้องถูก rollback ทั้งหมด
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    public function testImportSizedBatchWritesAllRowsAtomically(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // 50 แถว (เกินเพดานฟอร์ม 31) — ใช้ cap ของการนำเข้า
        $rows = [];
        $date = new \DateTimeImmutable('2026-01-01');
        for ($index = 0; $index < 50; $index++) {
            $rows[] = [
                'record_date' => $date->modify('+' . $index . ' days')->format('Y-m-d'),
                'revenue' => 1000 + $index,
                'ad_cost' => 100,
                'note' => null,
            ];
        }

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, $rows, RecordService::IMPORT_MAX_ROWS);

        $this->assertTrue($result['success']);
        $this->assertSame(50, $result['saved_count']);
        $this->assertSame(50, $this->countRows('daily_records'));
    }

    public function testImportSizedBatchWithBadRowWritesNothing(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $rows = [];
        $date = new \DateTimeImmutable('2026-02-01');
        for ($index = 0; $index < 40; $index++) {
            $rows[] = [
                'record_date' => $date->modify('+' . $index . ' days')->format('Y-m-d'),
                'revenue' => 1000,
                'ad_cost' => 100,
                'note' => null,
            ];
        }
        // แถวผิดกลางไฟล์ (ค่าแอดติดลบ)
        $rows[20]['ad_cost'] = -5;

        $result = $this->makeService()->upsertManyRecords($userId, $shopId, $rows, RecordService::IMPORT_MAX_ROWS);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('daily_records'));   // atomic
    }

    public function testParsedCsvImportsIntoDatabase(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $service = $this->makeService();

        $csv = "\xEF\xBB\xBF" . "วันที่,รายได้,ค่าแอด,กำไร,ROAS,โน้ต\n"
            . "2 ส.ค. 2569,\"1,000.00\",200.00,800.00,5.00,คอร์ส A\n"
            . "3 ส.ค. 2569,1500.50,0.00,1500.50,–,\n"
            . "รวม,2500.50,200.00,2300.50,5.00,–\n";

        $parsed = $service->parseImportCsv($csv);
        $this->assertTrue($parsed['success']);

        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows'], RecordService::IMPORT_MAX_ROWS);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $this->countRows('daily_records'));

        $stmt = $this->pdo->prepare('SELECT revenue, ad_cost, note FROM daily_records WHERE shop_id = ? AND record_date = ?');
        $stmt->execute([$shopId, '2026-08-02']);
        $row = $stmt->fetch();

        $this->assertSame(1000.0, (float)$row['revenue']);
        $this->assertSame(200.0, (float)$row['ad_cost']);
        $this->assertSame('คอร์ส A', $row['note']);
    }

    public function testBulkUpsertRejectsShopOfAnotherUser(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopId = $this->createShop($ownerId);
        $otherUserId = $this->createUser('intruder@example.com');

        $result = $this->makeService()->upsertManyRecords($otherUserId, $shopId, [
            ['record_date' => '2024-04-01', 'revenue' => 100, 'ad_cost' => 10, 'note' => null],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
        $this->assertSame(0, $this->countRows('daily_records'));
    }
}
