<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

use AnnualService;
use DashboardService;
use GoalRepository;
use RecordRepository;
use ShopRepository;

/**
 * ⭐⭐⭐ **ค้นหา "สถานะที่เป็นไปไม่ได้" ในฐานข้อมูล — คนละมุมกับเทสต์ที่ยิง input**
 *
 * เทสต์ทั่วไปถามว่า "ใส่ค่าแปลกแล้วระบบตอบอะไร" · ไฟล์นี้ถามคนละคำถาม:
 * **"หลังใช้งานไปแล้ว มีแถวที่ไม่ควรมีอยู่ในฐานข้อมูลไหม"**
 * จับบั๊กที่สะสมจากหลาย operation ต่อกัน ซึ่งการยิง input ทีละครั้งมองไม่เห็น
 *
 * ⚠️⚠️ **ทุกเทสต์ต้องสร้างข้อมูลผ่านทางที่ผู้ใช้เดินได้จริง (HTTP endpoint) ไม่ใช่ INSERT ตรง**
 * ข้อมูลเสียที่ต้องยัดเข้าฐานเองไม่ใช่บั๊กของ workflow — มันแค่บอกว่า "ถ้าข้อมูลเสียอยู่แล้ว
 * ระบบทนได้ไหม" ซึ่งเป็นคนละเรื่องและอ่อนกว่ามาก · **ข้อยกเว้นเดียว** คือแถววันอนาคตยุคเก่า
 * (กติกาปัจจุบันสร้างไม่ได้แล้ว แต่ข้อมูลเดิมยังอยู่ได้) — จดเหตุผลไว้ที่เทสต์นั้นแล้ว
 *
 * ⚠️ ทำไมเรื่องนี้สำคัญกับจุดขาย: รายงานจะเชื่อได้ก็ต่อเมื่อ **ข้อมูลต้นทางสะอาด** —
 * ยอดติดลบหรือค่าที่ถูกตัดเงียบทำให้ทุกตัวเลขเพี้ยนโดยไม่มี error ให้ใครเห็น
 */
final class DataIntegrityInvariantTest extends ControllerTestCase
{
    /** ยิง query หาแถวที่ละเมิดกติกา — ต้องได้ 0 แถวเสมอ */
    private function assertNoViolation(string $rule, string $sql): void
    {
        $rows = $this->pdo->query($sql)->fetchAll();
        $this->assertSame(
            [],
            $rows,
            'พบสถานะที่เป็นไปไม่ได้ — ' . $rule . "\n"
            . 'แถวที่ละเมิด: ' . json_encode(array_slice($rows, 0, 5), JSON_UNESCAPED_UNICODE)
        );
    }

    /** เขียนรายการหนึ่งวันผ่านทั้ง 3 ทางที่ผู้ใช้ใช้จริง — คืนวันที่ที่ใช้ของแต่ละทาง */
    private function writeThroughEveryPath(
        string $session,
        int $shopId,
        string $csrf,
        string $revenue,
        string $adCost,
        int $dayOffset
    ): array {
        $base = new \DateTimeImmutable('2026-06-01');
        $single = $base->modify('+' . $dayOffset . ' days')->format('Y-m-d');
        $bulk = $base->modify('+' . ($dayOffset + 1) . ' days')->format('Y-m-d');
        $csvDay = $base->modify('+' . ($dayOffset + 2) . ' days')->format('Y-m-d');

        $this->post('/api/records.php', [
            'action' => 'upsert', 'csrf_token' => $csrf, 'shop_context_id' => (string)$shopId,
            'record_date' => $single, 'revenue' => $revenue, 'ad_cost' => $adCost, 'note' => '',
        ], $session);

        $this->post('/api/records.php', [
            'action' => 'bulk_upsert', 'csrf_token' => $csrf, 'shop_context_id' => (string)$shopId,
            'record_date' => [$bulk], 'revenue' => [$revenue], 'ad_cost' => [$adCost],
            'note' => [''], 'row_number' => ['1'], 'note_checked' => ['1'],
        ], $session);

        $this->postFile('/api/records.php', [
            'action' => 'import_csv', 'csrf_token' => $csrf, 'shop_context_id' => (string)$shopId,
        ], 'csv', 'x.csv', "date,revenue,ad_cost\n{$csvDay},{$revenue},{$adCost}\n", $session);

        return [$single, $bulk, $csvDay];
    }

