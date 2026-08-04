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
     * endpoint ที่ "เขียนข้อมูล" ทั้งหมด + คำสั่งจริงของแต่ละไฟล์
     *
     * ⚠️ **ต้องส่ง action ที่ไฟล์นั้นรู้จักจริง** — `api/shops.php` และ `api/profile.php`
     * วางด่าน CSRF ไว้ *ข้างใน* แต่ละ `if ($action === …)` ถ้าส่งคำสั่งมั่ว ๆ มา
     * จะตกไปที่ "Invalid action" 404 ตั้งแต่ยังไม่ถึงด่าน แล้วเทสต์เขียวโดยไม่ได้ตรวจอะไร
     * (เคยเขียนพลาดแบบนี้มาแล้ว — ส่ง 'upsert' ให้ทุกไฟล์)
     *
     * `auth.php` ไม่อยู่ในรายชื่อเพราะเป็นทางเข้าของคนที่ยังไม่ได้ล็อกอิน
     * (มีเทสต์แยกใน AuthServiceTest / PasswordResetInvalidationTest)
     *
     * @return array<string,array{0:string,1:array<string,string>}>
     */
    public static function writeEndpointProvider(): array
    {
        return [
            'บันทึกรายวัน' => ['/api/records.php', [
                'action' => 'upsert',
                'record_date' => '2026-08-01',
                'revenue' => '1000',
                'ad_cost' => '100',
            ]],
            'เป้าหมายรายเดือน' => ['/api/goals.php', [
                'action' => 'upsert',
                'goal_month' => '2026-08',
                'target_revenue' => '50000',
            ]],
            'จัดการร้าน' => ['/api/shops.php', [
                'action' => 'create',
                'name' => 'ร้านใหม่จากเทสต์',
            ]],
            'โปรไฟล์' => ['/api/profile.php', [
                'action' => 'update_profile',
                'display_name' => 'ชื่อใหม่จากเทสต์',
            ]],
        ];
    }

    /**
     * ⭐ ไฟล์ใน `api/` ต้องอยู่ในรายชื่อใดรายชื่อหนึ่งเสมอ
     *
     * เพิ่ม endpoint ใหม่แล้วลืมเพิ่มในเทสต์ = ด่านตรวจของไฟล์นั้นไม่มีใครดูเลย
     * เทสต์นี้ทำให้ "การลืม" เป็นสิ่งที่ทำให้ชุดเทสต์แดง ไม่ใช่สิ่งที่เงียบไป
     */
    public function testEveryApiFileIsAccountedFor(): void
    {
        $known = array_map(
            static fn(array $row): string => basename((string)$row[0]),
            array_values(self::writeEndpointProvider())
        );
        foreach (self::readEndpointProvider() as $row) {
            $known[] = basename(explode('?', (string)$row[0])[0]);
        }
        $known[] = 'auth.php';   // ทางเข้าก่อนล็อกอิน — มีเทสต์ของตัวเองแยกไว้

        $onDisk = array_map('basename', (array)glob(dirname(__DIR__, 2) . '/api/*.php'));
        $missing = array_diff($onDisk, $known);

        $this->assertSame(
            [],
            array_values($missing),
            'endpoint ใหม่ยังไม่มีใครตรวจด่าน — เพิ่มใน writeEndpointProvider() หรือ readEndpointProvider()'
        );
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องกันคนที่ยังไม่ได้ล็อกอิน */
    /**
     * ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องกันคนที่ยังไม่ได้ล็อกอิน
     *
     * ⚠️ ต้องยืนยัน **401 เป๊ะ ๆ** ไม่ใช่ "ไม่ใช่ 200" — การไม่มี CSRF ก็ตอบ 403 ได้
     * เทสต์ที่รับทั้ง 302/401/403 จึงเขียวต่อแม้ถอดด่านล็อกอินออกทั้งอัน
     *
     * @param array<string,string> $fields
     */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsAnonymousRequests(string $path, array $fields): void
    {
        $response = $this->postJson($path, $fields);

        $this->assertSame(401, $response['status'], $path . ' ปล่อยให้คนที่ไม่ได้ล็อกอินส่งคำสั่งได้');
        $this->assertStringContainsString('Unauthorized', $response['body']);

        // ทางฟอร์มปกติต้องพากลับไปหน้าเข้าสู่ระบบ ไม่ใช่หน้าเดิม
        $form = $this->post($path, $fields);
        $this->assertSame(302, $form['status']);
        $this->assertStringContainsString('login.php', $form['headers']['location'] ?? '');
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องปฏิเสธ GET พร้อมบอกวิธีที่ถูกต้อง */
    /** @param array<string,string> $fields */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsGet(string $path, array $fields): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->getJson($path, $session);

        $this->assertSame(405, $response['status'], $path . ' ยอมรับ GET');
        $this->assertSame('POST', $response['headers']['allow'] ?? '', $path . ' ไม่บอกว่าต้องใช้วิธีไหน');
    }

    /** ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องปฏิเสธ body ที่ไม่ใช่ฟอร์ม */
    /** @param array<string,string> $fields */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsNonFormBodies(string $path, array $fields): void
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

    /**
     * ⭐ ทุก endpoint ที่เขียนข้อมูล ต้องปฏิเสธคำขอที่ไม่มี CSRF token
     *
     * ส่ง payload ที่ **ใช้งานได้จริง** ทุกอย่างยกเว้น token — ถ้าด่านถูกถอดออก
     * คำสั่งจะทำงานสำเร็จ ไม่ใช่ล้มด้วยเหตุผลอื่นแล้วเทสต์ยังเขียว
     *
     * @param array<string,string> $fields
     */
    #[DataProvider('writeEndpointProvider')]
    public function testEveryWriteEndpointRejectsMissingCsrf(string $path, array $fields): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $shopsBefore = $this->countRows('shops');
        $nameBefore = (string)$this->pdo
            ->query("SELECT display_name FROM users WHERE id = {$userId}")->fetchColumn();

        $response = $this->postJson($path, $fields + ['shop_context_id' => (string)$shopId], $session);

        $this->assertSame(403, $response['status'], $path . ' ทำงานให้ทั้งที่ไม่มี CSRF token');
        $this->assertStringContainsString('CSRF', $response['body']);
        $this->assertSame(0, $this->countRows('daily_records'), $path . ' เขียน daily_records');
        $this->assertSame(0, $this->countRows('monthly_goals'), $path . ' เขียน monthly_goals');
        $this->assertSame($shopsBefore, $this->countRows('shops'), $path . ' สร้างร้านใหม่');
        $this->assertSame(
            $nameBefore,
            (string)$this->pdo->query("SELECT display_name FROM users WHERE id = {$userId}")->fetchColumn(),
            $path . ' แก้ชื่อผู้ใช้'
        );
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

    /**
     * ⭐ คนที่ไม่ได้ล็อกอินต้องดึงข้อมูลของคนอื่นไม่ได้ แม้แต่ทางที่อ่านอย่างเดียว
     *
     * ⚠️ ต้องเป็น **401** เป๊ะ ๆ — 403 คือคำตอบของ "ล็อกอินแล้วแต่ไม่มีสิทธิ์ในร้านนี้"
     * ซึ่งเกิดได้เองเมื่อ userId เป็น 0 เทสต์ที่รับ 403 ด้วยจึงเขียวต่อแม้ถอด requireAuth() ออก
     */
    #[DataProvider('readEndpointProvider')]
    public function testEveryReadEndpointRejectsAnonymousRequests(string $path): void
    {
        $response = $this->getJson($path);

        $this->assertSame(401, $response['status'], $path . ' ปล่อยข้อมูลให้คนที่ไม่ได้ล็อกอิน');
        $this->assertStringContainsString('Unauthorized', $response['body']);
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
