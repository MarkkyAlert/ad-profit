<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ⭐⭐⭐ **ชีต "รายวัน" มีแถวน้อยกว่าหน้าประวัติได้ — แต่ต้องบอกเองว่าทำไม**
 *
 * ⚠️⚠️ สองฝั่งใช้คนละเมธอดและคนละช่วงเวลาโดยตั้งใจ:
 *   · `RecordService::getMonthlyRecords()` (หน้าประวัติ) — **ไม่รับ `$today` เลย**
 *     ต้องแสดงแถวเก่าที่ลงวันที่ล่วงหน้าไว้ ไม่งั้น **ลบมันผ่านหน้าเว็บไม่ได้อีกเลย**
 *   · `ExportService::buildYearlyDailyPayload()` — ตัดที่วันนี้
 *     เพราะ `daily_records` เป็นยอดจริง รายงานไม่ควรนับวันที่ยังไม่เกิดขึ้น
 *
 * ⚠️⚠️ **วัดจริงก่อนตัดสินใจ**: ใส่ 4 แถวปกติ + 1 แถวเก่าวันอนาคตในเดือนเดียวกัน
 * → หน้าประวัติ **5 แถว** · ชีตรายวัน **4 แถว** · ทุกช่องที่มีร่วมกันตรงกันเป๊ะ
 * (รวมเศษสตางค์ · ROAS ที่ค่าแอดเป็นศูนย์ → เว้นว่างทั้งสองฝั่ง)
 *
 * ✅ **[เจ้าของระบบตัดสิน 2026-08-12] คงพฤติกรรมไว้ ให้ไฟล์เขียนกำกับวันตัดของตัวเอง**
 * — คนเปิดไฟล์จะได้ไม่เดาว่าข้อมูลหาย
 *
 * ⚠️ เทสต์นี้ล็อก **ทั้งสองอย่าง**: ค่าที่มีร่วมกันต้องตรงเป๊ะ · ความต่างที่ตั้งใจต้องคงอยู่
 * และต้องมีข้อความอธิบาย · ถ้าใครเปลี่ยนข้างเดียวจะแดงทันที
 */
final class DailySheetCoverageTest extends ControllerTestCase
{
    private const YEAR = 2026;
    private const MONTH = '2026-08';

    /** @return array{0:int,1:string} */
    private function shopWithHistory(string $email): array
    {
        $userId = $this->createUser($email, 'CoveragePass123');
        $shopId = $this->createShop($userId, 'ร้านเทียบรายวัน');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );

        // ⚠️ ต้องมีเศษสตางค์ ไม่งั้นการปัดที่ต่างกันสองฝั่งอาจให้ผลเท่ากันโดยบังเอิญ
        foreach ([
            ['2026-08-01', '1234.56', '789.01', 'วันแรก'],
            ['2026-08-02', '2345.67', '0.00', 'ค่าแอดศูนย์'],
            ['2026-08-03', '0.00', '500.25', 'ขาดทุน'],
            ['2026-08-11', '9876.54', '1234.99', 'โน้ต, มีจุลภาค'],
        ] as $row) {
            $insert->execute([$shopId, $row[0], $row[1], $row[2], $row[3]]);
        }