    /**
     * ⭐⭐⭐ **ไม่มีทางเขียนไหนเก็บค่าที่เป็นไปไม่ได้ลงฐานข้อมูลได้**
     *
     * ⚠️⚠️ ยิงค่าเดียวกันผ่าน **ทุกทาง** เพราะจุดที่พังบ่อยที่สุดของโปรเจกต์นี้คือ
     * "ตัวกันมีที่ทางหนึ่ง แต่อีกทางลืมใส่" — ทางไฟล์ CSV อ่านค่าเอง จึงเสี่ยงสุด
     *
     * ค่าที่ยิง = ค่าที่ถ้าหลุดเข้าไปจะทำให้ **ทุกตัวเลขในรายงานเพี้ยนโดยไม่มี error**:
     *   · ติดลบ → กำไรทั้งเดือนเพี้ยน
     *   · 14 หลัก → MySQL อาจตัดให้พอดีคอลัมน์เงียบ ๆ แล้วรายงานตัวเลขที่ผู้ใช้ไม่ได้กรอก
     *   · ทศนิยม 4 ตำแหน่ง → ถูกปัดเงียบ ผลรวมไม่ตรงกับที่กรอก
     */
    public function testNoWritePathCanStoreAnImpossibleValue(): void
    {
        $userId = $this->createUser('invariant@example.com', 'InvariantPass123');
        $shopId = $this->createShop($userId, 'ร้านตรวจกติกา');
        $session = $this->startSession($userId, $shopId);
        $csrf = $this->csrfTokenFor($session);

        $offset = 0;
        foreach ([
            ['-500', '100'],                    // ติดลบ
            ['99999999999999.99', '100'],        // เกินคอลัมน์ไปมาก
            ['10000000000.00', '100'],           // เกินเพดานพอดี 1 สตางค์
            ['1234.5678', '100'],                // ทศนิยมเกิน 2 ตำแหน่ง
            ['100', '-1'],                       // ค่าแอดติดลบ
        ] as [$revenue, $adCost]) {
            $this->writeThroughEveryPath($session, $shopId, $csrf, $revenue, $adCost, $offset);
            $offset += 3;
        }

        $this->assertNoViolation(
            'ยอดขายหรือค่าแอดติดลบ (ทำให้กำไรทั้งเดือนเพี้ยน)',
            'SELECT id, shop_id, record_date, revenue, ad_cost FROM daily_records
             WHERE revenue < 0 OR ad_cost < 0'
        );
        $this->assertNoViolation(
            'ยอดชนเพดานคอลัมน์พอดี — แปลว่าถูกตัดให้พอดีเงียบ ๆ ไม่ใช่ค่าที่ผู้ใช้กรอก',
            'SELECT id, record_date, revenue, ad_cost FROM daily_records
             WHERE revenue >= 9999999999.99 OR ad_cost >= 9999999999.99'
        );

        /* ⚠️ ด้าน (ข) — ค่าปกติต้องยังบันทึกได้ **และทุกทางต้องเก็บเหมือนกันเป๊ะ**
           ตัวกันที่แน่นเกินจนกรอกยอดธรรมดาไม่ได้ ร้ายแรงพอ ๆ กับข้อมูลเสีย */
        [$a, $b, $c] = $this->writeThroughEveryPath($session, $shopId, $csrf, '1234.56', '78.90', $offset);
        $statement = $this->pdo->prepare(
            'SELECT record_date, revenue, ad_cost FROM daily_records
             WHERE shop_id = :s AND record_date IN (:a, :b, :c) ORDER BY record_date'
        );
        $statement->execute(['s' => $shopId, 'a' => $a, 'b' => $b, 'c' => $c]);
        $stored = $statement->fetchAll();

        $this->assertCount(3, $stored, 'ค่าปกติบันทึกไม่ครบทุกทาง — ตัวกันแน่นเกินไป');
        foreach ($stored as $row) {
            $this->assertSame('1234.56', (string)$row['revenue'], 'ยอดที่เก็บไม่ตรงกับที่กรอก');
            $this->assertSame('78.90', (string)$row['ad_cost'], 'ค่าแอดที่เก็บไม่ตรงกับที่กรอก');
        }
    }

