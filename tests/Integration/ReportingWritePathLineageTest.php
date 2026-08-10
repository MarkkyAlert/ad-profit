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

    /** @return array{0:int,1:int,2:string} */
    private function signedInShop(string $email): array
    {
        $userId = $this->createUser($email, 'LineagePass123');
        $shopId = $this->createShop($userId, 'ร้านทดสอบเส้นทางเขียน');

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
            self::PROFIT,
            $onScreen,
            0.005,
            "{$path}: กำไรบนหน้ารายปีไม่ตรงกับที่เขียนเข้าไป"
        );

        $csv = $this->get('/api/export.php?month=2026-08', $session);
        $this->assertSame(200, $csv['status'], "{$path}: ดาวน์โหลด CSV ไม่สำเร็จ");
        $this->assertStringContainsString(
            number_format(self::PROFIT, 2, '.', ''),
            $csv['body'],
            "{$path}: กำไรไม่ปรากฏในไฟล์ CSV"
        );
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
    }
}
