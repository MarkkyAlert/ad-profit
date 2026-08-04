<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ตรรกะเฉพาะของ `api/goals.php` และ `api/profile.php` ที่ไม่ได้อยู่ใน Service
 *
 * สองไฟล์นี้ทำงานสำคัญเองนอก Service:
 *  · `goals.php` อ่านจำนวนเงินจากข้อความที่ผู้ใช้พิมพ์ (`parse_decimal_input`) —
 *    กติกาการอ่านตัวเลขเป็นของ helper แต่การส่งค่าที่ถูกต้องเข้า Service เป็นของไฟล์นี้
 *  · `profile.php` มี rate-limit ของตัวเอง (5 ครั้ง/60 วินาที) ที่ **ไม่ได้อยู่ใน
 *    ProfileService** และนับเฉพาะตอนรหัสผ่านปัจจุบันผิด ไม่ใช่ทุก validation error
 */
final class GoalAndProfileEndpointTest extends ControllerTestCase
{
    /**
     * @param array<string,string> $fields
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function submit(string $path, string $session, array $fields): array
    {
        return $this->postJson($path, $fields + ['csrf_token' => $this->csrfTokenFor($session)], $session);
    }

    /**
     * @return array{revenue:float|null,profit:float|null}
     */
    private function storedGoal(int $shopId): array
    {
        $row = $this->pdo
            ->query("SELECT target_revenue, target_profit FROM monthly_goals WHERE shop_id = {$shopId}")
            ->fetch();

        if ($row === false) {
            return ['revenue' => null, 'profit' => null];
        }

        return [
            'revenue' => $row['target_revenue'] === null ? null : (float)$row['target_revenue'],
            'profit' => $row['target_profit'] === null ? null : (float)$row['target_profit'],
        ];
    }

    /** ⭐ ตัวเลขที่ผู้ใช้พิมพ์แบบมีจุลภาค ต้องเข้าไปเป็นค่าที่ถูก ไม่ใช่โต 1000 เท่า */
    public function testGoalAmountsAreReadWithTheSharedMoneyRule(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit('/api/goals.php', $session, [
            'action' => 'upsert',
            'goal_month' => '2026-08',
            'target_revenue' => '1,234.56',
            'target_profit' => '1.234,56',
        ]);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $this->assertSame(['revenue' => 1234.56, 'profit' => 1234.56], $this->storedGoal($shopId));
    }

    /** ⭐ ค่ากำกวมต้องถูกปฏิเสธที่ endpoint ด้วย ไม่ใช่เดาแล้วบันทึก */
    public function testAnAmbiguousGoalAmountIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit('/api/goals.php', $session, [
            'action' => 'upsert',
            'goal_month' => '2026-08',
            'target_revenue' => '1.234',
        ]);

        $this->assertNotSame(200, $response['status'], '1.234 ถูกเดาแล้วบันทึกลงไป');
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /** ⭐ ช่องที่เว้นว่างต้องคงค่าเดิม ไม่ใช่ล้างทิ้ง (ฟอร์มที่เปิดค้างไว้) */
    public function testABlankFieldKeepsTheExistingTarget(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createGoal($shopId, '2026-08', 50000.0, 20000.0);
        $session = $this->startSession($userId, $shopId);

        $this->submit('/api/goals.php', $session, [
            'action' => 'upsert',
            'goal_month' => '2026-08',
            'target_revenue' => '60000',
            'target_profit' => '',
        ]);

        $this->assertSame(['revenue' => 60000.0, 'profit' => 20000.0], $this->storedGoal($shopId));
    }