    /**
     * ⭐⭐ **เดือนของเป้าหมายต้องเป็นเดือนที่มีอยู่จริงเสมอ**
     *
     * ⚠️ คอลัมน์เป็น `CHAR(7)` ซึ่งรับอะไรก็ได้ 7 ตัวอักษร — ฐานข้อมูลไม่ได้กันให้
     * ถ้ามี `'2026-13'` หรือ `'abcdefg'` หลุดเข้าไป **หน้ารายปีจะหาเป้าของเดือนนั้นไม่เจอ**
     * ผู้ใช้ตั้งเป้าแล้วแต่ระบบทำเหมือนไม่เคยตั้ง โดยไม่มีข้อความอะไรบอก
     */
    public function testEveryStoredGoalMonthIsARealCalendarMonth(): void
    {
        $userId = $this->createUser('goalmonth@example.com', 'GoalMonthPass123');
        $shopId = $this->createShop($userId, 'ร้านเป้าหมาย');
        $session = $this->startSession($userId, $shopId);
        $csrf = $this->csrfTokenFor($session);

        foreach (['2026-13', '2026-00', '26-01  ', 'abcdefg', '2026-7 ', '', '2026-1'] as $month) {
            $this->post('/api/goals.php', [
                'action' => 'upsert', 'csrf_token' => $csrf, 'goal_month' => $month,
                'target_revenue' => '1000', 'target_profit' => '500',
            ], $session);
        }

        $this->assertNoViolation(
            'เดือนของเป้าหมายไม่ใช่รูปแบบ YYYY-MM ที่มีอยู่จริง (หน้ารายปีจะหาไม่เจอ)',
            "SELECT id, shop_id, CONCAT('[', goal_month, ']') AS goal_month FROM monthly_goals
             WHERE goal_month NOT REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'"
        );

        // ⚠️ ด้าน (ข) — เดือนที่ถูกต้องต้องตั้งเป้าได้ตามปกติ
        $this->post('/api/goals.php', [
            'action' => 'upsert', 'csrf_token' => $csrf, 'goal_month' => '2026-07',
            'target_revenue' => '1000', 'target_profit' => '500',
        ], $session);
        $saved = $this->pdo->prepare('SELECT goal_month FROM monthly_goals WHERE shop_id = :s');
        $saved->execute(['s' => $shopId]);
        $this->assertSame(['2026-07'], array_column($saved->fetchAll(), 'goal_month'), 'เดือนที่ถูกต้องกลับตั้งเป้าไม่ได้');
    }

