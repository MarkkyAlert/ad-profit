<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ หน้ายืนยันอีเมลต้องไม่แตะบัญชีของคนที่กดลิงก์ ถ้าลิงก์นั้นเป็นของคนอื่น
 *
 * ⚠️⚠️ ช่องโหว่ที่ทำซ้ำได้จริงก่อนแก้: ลิงก์เป็น GET ธรรมดา ไม่มี CSRF ไม่มีหน้ายืนยัน
 * และโค้ดล้าง `$_SESSION` ทิ้งทันทีที่ยืนยันสำเร็จ **โดยไม่ดูว่าคนที่กดเป็นเจ้าของ
 * ลิงก์หรือเปล่า** → ใครก็ได้ส่งลิงก์ของตัวเองให้เหยื่อกด (ทาง LINE/อีเมล) แล้ว
 *   · เหยื่อถูกเตะออกจากระบบทุกเครื่องทันที ทั้งที่บัญชีตัวเองไม่ได้ถูกแตะเลย
 *   · หน้าจอขึ้นว่า "เปลี่ยนอีเมลเรียบร้อยแล้ว — ต่อไปนี้ให้เข้าสู่ระบบด้วย
 *     <อีเมลของผู้ส่งลิงก์>" ซึ่งเป็นฉากตั้งต้นของการหลอกเอารหัสผ่าน
 *
 * เป็นบั๊กคลาสเดียวกับที่ `reset-password.php` แก้ไปแล้ว (คอมเมนต์ในไฟล์นั้นอธิบายครบ)
 * หน้านี้เขียนทีหลังแล้วพลาดซ้ำ
 */
final class VerifyEmailPageTest extends ControllerTestCase
{
    private function makeToken(int $userId, string $newEmail): string
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([$userId, $newEmail, hash('sha256', $token)]);

