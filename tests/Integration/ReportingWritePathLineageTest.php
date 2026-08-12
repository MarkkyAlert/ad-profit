<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * ⭐⭐ ข้อมูลที่เขียนผ่าน **ทางที่ผู้ใช้เดินจริง** ต้องโผล่ในรายงานด้วยตัวเลขเดียวกัน
 *
 * ⚠️⚠️ reconciliation test ที่มีอยู่ทั้งหมด **INSERT ตรงเข้าฐานข้อมูล** แล้วเช็กว่า
 * service คำนวณถูก — ซึ่งข้ามโฟลว์จริงไปทั้งเส้น · ถ้าวันหนึ่งทางเขียนบันทึกคนละแบบ
 * กับที่รายงานอ่าน (ชนิดข้อมูล · รูปแบบวันที่ · การปัดเศษ) เทสต์ทั้งชุดจะยังเขียว
 *
 * เทสต์นี้ยิง endpoint จริงทุกทาง แล้วไล่ต่อไปถึง **แดชบอร์ด · หน้ารายปี · ไฟล์ CSV**
 * ด้วยยอดที่รู้คำตอบล่วงหน้า
 *
 * ⚠️ ใช้ยอดที่มีเศษสตางค์ เพื่อให้การปัดที่ต่างกันระหว่างทางโผล่ออกมา
 */
final class ReportingWritePathLineageTest extends ControllerTestCase
{
    private const REVENUE = 12345.67;
    private const AD_COST = 2345.89;
    private const PROFIT = 9999.78;

    /* ⚠️⚠️ ต้องมีอีกหนึ่งแถวในเดือนเดียวกัน ไม่งั้น "ยอดรวมทั้งเดือน" เท่ากับ "กำไรของวันนั้น"
       พอดี แล้วการตรวจแดชบอร์ดจะผ่านได้ด้วยตัวเลขจากการ์ดอื่นที่คำนวณคนละทาง
       (วัดจริง: มิวเทชันบวก 1 ที่กำไรของแดชบอร์ด แล้วเทสต์ยังเขียว) */
    private const EXTRA_REVENUE = 4321.12;
    private const EXTRA_AD_COST = 1111.34;
    private const MONTH_PROFIT = 13209.56;

    /** @return array{0:int,1:int,2:string} */
    private function signedInShop(string $email): array
    {
        $userId = $this->createUser($email, 'LineagePass123');
        $shopId = $this->createShop($userId, 'ร้านทดสอบเส้นทางเขียน');

        // แถวประกอบฉากในเดือนเดียวกัน — ทำให้ยอดรวมเดือนต่างจากกำไรของวันที่กำลังทดสอบ
        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (?, \'2026-08-02\', ?, ?, \'\', NOW(), NOW())'
        )->execute([$shopId, self::EXTRA_REVENUE, self::EXTRA_AD_COST]);

        return [$userId, $shopId, $this->startSession($userId, $shopId)];
    }

    private function shopContextFrom(string $session): string
    {
        $body = $this->get('/add-record.php', $session)['body'];
        if (preg_match('/name="shop_context_id" value="([^"]*)"/', $body, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /** กำไรของเดือนที่หน้ารายปีแสดง (อ่านจาก HTML จริง) */
    private function annualMonthProfitOnScreen(string $session, string $monthLabel): ?float
    {
        $body = $this->get('/annual.php?year=2569', $session)['body'];

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $body);
        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//table') as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $headings = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headings[] = trim((string)preg_replace('/\s+/u', ' ', $th->textContent));
            }

            $profitColumn = array_search('กำไร', $headings, true);
            if (!in_array('เดือน', $headings, true) || !is_int($profitColumn)) {
                continue;
            }

            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $node) {
                    if ($node->nodeName === 'td') {
                        $cells[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
                    }
                }

                if (count($cells) > $profitColumn && mb_strpos($cells[0], $monthLabel) === 0) {
                    $value = (string)preg_replace('/[^0-9.\-]/u', '', $cells[$profitColumn]);

                    return $value === '' ? null : (float)$value;
                }
            }
        }

