<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ด่านตรวจของ **ทุก** endpoint ที่เปลี่ยนข้อมูล — ไม่ใช่แค่ตัวที่นึกออก
 *
 * รากของบั๊กซ้ำ ๆ ในโปรเจกต์นี้คือ "แก้ที่หนึ่ง ลืมคู่แฝด" เทสต์นี้จึงไล่ทุกไฟล์
 * ที่รับ POST พร้อมกันในเทสต์เดียว ถ้ามีคนเพิ่ม endpoint ใหม่แล้วลืมด่านใดด่านหนึ่ง
 * รายชื่อข้างล่างจะเป็นที่แรกที่แดง
 *
 * ⚠️ เพิ่ม endpoint ใหม่ใน `api/` ต้องมาเพิ่มในรายชื่อนี้ด้วย
 */
final class EndpointGuardChainTest extends ControllerTestCase
{
    /**
     * endpoint ที่ "เขียนข้อมูล" ทั้งหมด
     *
     * `auth.php` ไม่อยู่ในรายชื่อเพราะเป็นทางเข้าของคนที่ยังไม่ได้ล็อกอิน
     * (มีเทสต์แยกใน AuthServiceTest / PasswordResetInvalidationTest)
     *
     * @return array<string,array{0:string}>
     */
    public static function writeEndpointProvider(): array
    {
        return [
            'บันทึกรายวัน' => ['/api/records.php'],
            'เป้าหมายรายเดือน' => ['/api/goals.php'],
            'จัดการร้าน' => ['/api/shops.php'],
            'โปรไฟล์' => ['/api/profile.php'],
        ];
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องกันคนที่ยังไม่ได้ล็อกอิน */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsAnonymousRequests(string $path): void
    {
        $response = $this->postJson($path, ['action' => 'upsert']);

        $this->assertContains(
            $response['status'],
            [302, 401, 403],
            $path . ' ปล่อยให้คนที่ไม่ได้ล็อกอินส่งคำสั่งได้'
        );
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องปฏิเสธ GET พร้อมบอกวิธีที่ถูกต้อง */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsGet(string $path): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->getJson($path, $session);

        $this->assertSame(405, $response['status'], $path . ' ยอมรับ GET');
        $this->assertSame('POST', $response['headers']['allow'] ?? '', $path . ' ไม่บอกว่าต้องใช้วิธีไหน');
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องปฏิเสธ body ที่ไม่ใช่ฟอร์ม */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsNonFormBodies(string $path): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->request(
            'POST',
            $path,
            [],
            $session,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            '{"action":"upsert"}'
        );

        $this->assertSame(415, $response['status'], $path . ' ยอมรับ body ที่ไม่ใช่ฟอร์ม');
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องปฏิเสธคำขอที่ไม่มี CSRF token */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsMissingCsrf(string $path): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->postJson($path, ['action' => 'upsert'], $session);

        $this->assertNotSame(200, $response['status'], $path . ' ทำงานให้ทั้งที่ไม่มี CSRF token');
        $this->assertSame(0, $this->countRows('daily_records'));
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /**
     * endpoint ที่ "อ่านอย่างเดียว" — ต้องกันคนนอกเหมือนกัน แม้จะไม่มี CSRF
     *
     * @return array<string,array{0:string}>
     */
    public static function readEndpointProvider(): array
    {
        return [
            'ตารางรายเดือน' => ['/api/month-grid.php?month=2026-08'],
            'ข้อมูลแดชบอร์ด' => ['/api/dashboard-data.php'],
            'ข้อมูลรวมร้าน' => ['/api/overview-data.php'],
            'ข้อมูลรายปี' => ['/api/annual-data.php?year=2026'],
            'ดาวน์โหลด CSV' => ['/api/export.php?month=2026-08'],
            'ดาวน์โหลด Excel' => ['/api/export-xlsx.php?year=2026'],
        ];
    }

    /** ⭐ คนที่ไม่ได้ล็อกอินต้องดึงข้อมูลของคนอื่นไม่ได้ แม้แต่ทางที่อ่านอย่างเดียว */
    #[DataProvider('readEndpointProvider')]
    public function testEveryReadEndpointRejectsAnonymousRequests(string $path): void
    {
        $response = $this->getJson($path);

        $this->assertContains(
            $response['status'],
            [302, 401, 403],
            $path . ' ปล่อยข้อมูลให้คนที่ไม่ได้ล็อกอิน'
        );
    }

    /**
     * ⭐ session ที่ชี้ไปร้านที่ถูกลบไปแล้ว: ทางอ่านต้องซ่อมให้ ทางเขียนต้องล้มดัง ๆ
     *
     * ความต่างนี้ตั้งใจ — ซ่อม session ให้ตอนกำลัง "ดู" คือความสะดวก
     * แต่ซ่อมให้ตอนกำลัง "บันทึก" แปลว่าเงียบ ๆ เปลี่ยนร้านปลายทางให้ผู้ใช้
     */
    public function testReadPathsHealAStaleShopWhileWritePathsDoNot(): void
    {
        $userId = $this->createUser();
        $liveShop = $this->createShop($userId, 'ร้านที่ยังอยู่');
        $deletedShop = $this->createShop($userId, 'ร้านที่จะถูกลบ');
        $this->pdo->exec("DELETE FROM shops WHERE id = {$deletedShop}");

        $session = $this->startSession($userId, $deletedShop);

        // ทางอ่าน: ต้องสลับไปร้านที่ยังอยู่ให้เอง ไม่ใช่ล้ม
        $read = $this->getJson('/api/month-grid.php?month=2026-08', $session);
        $this->assertSame(200, $read['status'], 'ทางอ่านไม่ซ่อม session ที่ชี้ร้านที่ถูกลบ');

        // ทางเขียน: session ตอนนี้ถูกซ่อมเป็น $liveShop แล้ว — ฟอร์มเก่าที่ยังบอกร้านที่ถูกลบ
        // ต้องถูกปฏิเสธ ไม่ใช่เงียบ ๆ บันทึกลงร้านที่ยังอยู่แทน
        $token = $this->csrfTokenFor($session);
        $write = $this->postJson('/api/records.php', [
            'action' => 'upsert',
            'csrf_token' => $token,
            'shop_context_id' => (string)$deletedShop,
            'record_date' => '2026-08-01',
            'revenue' => '1000',
            'ad_cost' => '100',
        ], $session);

        $this->assertSame(409, $write['status'], 'บันทึกลงร้านอื่นให้เงียบ ๆ');
        $this->assertSame(0, $this->countRows('daily_records'), 'ข้อมูลไปตกที่ร้านที่ยังอยู่');
        $this->assertSame(
            1,
            (int)$this->pdo->query("SELECT COUNT(*) FROM shops WHERE id = {$liveShop}")->fetchColumn()
        );
    }
}