    /**
     * ⭐⭐⭐ **ร้านที่ "ดูเหมือนกัน" ต้องไม่กลายเป็นสองร้าน**
     *
     * ⚠️⚠️ ถ้าหลุด ผู้ใช้จะมีร้านสองร้านที่บนจอเขียนเหมือนกันทุกตัวอักษร แล้ว**รายงาน
     * จะแยกยอดออกเป็นสองก้อน** — เจ้าของร้านเห็นกำไรครึ่งเดียวโดยไม่รู้ว่าอีกครึ่งอยู่ไหน
     *
     * ตัวที่กันมีสองชั้นและต้องทำงานพร้อมกัน:
     *   1. `trim_unicode_whitespace()` ตัดช่องว่างที่มองไม่เห็น **หัวท้าย** (NBSP จากการก๊อปวาง)
     *   2. collation `utf8mb4_unicode_520_ci` ของ `shops.name` ตัดสินว่าอะไร "ซ้ำ"
     *      — ไม่สนตัวพิมพ์ใหญ่เล็ก และไม่ให้น้ำหนักอักขระความกว้างศูนย์ **ที่อยู่กลางคำ**
     *      (ชั้นที่ 1 ตัดไม่ถึงตรงกลาง ชั้นนี้จึงจำเป็น)
     */
    public function testNamesThatLookIdenticalNeverBecomeTwoShops(): void
    {
        $userId = $this->createUser('shopname@example.com', 'ShopNamePass123');
        $shopId = $this->createShop($userId, 'ร้านแรก');
        $session = $this->startSession($userId, $shopId);
        $csrf = $this->csrfTokenFor($session);

        foreach ([
            'ร้านกาแฟ',                 // ต้นฉบับ
            'ร้านกาแฟ ',                // เว้นวรรคท้าย
            "ร้านกาแฟ\u{00A0}",         // NBSP ท้าย (ก๊อปจากแชต/Word)
            " ร้านกาแฟ",                // เว้นวรรคหน้า
            /* ⚠️⚠️ NBSP **หน้า** ชื่อ คือเคสที่ฐานข้อมูลช่วยไม่ได้ —
               collation ตัดช่องว่าง **ท้าย** ให้เท่านั้น (PAD SPACE) ส่วนหน้าชื่อไม่ตัด
               ตัวตัดฝั่ง PHP จึงเป็นด่านเดียว · วัดแล้ว: [ร้าน] vs [<NBSP>ร้าน] ฐานข้อมูล
               ถือว่า "คนละชื่อ" · ถ้าไม่มีเคสนี้ เทสต์จะเขียวต่อให้ถอดตัวตัดออกทั้งหมด */
            "\u{00A0}ร้านกาแฟ",         // NBSP หน้า — ฐานข้อมูลไม่ช่วย
            "ร้าน\u{200B}กาแฟ",         // อักขระความกว้างศูนย์คั่นกลาง
            'SHOP A',
            'shop a',                   // ต่างแค่ตัวพิมพ์
        ] as $name) {
            $this->post('/api/shops.php', ['action' => 'create', 'csrf_token' => $csrf, 'name' => $name], $session);
        }

        $this->assertNoViolation(
            'ผู้ใช้คนเดียวมีสองร้านที่ชื่อเหมือนกันหลังตัดช่องว่าง (รายงานจะแยกยอดเป็นสองก้อน)',
            /* ⚠️ MySQL TRIM() ตัดแค่ช่องว่างธรรมดา — ต้องล้าง NBSP กับอักขระความกว้างศูนย์
               เองก่อนเทียบ ไม่งั้นชื่อที่ผู้ใช้เห็นว่าเหมือนกันจะลอดตัวตรวจไปได้ */
            "SELECT a.user_id, a.id AS id_a, a.name AS name_a, b.id AS id_b, b.name AS name_b
             FROM shops a JOIN shops b ON a.user_id = b.user_id AND a.id < b.id
             WHERE TRIM(REPLACE(REPLACE(a.name, UNHEX('C2A0'), ''), UNHEX('E2808B'), ''))
                 = TRIM(REPLACE(REPLACE(b.name, UNHEX('C2A0'), ''), UNHEX('E2808B'), ''))"
        );

        $shops = $this->pdo->prepare('SELECT name FROM shops WHERE user_id = :u ORDER BY id');
        $shops->execute(['u' => $userId]);
        $names = array_column($shops->fetchAll(), 'name');

        /* ⚠️ ด้าน (ข) — ชื่อที่ **ต่างกันจริง** ต้องสร้างได้ ไม่ใช่ถูกกลืนไปด้วย
           ถ้าตัวกันเหมารวมเกินไป ผู้ใช้จะสร้างร้านที่สองไม่ได้เลย */
        $this->assertContains('ร้านแรก', $names, 'ร้านตั้งต้นหายไป');
        $this->assertContains('ร้านกาแฟ', $names, 'ชื่อใหม่ที่ไม่ซ้ำกลับสร้างไม่ได้');
        $this->assertContains('SHOP A', $names, 'ชื่อภาษาอังกฤษที่ไม่ซ้ำกลับสร้างไม่ได้');
        $this->assertCount(3, $names, 'จำนวนร้านไม่ตรง — ได้ ' . implode(' · ', $names));
    }

