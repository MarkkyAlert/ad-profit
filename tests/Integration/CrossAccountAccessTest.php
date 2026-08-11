<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐⭐ **ผู้ใช้คนหนึ่งต้องไม่เห็น/แก้/ลบ/ดาวน์โหลด ข้อมูลของอีกคน — ทุกทาง**
 *
 * ⚠️⚠️ นี่คือความเสี่ยงอันดับหนึ่งของแอปนี้ ไม่ใช่เลขเพี้ยนหรือปุ่มตาย —
 * ข้อมูลที่เก็บคือ **ยอดขายจริง งบแอด และกำไร** ของเจ้าของร้าน ถ้าคนหนึ่งเห็นของอีกคน
 * แม้ครั้งเดียว = ความลับทางธุรกิจของลูกค้ารั่ว ซึ่งกู้คืนไม่ได้
 *
 * ระบบนี้ **ไม่มี role** (ทุกคนเป็นเจ้าของร้านเท่ากัน) ความเสี่ยงจึงเป็นแนวราบล้วน:
 * เอา id ของคนอื่นมายิงตรง ๆ
 *
 * ⚠️ **ทุกเทสต์ในไฟล์นี้ต้องตรวจสองด้านเสมอ**
 *   (ก) คนอื่นถูกปฏิเสธ  (ข) **เจ้าของตัวจริงยังทำได้**
 * ตัวกันที่แน่นเกินจนเจ้าของเข้าร้านตัวเองไม่ได้ ร้ายแรงพอ ๆ กับข้อมูลรั่ว —
 * และมองไม่เห็นถ้าเทสต์ตรวจแค่ด้านเดียว
 */
final class CrossAccountAccessTest extends ControllerTestCase
{
    /** ค่าที่ไม่มีทางโผล่โดยบังเอิญ — เจอเมื่อไหร่แปลว่าข้อมูลของ A รั่ว */
    private const SENTINELS = [
        '12345.67' => 'ยอดขายของ A',
        '12,345.67' => 'ยอดขายของ A (จัดรูปแล้ว)',
        'ความลับของเอ' => 'โน้ตของ A',
        'ร้านลับของเอ' => 'ชื่อร้านของ A',
        '999999.99' => 'เป้ารายได้ของ A',
        '999,999.99' => 'เป้ารายได้ของ A (จัดรูปแล้ว)',
        'victim-a@example.com' => 'อีเมลของ A',
    ];

    /** ค่าที่ไม่ควรหลุดใน response ไม่ว่ากรณีใด */
    private const NEVER_LEAK = ['password_hash', '$2y$', 'token_hash', 'SQLSTATE', 'Stack trace', 'xamppfiles'];

    /** @var array<string,int> */
    private array $ids = [];

