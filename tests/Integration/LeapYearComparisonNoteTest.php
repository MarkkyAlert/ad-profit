<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ⭐⭐⭐ ปีอธิกสุรทิน: สองฝั่งของ "เทียบปีก่อน" ยาวไม่เท่ากัน → ต้องกำกับให้ผู้ใช้รู้
 *
 * ⚠️⚠️ **วัดจริงด้วยร้านที่ทำกำไรวันละ ฿1,000 เท่ากันเป๊ะ 4 ปี กรอกครบทุกวัน**
 * (เดิน `วันนี้` ทีละวันทั้งปี — วิธีล่าบั๊กรายงานที่ได้ผลที่สุดของโปรเจกต์นี้):
 *   · 28 ก.พ. 71 → +0.0%   ✓
 *   · **29 ก.พ. 71 → +1.7%**   ← กระโดดข้ามคืนทั้งที่ธุรกิจไม่เปลี่ยนเลย
 *   · สิ้นปี 71 → +0.3%  ·  1 มี.ค. 72 → **−1.6%**  ·  สิ้นปี 72 → −0.3%
 *   · แถวเดือน ก.พ. 71 (29 วัน) → **+3.6%**  ·  ก.พ. 72 (28 วัน) → **−3.4%**
 *
 * [เจ้าของระบบตัดสิน 2026-08-11] **ไม่แก้ตัวเลข แต่ต้องกำกับ**
 * · ตัวเลขไม่ได้ผิด — ปีอธิกสุรทินได้เงินมากกว่าจริงเพราะมีวันขายเพิ่มมาหนึ่งวัน
 * · สิ่งที่ผิดคือคำว่า "ช่วงเดียวกัน" ที่ทำให้อ่านว่าเทียบกันได้ตรง ๆ
 * · ทางที่ตัดข้อมูลจริงทิ้ง 1 วันให้เท่ากันถูกปฏิเสธ เพราะจะทำให้การ์ด "กำไรรวมทั้งปี"
 *   กับป้ายเทียบข้าง ๆ ใช้ตัวเลขคนละชุด (ปัญหาที่โปรเจกต์นี้เจอซ้ำมาแล้ว)
 *
 * ⚠️ เทสต์นี้ต้องตรวจ **ฝั่งตรงข้าม** ด้วย — ปีปกติเทียบปีปกติต้อง **ไม่มี** ข้อความนี้
 * ไม่งั้นการ "แก้" ที่พิมพ์บรรทัดนี้ทุกปีก็ผ่านหน้าตาเฉย (ผู้ใช้ 3 ใน 4 ปีต้องไม่เห็นเลย)
 */
final class LeapYearComparisonNoteTest extends ControllerTestCase
{
    /**
     * ⚠️⚠️ ต้องใช้ปีอธิกสุรทินที่ **ผ่านมาแล้ว** — หน้าเว็บไม่มีทางปักวันที่ให้ได้
     * (ไม่มี seam `$today` ผ่าน HTTP) และปีอนาคตระบบไม่เทียบให้เลย → เทสต์จะแดง
     * โดยไม่เกี่ยวกับสิ่งที่กำลังพิสูจน์ (เขียนพลาดมาแล้วรอบแรก ใช้ปี 2571)
     *
     * 2567 = 2024 = ปีอธิกสุรทิน (366 วัน) · 2566 = 2023 = ปีปกติ (365 วัน)
     * 2565 = 2022 = ปีปกติ → ใช้เป็นคู่ควบคุมที่ต้องเงียบ
     *
     * ⚠️ ข้อความกำกับขึ้นกับ **ความยาวของปฏิทิน** ไม่ใช่จำนวนแถว จึงไม่ต้องกรอกทุกวัน
     * ขอแค่ทั้งสองปีมีข้อมูลพอให้ % คำนวณได้ (และเดือน ก.พ. ต้องมีทั้งสองฝั่ง)
     */
    private function seedSteadyShop(string $email, string $name): array
    {
        $userId = $this->createUser($email, 'LeapPass123');
        $shopId = $this->createShop($userId, $name);
        // ร้านที่สองให้หน้ารวมร้านเปิดได้ (กติกา: ต้องมี ≥ 2 ร้าน)
        $otherShopId = $this->createShop($userId, $name . ' สอง');

        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, 3000.00, 2000.00, \'\', NOW(), NOW())'
        );

        foreach ([2022, 2023, 2024] as $year) {
            foreach (['01-15', '02-10', '12-20'] as $dayOfYear) {
                foreach ([$shopId, $otherShopId] as $target) {
                    $statement->execute([
                        'shop' => $target,
                        'date' => sprintf('%04d-%s', $year, $dayOfYear),
                    ]);
                }
            }
        }

