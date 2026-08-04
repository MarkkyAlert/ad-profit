<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ด่านตรวจของหน้า "บันทึกข้อมูล" — ชั้นที่เดิมไม่มีเทสต์เลยสักตัว
 *
 * ตรรกะเหล่านี้อยู่ที่ controller ล้วน ๆ (ไม่มีใน Service) จึงพิสูจน์ได้ทางเดียวคือ
 * ยิง HTTP จริงแล้วดูสถานะที่ตอบกลับ:
 *  · ไม่ล็อกอิน → ต้องไม่หลุดเข้าไปถึงข้อมูล
 *  · GET / ส่งเป็น JSON → 405 / 415 (ไม่ใช่ "ไม่รู้จักคำสั่ง" 404)
 *  · ไม่มี CSRF → ต้องถูกปฏิเสธ
 *  · หน้าถูกเรนเดอร์ให้ร้าน A แต่ session ชี้ร้าน B แล้ว → 409 (กันลงผิดร้าน)
 */
final class RecordEndpointGuardTest extends ControllerTestCase
{
    /** ⭐ คนที่ไม่ได้ล็อกอินต้องถูกส่งกลับหน้าเข้าสู่ระบบ ไม่ใช่เขียนข้อมูลได้ */
    public function testAnonymousRequestsCannotWrite(): void
    {
        $response = $this->post('/api/records.php', [
            'action' => 'upsert',
            'record_date' => '2026-08-01',
            'revenue' => '1000',
            'ad_cost' => '100',
        ]);

        // ⚠️ ต้องดู "ถูกพาไปไหน" ไม่ใช่แค่ "ไม่ใช่ 200" — การไม่มี CSRF ก็ตอบ 302 เหมือนกัน
        // เทสต์ที่เช็กแค่สถานะจึงเขียวต่อแม้ถอดด่านล็อกอินออกทั้งอัน
        $this->assertSame(302, $response['status'], 'คนที่ไม่ได้ล็อกอินเขียนข้อมูลได้');
        $this->assertStringContainsString(
            'login.php',
            $response['headers']['location'] ?? '',
            'ไม่ได้พากลับไปหน้าเข้าสู่ระบบ — แปลว่าผ่านด่านล็อกอินไปแล้ว'
        );
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /**
     * ⭐ GET ต้องได้ 405 พร้อมบอกวิธีที่ถูกต้อง ไม่ใช่ 404 "ไม่รู้จักคำสั่ง"
     *
     * ⚠️ รหัสสถานะจริงเห็นได้เฉพาะตอนขอคำตอบเป็น JSON — ฟอร์มจากเบราว์เซอร์จะถูก
     * redirect กลับหน้าเดิมพร้อมข้อความ (302) ซึ่งเป็นพฤติกรรมที่ตั้งใจ
     */
    public function testGetIsRejectedAsMethodNotAllowed(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->getJson('/api/records.php?action=upsert', $session);

        $this->assertSame(405, $response['status']);
        $this->assertSame('POST', $response['headers']['allow'] ?? '');
    }

    /** ทางฟอร์มปกติ: GET ต้องถูกส่งกลับหน้าเดิม ไม่ใช่หน้าขาวหรือ 404 */
    public function testGetFromABrowserRedirectsBackWithAMessage(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/api/records.php?action=upsert', $session);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('add-record.php', $response['headers']['location'] ?? '');
        // ⚠️ 302 ไป add-record.php เป็นคำตอบของ "คำสั่งไม่ถูกต้อง" ด้วย — header Allow
        // คือสิ่งเดียวที่แยกได้ว่ามันมาจากด่าน 405 จริง ๆ (ด่านตั้งไว้ก่อน redirect)
        $this->assertSame('POST', $response['headers']['allow'] ?? '', 'ไม่ได้มาจากด่าน 405');
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /** ⭐ ส่งมาเป็น JSON (ไม่ใช่ฟอร์ม) → 415 */
    public function testJsonBodyIsRejectedAsUnsupportedMediaType(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->request(
            'POST',
            '/api/records.php',
            [],
            $session,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            '{"action":"upsert"}'
        );

        $this->assertSame(415, $response['status']);
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /** ⭐ ไม่มี CSRF token = ปฏิเสธ (ฟอร์มจากเว็บอื่นส่งมาแทนผู้ใช้ไม่ได้) */
    public function testMissingCsrfTokenIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $fields = [
            'action' => 'upsert',
            'shop_context_id' => (string)$shopId,
            'record_date' => '2026-08-01',
            'revenue' => '1000',
            'ad_cost' => '100',
        ];

        // ⚠️ โหมดฟอร์มตอบ 302 ทั้งตอนสำเร็จและตอนถูกปฏิเสธ — `assertNotSame(200, …)`
        // จึงผ่านแม้บันทึกสำเร็จ · รหัสสถานะจริงดูได้ในโหมด JSON เท่านั้น
        $this->assertSame(403, $this->postJson('/api/records.php', $fields, $session)['status']);

        $response = $this->post('/api/records.php', $fields, $session);
        $this->assertSame(302, $response['status']);
        $this->assertSame(0, $this->countRows('daily_records'), 'เขียนข้อมูลผ่านทั้งที่ไม่มี CSRF token');
    }

    /**
     * ⭐ เปิดหน้าค้างไว้ที่ร้าน A แล้วสลับไปร้าน B ในอีกแท็บ → กดบันทึกต้องไม่ลงร้าน B
     *
     * มีเทสต์ระดับ helper อยู่แล้ว (ShopContextGuardTest) แต่ไม่เคยพิสูจน์ว่า endpoint
     * เรียกใช้จริงและตอบ 409 — ซึ่งเป็นจุดที่เคยพลาดมาแล้ว (วาง guard ก่อน $shopId ถูกตั้งค่า)
     */
    public function testStaleShopContextIsRejectedWith409(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // session อยู่ที่ร้าน B แล้ว แต่ฟอร์มที่ค้างอยู่ยังบอกว่าเป็นร้าน A
        $session = $this->startSession($userId, $shopB);
        $token = $this->csrfTokenFor($session);

        $fields = [
            'action' => 'upsert',
            'csrf_token' => $token,
            'shop_context_id' => (string)$shopA,
            'record_date' => '2026-08-01',
            'revenue' => '1000',
            'ad_cost' => '100',
        ];

        $this->assertSame(409, $this->postJson('/api/records.php', $fields, $session)['status']);

        // ทางฟอร์มปกติ: ส่งกลับหน้าเดิมพร้อมข้อความ — แต่ต้องไม่เขียนข้อมูลเหมือนกัน
        $formResponse = $this->post('/api/records.php', $fields, $session);
        $this->assertSame(302, $formResponse['status']);
        $this->assertSame(0, $this->countRows('daily_records'), 'บันทึกลงร้านที่ผู้ใช้ไม่ได้มองอยู่');
    }

    /** ⭐ ทางปกติ: ครบทุกด่านแล้วต้องบันทึกได้จริง (ไม่งั้นด่านข้างบนพิสูจน์อะไรไม่ได้) */
    public function testAValidSubmissionActuallySaves(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $token = $this->csrfTokenFor($session);

        $response = $this->post('/api/records.php', [
            'action' => 'upsert',
            'csrf_token' => $token,
            'shop_context_id' => (string)$shopId,
            'record_date' => '2026-08-01',
            'revenue' => '5000',
            'ad_cost' => '1200',
            'note' => 'บันทึกจากเทสต์',
        ], $session);

        $this->assertContains($response['status'], [200, 302], 'ฟอร์มที่ถูกต้องยังบันทึกไม่ได้');

        $row = $this->pdo
            ->query("SELECT revenue, ad_cost, note FROM daily_records WHERE shop_id = {$shopId}")
            ->fetch();

        $this->assertNotFalse($row, 'ไม่มีแถวถูกบันทึก');
        $this->assertSame(5000.0, (float)$row['revenue']);
        $this->assertSame(1200.0, (float)$row['ad_cost']);
        $this->assertSame('บันทึกจากเทสต์', $row['note']);
    }

    /** ⭐ ค่าที่ธุรกิจไม่ยอมรับ (ติดลบ) ต้องไม่ถูกเขียน และต้องบอกผู้ใช้เป็นภาษาไทย */
    public function testABusinessRuleFailureIsReportedNotSaved(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $token = $this->csrfTokenFor($session);

        $this->post('/api/records.php', [
            'action' => 'upsert',
            'csrf_token' => $token,
            'shop_context_id' => (string)$shopId,
            'record_date' => '2026-08-01',
            'revenue' => '-500',
            'ad_cost' => '100',
        ], $session);

        $this->assertSame(0, $this->countRows('daily_records'));
        $this->assertStringContainsString('ติดลบ', $this->flashMessages($session));
    }
}
