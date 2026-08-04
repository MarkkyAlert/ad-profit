<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ตรรกะเฉพาะของ `api/shops.php` — ส่วนที่ไม่ได้อยู่ใน Service
 *
 * ไฟล์นี้ทำมากกว่าเรียก Service: มันแก้ `$_SESSION['current_shop_id']` เอง
 * ทั้งตอนสร้าง สลับ เปลี่ยนชื่อ และลบร้าน ซึ่งเป็นตัวตัดสินว่า "ตอนนี้ผู้ใช้อยู่ร้านไหน"
 * ทุกหน้าถัดจากนั้น · ผิดตรงนี้ = ข้อมูลไปลงร้านผิดโดยที่ไม่มีอะไรเตือน
 */
final class ShopEndpointTest extends ControllerTestCase
{
    /**
     * @param array<string,string> $fields
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function submit(string $session, array $fields): array
    {
        return $this->postJson('/api/shops.php', $fields + ['csrf_token' => $this->csrfTokenFor($session)], $session);
    }

    private function sessionShopId(string $session): int
    {
        $raw = $this->flashMessages($session);
        if (preg_match('/current_shop_id\|i:(\d+);/', $raw, $matched) === 1) {
            return (int)$matched[1];
        }

        return 0;
    }

    /** ⭐ สร้างร้านใหม่แล้วต้อง "ย้ายไปอยู่" ร้านนั้นทันที ไม่ใช่ค้างอยู่ร้านเดิม */
    public function testCreatingAShopSwitchesToIt(): void
    {
        $userId = $this->createUser();
        $firstShop = $this->createShop($userId, 'ร้านแรก');
        $session = $this->startSession($userId, $firstShop);

        $response = $this->submit($session, ['action' => 'create', 'name' => 'ร้านที่สอง']);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $newShopId = (int)$this->pdo
            ->query("SELECT id FROM shops WHERE user_id = {$userId} AND name = 'ร้านที่สอง'")->fetchColumn();
        $this->assertGreaterThan(0, $newShopId, 'ไม่ได้สร้างร้านใหม่');
        $this->assertSame($newShopId, $this->sessionShopId($session), 'สร้างแล้วยังค้างอยู่ร้านเดิม');
    }

    /**
     * ⭐ ชื่อซ้ำ = สลับไปร้านนั้น ไม่ใช่สร้างแถวใหม่ และไม่ติดโควตา 20 ร้าน
     *
     * เดิมโควตาถูกเช็กก่อน ผู้ใช้ที่ครบ 20 ร้านจึงพิมพ์ชื่อร้านของตัวเองไม่ได้เลย
     */
    public function testAnExistingNameSwitchesInsteadOfCreating(): void
    {
        $userId = $this->createUser();
        $target = $this->createShop($userId, 'ร้านเป้าหมาย');
        for ($index = 2; $index <= 20; $index++) {
            $this->createShop($userId, 'ร้านที่ ' . $index);
        }
        $current = $this->createShop($userId, 'ร้านล่าสุด');   // ครบ 21? ไม่ — createShop ตรงนี้เขียน DB ตรง

        $session = $this->startSession($userId, $current);
        $before = $this->countRows('shops');

        $response = $this->submit($session, ['action' => 'create', 'name' => 'ร้านเป้าหมาย']);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $this->assertSame($before, $this->countRows('shops'), 'สร้างแถวซ้ำสำหรับชื่อที่มีอยู่แล้ว');
        $this->assertSame($target, $this->sessionShopId($session), 'ไม่ได้สลับไปร้านที่ชื่อตรงกัน');
    }

