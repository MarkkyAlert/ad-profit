<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ "ระบบอีเมลยังไม่ได้ตั้งค่า" ต้องตอบคนละอย่างกับ "ตั้งค่าแล้วแต่ส่งไม่ออก"
 *
 * คลาสนี้ตั้งใจ **ไม่** เขียนทับ env จึงได้เซิร์ฟเวอร์ที่ระบบอีเมลปิดอยู่ตามปริยาย
 * (ตรงข้ามกับ `GoalAndProfileEndpointTest` ที่เปิดอีเมลแล้วชี้ไปโฮสต์ที่ไม่มีจริง)
 *
 * ⚠️ อาการที่วัดได้จริงตอนยังไม่แยกสองกรณีนี้ ระบบอีเมลปิดอยู่แล้วผู้ใช้กดเปลี่ยนอีเมล:
 *   ครั้งที่ 1 → "ส่งอีเมลยืนยันไม่สำเร็จ **กรุณาลองใหม่อีกครั้ง**"
 *   ครั้งที่ 2 → "ส่งลิงก์บ่อยเกินไป กรุณารอ 1 นาที"   ← ลงโทษที่ทำตามที่บอก
 *   ครั้งที่ 3-6 → เหมือนเดิม จนครบโควตาแล้วถูกกันอีก 1 ชั่วโมง
 * ไม่มีข้อความไหนบอกสาเหตุจริงเลย และไม่ว่ารออีกกี่ชั่วโมงก็ไม่มีทางสำเร็จ
 */
final class EmailSystemNotConfiguredTest extends ControllerTestCase
{
    /** @param array<string,mixed> $fields */
    private function submit(string $session, array $fields): array
    {
        return $this->postJson(
            '/api/profile.php',
            $fields + ['csrf_token' => $this->csrfTokenFor($session)],
            $session
        );
    }

    public function testItSaysTheMailSystemIsNotReadyInsteadOfBlamingTheUser(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $response = $this->submit($session, [
            'action' => 'change_email',
            'email' => 'new@example.com',
            'current_password' => 'OldPass123',
        ]);

        $this->assertStringContainsString(
            'ระบบส่งอีเมลยังไม่พร้อมใช้งาน',
            (string)$response['body'],
            'ต้องบอกสาเหตุจริง ไม่ใช่บอกให้ลองใหม่ทั้งที่ลองอีกกี่ครั้งก็ไม่สำเร็จ'
        );
        $this->assertStringNotContainsString(
            'ลองใหม่อีกครั้ง',
            (string)$response['body'],
            'สั่งให้ลองใหม่ทั้งที่ไม่มีทางสำเร็จ แล้วครั้งถัดไปจะโดนกันเพราะ "ส่งบ่อยเกินไป"'
        );
    }

    /**
     * ⚠️ ห้ามกินโควตาไปกับสิ่งที่ไม่มีทางสำเร็จ
     *
     * โควตาคือ 1 ครั้ง/นาที และ 5 ครั้ง/ชั่วโมง · ถ้านับ ผู้ใช้จะถูกกัน 1 ชั่วโมง
     * ด้วยข้อความที่ชี้ผิดสาเหตุ แล้วพอผู้ดูแลตั้งค่าอีเมลเสร็จก็ยังเข้าไม่ได้อยู่ดี
     */
    public function testItDoesNotBurnTheSendQuota(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->submit($session, [
                'action' => 'change_email',
                'email' => 'new@example.com',
                'current_password' => 'OldPass123',
            ]);

            $this->assertStringContainsString(
                'ระบบส่งอีเมลยังไม่พร้อมใช้งาน',
                (string)$response['body'],
                "ครั้งที่ {$attempt} เปลี่ยนไปตอบเรื่องโควตาแทนที่จะบอกสาเหตุจริง"
            );
        }

        $this->assertSame(0, $this->countRows('auth_rate_limits'), 'โควตาถูกกินไปกับคำขอที่ไม่มีทางสำเร็จ');
    }

    /**
     * ⚠️ คำขอที่รอยืนยันอยู่ต้องไม่ถูกทับหาย
     *
     * `createRequest()` เป็น ON DUPLICATE KEY UPDATE — 1 ผู้ใช้มีคำขอได้ใบเดียว
     * ถ้าเดินหน้าต่อทั้งที่ส่งไม่ได้ ลิงก์ที่ผู้ใช้ได้รับไว้แล้วจะใช้ไม่ได้ทันที
     * โดยที่ไม่มีลิงก์ใหม่มาแทน = เสียของฟรี ๆ
     */
    public function testItDoesNotDestroyARequestThatIsStillWaitingForConfirmation(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $this->pdo->prepare(
            'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$userId, 'already-pending@example.com', hash('sha256', 'STILL-VALID-TOKEN')]);

        $this->submit($session, [
            'action' => 'change_email',
            'email' => 'something-else@example.com',
            'current_password' => 'OldPass123',
        ]);

        $this->assertSame(
            'already-pending@example.com',
            (string)$this->pdo
                ->query("SELECT new_email FROM email_change_requests WHERE user_id = {$userId}")
                ->fetchColumn(),
            'คำขอเดิมที่ลิงก์ยังใช้ได้อยู่ถูกทับทิ้ง ทั้งที่คำขอใหม่ส่งออกไปไม่ได้เลย'
        );
    }
}
