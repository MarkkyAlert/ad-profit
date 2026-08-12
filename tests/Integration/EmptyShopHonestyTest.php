<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * ⭐⭐ ร้านที่ยังไม่เคยกรอกอะไรเลย = "ยังไม่รู้" ห้ามรายงานเป็น ฿0
 *
 * นี่คือ core value ของแอปนี้ — ตอนเพิ่งเริ่มใช้ (ข้อมูลน้อยที่สุด) รายงานต้องไม่โชว์
 * เลขที่ดูน่าเชื่อแต่ไม่มีอะไรรองรับ
 *
 * ⚠️⚠️ กติกานี้ถูกบังคับใช้ไม่ทั่วถึงมาตลอด และแต่ละครั้งที่แก้ก็ไปไม่ถึงที่เหลือ:
 *   · แดชบอร์ด — ซ่อนการ์ดตัวเลขแล้ว (`$showFirstRecordInvite`)
 *   · **หน้ารายปี — ยังกางการ์ด "ยอดขายรวมทั้งปี ฿0 · ค่าแอดรวมทั้งปี ฿0 · กำไรรวมทั้งปี ฿0"
 *     อยู่ใต้ข้อความ "ยังไม่มีข้อมูลยอดขาย" บนจอเดียวกัน** ขณะที่การ์ด ROAS ในชุดเดียวกัน
 *     ตอบ `–` ถูกต้องแล้ว
 *   · **ตารางเทียบร้าน — ร้านที่ days_count = 0 แสดง ฿0/฿0/฿0 และ "สัดส่วนกำไร 0.0%"**
 *     ขณะที่ ROAS/อัตรากำไร ในแถวเดียวกันตอบ `–` · ตารางนี้คือเครื่องมือตัดสินว่า
 *     "ร้านไหนคุ้ม" — คนอ่านเทียบ "ร้าน C กำไร ฿0" กับ "ร้าน D ขาดทุน ฿-5,000"
 *     แล้วสรุปว่า C ดีกว่า ทั้งที่ C แค่ยังไม่มีข้อมูล
 *
 * ⚠️ เทสต์นี้ต้องเปิด "หน้าเว็บจริง" ไม่ใช่เรียก service — เพราะ service คืน 0 ได้ถูกต้อง
 * (ต้องมีค่าให้คำนวณต่อ) สิ่งที่ห้ามคือ **การพิมพ์ออกไปหาผู้ใช้**
 */
final class EmptyShopHonestyTest extends ControllerTestCase
{
    /** @return array{0:int,1:int,2:int} [userId, ร้านที่มีข้อมูล, ร้านที่ยังไม่เคยกรอก] */
    private function seedOneUsedShopAndOneEmptyShop(): array
    {
        $userId = $this->createUser('honesty@example.com', 'HonestPass123');
        $usedShop = $this->createShop($userId, 'ร้านที่กรอกแล้ว');
        $emptyShop = $this->createShop($userId, 'ร้านที่ยังไม่เคยกรอก');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        foreach ([['2026-08-01', 12000.50, 4000.25], ['2026-08-02', 9000.00, 3000.00]] as [$date, $rev, $ad]) {
            $insert->execute(['shop' => $usedShop, 'date' => $date, 'revenue' => $rev, 'ad' => $ad]);
        }

        return [$userId, $usedShop, $emptyShop];
    }