    /**
     * ⭐⭐ **ลบร้านแล้วต้องไม่เหลือของกำพร้า และร้านอื่นต้องไม่ถูกแตะ**
     *
     * ⚠️ ลบผ่านหน้าเว็บจริง ไม่ใช่ DELETE ตรง — เพื่อพิสูจน์ว่า **ทางที่ผู้ใช้กด**
     * ทำให้ข้อมูลข้างในหายครบ (ไม่ใช่แค่ว่า foreign key ตั้งถูก)
     * · รายการกำพร้าที่ค้างอยู่จะไม่ปรากฏบนหน้าจอไหนเลย แต่ยังกินพื้นที่และโผล่ในยอดรวม
     *   ระดับฐานข้อมูลได้ถ้าวันหนึ่งมีคนเขียน query ที่ไม่ได้ join กับ shops
     */
    public function testDeletingAShopLeavesNothingBehind(): void
    {
        $userId = $this->createUser('cascade@example.com', 'CascadePass123');
        $keep = $this->createShop($userId, 'ร้านที่เก็บไว้');
        $doomed = $this->createShop($userId, 'ร้านที่จะลบ');
        $session = $this->startSession($userId, $keep);
        $csrf = $this->csrfTokenFor($session);

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:s, :d, 100, 50, \'x\', NOW(), NOW())'
        );
        $goal = $this->pdo->prepare(
            'INSERT INTO monthly_goals (shop_id, goal_month, target_revenue, target_profit, created_at, updated_at)
             VALUES (:s, \'2026-05\', 1000, 500, NOW(), NOW())'
        );
        foreach ([$keep, $doomed] as $shop) {
            $insert->execute(['s' => $shop, 'd' => '2026-05-01']);
            $insert->execute(['s' => $shop, 'd' => '2026-05-02']);
            $goal->execute(['s' => $shop]);
        }

        $this->post('/api/shops.php', [
            'action' => 'delete', 'csrf_token' => $csrf,
            'shop_id' => (string)$doomed, 'confirm_shop_name' => 'ร้านที่จะลบ',
        ], $session);

        $this->assertNoViolation(
            'มีรายการที่ร้านของมันหายไปแล้ว (กำพร้า)',
            'SELECT dr.id, dr.shop_id, dr.record_date FROM daily_records dr
             LEFT JOIN shops s ON s.id = dr.shop_id WHERE s.id IS NULL'
        );
        $this->assertNoViolation(
            'มีเป้าหมายที่ร้านของมันหายไปแล้ว (กำพร้า)',
            'SELECT g.id, g.shop_id FROM monthly_goals g
             LEFT JOIN shops s ON s.id = g.shop_id WHERE s.id IS NULL'
        );
        $this->assertNoViolation(
            'มีร้านที่เจ้าของหายไปแล้ว',
            'SELECT s.id FROM shops s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL'
        );

        // ⚠️ ด้าน (ข) — ร้านที่ไม่ได้สั่งลบต้องยังครบทุกอย่าง
        $left = $this->pdo->prepare('SELECT COUNT(*) FROM daily_records WHERE shop_id = :s');
        $left->execute(['s' => $keep]);
        $this->assertSame(2, (int)$left->fetchColumn(), 'ลบร้านหนึ่งแล้วข้อมูลของอีกร้านหายตามไปด้วย');

        $leftGoal = $this->pdo->prepare('SELECT COUNT(*) FROM monthly_goals WHERE shop_id = :s');
        $leftGoal->execute(['s' => $keep]);
        $this->assertSame(1, (int)$leftGoal->fetchColumn(), 'เป้าหมายของร้านที่เก็บไว้หายไปด้วย');
    }

