<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐ หน้าโปรไฟล์ต้องบอกว่ามีคำขอเปลี่ยนอีเมลค้างอยู่ และค้างอยู่กับที่อยู่ไหน
 *
 * ⚠️ ข้อความ "ส่งลิงก์ยืนยันไปที่ … แล้ว" เป็นข้อความชั่วคราว หายไปทันทีที่โหลด
 * หน้าใหม่ · หลังจากนั้นหน้าโปรไฟล์แสดงแต่อีเมลปัจจุบัน ไม่มีที่ไหนบอกเลยว่ามี
 * คำขอค้างอยู่
 *
 * คนที่พิมพ์อีเมลใหม่ผิด (`@gmial.com` แทน `@gmail.com`) จึงเห็นแค่ "รอแล้ว
 * ไม่มีอะไรมา" โดยไม่มีทางรู้ว่าลิงก์ถูกส่งไปที่ไหน — ซึ่งเป็นข้อมูลชิ้นเดียว
 * ที่ทำให้เห็นว่าตัวเองพิมพ์ผิดตรงไหน
 */
final class PendingEmailChangeVisibilityTest extends ControllerTestCase
{
    private function makePendingRequest(int $userId, string $newEmail, string $interval = '1 HOUR'): void
    {
        $this->pdo->prepare(
            "INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL {$interval}))"
        )->execute([$userId, $newEmail, hash('sha256', 'TOKEN-' . $newEmail)]);
    }

    public function testTheProfilePageShowsWhereTheConfirmationLinkWasSent(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->makePendingRequest($userId, 'typo@gmial.com');

        $body = (string)$this->get('/profile.php', $session)['body'];

        $this->assertStringContainsString(
            'typo@gmial.com',
            $body,
            'ไม่บอกว่าลิงก์ถูกส่งไปที่ไหน — คนที่พิมพ์ผิดจึงไม่มีทางรู้ว่าผิดตรงไหน'
        );
        $this->assertStringContainsString('รอการยืนยัน', $body, 'ไม่บอกว่ามีคำขอค้างอยู่');
    }

    /** อีเมลปัจจุบันต้องยังแสดงอยู่ — คำขอที่รอยืนยันไม่ได้แปลว่าเปลี่ยนแล้ว */
    public function testTheCurrentEmailIsStillShownAsTheActiveOne(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->makePendingRequest($userId, 'pending@example.com');

        $body = (string)$this->get('/profile.php', $session)['body'];

        $this->assertStringContainsString('owner@example.com', $body, 'อีเมลที่ใช้อยู่จริงหายไปจากหน้า');
    }

    /**
     * ⚠️ ไม่มีคำขอค้าง = ต้องไม่ขึ้นแถบอะไรเลย
     *
     * ถ้าขึ้นตลอด ผู้ใช้จะชินแล้วเลิกอ่าน แถบนี้จึงไร้ประโยชน์ในวันที่มันสำคัญจริง
     */
    public function testNothingIsShownWhenThereIsNoPendingRequest(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $body = (string)$this->get('/profile.php', $session)['body'];

        $this->assertStringNotContainsString('รอการยืนยัน', $body, 'ขึ้นแถบทั้งที่ไม่มีคำขอค้างอยู่');
    }

    /**
     * ⚠️ คำขอที่หมดอายุแล้วต้องไม่ขึ้น — ลิงก์นั้นกดไม่ได้แล้ว
     *
     * ถ้ายังขึ้น ผู้ใช้จะนั่งรออีเมลที่ต่อให้กดก็ใช้ไม่ได้ แทนที่จะกดขอใหม่
     */
    public function testAnExpiredRequestIsNotShown(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->makePendingRequest($userId, 'expired@example.com', '-1 HOUR');

        $body = (string)$this->get('/profile.php', $session)['body'];

        $this->assertStringNotContainsString(
            'expired@example.com',
            $body,
            'ยังชวนให้รอลิงก์ที่หมดอายุไปแล้ว แทนที่จะให้กดขอใหม่'
        );
    }
}
