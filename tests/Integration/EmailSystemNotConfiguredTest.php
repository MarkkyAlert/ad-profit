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

    /** token ของหน้าที่ยังไม่ได้ล็อกอิน */
    private function guestCsrf(string $sessionId): string
    {
        $response = $this->get('/login.php', $sessionId);
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $response['body'], $matched) === 1) {
            return $matched[1];
        }

        $this->fail('หา csrf_token ในหน้าเข้าสู่ระบบไม่เจอ');
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function askForResetLink(string $sessionId): array
    {
        return $this->postJson('/api/auth.php', [
            'csrf_token' => $this->guestCsrf($sessionId),
            'action' => 'forgot_password',
            'email' => 'owner@example.com',
        ], $sessionId);
    }

    /**
     * ⭐⭐ กติกาเดียวกันนี้ต้องใช้กับ **ทางลืมรหัสผ่าน** ด้วย
     *
     * ⚠️⚠️ กฎ "ยังไม่ได้ตั้งค่า ≠ ส่งไม่ออก" ถูกเขียนไว้ตอนแก้ทางเปลี่ยนอีเมล
     * แล้วไม่มีใครไล่ดูว่าทางอื่นละเมิดอยู่ไหม — ทางนี้ยังละเมิดอยู่เต็ม ๆ
     * (เป็นรูปแบบเดิมที่ CLAUDE.md เตือนไว้ว่า "เขียนกฎลงเอกสารแล้วต้องไล่ตรวจที่อื่น")
     *
     * ⚠️ ทางนี้ **ร้ายแรงกว่า** ทางเปลี่ยนอีเมล เพราะคนที่มาถึงหน้านี้คือคนที่
     * เข้าระบบไม่ได้แล้ว ลิงก์รีเซ็ตคือประตูบานเดียวที่เหลือ
     *
     * วัดจริงก่อนแก้ (เซิร์ฟเวอร์ที่ยังไม่ได้ตั้ง SMTP):
     *   ครั้งที่ 1 → "ระบบส่งอีเมลไม่สำเร็จในขณะนี้ **กรุณาลองใหม่อีกครั้ง**"
     *   ทำตามทันที → "ลองขอรีเซ็ตรหัสผ่านบ่อยเกินไป กรุณารอ 1 นาทีแล้วลองใหม่"
     */
    public function testAskingForAResetLinkNamesTheRealCauseInsteadOfTellingYouToRetry(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startBlankSession();

        $response = $this->askForResetLink($guest);

        $this->assertStringContainsString(
            'ระบบส่งอีเมลยังไม่พร้อมใช้งาน',
            (string)$response['body'],
            'ต้องบอกสาเหตุจริง ไม่ใช่ปล่อยให้ผู้ใช้เดาว่าอีเมลหายไปไหน'
        );
        $this->assertStringNotContainsString(
            'ลองใหม่อีกครั้ง',
            (string)$response['body'],
            'สั่งให้ลองใหม่ทั้งที่กดอีกกี่ครั้งก็ไม่มีทางสำเร็จ และครั้งถัดไปจะโดนกันเพราะ "บ่อยเกินไป"'
        );
    }

    /**
     * ⭐⭐ ต้องตอบ 503 — เป็นปัญหาของเซิร์ฟเวอร์ ไม่ใช่ของสิ่งที่ผู้ใช้กรอก
     *
     * (ย้ายมาจาก `AuthEndpointTest` ซึ่งตอนนี้รันบนเซิร์ฟเวอร์ที่ตั้งค่าอีเมลแล้ว
     * จึงไปไม่ถึงทางนี้อีก — คลาสนี้คือที่เดียวที่ระบบอีเมลปิดอยู่จริง)
     */
    public function testAskingForAResetLinkAnswers503WhenTheMailSystemIsDown(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startBlankSession();

        $response = $this->askForResetLink($guest);

        $this->assertSame(
            503,
            $response['status'],
            'บอกว่าสำเร็จทั้งที่ระบบยังส่งอีเมลไม่ได้ — ผู้ใช้จะนั่งรอลิงก์ที่ไม่มีวันมา'
        );
    }

    /**
     * ⭐⭐ การปฏิเสธต้องเหมือนกันเป๊ะ ทั้งอีเมลที่มีบัญชีและไม่มี
     *
     * ⚠️ กับดักที่เจอตอนแก้รอบก่อน: `email_sent` มีเฉพาะตอนอีเมลนั้นมีบัญชีจริง
     * ถ้าเอามาตัดสิน คำขอของอีเมลที่มีบัญชีจะตอบคนละแบบกับที่ไม่มี
     * = **บอกใบ้ว่าใครสมัครไว้แล้ว** ซึ่งเป็นสิ่งที่ข้อความกลาง ๆ ตั้งใจปิดไว้
     * จึงต้องตัดสินจาก "ระบบอีเมลพร้อมไหม" ซึ่งเป็นสถานะของระบบ ไม่ใช่ของบัญชี
     *
     * (ย้ายมาจาก `AuthEndpointTest` — ที่นั่นอีเมลเปิดอยู่ ทั้งสองทางจึงตอบ 200
     * เหมือนกันโดยปริยาย เทสต์เลยกลายเป็นจริงโดยโครงสร้าง ไม่ได้พิสูจน์อะไร)
     */
    public function testTheRefusalLooksTheSameForAnAccountThatDoesNotExist(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startBlankSession();

        $known = $this->askForResetLink($guest);

        $unknown = $this->postJson('/api/auth.php', [
            'csrf_token' => $this->guestCsrf($guest),
            'action' => 'forgot_password',
            'email' => 'never-signed-up@example.com',
        ], $guest);

        $this->assertSame($known['status'], $unknown['status'], 'ตอบคนละรหัสสถานะ = บอกใบ้ว่ามีบัญชีอยู่จริง');
        $this->assertSame((string)$known['body'], (string)$unknown['body'], 'ตอบคนละข้อความ = บอกใบ้ว่ามีบัญชีอยู่จริง');
    }

    /**
     * ⚠️⚠️ เพดานของทางนี้คือ **1 ครั้งต่อนาที** — กินโควตาไป 1 คือกินหมดพอดี
     *
     * ผลคือผู้ใช้ที่ทำตามคำสั่งบนหน้าจอ ("ลองใหม่อีกครั้ง") จะถูกกันทันที
     * และข้อความที่ได้กลับมาก็ชี้ไปคนละสาเหตุ = ไม่มีทางรู้ว่าเกิดอะไรขึ้นจริง
     */
    public function testAskingForAResetLinkDoesNotBurnTheQuotaWhenMailCannotSend(): void
    {
        $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startBlankSession();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->askForResetLink($guest);

            $this->assertStringContainsString(
                'ระบบส่งอีเมลยังไม่พร้อมใช้งาน',
                (string)$response['body'],
                "ครั้งที่ {$attempt} เปลี่ยนไปตอบเรื่องโควตาแทนที่จะบอกสาเหตุจริง"
            );
        }

        $this->assertSame(
            0,
            $this->countRows('auth_rate_limits'),
            'โควตาถูกกินไปกับคำขอที่ไม่มีทางสำเร็จ'
        );
    }

    /**
     * ⚠️ ห้ามสร้าง token ทิ้งไว้ทั้งที่ส่งออกไปไม่ได้
     *
     * `createToken()` ทำให้ token ใบก่อนหน้าใช้ไม่ได้ทันที · ถ้าเดินหน้าต่อทั้งที่
     * ส่งไม่ได้ ลิงก์ที่ผู้ใช้ถืออยู่ (จากตอนที่อีเมลยังส่งได้) จะตายไปฟรี ๆ
     */
    public function testAskingForAResetLinkDoesNotKillALinkTheUserAlreadyHas(): void
    {
        $userId = $this->createUser('owner@example.com', 'OldPass123');
        $guest = $this->startBlankSession();

        $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())'
        )->execute([$userId, hash('sha256', 'STILL-VALID-TOKEN')]);

        $this->askForResetLink($guest);

        $this->assertSame(
            hash('sha256', 'STILL-VALID-TOKEN'),
            (string)$this->pdo
                ->query("SELECT token_hash FROM password_reset_tokens WHERE user_id = {$userId}")
                ->fetchColumn(),
            'ลิงก์เดิมที่ยังใช้ได้ถูกทับทิ้ง ทั้งที่ลิงก์ใหม่ส่งออกไปไม่ได้เลย'
        );
    }
}
