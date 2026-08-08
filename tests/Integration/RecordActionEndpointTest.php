<?php

declare(strict_types=1);

namespace Tests\Integration;

use RecordService;

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

    /** ⭐ ยอดรายวันเป็น actuals: แก้รายการไปวันอนาคตไม่ได้ */
    public function testEditingIntoAFutureDateIsRejectedAndKeepsTheOldRecord(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $originalDate = date('Y-m-d', strtotime('-1 day'));
        $futureDate = date('Y-m-d', strtotime('+1 day'));
        $this->createRecord($shopId, $originalDate, 1000.0, 100.0, null);
        $recordId = (int)$this->pdo->query("SELECT id FROM daily_records WHERE shop_id = {$shopId}")->fetchColumn();
        $session = $this->startSession($userId, $shopId);

        $response = $this->postJson('/api/records.php', [
            'action' => 'update',
            'csrf_token' => $this->csrfTokenFor($session),
            'shop_context_id' => (string)$shopId,
            'record_id' => (string)$recordId,
            'record_date' => $futureDate,
            'revenue' => '1000',
            'ad_cost' => '100',
            'month' => substr($originalDate, 0, 7),
        ], $session);

        $this->assertSame(422, $response['status'], (string)$response['body']);
        $this->assertStringContainsString('อนาคต', $response['body']);
        $this->assertSame(
            $originalDate,
            $this->pdo->query("SELECT record_date FROM daily_records WHERE id = {$recordId}")->fetchColumn(),
            'รายการถูกย้ายไปวันอนาคตทั้งที่ service ปฏิเสธแล้ว'
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
    /**
     * ⭐⭐ กดบันทึกซ้ำ / refresh หลัง POST ต้องได้แถวเดียว ไม่เบิ้ล
     *
     * ท่าที่ผู้ใช้ทำจริงบ่อยที่สุด: กดบันทึกแล้วหน้าไม่ตอบทันที เลยกดซ้ำ
     * หรือกด F5 หลังบันทึกแล้วเบราว์เซอร์ถามว่าจะส่งฟอร์มซ้ำไหม
     *
     * ⚠️⚠️ ระบบนี้ **ไม่มีตาราง idempotency** (`database/schema.sql:11` สั่ง DROP ทิ้ง
     * และไม่สร้างใหม่) การกันซ้ำจึงพึ่ง 2 อย่างเท่านั้น:
     *   1. `uq_daily_records_shop_date` + `INSERT … ON DUPLICATE KEY UPDATE` → ทับ ไม่เพิ่มแถว
     *   2. `ShopRepository::lockForWrite()` → การเขียนของร้านเดียวกันเข้าคิว
     * ถ้า unique key หายไป ทุกครั้งที่กดซ้ำจะได้แถวใหม่ **แล้วยอดรวมทุกหน้ารายงาน
     * บวมขึ้นเงียบ ๆ โดยไม่มีอะไรเตือน**
     *
     * ⚠️ เดิมมีเทสต์เรื่องนี้แค่ที่ระดับ service — ระดับ endpoint (ซึ่งเป็นทางที่
     * ผู้ใช้เดินจริง พร้อม guard chain ครบชุด) ไม่เคยมีเทสต์ตัวไหนยิง upsert ซ้ำเลย
     */
    public function testSubmittingTheSameDayTwiceUpdatesInsteadOfDuplicating(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $send = fn(string $revenue, string $note): array => $this->submit($session, $shopId, [
            'action' => 'upsert',
            'record_date' => '2026-08-01',
            'revenue' => $revenue,
            'ad_cost' => '100',
            'note' => $note,
        ]);

        $this->assertSame(302, $send('1000', 'กดครั้งแรก')['status']);
        $this->assertSame(302, $send('2000', 'กดซ้ำครั้งที่สอง')['status']);
        $this->assertSame(302, $send('3000', 'กดซ้ำครั้งที่สาม')['status']);

        $rows = $this->pdo
            ->query("SELECT revenue, note FROM daily_records WHERE shop_id = {$shopId} AND record_date = '2026-08-01'")
            ->fetchAll();

        $this->assertCount(
            1,
            $rows,
            'กดบันทึกวันเดิมซ้ำแล้วเกิดแถวใหม่ — ยอดรวมทุกหน้ารายงานจะบวมโดยไม่มีสัญญาณเตือน'
        );
        $this->assertSame(3000.0, (float)$rows[0]['revenue'], 'ค่าที่เก็บไม่ใช่ของครั้งล่าสุด');
        $this->assertSame('กดซ้ำครั้งที่สาม', (string)$rows[0]['note']);
    }

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

    /**
     * ⭐⭐ ตารางกรอกหลายวันต้องไม่ลบโน้ตเดิมทิ้งเงียบ ๆ เมื่อยังไม่ได้เทียบกับของเดิม
     *
     * ⚠️ อาการที่วัดได้จริง: วันที่ 3 ส.ค. มีโน้ต "ปิดแอด A เปิด B แทน" อยู่ ผู้ใช้กรอก
     * วันที่+ยอดในตาราง (ไม่แตะช่องโน้ต) กดบันทึก → **"บันทึกข้อมูล 1 วันเรียบร้อยแล้ว"**
     * แต่โน้ตหายถาวร · ขณะที่ไฟล์ CSV ที่ทำสิ่งเดียวกันเป๊ะ ๆ ถูกปฏิเสธพร้อมบอกแถว
     *
     * ตารางเติมโน้ตเดิมกลับมาให้เห็นก่อนเสมอ ช่องว่างจึงแปลว่า "ตั้งใจล้าง" —
     * **แต่จริงเฉพาะเมื่อการเติมนั้นเกิดขึ้นจริง** ถ้าโหลดข้อมูลเดือนไม่สำเร็จ
     * ผู้ใช้จะเห็นช่องว่างโดยไม่รู้ว่ามีของเดิมอยู่ ธง `note_checked` แยกสองกรณีนี้
     */
    public function testBulkUpsertRefusesToWipeANoteItNeverShowedTheUser(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note) VALUES (?, ?, ?, ?, ?)'
        )->execute([$shopId, '2026-08-03', 5000, 2000, 'ปิดแอด A เปิด B แทน']);

        $this->submit($session, $shopId, [
            'action' => 'bulk_upsert',
            'row_number[0]' => '1',
            'record_date[0]' => '2026-08-03',
            'revenue[0]' => '7000',
            'ad_cost[0]' => '2500',
            'note[0]' => '',
            'note_checked[0]' => '',   // โหลดข้อมูลเดิมไม่สำเร็จ = ยังไม่เคยเห็นโน้ตเดิม
        ]);

        $this->assertSame(
            'ปิดแอด A เปิด B แทน',
            (string)$this->pdo
                ->query("SELECT note FROM daily_records WHERE shop_id = {$shopId} AND record_date = '2026-08-03'")
                ->fetchColumn(),
            'โน้ตถูกลบทิ้งทั้งที่ผู้ใช้ไม่เคยเห็นว่ามันมีอยู่'
        );
        $this->assertStringContainsString('เว้นช่องโน้ต', $this->flashMessages($session), 'ไม่ได้บอกผู้ใช้ว่าเกิดอะไรขึ้น');
    }

    /**
     * ⚠️ อีกด้านของกติกาเดียวกัน — เห็นโน้ตเดิมแล้วตั้งใจล้าง ต้องล้างได้จริง
     *
     * ถ้ากันหมดทุกกรณี ผู้ใช้จะลบโน้ตผ่านตารางไม่ได้เลยตลอดกาล
     */
    public function testBulkUpsertStillLetsTheUserClearANoteOnPurpose(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note) VALUES (?, ?, ?, ?, ?)'
        )->execute([$shopId, '2026-08-03', 5000, 2000, 'โน้ตที่จะลบ']);

        $this->submit($session, $shopId, [
            'action' => 'bulk_upsert',
            'row_number[0]' => '1',
            'record_date[0]' => '2026-08-03',
            'revenue[0]' => '7000',
            'ad_cost[0]' => '2500',
            'note[0]' => '',
            'note_checked[0]' => '1',  // เห็นโน้ตเดิมแล้ว แล้วลบออกเอง
        ]);

        $this->assertNull(
            $this->pdo
                ->query("SELECT note FROM daily_records WHERE shop_id = {$shopId} AND record_date = '2026-08-03'")
                ->fetchColumn() ?: null,
            'ผู้ใช้ตั้งใจล้างโน้ตแล้วยังล้างไม่ได้'
        );
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

        // ⚠️ ต้องใหญ่เกิน 2MB แต่มีจำนวนแถวน้อย ๆ ไม่งั้นจะไปติดด่าน "แถวต่อครั้ง"
        // ก่อน แล้วเทสต์จะผ่านด้วยเหตุผลคนละเรื่องกับชื่อของมัน
        $padding = str_repeat('x', 300000);
        $bigCsv = "date,revenue,ad_cost,note\n";
        for ($row = 1; $row <= 10; $row++) {
            $bigCsv .= sprintf("2026-08-%02d,5000,1200,%s\n", $row, $padding);
        }
        $this->assertGreaterThan(2 * 1024 * 1024, strlen($bigCsv), 'ไฟล์ทดสอบยังไม่เกิน 2MB');

        $response = $this->postFile(
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

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString(
            'ไฟล์ใหญ่เกิน 2MB',
            $this->flashMessages($session),
            'ไม่ได้มาจากด่านของแอป — อาจไปติดเพดานของ PHP หรือด่านจำนวนแถวก่อน'
        );
        $this->assertSame(0, $this->countRows('daily_records'), 'ไฟล์ใหญ่เกินกำหนดถูกนำเข้าได้');
    }

    /**
     * ⭐⭐ ไฟล์ที่ "เล็กพอผ่านด่านขนาด แต่แถวเยอะมหาศาล" ต้องถูกปฏิเสธ ไม่ใช่ทำให้หน้าเว็บพัง
     *
     * ⚠️ เทสต์ตัวบน (`testAnOversizedFileIsRejected`) คุมด้าน "ใหญ่เกิน 2MB แถวน้อย"
     * ตัวนี้คือ **ด้านตรงข้ามที่ตกสำรวจ**: เพดานของแอปมี 2 ชั้น (ขนาดไฟล์ 2MB ที่
     * `api/records.php` และจำนวนแถว `IMPORT_MAX_ROWS` ที่ service) — ไฟล์ที่แต่ละแถว
     * สั้นมากจึงมีแถวได้เป็นแสนโดยขนาดยังไม่ถึง 2MB คือช่องที่หลุดทั้งสองชั้น
     *
     * ⚠️⚠️ วัดจริงก่อนแก้ (ไฟล์ 120,000 แถว = 1.95MB): `Allowed memory size of
     * 134217728 bytes exhausted` แล้วผู้ใช้ได้ **HTTP 500** แทนข้อความไทยที่บอกว่า
     * ต้องแก้อะไร · สาเหตุคือ `parseImportCsv()` สะสมแถวจนครบทั้งไฟล์ *ก่อน* แล้ว
     * เพดานจำนวนแถวเพิ่งถูกตรวจทีหลังใน `upsertManyRecords()` — หน่วยความจำหมดก่อน
     * ที่เพดานจะได้ทำงาน · ที่ 100,000 แถว (1.62MB) ยังปฏิเสธถูกต้อง จุดพังอยู่ระหว่างนั้น
     *
     * ⚠️ ต้องใช้ไฟล์ใหญ่จริงเท่านั้น — ไฟล์เล็ก (เช่น 5,000 แถว) ผ่านทั้งก่อนและหลังแก้
     * จึงพิสูจน์อะไรไม่ได้เลย · และเซิร์ฟเวอร์ของเทสต์ปัก `memory_limit` ไว้แน่นอน
     * (ดู `ControllerTestCase`) ไม่งั้นเครื่องที่ตั้งไม่จำกัดจะเขียวโดยไม่ได้ตรวจอะไร
     */
    public function testAFileUnderTheSizeCapWithTooManyRowsIsRejectedInsteadOfCrashing(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $manyRowCsv = "date,revenue,ad_cost,note\n" . str_repeat("2026-05-01,1,1,x\n", 120000);
        $this->assertLessThan(
            2 * 1024 * 1024,
            strlen($manyRowCsv),
            'ไฟล์ทดสอบใหญ่เกิน 2MB — จะไปติดด่านขนาดไฟล์ก่อน ไม่ได้ทดสอบด่านจำนวนแถว'
        );

        $response = $this->postFile(
            '/api/records.php',
            [
                'action' => 'import_csv',
                'csrf_token' => $this->csrfTokenFor($session),
                'shop_context_id' => (string)$shopId,
            ],
            'csv',
            'แถวเยอะมาก.csv',
            $manyRowCsv,
            $session
        );
        unset($manyRowCsv);

        $this->assertStringNotContainsStringIgnoringCase(
            'Allowed memory size',
            $response['body'],
            'หน่วยความจำหมดระหว่างอ่านไฟล์ — เพดานจำนวนแถวถูกตรวจช้าเกินไป'
        );
        $this->assertSame(
            302,
            $response['status'],
            'ไฟล์ที่แถวเยอะเกินทำให้คำขอล้มกลางคัน แทนที่จะถูกปฏิเสธพร้อมข้อความไทย'
        );
        $this->assertStringContainsString(
            'กรอกได้สูงสุด ' . RecordService::IMPORT_MAX_ROWS . ' แถวต่อครั้ง',
            $this->flashMessages($session),
            'ปฏิเสธด้วยเหตุผลอื่น — ข้อความต้องเป็นตัวเดียวกับตอนบันทึก ผู้ใช้จะได้รู้ว่าต้องแบ่งไฟล์'
        );
        $this->assertSame(0, $this->countRows('daily_records'), 'ไฟล์ที่ถูกปฏิเสธกลับเขียนข้อมูลลงไปได้');
    }

    /**
     * ⭐ ส่งข้อมูลใหญ่จน PHP ทิ้งทั้งก้อน → ต้องบอก 413 ไม่ใช่ "ไม่รู้จักคำสั่ง" 404
     *
     * เมื่อ body เกิน `post_max_size` PHP จะล้าง `$_POST`/`$_FILES` ทิ้งทั้งหมด
     * ทุก endpoint จึงอ่าน `action` ไม่เจอแล้วตอบ 404 ซึ่งเดาไม่ได้เลยว่าเกิดอะไรขึ้น
     * · ด่านนี้ (`ensure_post_body_not_truncated_or_respond`) เดิมไม่มีเทสต์แตะเลย
     */
    public function testABodyTooLargeForPhpIsReportedAsTooLarge(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        // ⚠️ ไม่ส่งไฟล์ใหญ่จริง (กินหน่วยความจำจนชุดเทสต์ล้ม) — จำลอง **สภาพเดียวกัน**
        // ที่ด่านนี้ตรวจ: body มีความยาว แต่ PHP แกะเป็น $_POST/$_FILES ไม่ได้เลย
        // (ที่นี่ใช้ boundary ที่ไม่ตรงกับเนื้อ ซึ่งให้ผลเหมือน body ถูกทิ้งเพราะใหญ่เกิน)
        $response = $this->request(
            'POST',
            '/api/records.php',
            [],
            $session,
            ['Content-Type' => 'multipart/form-data; boundary=----boundary-mismatched'],
            "------cetamai\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\nimport_csv\r\n------cetamai--\r\n"
        );

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString(
            'ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้',
            $this->flashMessages($session),
            'PHP ทิ้ง body ทั้งก้อนแล้วระบบตอบ "ไม่รู้จักคำสั่ง" แทนที่จะบอกว่าไฟล์ใหญ่เกิน'
        );
        $this->assertSame(0, $this->countRows('daily_records'));
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