    /**
     * A = เหยื่อ (ร้าน 2 ร้าน + รายการ + เป้า) · B = ผู้บุกรุก (ร้าน 2 ร้าน + รายการ)
     *
     * ⚠️ B ต้องมี 2 ร้านด้วย ไม่งั้นทดสอบ "เจ้าของยังสลับ/เปลี่ยนชื่อร้านตัวเองได้" ไม่ได้
     */
    private function seed(): void
    {
        $userA = $this->createUser('victim-a@example.com', 'VictimPassA123');
        $shopA1 = $this->createShop($userA, 'ร้านลับของเอ');
        $shopA2 = $this->createShop($userA, 'ร้านสองของเอ');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:s, :d, :r, :a, :n, NOW(), NOW())'
        );
        $insert->execute(['s' => $shopA1, 'd' => '2026-04-01', 'r' => 12345.67, 'a' => 111.11, 'n' => 'ความลับของเอ']);
        $recordA = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO monthly_goals (shop_id, goal_month, target_revenue, target_profit, created_at, updated_at)
             VALUES (:s, \'2026-04\', 999999.99, 888888.88, NOW(), NOW())'
        )->execute(['s' => $shopA1]);

        $userB = $this->createUser('attacker-b@example.com', 'AttackPassB123');
        $shopB1 = $this->createShop($userB, 'ร้านของบี');
        $shopB2 = $this->createShop($userB, 'ร้านสองของบี');
        $insert->execute(['s' => $shopB1, 'd' => '2026-04-01', 'r' => 100.00, 'a' => 50.00, 'n' => 'ของบี']);
        $recordB = (int)$this->pdo->lastInsertId();

        $this->ids = compact('userA', 'shopA1', 'shopA2', 'recordA', 'userB', 'shopB1', 'shopB2', 'recordB');
    }

    /** ทุกทางที่ "อ่าน" ข้อมูลออกมาให้ผู้ใช้เห็น — รวมไฟล์ที่ดาวน์โหลดได้ */
    private function readSurfaces(): array
    {
        return [
            'แดชบอร์ด' => '/dashboard.php',
            'ประวัติ' => '/history.php?month=2026-04',
            'รายปี' => '/annual.php?year=2569',
            'บันทึกข้อมูล' => '/add-record.php',
            'รวมร้าน' => '/overview.php?month=2026-04',
            'จัดการร้าน' => '/shops.php',
            'โปรไฟล์' => '/profile.php',
            'api แดชบอร์ด' => '/api/dashboard-data.php',
            'api รายปี' => '/api/annual-data.php?year=2569',
            'api รวมร้าน' => '/api/overview-data.php?month=2026-04',
            'ไฟล์ CSV' => '/api/export.php?month=2026-04',
            'ไฟล์ Excel' => '/api/export-xlsx.php?year=2569',
        ];
    }

    private function assertNoSentinel(string $where, array $response): void
    {
        $body = (string)($response['body'] ?? '');
        foreach (self::SENTINELS as $needle => $label) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                $where . ': ' . $label . ' รั่วออกมาให้ผู้ใช้อีกคนเห็น'
            );
        }
        foreach (self::NEVER_LEAK as $needle) {
            $this->assertStringNotContainsString($needle, $body, $where . ': ข้อมูลภายใน "' . $needle . '" หลุดออกมา');
        }
    }

    /**
     * ⭐⭐⭐ **session ที่ชี้ไปร้านของคนอื่น ต้องไม่เปิดประตูให้เลยสักบาน**
     *
     * ⚠️⚠️ นี่คือช่องที่ร้ายแรงที่สุดถ้าหลุด — ไม่ต้องแก้ URL อะไรเลย ขอแค่ `current_shop_id`
     * ใน session เพี้ยนไปชี้ร้านคนอื่น (เช่นร้านถูกลบแล้ว id ถูกใช้ซ้ำ · session ถูกยัด ·
     * บั๊กในอนาคตที่เขียนค่านี้ผิด) แล้ว **ทุกหน้าและทุกไฟล์ดาวน์โหลดจะเสิร์ฟข้อมูลคนอื่น**
     *
     * ตัวที่กันอยู่คือ `resolve_current_shop_id()` ซึ่งซ่อม session กลับไปร้านของเจ้าของ
     * — เทสต์นี้คือสิ่งเดียวที่พิสูจน์ว่ามันยังทำงาน
     */
    public function testASessionPointingAtSomeoneElsesShopLeaksNothing(): void
    {
        $this->seed();
        // B ล็อกอินอยู่จริง แต่ session ชี้ไปร้านของ A
        $hostile = $this->startSession($this->ids['userB'], $this->ids['shopA1']);

        foreach ($this->readSurfaces() as $label => $url) {
            $this->assertNoSentinel('session ชี้ร้านคนอื่น → ' . $label, $this->get($url, $hostile));
        }

        // ตรวจบริบทของทางอ่านแบบ AJAX ด้วย — ต้องปฏิเสธตรง ๆ ไม่ใช่เสิร์ฟข้อมูล
        $grid = $this->get(
            '/api/month-grid.php?month=2026-04&shop_context_id=' . $this->ids['shopA1'],
            $hostile
        );
        $this->assertSame(409, (int)$grid['status'], 'ตารางเดือนยอมเสิร์ฟทั้งที่บริบทร้านไม่ตรง');

        /* ⚠️ ด้าน (ข) — เจ้าของตัวจริงต้องยังเปิดทุกหน้าได้ตามปกติ
           ตัวกันที่แน่นเกินจนเจ้าของเข้าไม่ได้ ร้ายแรงพอ ๆ กับข้อมูลรั่ว */
        $owner = $this->startSession($this->ids['userA'], $this->ids['shopA1']);
        foreach ($this->readSurfaces() as $label => $url) {
            $this->assertSame(
                200,
                (int)$this->get($url, $owner)['status'],
                'เจ้าของตัวจริงเปิด "' . $label . '" ไม่ได้ — ตัวกันแน่นเกินไป'
            );
        }
        $this->assertStringContainsString(
            'ความลับของเอ',
            (string)$this->get('/api/export.php?month=2026-04', $owner)['body'],
            'เจ้าของโหลดไฟล์ของตัวเองแล้วไม่มีข้อมูลตัวเอง'
        );
    }

    /**
     * ⭐⭐⭐ ยิง id ของคนอื่นตรง ๆ ทุกทางที่ "เขียน" — ข้อมูลของ A ต้องไม่ขยับแม้แต่ช่องเดียว
     *
     * ⚠️ ตรวจที่ **ฐานข้อมูล** ไม่ใช่ที่รหัสสถานะ — โปรเจกต์นี้ตอบ 302 ทั้งตอนสำเร็จและ
     * ตอนล้มเหลว (โหมดฟอร์ม) การดูแค่ status จึงพิสูจน์อะไรไม่ได้เลย
     */
    public function testWritingWithAnotherAccountsIdsChangesNothing(): void
    {
        $this->seed();
        $session = $this->startSession($this->ids['userB'], $this->ids['shopB1']);
        $csrf = $this->csrfTokenFor($session);

        $attacks = [
            ['/api/shops.php', ['action' => 'rename', 'shop_id' => $this->ids['shopA1'], 'name' => 'โดนแฮ็ก']],
            ['/api/shops.php', ['action' => 'switch', 'shop_id' => $this->ids['shopA1']]],
            ['/api/shops.php', ['action' => 'delete', 'shop_id' => $this->ids['shopA1'],
                                'confirm_shop_name' => 'ร้านลับของเอ']],
            ['/api/records.php', ['action' => 'update', 'shop_context_id' => $this->ids['shopB1'],
                                  'record_id' => $this->ids['recordA'], 'record_date' => '2026-04-01',
                                  'revenue' => '1', 'ad_cost' => '1', 'note' => 'โดนแก้']],
            ['/api/records.php', ['action' => 'delete', 'shop_context_id' => $this->ids['shopB1'],
                                  'record_id' => $this->ids['recordA']]],
        ];

        foreach ($attacks as [$path, $fields]) {
            $this->post($path, ['csrf_token' => $csrf] + array_map('strval', $fields), $session);
        }

        $shops = $this->pdo->prepare('SELECT name FROM shops WHERE user_id = :u ORDER BY id');
        $shops->execute(['u' => $this->ids['userA']]);
        $this->assertSame(
            ['ร้านลับของเอ', 'ร้านสองของเอ'],
            array_column($shops->fetchAll(), 'name'),
            'ร้านของ A ถูกเปลี่ยนชื่อหรือถูกลบโดยผู้ใช้อีกคน'
        );

        $record = $this->pdo->prepare('SELECT revenue, note FROM daily_records WHERE id = :i');
        $record->execute(['i' => $this->ids['recordA']]);
        $row = $record->fetch();
        $this->assertNotFalse($row, 'รายการของ A ถูกลบโดยผู้ใช้อีกคน');
        $this->assertSame('12345.67', (string)$row['revenue'], 'ยอดของ A ถูกแก้โดยผู้ใช้อีกคน');
        $this->assertSame('ความลับของเอ', (string)$row['note'], 'โน้ตของ A ถูกแก้โดยผู้ใช้อีกคน');

        /* ⚠️ ด้าน (ข) — B ต้องยังทำสิ่งเดียวกันกับของตัวเองได้ทุกอย่าง */
        $this->post('/api/shops.php', ['action' => 'rename', 'csrf_token' => $csrf,
            'shop_id' => (string)$this->ids['shopB2'], 'name' => 'ร้านสองที่เปลี่ยนชื่อแล้ว'], $session);
        $renamed = $this->pdo->prepare('SELECT name FROM shops WHERE id = :i');
        $renamed->execute(['i' => $this->ids['shopB2']]);
        $this->assertSame(
            'ร้านสองที่เปลี่ยนชื่อแล้ว',
            (string)$renamed->fetchColumn(),
            'เจ้าของเปลี่ยนชื่อร้านตัวเองไม่ได้ — ตัวกันแน่นเกินไป'
        );

        $this->post('/api/records.php', ['action' => 'update', 'csrf_token' => $csrf,
            'shop_context_id' => (string)$this->ids['shopB1'], 'record_id' => (string)$this->ids['recordB'],
            'record_date' => '2026-04-01', 'revenue' => '777', 'ad_cost' => '7', 'note' => 'แก้เอง'], $session);
        $own = $this->pdo->prepare('SELECT revenue FROM daily_records WHERE id = :i');
        $own->execute(['i' => $this->ids['recordB']]);
        $this->assertSame('777.00', (string)$own->fetchColumn(), 'เจ้าของแก้รายการตัวเองไม่ได้');
    }

    /**
     * ⭐⭐ แนบ field เกินมาเพื่อเปลี่ยนปลายทางของการเขียน (mass assignment)
     *
     * ⚠️ ปุ่มที่ซ่อนใน UI ไม่ใช่ตัวกัน — ใครก็ส่ง field อะไรมาก็ได้ ตัวกันต้องอยู่ฝั่งเซิร์ฟเวอร์
     */
    public function testExtraFieldsCannotRedirectAWriteToAnotherAccount(): void
    {
        $this->seed();
        $session = $this->startSession($this->ids['userB'], $this->ids['shopB1']);
        $csrf = $this->csrfTokenFor($session);

        $countFor = function (int $userId): int {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM daily_records dr JOIN shops s ON s.id = dr.shop_id WHERE s.user_id = :u'
            );
            $statement->execute(['u' => $userId]);

            return (int)$statement->fetchColumn();
        };
        $beforeA = $countFor($this->ids['userA']);

        // แนบ shop_id/user_id ของ A มากับการบันทึกรายการ
        $this->post('/api/records.php', [
            'action' => 'upsert', 'csrf_token' => $csrf,
            'shop_context_id' => (string)$this->ids['shopB1'],
            'shop_id' => (string)$this->ids['shopA1'], 'user_id' => (string)$this->ids['userA'],
            'record_date' => '2026-04-20', 'revenue' => '7', 'ad_cost' => '7', 'note' => 'แทรกโดยบี',
        ], $session);

        // สร้างร้านโดยแนบ user_id ของ A
        $this->post('/api/shops.php', [
            'action' => 'create', 'csrf_token' => $csrf, 'name' => 'ร้านที่บีสร้าง',
            'user_id' => (string)$this->ids['userA'], 'id' => (string)$this->ids['shopA1'],
        ], $session);

        // แก้โปรไฟล์โดยแนบ id/อีเมลของ A
        $this->post('/api/profile.php', [
            'action' => 'update_profile', 'csrf_token' => $csrf, 'display_name' => 'บี',
            'id' => (string)$this->ids['userA'], 'user_id' => (string)$this->ids['userA'],
        ], $session);

        $this->assertSame($beforeA, $countFor($this->ids['userA']), 'มีรายการแปลกปลอมถูกแทรกเข้าไปในร้านของ A');

        $shopsOfA = $this->pdo->prepare('SELECT COUNT(*) FROM shops WHERE user_id = :u');
        $shopsOfA->execute(['u' => $this->ids['userA']]);
        $this->assertSame(2, (int)$shopsOfA->fetchColumn(), 'มีร้านถูกสร้างเข้าไปในบัญชีของ A');

        $nameOfA = $this->pdo->prepare('SELECT display_name FROM users WHERE id = :u');
        $nameOfA->execute(['u' => $this->ids['userA']]);
        $this->assertNotSame('บี', (string)$nameOfA->fetchColumn(), 'ชื่อที่แสดงของ A ถูกเขียนทับ');

        /* ⚠️ ด้าน (ข) — field เกินต้องถูกเมิน ไม่ใช่ทำให้คำขอทั้งอันล้ม
           (ถ้าปฏิเสธทั้งคำขอ ผู้ใช้ที่ใช้ส่วนขยายเบราว์เซอร์แปลก ๆ จะบันทึกอะไรไม่ได้เลย) */
        $ownRows = $this->pdo->prepare(
            'SELECT COUNT(*) FROM daily_records WHERE shop_id = :s AND record_date = \'2026-04-20\''
        );
        $ownRows->execute(['s' => $this->ids['shopB1']]);
        $this->assertSame(1, (int)$ownRows->fetchColumn(), 'field เกินทำให้เจ้าของบันทึกลงร้านตัวเองไม่ได้');
    }

    /**
     * ⭐⭐⭐ **ทุกไฟล์ใน api/ และทุกหน้า ต้องปิดเมื่อไม่ได้ล็อกอิน**
     *
     * ⚠️⚠️ ไล่จาก `glob()` ของโฟลเดอร์จริง ไม่ใช่รายชื่อที่พิมพ์ไว้ตายตัว —
     * **เพิ่ม endpoint ใหม่แล้วลืมใส่ `requireAuth()` = เทสต์แดงทันที**
     * (กติกาเดียวกับ `WebExposureTest` และ `testEveryApiFileIsAccountedFor`)
     *
     * ⚠️ ไฟล์ที่เปิดสาธารณะโดยตั้งใจต้องมาอยู่ในรายชื่อข้างล่างอย่างจงใจ —
     * ถ้าใครเพิ่มหน้าใหม่ที่ควรปิดแล้วลืม จะไม่มีทางลอดผ่านไปเงียบ ๆ
     */
    public function testEveryEndpointIsClosedWithoutASession(): void
    {
        $this->seed();

        /* เปิดได้โดยไม่ต้องล็อกอินโดยตั้งใจ — เข้าสู่ระบบ · ลืมรหัส · ตั้งรหัสใหม่ ·
           ยืนยันอีเมลจากลิงก์ในกล่องจดหมาย · หน้าแจ้งข้อผิดพลาด · หน้าแรกที่พาไป login */
        $publicByDesign = [
            '/login.php', '/forgot-password.php', '/reset-password.php',
            '/verify-email.php', '/error.php', '/index.php', '/logout.php', '/api/auth.php',
        ];

        $root = dirname(__DIR__, 2);
        $targets = array_merge(
            array_map(static fn(string $f): string => '/api/' . basename($f), (array)glob($root . '/api/*.php')),
            array_map(static fn(string $f): string => '/' . basename($f), (array)glob($root . '/*.php'))
        );
        $this->assertGreaterThan(15, count($targets), 'กวาดไฟล์ไม่เจอ — เทสต์นี้จะไม่ได้ตรวจอะไรเลย');

        foreach ($targets as $path) {
            if (in_array($path, $publicByDesign, true)) {
                continue;
            }

            foreach (['GET', 'POST'] as $method) {
                $response = $method === 'GET'
                    ? $this->get($path, null)
                    : $this->post($path, ['action' => 'x'], null);

                $status = (int)$response['status'];
                $location = (string)($response['headers']['location'] ?? '');
                $blocked = in_array($status, [401, 403, 405], true)
                    || ($status === 302 && str_contains($location, 'login.php'));

                $this->assertTrue(
                    $blocked,
                    sprintf('%s %s เปิดให้คนที่ยังไม่ล็อกอิน (status=%d %s)', $method, $path, $status, $location)
                );
                $this->assertNoSentinel('ไม่ล็อกอิน → ' . $method . ' ' . $path, $response);
            }
        }
    }

    /**
     * ⭐⭐ คำตอบต้องไม่บอกใบ้ว่า id ไหน "มีอยู่จริงแต่ไม่ใช่ของคุณ"
     *
     * ⚠️ ถ้าข้อความต่างกันระหว่าง "ร้านของคนอื่น" กับ "ร้านที่ไม่มีอยู่จริง" คนร้ายจะไล่ยิง
     * id ทีละเลขเพื่อวาดแผนที่ว่าระบบมีร้าน/รายการอยู่กี่อันและ id ไหนใช้งานอยู่
     */
    public function testTheReplyNeverRevealsWhichIdsExist(): void
    {
        $this->seed();
        $session = $this->startSession($this->ids['userB'], $this->ids['shopB1']);
        $csrf = $this->csrfTokenFor($session);

        $flashOf = function (string $path, array $fields) use ($session): string {
            $this->post($path, $fields, $session);
            $raw = $this->flashMessages($session);
            if (preg_match('/s:5:"error";s:\d+:"(.*?)";/su', $raw, $matched) === 1) {
                return $matched[1];
            }

            return '(ไม่มีข้อความผิดพลาด)';
        };

        $shopOfOther = $flashOf('/api/shops.php', ['action' => 'rename', 'csrf_token' => $csrf,
            'shop_id' => (string)$this->ids['shopA1'], 'name' => 'ชื่อใหม่']);
        $shopNotThere = $flashOf('/api/shops.php', ['action' => 'rename', 'csrf_token' => $csrf,
            'shop_id' => '999999', 'name' => 'ชื่อใหม่']);
        $this->assertSame(
            $shopOfOther,
            $shopNotThere,
            'ข้อความต่างกันระหว่าง "ร้านของคนอื่น" กับ "ร้านที่ไม่มีอยู่" — ใช้ไล่หา id ที่มีจริงได้'
        );
        $this->assertNotSame('(ไม่มีข้อความผิดพลาด)', $shopOfOther, 'ไม่มีข้อความผิดพลาดเลย — เทสต์นี้ไม่ได้ตรวจอะไร');

        $recordOfOther = $flashOf('/api/records.php', ['action' => 'delete', 'csrf_token' => $csrf,
            'shop_context_id' => (string)$this->ids['shopB1'], 'record_id' => (string)$this->ids['recordA']]);
        $recordNotThere = $flashOf('/api/records.php', ['action' => 'delete', 'csrf_token' => $csrf,
            'shop_context_id' => (string)$this->ids['shopB1'], 'record_id' => '999999']);
        $this->assertSame(
            $recordOfOther,
            $recordNotThere,
            'ข้อความต่างกันระหว่าง "รายการของคนอื่น" กับ "รายการที่ไม่มีอยู่"'
        );
    }
}