    /**
     * ⭐ ฟอร์มที่ชี้ไปร้านของคนอื่น ต้องไม่เขียนอะไรเลย
     *
     * ⚠️ ชื่อเทสต์ไม่ได้พูดถึง "ด่านตรวจสิทธิ์" โดยตั้งใจ — ในทางปฏิบัติคำขอนี้ถูก
     * ปฏิเสธที่ **ด่าน 409 (ฟอร์มเป็นของอีกร้าน)** ก่อน เพราะการเปิดหน้าเว็บเรียก
     * `resolve_current_shop_id()` ซึ่งซ่อม session กลับไปร้านของเจ้าตัวแล้ว
     * · ด่านตรวจสิทธิ์ในชั้น Service ครอบไว้ที่ `GoalServiceOverwriteTest` แทน
     */
    public function testAFormPointingAtAnotherUsersShopWritesNothing(): void
    {
        $userId = $this->createUser();
        $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');

        // session ถูกดัดให้ชี้ร้านของคนอื่น (เหมือนคนที่แก้คุกกี้/เดา id)
        $session = $this->startSession($userId, $strangerShop);

        $response = $this->submit('/api/goals.php', $session, [
            'action' => 'upsert',
            'goal_month' => '2026-08',
            'target_revenue' => '10000',
            'shop_context_id' => (string)$strangerShop,
        ]);

        // 409 = ด่าน "ฟอร์มนี้เป็นของอีกร้าน" ยิงก่อน เพราะการเปิดหน้าเว็บซ่อม session
        // ให้กลับไปร้านของเจ้าตัวแล้ว · จะเป็น 403 (ด่านสิทธิ์ใน Service) หรือ 409 ก็ได้
        // สิ่งที่ห้ามเกิดคือ "เขียนสำเร็จ"
        $this->assertContains($response['status'], [403, 409], 'ตั้งเป้าลงร้านของคนอื่นได้');
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /** ⭐ เป้าติดลบต้องถูกปฏิเสธ ไม่ใช่บันทึกแล้วไปโผล่เป็น % ประหลาดบนแดชบอร์ด */
    public function testANegativeTargetIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit('/api/goals.php', $session, [
            'action' => 'upsert',
            'goal_month' => '2026-08',
            'target_revenue' => '-5000',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertStringContainsString('ติดลบ', (string)$response['body']);
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /** ⭐ ฟอร์มลบที่ชี้ไปร้านของคนอื่น ต้องไม่ลบอะไรเลย (ดูหมายเหตุด้านบน) */
    public function testAFormPointingAtAnotherUsersShopDeletesNothing(): void
    {
        $userId = $this->createUser();
        $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');
        $this->createGoal($strangerShop, '2026-08', 50000.0, null);

        $session = $this->startSession($userId, $strangerShop);
        $response = $this->submit('/api/goals.php', $session, [
            'action' => 'delete',
            'goal_month' => '2026-08',
            'shop_context_id' => (string)$strangerShop,
        ]);

        $this->assertContains($response['status'], [403, 409]);
        $this->assertSame(1, $this->countRows('monthly_goals'), 'เป้าของคนอื่นถูกลบ');
    }

    /** ⭐ ลบเป้าหมายซ้ำต้องไม่ขึ้น error แดง (กด back แล้วส่งใหม่) */
    public function testDeletingAGoalTwiceStillReportsSuccess(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createGoal($shopId, '2026-08', 50000.0, null);
        $session = $this->startSession($userId, $shopId);

        $first = $this->submit('/api/goals.php', $session, ['action' => 'delete', 'goal_month' => '2026-08']);
        $second = $this->submit('/api/goals.php', $session, ['action' => 'delete', 'goal_month' => '2026-08']);

        $this->assertSame(200, $first['status'], (string)$first['body']);
        $this->assertSame(200, $second['status'], 'ลบซ้ำแล้วขึ้น error ทั้งที่ผลลัพธ์ตรงกับที่ขอ');
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /** ⭐ เปลี่ยนชื่อที่แสดงต้องเข้าฐานข้อมูลจริง */
    public function testDisplayNameIsUpdated(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit('/api/profile.php', $session, [
            'action' => 'update_profile',
            'display_name' => 'ชื่อที่ตั้งใหม่',
        ]);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $this->assertSame('ชื่อที่ตั้งใหม่', (string)$this->pdo
            ->query("SELECT display_name FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** ⭐ เปลี่ยนรหัสผ่านโดยกรอกรหัสเดิมผิด ต้องไม่เปลี่ยนอะไรเลย */
    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $hashBefore = (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn();

        $response = $this->submit('/api/profile.php', $session, [
            'action' => 'change_password',
            'current_password' => 'ผิดแน่นอน',
            'password' => 'BrandNew456',
            'password_confirm' => 'BrandNew456',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame($hashBefore, (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /**
     * ⭐ เดารหัสผ่านปัจจุบันรัว ๆ ต้องถูกจำกัด (429) — ตัวจำกัดอยู่ที่ controller ไม่ใช่ Service
     *
     * นับเฉพาะเคสที่ "รหัสผ่านปัจจุบันผิด" ไม่ใช่ทุก validation error
     */
    public function testRepeatedWrongPasswordAttemptsAreThrottled(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $statuses = [];
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $statuses[] = $this->submit('/api/profile.php', $session, [
                'action' => 'change_password',
                'current_password' => 'ผิดครั้งที่ ' . $attempt,
                'password' => 'BrandNew456',
                'password_confirm' => 'BrandNew456',
            ])['status'];
        }

        $this->assertContains(429, $statuses, 'เดารหัสผ่านปัจจุบันได้ไม่จำกัดครั้ง');
        $this->assertTrue(password_verify('OldPass123', (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn()));
    }

    /** ⭐ เปลี่ยนอีเมลสำเร็จ ต้องเข้า DB จริงและเตะ session อื่นออก (อีเมลคือช่องทางกู้บัญชี) */
    public function testChangingTheEmailUpdatesItAndBumpsTheSessionVersion(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $versionBefore = (int)$this->pdo
            ->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();

        $response = $this->submit('/api/profile.php', $session, [
            'action' => 'change_email',
            'email' => 'moved@example.com',
            'current_password' => 'OldPass123',
        ]);

        $this->assertSame(200, $response['status'], (string)$response['body']);
        $this->assertSame('moved@example.com', (string)$this->pdo
            ->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn());
        $this->assertGreaterThan(
            $versionBefore,
            (int)$this->pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn(),
            'เปลี่ยนอีเมลแล้วอุปกรณ์อื่นยังใช้ต่อได้'
        );
    }

    /** ⭐ เปลี่ยนอีเมลโดยกรอกรหัสผ่านผิด → อีเมลต้องไม่ขยับ */
    public function testChangingTheEmailWithAWrongPasswordChangesNothing(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit('/api/profile.php', $session, [
            'action' => 'change_email',
            'email' => 'moved@example.com',
            'current_password' => 'ผิดแน่นอน',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame('owner@example.com', (string)$this->pdo
            ->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** ⭐ ย้ายไปอีเมลที่คนอื่นใช้อยู่แล้วไม่ได้ */
    public function testMovingToAnEmailOwnedByAnotherAccountIsRejected(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $this->createUser('taken@example.com', 'OtherPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit('/api/profile.php', $session, [
            'action' => 'change_email',
            'email' => 'taken@example.com',
            'current_password' => 'OldPass123',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame('owner@example.com', (string)$this->pdo
            ->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** ⭐ ชื่อที่แสดงว่างเปล่าไม่ได้ */
    public function testAnEmptyDisplayNameIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $before = (string)$this->pdo
            ->query("SELECT display_name FROM users WHERE id = {$userId}")->fetchColumn();

        $response = $this->submit('/api/profile.php', $session, [
            'action' => 'update_profile',
            'display_name' => '   ',
        ]);

        $this->assertNotSame(200, $response['status']);
        $this->assertSame($before, (string)$this->pdo
            ->query("SELECT display_name FROM users WHERE id = {$userId}")->fetchColumn());
    }

    /** ⭐ เปลี่ยนรหัสผ่านสำเร็จต้องเตะ session อื่นออก (session_version เพิ่ม) */
    public function testASuccessfulPasswordChangeBumpsTheSessionVersion(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $versionBefore = (int)$this->pdo
            ->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();

        $this->submit('/api/profile.php', $session, [
            'action' => 'change_password',
            'current_password' => 'OldPass123',
            'password' => 'BrandNew456',
            'password_confirm' => 'BrandNew456',
        ]);

        $this->assertTrue(password_verify('BrandNew456', (string)$this->pdo
            ->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn()));
        $this->assertGreaterThan(
            $versionBefore,
            (int)$this->pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn(),
            'อุปกรณ์อื่นที่ล็อกอินค้างไว้ยังใช้งานต่อได้หลังเปลี่ยนรหัสผ่าน'
        );
    }
}