        return null;
    }

    private function assertReportsAgree(int $shopId, string $session, string $path): void
    {
        $stored = $this->pdo->prepare(
            'SELECT revenue, ad_cost FROM daily_records WHERE shop_id = ? AND record_date = ?'
        );
        $stored->execute([$shopId, '2026-08-05']);
        $row = $stored->fetch();

        $this->assertIsArray($row, "{$path}: ไม่มีแถวลงฐานข้อมูลเลย");
        $this->assertSame(number_format(self::REVENUE, 2, '.', ''), (string)$row['revenue'], "{$path}: ยอดขายที่เก็บไม่ตรง");
        $this->assertSame(number_format(self::AD_COST, 2, '.', ''), (string)$row['ad_cost'], "{$path}: ค่าแอดที่เก็บไม่ตรง");

        $onScreen = $this->annualMonthProfitOnScreen($session, 'ส.ค.');
        $this->assertNotNull($onScreen, "{$path}: หน้ารายปีไม่แสดงเดือน ส.ค.");
        $this->assertEqualsWithDelta(
            self::MONTH_PROFIT,
            $onScreen,
            0.005,
            "{$path}: กำไรบนหน้ารายปีไม่ตรงกับที่เขียนเข้าไป"
        );

        /* ⚠️⚠️ docblock ของคลาสนี้อ้างว่าไล่ถึง "แดชบอร์ด · หน้ารายปี · ไฟล์ CSV"
           แต่เวอร์ชันแรกตรวจแค่สองอย่างหลัง — คำอธิบายแรงกว่าสิ่งที่ทำจริง
           ซึ่งเป็นความผิดพลาดแบบเดียวกับเทสต์ "รวมได้ 100 เป๊ะ" ที่ใส่ค่าคลาดเคลื่อนไว้ */
        /* ⚠️⚠️ ต้องระบุเดือนตรง ๆ ไม่ใช่ `range=month_this` — ข้อมูลทดสอบปักไว้ที่ ส.ค. 2569
           ถ้าใช้ "เดือนนี้" เทสต์จะพังเองเมื่อเวลาผ่านไปถึงเดือนถัดไป โดยไม่มีใครแก้อะไรผิด */
        $dashboard = $this->get('/dashboard.php?range=month_pick&month=2026-08', $session);
        $this->assertSame(200, $dashboard['status'], "{$path}: เปิดแดชบอร์ดไม่สำเร็จ");
        $this->assertStringContainsString(
            formatMoney(self::MONTH_PROFIT),
            (string)preg_replace('/\s+/u', ' ', strip_tags(
                (string)preg_replace('#<script.*?</script>#s', ' ', $dashboard['body'])
            )),
            "{$path}: กำไรไม่ปรากฏบนแดชบอร์ด"
        );

        $csv = $this->get('/api/export.php?month=2026-08', $session);
        $this->assertSame(200, $csv['status'], "{$path}: ดาวน์โหลด CSV ไม่สำเร็จ");
        $this->assertStringContainsString(
            number_format(self::PROFIT, 2, '.', ''),
            $csv['body'],
            "{$path}: กำไรไม่ปรากฏในไฟล์ CSV"
        );

        /* ⚠️ ปลายทางต้องครบทุกที่ที่ผู้ใช้เปิดดูได้ ไม่ใช่แค่ที่นึกออก —
           ไฟล์ Excel เป็นปลายทางที่ยาวที่สุด (ผ่าน AnnualService → XlsxReportService)
           และเป็นทางที่กติกาตกสำรวจบ่อยที่สุดในโปรเจกต์นี้ */
        $workbook = $this->get('/api/export-xlsx.php?year=2569', $session);
        $this->assertSame(200, $workbook['status'], "{$path}: ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ");
        $this->assertGreaterThan(
            5000,
            strlen($workbook['body']),
            "{$path}: ไฟล์ Excel เล็กผิดปกติ — น่าจะสร้างไม่สำเร็จ"
        );

        $path2 = tempnam(sys_get_temp_dir(), 'lineage') . '.xlsx';
        file_put_contents($path2, $workbook['body']);

        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path2);
            $monthly = $book->getSheetByName('รายเดือน');
            $this->assertNotNull($monthly, "{$path}: ไม่พบชีตรายเดือน");

            $augustProfit = null;
            foreach ($monthly->getRowIterator(2) as $row) {
                $index = $row->getRowIndex();
                if (trim((string)$monthly->getCell('A' . $index)->getValue()) === 'ส.ค.') {
                    $augustProfit = $monthly->getCell('D' . $index)->getValue();
                }
            }

            $this->assertNotNull($augustProfit, "{$path}: ไฟล์ Excel ไม่มีแถวเดือน ส.ค.");
            $this->assertEqualsWithDelta(
                self::MONTH_PROFIT,
                (float)$augustProfit,
                0.005,
                "{$path}: กำไรในไฟล์ Excel ไม่ตรงกับที่เขียนเข้าไป"
            );
        } finally {
            @unlink($path2);
        }
    }

    /** ⭐ ทาง 1 — ฟอร์มกรอกวันเดียว */
    public function testTheSingleDayFormReachesEveryReport(): void
    {
        [, $shopId, $session] = $this->signedInShop('single@example.com');

        $response = $this->post('/api/records.php', [
            'csrf_token' => $this->csrfTokenFor($session),
            'action' => 'upsert',
            'shop_context_id' => $this->shopContextFrom($session),
            'record_date' => '2026-08-05',
            'revenue' => (string)self::REVENUE,
            'ad_cost' => (string)self::AD_COST,
            'note' => 'ทางฟอร์มวันเดียว',
        ], $session);
        /* ⚠️⚠️ 302 ไม่ได้แปลว่าสำเร็จ — ด่าน CSRF/method ก็ตอบ 302 เหมือนกัน
           การยืนยันจริงอยู่ที่ `assertReportsAgree()` ซึ่งอ่านฐานข้อมูลและหน้าเว็บ
           (เขียนเทสต์รอบแรกลืมแนบ csrf แล้ว assert 302 ผ่านฉลุยทั้งที่ไม่มีอะไรถูกเขียน) */
        $this->assertSame(302, $response['status']);

        $this->assertReportsAgree($shopId, $session, 'ฟอร์มกรอกวันเดียว');
    }

    /** ⭐ ทาง 2 — ตารางกรอกหลายวัน (ทางเดียวกับวางจาก Excel และเติมทั้งเดือน) */
    public function testTheBulkTableReachesEveryReport(): void
    {
        [, $shopId, $session] = $this->signedInShop('bulk@example.com');

        $response = $this->post('/api/records.php', [
            'csrf_token' => $this->csrfTokenFor($session),
            'action' => 'bulk_upsert',
            'shop_context_id' => $this->shopContextFrom($session),
            'record_date' => ['2026-08-05'],
            'revenue' => [(string)self::REVENUE],
            'ad_cost' => [(string)self::AD_COST],
            'note' => ['ทางตารางหลายวัน'],
            'row_number' => ['1'],
            'note_checked' => ['1'],
        ], $session);
        /* ⚠️⚠️ 302 ไม่ได้แปลว่าสำเร็จ — ด่าน CSRF/method ก็ตอบ 302 เหมือนกัน
           การยืนยันจริงอยู่ที่ `assertReportsAgree()` ซึ่งอ่านฐานข้อมูลและหน้าเว็บ
           (เขียนเทสต์รอบแรกลืมแนบ csrf แล้ว assert 302 ผ่านฉลุยทั้งที่ไม่มีอะไรถูกเขียน) */
        $this->assertSame(302, $response['status']);

        $this->assertReportsAgree($shopId, $session, 'ตารางกรอกหลายวัน');
    }

    /** ⭐ ทาง 3 — แก้ไขรายการที่มีอยู่ */
    public function testEditingAnExistingRecordReachesEveryReport(): void
    {
        [, $shopId, $session] = $this->signedInShop('edit@example.com');

        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (?, \'2026-08-05\', 1, 1, \'\', NOW(), NOW())'
        )->execute([$shopId]);
        $recordId = (int)$this->pdo->query('SELECT id FROM daily_records ORDER BY id DESC LIMIT 1')->fetchColumn();

        $response = $this->post('/api/records.php', [
            'csrf_token' => $this->csrfTokenFor($session),
            'action' => 'update',
            'shop_context_id' => $this->shopContextFrom($session),
            'record_id' => (string)$recordId,
            'record_date' => '2026-08-05',
            'revenue' => (string)self::REVENUE,
            'ad_cost' => (string)self::AD_COST,
            'note' => 'ทางแก้ไข',
        ], $session);
        /* ⚠️⚠️ 302 ไม่ได้แปลว่าสำเร็จ — ด่าน CSRF/method ก็ตอบ 302 เหมือนกัน
           การยืนยันจริงอยู่ที่ `assertReportsAgree()` ซึ่งอ่านฐานข้อมูลและหน้าเว็บ
           (เขียนเทสต์รอบแรกลืมแนบ csrf แล้ว assert 302 ผ่านฉลุยทั้งที่ไม่มีอะไรถูกเขียน) */
        $this->assertSame(302, $response['status']);

        $this->assertReportsAgree($shopId, $session, 'แก้ไขรายการ');
    }

    /**
     * ⭐⭐⭐ **ทาง 5 — การลบ ก็เป็นทางเขียนเหมือนกัน และเป็นทางเดียวที่ไม่เคยถูกไล่ถึงปลายทาง**
     *
     * ⚠️⚠️ ตาข่ายเดิมไล่ครบ 4 ทางที่ *เพิ่ม/แก้* ข้อมูล (กรอกเดี่ยว · ตารางหลายวัน ·
     * แก้ไขรายการ · นำเข้าไฟล์) แต่ **การลบมีเทสต์แค่ระดับ endpoint** คือยืนยันว่าแถว
     * หายไปจากฐานข้อมูล — **ไม่เคยมีใครถามว่ารายงานตามทันไหม**
     *
     * ทำไมถึงสำคัญ: การลบคือทางเดียวที่ทำให้ตัวเลข **ลดลง** · ถ้าปลายทางไหนสักที่
     * ยังนับแถวที่ลบไปแล้ว ผู้ใช้จะเห็นกำไรสูงกว่าความจริงโดยไม่มีอะไรบอก และเป็น
     * ทิศทางที่คนไม่ทันสังเกต (เลขมากขึ้นดูเหมือนข่าวดี)
     *
     * ⚠️ ต้องตรวจ **สองด้าน**: ยอดของวันที่ลบต้องหายไป **และ** ยอดของแถวประกอบฉาก
     * ในเดือนเดียวกันต้องยังอยู่ครบ — ไม่งั้นการ "แก้" ที่ลบทั้งเดือนทิ้งก็ผ่านได้
     */
    public function testDeletingARecordReachesEveryReport(): void
    {
        [, $shopId, $session] = $this->signedInShop('delete@example.com');

        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (?, \'2026-08-05\', ?, ?, \'จะถูกลบ\', NOW(), NOW())'
        )->execute([$shopId, self::REVENUE, self::AD_COST]);
        $recordId = (int)$this->pdo->query('SELECT id FROM daily_records ORDER BY id DESC LIMIT 1')->fetchColumn();

        // ยืนยันก่อนว่ารายงานเห็นมันจริง ไม่งั้น "หายไป" ทีหลังพิสูจน์อะไรไม่ได้
        $before = $this->annualMonthProfitOnScreen($session, 'ส.ค.');
        $this->assertNotNull($before, 'หน้ารายปีไม่แสดงเดือน ส.ค. ตั้งแต่ก่อนลบ');
        $this->assertEqualsWithDelta(self::MONTH_PROFIT, $before, 0.005, 'ยอดตั้งต้นก่อนลบไม่ตรง');

        $response = $this->post('/api/records.php', [
            'csrf_token' => $this->csrfTokenFor($session),
            'action' => 'delete',
            'shop_context_id' => $this->shopContextFrom($session),
            'record_id' => (string)$recordId,
        ], $session);
        $this->assertSame(302, $response['status']);

        $remaining = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM daily_records WHERE shop_id = {$shopId} AND record_date = '2026-08-05'"
        )->fetchColumn();
        $this->assertSame(0, $remaining, 'แถวยังอยู่ในฐานข้อมูลหลังกดลบ');

        $expected = self::EXTRA_REVENUE - self::EXTRA_AD_COST;   // เหลือแต่แถวประกอบฉาก

        $onScreen = $this->annualMonthProfitOnScreen($session, 'ส.ค.');
        $this->assertNotNull($onScreen, 'หน้ารายปีไม่แสดงเดือน ส.ค. หลังลบ');
        $this->assertEqualsWithDelta(
            $expected,
            $onScreen,
            0.005,
            'หน้ารายปียังนับแถวที่ลบไปแล้ว — ผู้ใช้เห็นกำไรสูงกว่าความจริง'
        );

        $dashboard = $this->get('/dashboard.php?range=month_pick&month=2026-08', $session);
        $this->assertSame(200, $dashboard['status'], 'เปิดแดชบอร์ดไม่สำเร็จหลังลบ');
        $plain = (string)preg_replace('/\s+/u', ' ', strip_tags(
            (string)preg_replace('#<script.*?</script>#s', ' ', $dashboard['body'])
        ));
        $this->assertStringContainsString(formatMoney($expected), $plain, 'แดชบอร์ดไม่ได้อัปเดตหลังลบ');
        $this->assertStringNotContainsString(
            formatMoney(self::MONTH_PROFIT),
            $plain,
            'แดชบอร์ดยังพิมพ์ยอดเดิมที่รวมแถวที่ลบไปแล้ว'
        );

        $csv = $this->get('/api/export.php?month=2026-08', $session);
        $this->assertSame(200, $csv['status'], 'ดาวน์โหลด CSV ไม่สำเร็จหลังลบ');
        $this->assertStringNotContainsString(
            number_format(self::PROFIT, 2, '.', ''),
            $csv['body'],
            'ไฟล์ CSV ยังมีรายการที่ลบไปแล้ว'
        );
        $this->assertStringContainsString(
            number_format(self::EXTRA_REVENUE, 2, '.', ''),
            $csv['body'],
            'ไฟล์ CSV หายไปทั้งเดือน — การลบไม่ควรกระทบวันอื่น'
        );
    }

    /**
     * ⭐⭐ เป้าหมายรายเดือนที่ตั้งผ่าน endpoint จริง ต้องไปโผล่ในหน้ารายปี
     *
     * ⚠️ `monthly_goals` เป็นตารางเดียวที่รายงานอ่านแต่ไม่ได้มาจากทางเขียน record
     * ถ้าวันหนึ่ง endpoint เขียนคนละคีย์กับที่รายงานอ่าน จะไม่มีอะไรจับได้เลย
     */
    public function testAGoalSetThroughTheEndpointShowsUpInTheAnnualReport(): void
    {
        [, , $session] = $this->signedInShop('goal@example.com');

        $response = $this->post('/api/goals.php', [
            'csrf_token' => $this->csrfTokenFor($session),
            'action' => 'upsert',
            'shop_context_id' => $this->shopContextFrom($session),
            'goal_month' => '2026-08',
            'target_revenue' => '500000',
            'target_profit' => '200000',
        ], $session);
        $this->assertSame(302, $response['status'], 'ตั้งเป้าหมายผ่าน endpoint ไม่สำเร็จ');

        $stored = $this->pdo->query(
            'SELECT target_revenue, target_profit FROM monthly_goals WHERE goal_month = \'2026-08\''
        )->fetch();
        $this->assertIsArray($stored, 'เป้าหมายไม่ได้ถูกบันทึกลงฐานข้อมูล');

        $body = $this->get('/annual.php?year=2569', $session)['body'];
        $text = (string)preg_replace('/\s+/u', ' ', strip_tags(
            (string)preg_replace('#<script.*?</script>#s', ' ', $body)
        ));

        $this->assertStringContainsString(
            '500,000',
            $text,
            'เป้ารายได้ที่ตั้งผ่าน endpoint ไม่ปรากฏในหน้ารายปี'
        );

        /* ⚠️ ต้องตรวจ **เป้ากำไร** ด้วย — เดิมตรวจแต่เป้ารายได้ ถ้าวันหนึ่ง endpoint
           เขียนคีย์ผิดเฉพาะช่องกำไร จะไม่มีอะไรจับได้เลย */
        $this->assertSame(
            '200000.00',
            (string)($stored['target_profit'] ?? ''),
            'เป้ากำไรที่ตั้งผ่าน endpoint ไม่ถูกบันทึก'
        );
        $this->assertStringContainsString(
            '200,000',
            $text,
            'เป้ากำไรที่ตั้งผ่าน endpoint ไม่ปรากฏในหน้ารายปี'
        );
    }

    /**
     * ⭐⭐ ทาง 4 — นำเข้าไฟล์ CSV
     *
     * ⚠️ ทางนี้มีตัวอ่านของตัวเอง (แปลงวันที่ · อ่านจำนวนเงิน · จับคู่หัวตาราง) แยกจาก
     * อีกสามทางโดยสิ้นเชิง · เทสต์ที่มีอยู่ตรวจแค่ "นำเข้าสำเร็จกี่แถว" ไม่เคยไล่ต่อว่า
     * ตัวเลขที่เข้าไปโผล่ในรายงานตรงกับไฟล์ต้นทางไหม
     */
    public function testTheCsvImportReachesEveryReport(): void
    {
        [, $shopId, $session] = $this->signedInShop('import@example.com');

        $csv = "วันที่,ยอดขาย,ค่าแอด,โน้ต\n"
            . '2026-08-05,' . self::REVENUE . ',' . self::AD_COST . ",ทางนำเข้าไฟล์\n";

        $response = $this->postFile(
            '/api/records.php',
            [
                'csrf_token' => $this->csrfTokenFor($session),
                'action' => 'import_csv',
                'shop_context_id' => $this->shopContextFrom($session),
            ],
            // ⚠️ ชื่อช่องไฟล์คือ `csv` ไม่ใช่ `csv_file` — ผิดแล้ว endpoint ตอบ 302 เหมือนสำเร็จ
            'csv',
            'records.csv',
            $csv,
            $session
        );
        $this->assertSame(302, $response['status']);

        $this->assertReportsAgree($shopId, $session, 'นำเข้าไฟล์ CSV');
    }
}