        return $token;
    }

    /**
     * กดปุ่มยืนยันบนหน้า — เหมือนผู้ใช้จริง: เปิดหน้าด้วย GET แล้วส่งฟอร์ม POST
     *
     * ⚠️ GET อย่างเดียวต้องไม่เปลี่ยนอะไร (ดู `testAGetRequestAloneConfirmsNothing`)
     */
    private function pressConfirm(string $token, ?string $session = null): array
    {
        // ⚠️ GET กับ POST ต้องอยู่ session เดียวกัน — csrf_token ผูกกับ session
        // ผู้ใช้จริงที่ยังไม่ล็อกอินก็มี session ของตัวเอง (bootstrap เปิดให้ทุกคำขอ)
        $session ??= $this->startBlankSession();

        $page = $this->get('/verify-email.php?token=' . $token, $session);

        // ⚠️ ต้องดึง token จาก **ฟอร์มยืนยัน** เท่านั้น — หน้าเว็บมีฟอร์มออกจากระบบ
        // ใน header ซึ่งก็มี csrf_token เหมือนกัน ถ้าหยิบมั่วเทสต์จะเขียวแม้ไม่มีฟอร์มยืนยันเลย
        $found = preg_match(
            '#<form[^>]*>(?:(?!</form>).)*?name="token"(?:(?!</form>).)*?</form>#s',
            $page['body'],
            $formMatch
        ) === 1;
        $this->assertTrue($found, 'หน้ายืนยันไม่มีฟอร์มที่ส่ง token กลับมา');

        $csrf = preg_match('/name="csrf_token"\s+value="([^"]+)"/', $formMatch[0], $m) === 1 ? $m[1] : '';
        $this->assertNotSame('', $csrf, 'ฟอร์มยืนยันไม่มี csrf_token');

        return $this->post('/verify-email.php', ['csrf_token' => $csrf, 'token' => $token], $session);
    }

    public function testALinkForAnotherAccountDoesNotLogTheVisitorOut(): void
    {
        $victimId = $this->createUser('victim@example.com', 'VictimPass123');
        $shopId = $this->createShop($victimId);
        $session = $this->startSession($victimId, $shopId);

        $attackerId = $this->createUser('attacker@evil.test', 'AttackPass123');
        $token = $this->makeToken($attackerId, 'attacker-new@evil.test');

        $this->assertSame(200, $this->get('/dashboard.php', $session)['status'], 'เหยื่อควรใช้งานได้ก่อนกดลิงก์');

        $this->get('/verify-email.php?token=' . $token, $session);

        $this->assertSame(
            200,
            $this->get('/dashboard.php', $session)['status'],
            'เหยื่อถูกเตะออกจากระบบด้วยลิงก์ของคนอื่น'
        );
    }

    /** ⚠️ และต้องไม่เปลี่ยนอีเมลให้ด้วย — ไม่งั้นกลายเป็นเปลี่ยน state ผ่าน GET ของคนอื่น */
    public function testALinkForAnotherAccountChangesNothing(): void
    {
        $victimId = $this->createUser('victim@example.com', 'VictimPass123');
        $shopId = $this->createShop($victimId);
        $session = $this->startSession($victimId, $shopId);

        $attackerId = $this->createUser('attacker@evil.test', 'AttackPass123');
        $token = $this->makeToken($attackerId, 'attacker-new@evil.test');

        $body = (string)$this->get('/verify-email.php?token=' . $token, $session)['body'];

        $this->assertStringNotContainsString(
            'attacker-new@evil.test',
            $body,
            'หน้าจอบอกเหยื่อให้ไปล็อกอินด้วยอีเมลของผู้ส่งลิงก์'
        );
        $this->assertSame(
            'attacker@evil.test',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$attackerId}")->fetchColumn(),
            'ยืนยันให้บัญชีอื่นผ่านการกดของคนที่ไม่ใช่เจ้าของ'
        );
        $this->assertSame(
            'victim@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$victimId}")->fetchColumn()
        );
    }

    /** ✅ เจ้าของลิงก์ตัวจริงที่ยังไม่ได้ล็อกอิน (กดจากมือถือ) ต้องยืนยันได้ตามปกติ */
    public function testTheRealOwnerCanStillConfirmWithoutLoggingIn(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $token = $this->makeToken($userId, 'moved@example.com');

        $this->pressConfirm($token);

        $this->assertSame(
            'moved@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn(),
            'เจ้าของลิงก์ยืนยันไม่ได้ — ตัวกันเข้มเกินจนใช้งานจริงไม่ได้'
        );
    }

    /** ✅ เจ้าของที่ล็อกอินอยู่ก็ยืนยันได้ และถูกเตะออกทุกเครื่องตามกติกา */
    public function testTheRealOwnerCanConfirmWhileSignedInAndIsThenSignedOut(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $token = $this->makeToken($userId, 'moved@example.com');

        $this->pressConfirm($token, $session);

        $this->assertSame(
            'moved@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn()
        );
        $this->assertSame(
            302,
            $this->get('/dashboard.php', $session)['status'],
            'เปลี่ยนอีเมลแล้วต้องถูกเตะออกทุกเครื่อง — อีเมลคือช่องทางกู้บัญชี'
        );
    }

    /** ข้อความที่ผู้ใช้อ่านได้จริง (ตัด CSS/สคริปต์ออกก่อน) */
    private function visibleText(string $html): string
    {
        $withoutAssets = (string)preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#si', ' ', $html);

        return trim((string)preg_replace('/\s+/u', ' ', strip_tags($withoutAssets)));
    }

    /**
     * ⭐⭐ คำอธิบายใต้หัวข้อต้องตรงกับสาเหตุจริง ห้ามเป็นข้อความตายตัว
     *
     * ⚠️ เดิมทุกกรณีที่ล้มเหลวพิมพ์บรรทัดเดียวกันหมด:
     *   "ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว — … ขอลิงก์ใหม่ได้จากหน้าโปรไฟล์"
     * ซึ่งขัดกับหัวข้อที่อยู่เหนือมันบนจอเดียวกัน:
     *   หัวข้อ: "ลิงก์นี้เป็นของบัญชีอื่น … กรุณาออกจากระบบก่อนแล้วกดลิงก์อีกครั้ง"
     *           (= ลิงก์ยังดีอยู่)
     *   บรรทัดถัดมา: "ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว" (= ลิงก์เสียแล้ว)
     *
     * ผู้ใช้ที่ทำตามบรรทัดล่างจะไปขอลิงก์ใหม่โดยไม่จำเป็น — และขอไม่ได้ด้วย
     * เพราะเจ้าของลิงก์คือคนอื่น เป็นหลักเดียวกับ `extremes_not_comparable_text()`
     * (ห้ามเดาสาเหตุแทนข้อมูล)
     */
    public function testTheHintDoesNotContradictTheHeadlineForALinkOfAnotherAccount(): void
    {
        $victimId = $this->createUser('victim@example.com', 'VictimPass123');
        $shopId = $this->createShop($victimId);
        $session = $this->startSession($victimId, $shopId);

        $attackerId = $this->createUser('attacker@evil.test', 'AttackPass123');
        $token = $this->makeToken($attackerId, 'attacker-new@evil.test');

        $page = $this->visibleText($this->get('/verify-email.php?token=' . $token, $session)['body']);

        $this->assertStringContainsString(
            'ลิงก์นี้เป็นของบัญชีอื่น',
            $page,
            'ต้องบอกสาเหตุจริงว่าเป็นลิงก์ของบัญชีอื่น'
        );
        $this->assertStringNotContainsString(
            'ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว',
            $page,
            'บรรทัดนี้ขัดกับหัวข้อที่บอกว่าให้ออกจากระบบแล้วกดลิงก์เดิมอีกครั้ง'
        );
    }

    /**
     * ⭐ ปัญหาอยู่ที่ "อีเมลปลายทาง" ไม่ใช่ที่ลิงก์ — บอกให้ขอลิงก์ใหม่ = วนซ้ำที่เดิม
     *
     * ⚠️ ขอลิงก์ใหม่ไปที่อีเมลเดิมจะล้มเหมือนเดิมทุกครั้ง เพราะอีเมลนั้นมีคนใช้แล้ว
     * สิ่งที่ต้องทำคือเปลี่ยน **อีเมลปลายทาง** ข้อความจึงต้องพาไปทางนั้น
     */
    public function testTheHintPointsAtTheDestinationAddressWhenItIsAlreadyTaken(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $this->createUser('taken@example.com', 'OtherPass123');
        $token = $this->makeToken($userId, 'taken@example.com');

        // ⚠️ รู้ตอน "กดยืนยัน" ไม่ใช่ตอนเปิดหน้า — `confirmEmailChange()` เป็นผู้ตัดสิน
        // เพียงผู้เดียวว่าอีเมลปลายทางใช้ได้ไหม (ตรวจซ้ำตอนยืนยันเพราะระหว่างรอ
        // อาจมีคนสมัครไปก่อน) ถ้าหน้าเว็บตรวจเองตอน GET จะกลายเป็นกติกาสองที่
        $page = $this->visibleText($this->pressConfirm($token)['body']);

        $this->assertStringContainsString('อีเมลนี้ถูกใช้งานแล้ว', $page);
        $this->assertStringNotContainsString(
            'ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว',
            $page,
            'ลิงก์ไม่ได้หมดอายุ — ที่ใช้ไม่ได้คืออีเมลปลายทาง'
        );
    }

    /** ⭐ ส่วนกรณีที่ลิงก์เสียจริง ๆ ข้อความเดิมถูกอยู่แล้ว ต้องไม่หายไป */
    public function testAnExpiredLinkStillSaysTheLinkExpired(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $token = $this->makeToken($userId, 'moved@example.com');
        $this->pdo->exec('UPDATE email_change_requests SET expires_at = NOW() - INTERVAL 1 HOUR');

        $page = $this->visibleText($this->get('/verify-email.php?token=' . $token)['body']);

        $this->assertStringContainsString(
            'ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว',
            $page,
            'กรณีนี้ข้อความเดิมตรงกับความจริง ห้ามแก้ทิ้ง'
        );
        $this->assertStringContainsString('อีเมลของบัญชียังเป็นอันเดิม', $page);
    }

    /**
     * ⭐⭐ เปิดลิงก์เฉย ๆ (GET) ต้องยังไม่เปลี่ยนอะไรเลย
     *
     * ⚠️⚠️ เดิมการเปิดลิงก์ = เปลี่ยนอีเมลทันที + เตะทุก session + ล้าง token รีเซ็ต
     * ทั้งหมดจากคำขอ GET เดียว · ระบบสแกนลิงก์ในอีเมล (Outlook Safe Links,
     * Proofpoint, พร็อกซีของ Gmail) ดึง URL อัตโนมัติก่อนผู้ใช้กด → ผู้ใช้
     * **ถูกเตะออกจากระบบทุกเครื่องโดยไม่ได้แตะอะไรเลย** และอีเมลเปลี่ยนไปแล้ว
     *
     * ตอนนี้ GET แค่แสดงหน้ายืนยัน การเปลี่ยนจริงเกิดที่ POST + CSRF
     * (หลักเดียวกับ `reset-password.php` ที่ GET แสดงฟอร์ม POST เป็นตัวเปลี่ยน)
     */
    public function testAGetRequestAloneConfirmsNothing(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $token = $this->makeToken($userId, 'moved@example.com');
        $versionBefore = (int)$this->pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();

        $page = $this->get('/verify-email.php?token=' . $token, $session);

        $this->assertSame(200, $page['status']);
        $this->assertSame(
            'owner@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn(),
            'แค่เปิดลิงก์ก็เปลี่ยนอีเมลแล้ว — ตัวสแกนอีเมลกดแทนผู้ใช้ได้'
        );
        $this->assertSame(
            $versionBefore,
            (int)$this->pdo->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn(),
            'แค่เปิดลิงก์ก็ถูกเตะออกจากระบบทุกเครื่องแล้ว'
        );
        $this->assertSame(
            1,
            $this->countRows('email_change_requests'),
            'คำขอถูกใช้ทิ้งไปแล้วทั้งที่ผู้ใช้ยังไม่ได้กดยืนยัน'
        );
        $this->assertSame(200, $this->get('/dashboard.php', $session)['status'], 'ผู้ใช้หลุดจากระบบทั้งที่ยังไม่ได้ยืนยัน');
    }

    /** ⭐ หน้ายืนยันต้องบอกให้ชัดว่ากำลังจะเปลี่ยนเป็นอีเมลอะไร */
    public function testTheConfirmationPageNamesTheNewEmail(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $token = $this->makeToken($userId, 'moved@example.com');

        $page = $this->visibleText($this->get('/verify-email.php?token=' . $token)['body']);

        $this->assertStringContainsString('moved@example.com', $page, 'ไม่บอกว่ากำลังจะเปลี่ยนเป็นอีเมลอะไร');
    }

    /** ⭐⭐ ปุ่มยืนยันต้องผ่าน CSRF — ไม่งั้นเว็บอื่นสั่งยืนยันแทนได้ถ้ารู้ token */
    public function testTheConfirmationNeedsAValidCsrfToken(): void
    {
        $userId = $this->createUser('owner@example.com', 'OwnerPass123');
        $token = $this->makeToken($userId, 'moved@example.com');

        $this->post('/verify-email.php', ['token' => $token, 'csrf_token' => 'ปลอม']);

        $this->assertSame(
            'owner@example.com',
            (string)$this->pdo->query("SELECT email FROM users WHERE id = {$userId}")->fetchColumn(),
            'ยืนยันสำเร็จทั้งที่ไม่มี CSRF token ที่ถูกต้อง'
        );
    }
}
