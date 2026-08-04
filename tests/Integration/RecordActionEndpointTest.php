<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * คำสั่งที่เหลือของ `api/records.php` — เดิมมีเทสต์เฉพาะ `upsert`
 *
 * `update` / `delete` / `bulk_upsert` / `import_csv` มีตรรกะเป็นของ controller เอง:
 *  · `update` ต้องพาไป "เดือนของวันที่ใหม่" หลังย้ายรายการข้ามเดือน ไม่งั้นผู้ใช้เห็น
 *    "แก้ไขเรียบร้อย" พร้อมตารางที่ไม่มีรายการนั้น แล้วเข้าใจว่าโดนลบ
 *  · `bulk_upsert` ประกอบ `$_POST` ที่เป็นอาร์เรย์กลับเป็นแถว พร้อมเลขแถวที่ผู้ใช้เห็น
 *  · `import_csv` มีด่านตรวจไฟล์ 6 ด่านที่ไม่เคยมีใครแตะเลย (ส่งไฟล์ผ่านเทสต์ไม่ได้มาก่อน)
 */
final class RecordActionEndpointTest extends ControllerTestCase
{
    /**
     * @param array<string,scalar> $fields
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function submit(string $session, int $shopId, array $fields): array
    {
        return $this->post('/api/records.php', $fields + [
            'csrf_token' => $this->csrfTokenFor($session),
            'shop_context_id' => (string)$shopId,
        ], $session);
    }

    /** ⭐ แก้ไขรายการแล้วค่าต้องเปลี่ยนจริง */
    public function testUpdateChangesTheStoredValues(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 1000.0, 100.0, 'โน้ตเดิม');
        $recordId = (int)$this->pdo->query("SELECT id FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn();
        $session = $this->startSession($userId, $shopId);

        $this->submit($session, $shopId, [
            'action' => 'update',
            'record_id' => (string)$recordId,
            'record_date' => '2026-08-01',
            'revenue' => '7000',
            'ad_cost' => '2000',
            'note' => 'โน้ตใหม่',
        ]);

        $row = $this->pdo->query("SELECT revenue, ad_cost, note FROM daily_records WHERE id = {$recordId}")->fetch();
        $this->assertSame(7000.0, (float)$row['revenue']);
        $this->assertSame(2000.0, (float)$row['ad_cost']);
        $this->assertSame('โน้ตใหม่', $row['note']);
    }

    /**
     * ⭐ ย้ายรายการไปเดือนอื่น → ต้องพาผู้ใช้ไปดูเดือนนั้น
     *
     * ไม่งั้นขึ้น "แก้ไขเรียบร้อยแล้ว" พร้อมตารางเดือนเดิมที่ไม่มีรายการนั้น
     * ผู้ใช้เข้าใจว่าข้อมูลหาย
     */
    public function testEditingIntoAnotherMonthRedirectsToThatMonth(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 1000.0, 100.0, null);
        $recordId = (int)$this->pdo->query("SELECT id FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn();
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit($session, $shopId, [
            'action' => 'update',
            'record_id' => (string)$recordId,
            'record_date' => '2026-07-15',
            'revenue' => '1000',
            'ad_cost' => '100',
            'month' => '2026-08',
        ]);

        $this->assertStringContainsString(
            '2026-07',
            $response['headers']['location'] ?? '',
            'พากลับไปเดือนเดิมที่ไม่มีรายการนั้นแล้ว'
        );
    }

    /** ⭐ แก้ไขรายการของร้านคนอื่นไม่ได้ */
    public function testUpdatingAnotherUsersRecordIsRejected(): void
    {
        $userId = $this->createUser();
        $ownShop = $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');
        $this->createRecord($strangerShop, '2026-08-01', 5000.0, 1000.0, null);
        $recordId = (int)$this->pdo
            ->query("SELECT id FROM daily_records WHERE shop_id = {$strangerShop}")->fetchColumn();

        $session = $this->startSession($userId, $ownShop);
        $this->submit($session, $ownShop, [
            'action' => 'update',
            'record_id' => (string)$recordId,
            'record_date' => '2026-08-01',
            'revenue' => '1',
            'ad_cost' => '1',
        ]);

        $this->assertSame(5000.0, (float)$this->pdo
            ->query("SELECT revenue FROM daily_records WHERE id = {$recordId}")->fetchColumn());
    }

    /** ⭐ ลบรายการแล้วต้องหายจริง และลบซ้ำต้องไม่ขึ้น error แดง */
    public function testDeleteRemovesTheRowAndIsIdempotent(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 1000.0, 100.0, null);
        $recordId = (int)$this->pdo->query("SELECT id FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn();
        $session = $this->startSession($userId, $shopId);

        $this->submit($session, $shopId, ['action' => 'delete', 'record_id' => (string)$recordId]);
        $this->assertSame(0, $this->countRows('daily_records'));

        $second = $this->postJson('/api/records.php', [
            'action' => 'delete',
            'csrf_token' => $this->csrfTokenFor($session),
            'shop_context_id' => (string)$shopId,
            'record_id' => (string)$recordId,
        ], $session);

        $this->assertSame(200, $second['status'], 'กด back แล้วลบซ้ำขึ้น error ทั้งที่ลบไปแล้ว');
    }

    /** ⭐ ลบรายการของร้านคนอื่นไม่ได้ */
    public function testDeletingAnotherUsersRecordIsRejected(): void
    {
        $userId = $this->createUser();
        $ownShop = $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');
        $this->createRecord($strangerShop, '2026-08-01', 5000.0, 1000.0, null);
        $recordId = (int)$this->pdo
            ->query("SELECT id FROM daily_records WHERE shop_id = {$strangerShop}")->fetchColumn();

        $session = $this->startSession($userId, $ownShop);
        $this->submit($session, $ownShop, ['action' => 'delete', 'record_id' => (string)$recordId]);

        $this->assertSame(1, $this->countRows('daily_records'), 'ลบรายการของผู้ใช้คนอื่นได้');
    }