    /** ⭐ สลับไปร้านของคนอื่นไม่ได้ และ session ต้องไม่ขยับ */
    public function testSwitchingToAnotherUsersShopIsRejected(): void
    {
        $userId = $this->createUser();
        $ownShop = $this->createShop($userId, 'ร้านของฉัน');
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');

        $session = $this->startSession($userId, $ownShop);
        $response = $this->submit($session, ['action' => 'switch', 'shop_id' => (string)$strangerShop]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame($ownShop, $this->sessionShopId($session), 'session ถูกย้ายไปร้านของคนอื่น');
    }

    /** ⭐ เปลี่ยนชื่อร้านที่กำลังเปิดอยู่ ต้องอัปเดตชื่อใน session ด้วย */
    public function testRenamingTheCurrentShopUpdatesTheSessionName(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ชื่อเดิม');
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit($session, [
            'action' => 'rename',
            'shop_id' => (string)$shopId,
            'name' => 'ชื่อใหม่',
        ]);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $this->assertSame('ชื่อใหม่', (string)$this->pdo
            ->query("SELECT name FROM shops WHERE id = {$shopId}")->fetchColumn());
        $this->assertStringContainsString('ชื่อใหม่', $this->flashMessages($session), 'ชื่อใน session ยังเป็นของเก่า');
    }

    /** ⭐ ลบร้านต้องพิมพ์ชื่อยืนยันให้ตรง — พิมพ์ผิดแล้วห้ามลบ */
    public function testDeletingWithoutTheTypedConfirmationIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านที่จะลบ');
        $this->createShop($userId, 'ร้านสำรอง');
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1000.0, null);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit($session, [
            'action' => 'delete',
            'shop_id' => (string)$shopId,
            'confirm_shop_name' => 'พิมพ์ผิด',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame(2, $this->countRows('shops'), 'ลบร้านทั้งที่ยืนยันไม่ตรง');
        $this->assertSame(1, $this->countRows('daily_records'), 'ข้อมูลในร้านหายไปด้วย');
    }

    /** ⭐ ลบร้านสุดท้ายไม่ได้ แม้พิมพ์ชื่อยืนยันถูก */
    public function testTheLastShopCannotBeDeleted(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านเดียว');
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit($session, [
            'action' => 'delete',
            'shop_id' => (string)$shopId,
            'confirm_shop_name' => 'ร้านเดียว',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame(1, $this->countRows('shops'));
    }

    /**
     * ⭐ ลบร้านที่กำลังเปิดอยู่สำเร็จ → session ต้องย้ายไปร้านที่ยังเหลือ
     *
     * ถ้า session ยังชี้ร้านที่ถูกลบ หน้าถัดไปจะขึ้น "ไม่มีสิทธิ์เข้าถึงร้านค้านี้"
     * ทั้งที่ผู้ใช้เพิ่งทำสิ่งที่ระบบบอกให้ทำ
     */
    public function testDeletingTheCurrentShopMovesTheSessionToAnother(): void
    {
        $userId = $this->createUser();
        $doomed = $this->createShop($userId, 'ร้านที่จะลบ');
        $survivor = $this->createShop($userId, 'ร้านที่เหลือ');
        $this->createRecord($doomed, '2026-08-01', 5000.0, 1000.0, null);
        $session = $this->startSession($userId, $doomed);

        $response = $this->submit($session, [
            'action' => 'delete',
            'shop_id' => (string)$doomed,
            'confirm_shop_name' => 'ร้านที่จะลบ',
        ]);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $this->assertSame(1, $this->countRows('shops'));
        $this->assertSame($survivor, $this->sessionShopId($session), 'session ยังชี้ร้านที่เพิ่งถูกลบ');
        $this->assertSame(0, $this->countRows('daily_records'), 'ข้อมูลของร้านที่ถูกลบยังค้างอยู่');
    }

    /** ⭐ ลบร้านของคนอื่นไม่ได้ แม้จะรู้ทั้ง id และชื่อ */
    public function testAnotherUsersShopCannotBeDeleted(): void
    {
        $userId = $this->createUser();
        $ownShop = $this->createShop($userId, 'ร้านของฉัน');
        $this->createShop($userId, 'ร้านสำรอง');
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');

        $session = $this->startSession($userId, $ownShop);
        $response = $this->submit($session, [
            'action' => 'delete',
            'shop_id' => (string)$strangerShop,
            'confirm_shop_name' => 'ร้านของคนอื่น',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame(
            1,
            (int)$this->pdo->query("SELECT COUNT(*) FROM shops WHERE id = {$strangerShop}")->fetchColumn(),
            'ลบร้านของผู้ใช้คนอื่นได้'
        );
    }
}