        return [$userId, $shopId];
    }

    private function pageText(string $session, string $url): string
    {
        $response = $this->get($url, $session);
        $this->assertSame(200, $response['status'], 'เปิดหน้าไม่สำเร็จ: ' . $url);

        return (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $response['body']
        )));
    }

    /**
     * ⭐⭐ หน้ารายปี: ปีอธิกสุรทินต้องกำกับความยาว · ปีปกติต้องเงียบ
     *
     * ⚠️ ปี 2567 (2024) มี 366 วัน · ปี 2566 (2023) มี 365 วัน
     */
    public function testTheAnnualPageSaysWhenTheTwoYearsAreDifferentLengths(): void
    {
        [$userId, $shopId] = $this->seedSteadyShop('leapannual@example.com', 'ร้านนิ่ง');
        $session = $this->startSession($userId, $shopId);

        $leap = $this->pageText($session, '/annual.php?year=2567');
        $this->assertStringContainsString(
            'ช่วงนี้ 366 วัน เทียบกับ 365 วัน',
            $leap,
            'ปีอธิกสุรทินเทียบกับปีปกติ แต่หน้าเว็บไม่ได้บอกว่าสองฝั่งยาวไม่เท่ากัน'
        );

        // ฝั่งตรงข้าม — ปีปกติเทียบปีปกติ ต้องไม่มีบรรทัดนี้เลย
        $normal = $this->pageText($session, '/annual.php?year=2566');
        $this->assertStringNotContainsString(
            'ช่วงนี้',
            $normal,
            'ปีที่ยาวเท่ากันไม่ควรมีข้อความกำกับ — ผู้ใช้ 3 ใน 4 ปีต้องไม่เห็นเลย'
        );
    }

    /**
     * ⭐⭐ หน้ารวมร้าน มุมรายปี ต้องพูดเหมือนหน้ารายปี
     *
     * ⚠️ ตาข่ายต้องครอบทุกหน้าที่ใช้กติกาเดียวกัน ไม่ใช่หน้าที่เพิ่งแก้ —
     * `overview.php` เคยอ่านค่าจากสรุปก่อนที่สรุปจะถูกโหลด แล้วเทสต์ของ annual ยังเขียว
     */
    public function testTheOverviewYearTabSaysItToo(): void
    {
        [$userId, $shopId] = $this->seedSteadyShop('leapoverview@example.com', 'ร้านนิ่ง');
        $session = $this->startSession($userId, $shopId);

        $this->assertStringContainsString(
            'ช่วงนี้ 366 วัน เทียบกับ 365 วัน',
            $this->pageText($session, '/overview.php?view=year&year=2567'),
            'หน้ารวมร้านมุมรายปีไม่ได้บอกว่าสองฝั่งยาวไม่เท่ากัน'
        );

        $this->assertStringNotContainsString(
            'ช่วงนี้',
            $this->pageText($session, '/overview.php?view=year&year=2566'),
            'ปีที่ยาวเท่ากันไม่ควรมีข้อความกำกับ'
        );
    }

    /**
     * ⭐⭐⭐ ไฟล์ Excel ต้องพูดเหมือนหน้าจอ
     *
     * ⚠️ บทเรียนที่จดไว้: "กติกาของรายงานต้องลงถึงไฟล์ Excel ด้วยเสมอ ไม่ใช่แค่หน้าจอ"
     * — คอมมิตที่แก้กริดฤดูกาลเคยแก้หน้าจออย่างเดียวแล้วไฟล์ยังโกหกอยู่
     */
    public function testTheWorkbookCarriesTheSameNote(): void
    {
        [$userId, $shopId] = $this->seedSteadyShop('leapxlsx@example.com', 'ร้านนิ่ง');
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/api/export-xlsx.php?year=2567', $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ');

        $path = tempnam(sys_get_temp_dir(), 'leap') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $book = IOFactory::load($path);
            $text = '';
            foreach ($book->getSheetNames() as $index => $name) {
                foreach ($book->getSheet($index)->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $value = $cell->getValue();
                        if (is_string($value)) {
                            $text .= $value . "\n";
                        }
                    }
                }
            }
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString(
            'ช่วงนี้ 366 วัน เทียบกับ 365 วัน',
            $text,
            'ไฟล์ Excel ไม่ได้กำกับความยาวช่วง ทั้งที่หน้าจอกำกับแล้ว'
        );
    }

    /**
     * ⭐⭐ แถวเดือน ก.พ. ของปีอธิกสุรทิน (29 วัน) เทียบกับ ก.พ. ปีก่อน (28 วัน)
     *
     * ช่องในตารางแคบเกินกว่าจะเขียนข้อความ จึงกำกับไว้ที่ tooltip (`title=`)
     * ⚠️ ตรวจจาก HTML ดิบ เพราะ `strip_tags()` จะกิน attribute นี้ทิ้ง
     */
    public function testTheFebruaryRowExplainsItsExtraDay(): void
    {
        [$userId, $shopId] = $this->seedSteadyShop('leapmonth@example.com', 'ร้านนิ่ง');
        $session = $this->startSession($userId, $shopId);

        $leap = (string)$this->get('/annual.php?year=2567', $session)['body'];
        $this->assertStringContainsString(
            'title="ช่วงนี้ 29 วัน เทียบกับ 28 วัน"',
            $leap,
            'แถวเดือน ก.พ. ของปีอธิกสุรทินไม่ได้อธิบายว่าทำไม % ถึงบวกขึ้นมา'
        );

        $normal = (string)$this->get('/annual.php?year=2566', $session)['body'];
        $this->assertStringNotContainsString(
            'title="ช่วงนี้',
            $normal,
            'ปีปกติไม่ควรมี tooltip กำกับความยาวเลยสักแถว'
        );
    }
}