    /**
     * ⭐⭐⭐ **แถววันอนาคตยุคเก่า ต้องไม่ไหลเข้ายอดรวมของรายงาน**
     *
     * ⚠️⚠️ **นี่คือข้อยกเว้นเดียวของไฟล์นี้ที่ใส่ข้อมูลเข้าฐานตรง ๆ — และมีเหตุผล**
     * กติกาปัจจุบันคือ `daily_records` เป็นยอดที่เกิดขึ้นจริงเท่านั้น ห้ามบันทึกวันอนาคต
     * (เจ้าของระบบตัดสิน 6 ส.ค. 2569) จึงสร้างแถวแบบนี้ผ่านหน้าเว็บไม่ได้อีกแล้ว
     * **แต่ข้อมูลที่ลงไว้ก่อนหน้านั้นยังอยู่ในฐานข้อมูลของลูกค้าได้** — และถ้ามันไหลเข้า
     * ยอดรวม รายงานจะบวกเงินที่ยังไม่เกิดขึ้นเข้าไปโดยไม่มีอะไรบอก
     *
     * ⚠️ ยอดของแถวล่อตั้งไว้สูงมาก (฿500,000) เทียบกับยอดจริง (฿9,000) —
     * ถ้ารั่วแม้แต่นิดเดียวตัวเลขจะผิดจนเห็นชัด ไม่ใช่ผิดแบบเดาไม่ออก
     */
    public function testLegacyFutureRowsNeverReachTheTotals(): void
    {
        $userId = $this->createUser('legacy@example.com', 'LegacyPass1234');
        $shopId = $this->createShop($userId, 'ร้านที่มีแถวเก่า');
        $today = new \DateTimeImmutable('today');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:s, :d, :r, :a, \'\', NOW(), NOW())'
        );
        for ($i = 1; $i <= 3; $i++) {
            $insert->execute([
                's' => $shopId,
                'd' => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'r' => 3000, 'a' => 2000,
            ]);
        }
        $insert->execute([
            's' => $shopId,
            'd' => $today->modify('+3 days')->format('Y-m-d'),
            'r' => 500000, 'a' => 0,
        ]);

        $recordRepository = new RecordRepository($this->pdo);
        $shopRepository = new ShopRepository($this->pdo);
        $goalRepository = new GoalRepository($this->pdo);

        $dashboard = (new DashboardService($recordRepository, $shopRepository, $goalRepository))
            ->buildDashboard($userId, $shopId, 'month_this', null, null, null, $today->format('Y-m-d'));
        $this->assertSame(
            9000.0,
            round((float)($dashboard['data']['summary']['total_revenue'] ?? -1), 2),
            'แดชบอร์ดนับแถววันอนาคตเข้ายอดรวมของเดือนนี้'
        );

        $annual = (new AnnualService($recordRepository, $shopRepository, $goalRepository))
            ->buildYearlySummary($userId, $shopId, (int)$today->format('Y'), $today->format('Y-m-d'));
        $this->assertSame(
            9000.0,
            round((float)($annual['data']['summary']['total_revenue'] ?? -1), 2),
            'หน้ารายปีนับแถววันอนาคตเข้ายอดรวมทั้งปี'
        );

        /* ⚠️ ด้าน (ข) — แถวในอดีตต้องถูกนับครบ ไม่ใช่ถูกตัดทิ้งไปด้วย
           (ตัวตัดที่กว้างเกินจะทำให้ยอดหายทั้งเดือน ซึ่งแย่กว่าปัญหาเดิม) */
        $statistics = (array)($dashboard['data']['statistics'] ?? []);
        $this->assertSame(3, (int)($statistics['days_count'] ?? -1), 'วันในอดีตถูกตัดทิ้งไปด้วย');
    }
}