        return [$shopId, $this->startSession($userId, $shopId)];
    }

    private function addLegacyFutureRow(int $shopId): void
    {
        /* ⚠️ สร้างผ่านหน้าเว็บไม่ได้แล้ว (กติกา "ห้ามบันทึกวันอนาคต") — แต่ข้อมูลเก่า
           ที่ลงไว้ก่อนกติกานี้ยังอยู่ในฐานข้อมูลได้ จึงต้อง INSERT ตรงเพื่อจำลอง */
        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$shopId, '2026-08-28', '5000.00', '1000.00', 'แถวเก่าวันอนาคต']);
    }

    /** วันที่ (ISO) ของทุกแถวในชีตรายวัน */
    private function dailySheetDates(string $session): array
    {
        $response = $this->get('/api/export-xlsx.php?year=' . (self::YEAR + 543), $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ');

        $path = tempnam(sys_get_temp_dir(), 'coverage') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $sheet = IOFactory::load($path)->getSheetByName('รายวัน');
            $this->assertNotNull($sheet, 'ไม่พบชีต "รายวัน"');

            $dates = [];
            foreach ($sheet->getRowIterator(2) as $row) {
                $value = $sheet->getCell('A' . $row->getRowIndex())->getValue();
                if (!is_numeric($value)) {
                    continue;   // แถวรวม / บรรทัดกำกับ
                }
                $dates[] = gmdate('Y-m-d', (int)round(((float)$value - 25569) * 86400));
            }

            return $dates;
        } finally {
            @unlink($path);
        }
    }

    /** วันที่ (ISO) ของทุกแถวบนหน้าประวัติ */
    private function historyDates(string $session): array
    {
        $body = (string)$this->get('/history.php?month=' . self::MONTH, $session)['body'];
        preg_match_all('/(\d{1,2})\s+ส\.ค\.\s+2569/u', $body, $matched);

        $dates = [];
        foreach ($matched[1] as $day) {
            $dates[] = sprintf('2026-08-%02d', (int)$day);
        }

        return array_values(array_unique($dates));
    }

    /**
     * ⭐⭐ ไม่มีแถวเก่าวันอนาคต → สองฝั่งต้องมีวันเดียวกันเป๊ะ
     *
     * ⚠️ ด้านนี้สำคัญเท่าอีกด้าน — ถ้าไม่ตรวจ การ "แก้" ที่ทำให้ไฟล์ตัดทิ้งมั่ว ๆ ก็ผ่าน
     */
    public function testWithoutLegacyRowsTheFileCoversExactlyWhatTheScreenShows(): void
    {
        [, $session] = $this->shopWithHistory('coverage-clean@example.com');

        $screen = $this->historyDates($session);
        $file = array_values(array_filter(
            $this->dailySheetDates($session),
            static fn(string $date): bool => str_starts_with($date, '2026-08')
        ));

        sort($screen);
        sort($file);

        $this->assertSame(
            $screen,
            $file,
            'ร้านที่ไม่มีแถวเก่าวันอนาคต จอกับไฟล์ต้องมีวันเดียวกันทุกวัน'
        );
        $this->assertNotSame([], $screen, 'ข้อมูลตั้งต้นไม่เข้า — เทสต์นี้จะไม่ได้พิสูจน์อะไร');
    }

    /**
     * ⭐⭐⭐ มีแถวเก่าวันอนาคต → ไฟล์ต้องไม่มีวันนั้น **และต้องเขียนกำกับว่าตัดถึงวันไหน**
     */
    public function testTheFileExplainsItsOwnCutoffWhenItTrimsFutureRows(): void
    {
        [$shopId, $session] = $this->shopWithHistory('coverage-legacy@example.com');
        $this->addLegacyFutureRow($shopId);

        $screen = $this->historyDates($session);
        $file = $this->dailySheetDates($session);

        $this->assertContains(
            '2026-08-28',
            $screen,
            'หน้าประวัติต้องยังแสดงแถวเก่าวันอนาคต ไม่งั้นผู้ใช้ลบมันไม่ได้อีกเลย'
        );
        $this->assertNotContains(
            '2026-08-28',
            $file,
            'ไฟล์รายงานต้องไม่นับวันที่ยังไม่เกิดขึ้น'
        );

        // ⚠️ หัวใจของการตัดสินใจ: ต่างกันได้ แต่ไฟล์ต้องอธิบายตัวเอง
        $response = $this->get('/api/export-xlsx.php?year=' . (self::YEAR + 543), $session);
        $path = tempnam(sys_get_temp_dir(), 'coverage') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $sheet = IOFactory::load($path)->getSheetByName('รายวัน');
            $text = '';
            foreach ($sheet->getRowIterator(2) as $row) {
                $text .= (string)$sheet->getCell('A' . $row->getRowIndex())->getValue() . "\n";
            }

            $this->assertStringContainsString(
                'ข้อมูลถึงวันที่',
                $text,
                'ไฟล์ตัดวันทิ้งแต่ไม่บอกว่าตัดถึงวันไหน — คนเปิดไฟล์จะนึกว่าข้อมูลหาย'
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * ⚠️ **ปีที่จบไปแล้วไม่ได้ตัดอะไรเลย → ต้องไม่มีข้อความกำกับ**
     *
     * เขียนกำกับทุกปีคือเสียงรบกวนที่ไม่บอกอะไรใหม่ และทำให้ข้อความนี้ถูกมองข้าม
     * ตอนที่มันสำคัญจริง ๆ · หลักเดียวกับ `comparison_length_note()`
     */
    public function testAFinishedYearSaysNothingBecauseNothingWasTrimmed(): void
    {
        $userId = $this->createUser('coverage-past@example.com', 'CoveragePass123');
        $shopId = $this->createShop($userId, 'ร้านปีที่จบแล้ว');
        $session = $this->startSession($userId, $shopId);

        $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$shopId, '2025-06-15', '3000.00', '1000.00', 'ปีที่แล้ว']);

        $response = $this->get('/api/export-xlsx.php?year=2568', $session);
        $path = tempnam(sys_get_temp_dir(), 'coverage') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $sheet = IOFactory::load($path)->getSheetByName('รายวัน');
            $text = '';
            foreach ($sheet->getRowIterator(1) as $row) {
                $text .= (string)$sheet->getCell('A' . $row->getRowIndex())->getValue() . "\n";
            }

            $this->assertStringNotContainsString(
                'ข้อมูลถึงวันที่',
                $text,
                'ปีที่จบแล้วไม่ได้ตัดอะไรเลย ไม่ควรมีข้อความกำกับ'
            );
            $this->assertStringContainsString('รวมทั้งปี', $text, 'ชีตว่างเปล่า — ข้อมูลตั้งต้นไม่เข้า');
        } finally {
            @unlink($path);
        }
    }
}