    /** ข้อความล้วนของหน้า (ตัด <style>/<script> ออก เพราะ CSS มีคำว่า 0 เต็มไปหมด) */
    private function visibleText(string $html): string
    {
        $html = (string)preg_replace('#<style.*?</style>#s', ' ', $html);
        $html = (string)preg_replace('#<script.*?</script>#s', ' ', $html);

        return (string)preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    /**
     * ⭐ หน้ารายปีของร้านที่ยังไม่เคยกรอก ต้องไม่มีการ์ดตัวเลขเลยสักใบ
     */
    public function testTheAnnualPageShowsNoMoneyCardsForAShopThatNeverRecorded(): void
    {
        [$userId, , $emptyShop] = $this->seedOneUsedShopAndOneEmptyShop();
        $session = $this->startSession($userId, $emptyShop);

        $body = $this->get('/annual.php?year=2569', $session)['body'];

        $this->assertStringContainsString(
            'ยังไม่มีข้อมูลรายได้',
            $this->visibleText($body),
            'หน้าไม่ได้บอกด้วยซ้ำว่ายังไม่มีข้อมูล'
        );

        $this->assertSame(
            0,
            preg_match_all('/<article class="stat-card/', $body),
            'ร้านที่ยังไม่เคยกรอกอะไรเลย แต่หน้ารายปียังกางการ์ดตัวเลข (ค่าจะเป็น ฿0 ทั้งหมด)'
        );

        $this->assertStringNotContainsString(
            'กำไรสะสม ณ',
            $this->visibleText($body),
            'ร้านที่ยังไม่เคยกรอก ไม่ควรมีบรรทัด "กำไรสะสม" ให้พูดถึง'
        );

        /* ⚠️ แถวรวมท้ายตารางก็ต้องเป็นขีด — ทุกแถวเดือนเหนือมันเป็นขีดอยู่แล้ว
           ตารางที่ว่างทั้งตารางแล้วลงท้ายด้วย "รวมทั้งปี ฿0" อ่านว่า "ทำมาทั้งปีได้ศูนย์" */
        $this->assertSame(
            0,
            preg_match_all('/฿0(?![0-9,.])/u', $this->visibleText($body)),
            'ยังมีจำนวนเงิน ฿0 หลงเหลือบนหน้ารายปีของร้านที่ยังไม่เคยกรอก'
        );
    }

    /**
     * ⭐ ปีที่เลือกไม่มีข้อมูล (แต่ร้านมีข้อมูลปีอื่น) ยังต้องเห็นการ์ด
     *
     * ⚠️⚠️ ตัวกันฝั่งตรงข้าม — ถ้าเผลอซ่อนด้วย "ปีนี้ไม่มีข้อมูล" แทน "ร้านไม่เคยกรอก"
     * ผู้ใช้ที่เลือกดูปีเก่าจะไม่ได้คำตอบของคำถามที่ตัวเองถาม
     * (หลักเดียวกับที่แดชบอร์ดยังโชว์ ฿0 เมื่อผู้ใช้เลือกช่วงเวลาเอง)
     */
    public function testAYearWithNoDataStillShowsCardsWhenTheShopHasDataElsewhere(): void
    {
        [$userId, $usedShop] = $this->seedOneUsedShopAndOneEmptyShop();
        $session = $this->startSession($userId, $usedShop);

        // ร้านนี้มีข้อมูลปี 2026 แต่เปิดดูปี 2025 (พ.ศ. 2568) ซึ่งไม่มีข้อมูล
        $body = $this->get('/annual.php?year=2568', $session)['body'];

        $this->assertGreaterThan(
            0,
            preg_match_all('/<article class="stat-card/', $body),
            'เลือกดูปีที่ไม่มีข้อมูลแล้วการ์ดหายหมด — ผู้ใช้ไม่ได้คำตอบของคำถามที่ถาม'
        );
    }

    /**
     * ⭐ ตารางเทียบร้าน: แถวของร้านที่ยังไม่เคยกรอก ต้องเป็นขีดทั้งแถว
     */
    public function testTheShopComparisonRowIsAllDashesForAShopThatNeverRecorded(): void
    {
        [$userId, $usedShop, $emptyShop] = $this->seedOneUsedShopAndOneEmptyShop();
        $session = $this->startSession($userId, $usedShop);

        $body = $this->get('/overview.php', $session)['body'];

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $body);
        $xpath = new DOMXPath($doc);

        $row = null;
        foreach ($xpath->query('//tr') as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }

            $cells = [];
            foreach ($tr->childNodes as $node) {
                if ($node->nodeName === 'td') {
                    $cells[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
                }
            }

            if (in_array('ร้านที่ยังไม่เคยกรอก', $cells, true)) {
                $row = $cells;
                break;
            }
        }

        $this->assertNotNull($row, 'ไม่พบแถวของร้านที่ยังไม่เคยกรอกในตารางเทียบร้าน');

        $dash = no_value_text();
        $money = array_values(array_filter(
            $row,
            static fn(string $cell): bool => str_contains($cell, '฿')
        ));

        $this->assertSame(
            [],
            $money,
            'แถวของร้านที่ยังไม่เคยกรอกยังมีจำนวนเงินอยู่: ' . implode(' | ', $money)
        );

        $this->assertNotContains(
            '0.0%',
            $row,
            'สัดส่วนกำไรของร้านที่ยังไม่เคยกรอกเขียน 0.0% — ต้องเป็น ' . $dash . ' (แปลว่ายังไม่รู้)'
        );

        $this->assertContains(
            '0 วัน',
            $row,
            'คอลัมน์ "วันที่กรอก" คือตัวอธิบายว่าทำไมทั้งแถวเป็นขีด ต้องยังอยู่'
        );
    }
}