    /** ⭐ ตารางกรอกหลายวัน: ทุกแถวต้องเข้าฐานข้อมูลครบและตรงวัน */
    public function testBulkUpsertWritesEveryRow(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit($session, $shopId, [
            'action' => 'bulk_upsert',
            'record_date[0]' => '2026-08-01',
            'revenue[0]' => '1000',
            'ad_cost[0]' => '100',
            'note[0]' => 'วันแรก',
            'record_date[1]' => '2026-08-02',
            'revenue[1]' => '2000',
            'ad_cost[1]' => '200',
            'note[1]' => '',
        ]);

        $this->assertSame(302, $response['status']);
        $rows = $this->pdo
            ->query("SELECT record_date, revenue FROM daily_records WHERE shop_id = {$shopId} ORDER BY record_date")
            ->fetchAll();
        $this->assertCount(2, $rows, 'บันทึกไม่ครบทุกแถว');
        $this->assertSame('2026-08-01', $rows[0]['record_date']);
        $this->assertSame(2000.0, (float)$rows[1]['revenue']);
    }

    /** ⭐ แถวที่ผิดต้องรายงาน "แถวที่เท่าไหร่" ตามที่ผู้ใช้เห็นบนจอ */
    public function testBulkUpsertReportsTheRowNumberTheUserSees(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->submit($session, $shopId, [
            'action' => 'bulk_upsert',
            'record_date[0]' => '2026-08-01',
            'revenue[0]' => '1000',
            'ad_cost[0]' => '100',
            'record_date[1]' => '2026-08-02',
            'revenue[1]' => '-500',
            'ad_cost[1]' => '200',
        ]);

        $this->assertStringContainsString('แถวที่ 2', $this->flashMessages($session));
        $this->assertSame(0, $this->countRows('daily_records'), 'เขียนครึ่ง ๆ กลาง ๆ ทั้งที่มีแถวผิด');
    }

    /** ⭐ นำเข้าไฟล์ CSV ที่ถูกต้องต้องเข้าฐานข้อมูลจริง */
    public function testImportingAValidCsvSavesEveryRow(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->postFile(
            '/api/records.php',
            [
                'action' => 'import_csv',
                'csrf_token' => $this->csrfTokenFor($session),
                'shop_context_id' => (string)$shopId,
            ],
            'csv',
            'ยอดขาย.csv',
            "date,revenue,ad_cost,note\n2026-08-01,5000,1200,เปิดตัว\n2026-08-02,6200,1500,\n",
            $session
        );

        $this->assertSame(302, $response['status'], (string)$response['body']);
        $this->assertSame(2, $this->countRows('daily_records'), 'ไฟล์ที่ถูกต้องยังนำเข้าไม่ได้');
    }

    /** ⭐ ไม่แนบไฟล์มาเลย → ต้องบอกให้ชัด ไม่ใช่เงียบหรือขึ้น 404 */
    public function testImportingWithoutAFileIsReported(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->submit($session, $shopId, ['action' => 'import_csv']);

        $this->assertStringContainsString('ไฟล์', $this->flashMessages($session));
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /** ⭐ นามสกุลไฟล์ที่ไม่รองรับต้องถูกปฏิเสธ */
    public function testAnUnsupportedFileTypeIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->postFile(
            '/api/records.php',
            [
                'action' => 'import_csv',
                'csrf_token' => $this->csrfTokenFor($session),
                'shop_context_id' => (string)$shopId,
            ],
            'csv',
            'รูปภาพ.png',
            "date,revenue,ad_cost\n2026-08-01,5000,1200\n",
            $session,
            'image/png'
        );

        $this->assertSame(0, $this->countRows('daily_records'), 'ไฟล์นามสกุลอื่นถูกนำเข้าได้');
    }

    /** ⭐ ไฟล์ที่ใหญ่เกินกำหนดต้องถูกปฏิเสธ ไม่ใช่ทำให้ระบบล่ม */
    public function testAnOversizedFileIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $bigCsv = "date,revenue,ad_cost\n" . str_repeat("2026-08-01,5000,1200\n", 120000);
        $this->assertGreaterThan(2 * 1024 * 1024, strlen($bigCsv));

        $this->postFile(
            '/api/records.php',
            [
                'action' => 'import_csv',
                'csrf_token' => $this->csrfTokenFor($session),
                'shop_context_id' => (string)$shopId,
            ],
            'csv',
            'ใหญ่มาก.csv',
            $bigCsv,
            $session
        );

        $this->assertSame(0, $this->countRows('daily_records'), 'ไฟล์ใหญ่เกินกำหนดถูกนำเข้าได้');
    }

    /** ⭐ ไฟล์ที่มีวันซ้ำกันต้องถูกปฏิเสธทั้งไฟล์ ไม่ใช่เขียนทับตัวเอง */
    public function testACsvWithDuplicateDatesIsRejectedWholesale(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->postFile(
            '/api/records.php',
            [
                'action' => 'import_csv',
                'csrf_token' => $this->csrfTokenFor($session),
                'shop_context_id' => (string)$shopId,
            ],
            'csv',
            'ซ้ำ.csv',
            "date,revenue,ad_cost\n2026-08-01,5000,1200\n2026-08-01,9999,1\n",
            $session
        );

        $this->assertSame(0, $this->countRows('daily_records'));
        $this->assertStringContainsString('ซ้ำ', $this->flashMessages($session));
    }
}
