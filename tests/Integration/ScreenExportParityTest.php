<?php

declare(strict_types=1);

namespace Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ⭐⭐ เลขที่ผู้ใช้เห็นบนหน้าเว็บ ต้องเท่ากับเลขในไฟล์ที่ดาวน์โหลดไป
 *
 * ⚠️⚠️ **ตาข่ายเดิมเป็นแบบ "ไฟล์เทียบไฟล์" ทั้งหมด** — `XlsxAnnualParityTest` พิสูจน์ว่า
 * ชีตรายปี = Σ ชีตรายเดือน = Σ ชีตรายวัน และเทสต์ระดับ service พิสูจน์ว่าสูตรถูก
 * แต่**ไม่มีตัวไหนอ่าน HTML ที่ผู้ใช้เห็นจริงมาเทียบกับเซลล์ในไฟล์เลย**
 *
 * นี่คือคลาสบั๊กที่โปรเจกต์นี้เคยเจอมาแล้วและบันทึกไว้ในคู่มือ:
 * คอมมิตที่แก้กติกากริดฤดูกาลแก้ `annual.php` + `AnnualService` + เทสต์ครบ
 * **แต่ไม่ได้แตะ `XlsxReportService`** → หน้าจอระบายเทาว่า "ยังตัดสินไม่ได้"
 * ขณะที่ไฟล์ยังเขียวเหมือนอีกสองปี · คนเปิดไฟล์อ่านแล้วสรุปผิดโดยไม่มีอะไรเตือน
 *
 * ⚠️ ต้องเทียบด้วยยอดที่ **มีเศษสตางค์** ไม่ลงตัว — ถ้าใช้เลขกลม ๆ การปัดเศษที่ต่างกัน
 * ระหว่างสองฝั่งจะให้ผลเท่ากันโดยบังเอิญ แล้วเทสต์เขียวทั้งที่ไม่ได้พิสูจน์อะไร
 */
final class ScreenExportParityTest extends ControllerTestCase
{
    private const YEAR_CE = 2026;
    private const YEAR_BE = 2569;

    /**
     * ยอดที่ตั้งใจให้มีเศษสตางค์และไม่ลงตัวเมื่อบวกกัน
     *
     * @return list<array{0:string,1:float,2:float}>
     */
    private function fixtureRows(): array
    {
        return [
            ['2026-01-05', 12345.67, 2345.89],
            ['2026-01-19', 9876.54, 1234.56],
            ['2026-02-11', 23456.78, 8765.43],
            ['2026-03-03', 7654.32, 9876.54],
            ['2026-03-28', 34567.89, 1111.11],
        ];
    }

    private function seedShop(): array
    {
        $userId = $this->createUser('parity@example.com', 'ParityPass123');
        $shopId = $this->createShop($userId, 'ร้านเทียบจอกับไฟล์');

        $insert = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, :note, NOW(), NOW())'
        );

        foreach ($this->fixtureRows() as [$date, $revenue, $adCost]) {
            $insert->execute([
                'shop' => $shopId,
                'date' => $date,
                'revenue' => $revenue,
                'ad' => $adCost,
                'note' => '',
            ]);
        }

        return [$userId, $shopId];
    }

    /**
     * ตัวเลขกำไรรายเดือนที่ "อยู่บนจอ" — อ่านจาก HTML ที่เซิร์ฟเวอร์ส่งมาจริง
     *
     * @return array<string,float> ชื่อเดือนไทย → กำไร
     */
    private function monthlyProfitOnScreen(string $sessionId): array
    {
        $response = $this->get('/annual.php?year=' . self::YEAR_BE, $sessionId);
        $this->assertSame(200, $response['status'], 'เปิดหน้ารายปีไม่สำเร็จ');

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $response['body']);
        $xpath = new DOMXPath($doc);

        $found = [];
        foreach ($xpath->query('//table') as $table) {
            // DOMXPath คืน DOMNode — ต้องแคบชนิดก่อนถึงเรียกเมธอดของ DOMElement ได้
            if (!$table instanceof DOMElement) {
                continue;
            }

            $headings = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headings[] = trim((string)preg_replace('/\s+/u', ' ', $th->textContent));
            }

            if (!in_array('เดือน', $headings, true)) {
                continue;
            }

            $profitColumn = array_search('กำไร', $headings, true);
            $this->assertIsInt($profitColumn, 'ตารางรายเดือนบนหน้าจอไม่มีคอลัมน์ "กำไร"');

            foreach ($table->getElementsByTagName('tr') as $row) {
                $cells = [];
                foreach ($row->childNodes as $node) {
                    if ($node->nodeName === 'td') {
                        $cells[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
                    }
                }

                if (count($cells) <= $profitColumn) {
                    continue;
                }

                $label = $cells[0];
                $value = $cells[$profitColumn];
                // แถวรวมท้ายตารางไม่ใช่เดือน — เทียบแยกต่างหากไม่ได้อยู่ในชุดนี้
                if (mb_strpos($label, 'รวม') === 0) {
                    continue;
                }

                // เดือนที่ยังไม่มีข้อมูลเป็นขีด — ไม่ใช่ตัวเลขที่เอาไปเทียบได้
                if ($label === '' || !preg_match('/[0-9]/', $value)) {
                    continue;
                }

                $found[$label] = (float)str_replace(['฿', ','], '', $value);
            }

            break;
        }

        $this->assertNotSame([], $found, 'อ่านตัวเลขกำไรรายเดือนจากหน้าจอไม่ได้เลย');

        return $found;
    }

    /**
     * ตัวเลขกำไรรายเดือนที่ "อยู่ในไฟล์" — โหลด xlsx กลับมาอ่านเซลล์จริง
     *
     * @return array<string,float>
     */
    private function monthlyProfitInWorkbook(string $sessionId): array
    {
        $response = $this->get('/api/export-xlsx.php?year=' . self::YEAR_BE, $sessionId);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ');

        $path = tempnam(sys_get_temp_dir(), 'parity') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $book = IOFactory::load($path);

            $sheet = null;
            foreach ($book->getSheetNames() as $index => $name) {
                if (mb_strpos($name, 'เดือน') !== false) {
                    $sheet = $book->getSheet($index);
                    break;
                }
            }

            $this->assertNotNull($sheet, 'ไม่พบชีตรายเดือนในไฟล์');

            $found = [];
            foreach ($sheet->getRowIterator() as $row) {
                $values = [];
                foreach ($row->getCellIterator() as $cell) {
                    $values[] = $cell->getValue();
                }

                $label = isset($values[0]) ? trim((string)$values[0]) : '';
                // คอลัมน์ D = กำไร (เหมือนหน้าจอ) — ต้องเป็นตัวเลขจริง ไม่ใช่ข้อความ
                if ($label === '' || !isset($values[3]) || !is_numeric($values[3])) {
                    continue;
                }

                $found[$label] = round((float)$values[3], 2);
            }

            $this->assertNotSame([], $found, 'อ่านตัวเลขกำไรรายเดือนจากไฟล์ไม่ได้เลย');

            return $found;
        } finally {
            @unlink($path);
        }
    }

    /**
     * ⭐ กำไรรายเดือนบนหน้าจอ ต้องเท่ากับในไฟล์ทุกเดือน รวมสตางค์
     */
    public function testEveryMonthlyProfitOnScreenMatchesTheDownloadedFile(): void
    {
        [$userId, $shopId] = $this->seedShop();
        $session = $this->startSession($userId, $shopId);

        $screen = $this->monthlyProfitOnScreen($session);
        $file = $this->monthlyProfitInWorkbook($session);

        // ยืนยันก่อนว่าข้อมูลตั้งต้นมีเศษสตางค์จริง ไม่งั้นเทสต์ผ่านโดยไม่ได้พิสูจน์การปัดเศษ
        $hasSatang = false;
        foreach ($screen as $profit) {
            if (abs($profit - round($profit)) >= 0.005) {
                $hasSatang = true;
                break;
            }
        }
        $this->assertTrue($hasSatang, 'ข้อมูลตั้งต้นไม่มีเศษสตางค์ — เทสต์นี้จะผ่านโดยบังเอิญ');

        foreach ($screen as $month => $profit) {
            $key = null;
            foreach (array_keys($file) as $candidate) {
                if (mb_strpos($candidate, $month) === 0 || mb_strpos($month, $candidate) === 0) {
                    $key = $candidate;
                    break;
                }
            }

            $this->assertNotNull($key, "เดือน {$month} อยู่บนหน้าจอแต่ไม่มีในไฟล์");
            $this->assertEqualsWithDelta(
                $profit,
                $file[$key],
                0.005,
                "กำไรเดือน {$month} บนหน้าจอ ({$profit}) ไม่ตรงกับในไฟล์ ({$file[$key]})"
            );
        }
    }

    /**
     * แปลงวันที่แบบไทยบนหน้าจอ ("1 ส.ค. 2569") กลับเป็น ISO เพื่อจับคู่กับไฟล์
     *
     * ⚠️ เคยจับคู่หยาบ ๆ ด้วยเลขวันแล้วได้ผลลวงว่า 2 แถวไม่ตรงกัน ทั้งที่ตรง —
     * ต้องแปลงให้ครบทั้ง วัน/เดือน/ปี พ.ศ. ไม่ใช่เทียบบางส่วน
     */
    private function thaiDateToIso(string $label): ?string
    {
        static $months = [
            'ม.ค.' => 1, 'ก.พ.' => 2, 'มี.ค.' => 3, 'เม.ย.' => 4, 'พ.ค.' => 5, 'มิ.ย.' => 6,
            'ก.ค.' => 7, 'ส.ค.' => 8, 'ก.ย.' => 9, 'ต.ค.' => 10, 'พ.ย.' => 11, 'ธ.ค.' => 12,
        ];

        if (preg_match('/^(\d{1,2})\s+(\S+)\s+(\d{4})$/u', trim($label), $parts) !== 1) {
            return null;
        }

        $month = $months[$parts[2]] ?? null;
        if ($month === null) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int)$parts[3] - 543, $month, (int)$parts[1]);
    }

    /**
     * แถวรายวันที่ "อยู่บนจอ" ของหน้าประวัติ
     *
     * @return array<string,array<string,string>> ISO date → คอลัมน์ → ค่าที่แสดง
     */
    private function historyRowsOnScreen(string $sessionId, string $month): array
    {
        $response = $this->get('/history.php?month=' . $month, $sessionId);
        $this->assertSame(200, $response['status'], 'เปิดหน้าประวัติไม่สำเร็จ');

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $response['body']);
        $xpath = new DOMXPath($doc);

        $rows = [];
        foreach ($xpath->query('//table') as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $headings = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headings[] = trim((string)preg_replace('/\s+/u', ' ', $th->textContent));
            }

            if (!in_array('เทียบครั้งก่อน', $headings, true)) {
                continue;
            }

            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $node) {
                    if ($node->nodeName === 'td') {
                        $cells[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
                    }
                }

                if (count($cells) < count($headings)) {
                    continue;
                }

                $byName = [];
                foreach ($headings as $index => $name) {
                    $value = $cells[$index] ?? '';
                    /* ⚠️ บนจอแคบสคริปต์แปะชื่อคอลัมน์ไว้หน้าค่า (`<span class="cell-label">`)
                       ต้องตัดออกก่อน ไม่งั้นค่าที่อ่านได้จะเป็น "กำไร฿1,000" */
                    if ($name !== '' && mb_strpos($value, $name) === 0) {
                        $value = trim(mb_substr($value, mb_strlen($name)));
                    }

                    $byName[$name] = $value;
                }

                $iso = $this->thaiDateToIso($byName['วันที่'] ?? '');
                if ($iso !== null) {
                    $rows[$iso] = $byName;
                }
            }

            break;
        }

        $this->assertNotSame([], $rows, 'อ่านแถวรายวันจากหน้าประวัติไม่ได้เลย');

        return $rows;
    }

    /**
     * แถวรายวันที่ "อยู่ในไฟล์ CSV"
     *
     * @return array<string,array<string,string>>
     */
    private function historyRowsInCsv(string $sessionId, string $month): array
    {
        $response = $this->get('/api/export.php?month=' . $month, $sessionId);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลด CSV ไม่สำเร็จ');

        $body = (string)preg_replace('/^\xEF\xBB\xBF/', '', $response['body']);
        $lines = explode("\n", trim($body));
        $headings = str_getcsv(rtrim(array_shift($lines) ?? '', "\r"), ',', '"', '');

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv(rtrim($line, "\r"), ',', '"', '');
            if (count($cells) < count($headings)) {
                continue;
            }

            $byName = array_combine($headings, array_slice($cells, 0, count($headings)));
            $date = (string)($byName['วันที่'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $rows[$date] = array_map(static fn($cell): string => (string)$cell, $byName);
        }

        $this->assertNotSame([], $rows, 'อ่านแถวรายวันจากไฟล์ CSV ไม่ได้เลย');

        return $rows;
    }

    /**
     * ⭐⭐ ทุกแถวรายวันบนหน้าประวัติ ต้องตรงกับไฟล์ CSV ที่ปุ่ม Export ให้มา
     *
     * รอบก่อนล็อกไว้แค่ฝั่ง xlsx — ทาง CSV ซึ่งเป็นปุ่มที่อยู่บนหน้าประวัติเองยังไม่มีตาข่าย
     *
     * ⚠️ เทียบ "เทียบครั้งก่อน" ด้วยหลังตัดตัวคั่นหลักพันออก — จอเขียน `25,825.9%`
     * แต่ไฟล์เขียน `25825.9%` **โดยตั้งใจ** เพราะ Excel อ่านเซลล์ที่มีจุลภาคในตัวเลข
     * เป็นสูตรผิดไวยากรณ์ (บันทึกไว้ในคู่มือแล้ว) — ค่าต้องเท่ากัน รูปแบบต่างได้
     */
    public function testEveryHistoryRowOnScreenMatchesTheDownloadedCsv(): void
    {
        [$userId, $shopId] = $this->seedShop();
        $session = $this->startSession($userId, $shopId);

        $screen = $this->historyRowsOnScreen($session, '2026-01');
        $file = $this->historyRowsInCsv($session, '2026-01');

        $this->assertSame(
            array_keys($screen),
            array_keys($file),
            'วันที่ที่แสดงบนจอกับในไฟล์ไม่ตรงกัน'
        );

        $plain = static fn(string $value): string => trim(str_replace(['฿', ',', '↑', '↓', '+'], '', $value));

        foreach ($screen as $date => $row) {
            foreach (['รายได้', 'ค่าแอด', 'กำไร', 'ROAS'] as $column) {
                $onScreen = $plain($row[$column] ?? '');
                $inFile = $plain($file[$date][$column] ?? '');

                if ($onScreen === no_value_text() || $inFile === '') {
                    continue;
                }

                $this->assertEqualsWithDelta(
                    (float)$onScreen,
                    (float)$inFile,
                    0.005,
                    "{$column} ของวันที่ {$date}: จอแสดง {$onScreen} แต่ไฟล์เขียน {$inFile}"
                );
            }

            /* ⚠️⚠️ ทิศทางถูกเขียนคนละแบบสองฝั่ง — จอใช้ลูกศร (`↓ 20.0%`) ไฟล์ใช้
               เครื่องหมายลบ (`-20.0%`) เพราะ Excel ต้องอ่านเป็นจำนวนลบให้ได้
               **ห้ามตัดลูกศรทิ้งเฉย ๆ** ไม่งั้นเทสต์จะมองว่า "ลง 20%" กับ "ขึ้น 20%"
               เท่ากัน ซึ่งเป็นความต่างที่ร้ายแรงที่สุดที่คอลัมน์นี้จะผิดได้ */
            $signed = static function (string $value): ?float {
                $value = trim($value);
                if ($value === '' || $value === no_value_text()) {
                    return null;
                }

                $negative = str_contains($value, '↓') || str_starts_with($value, '-');
                $number = (float)str_replace(['฿', ',', '↑', '↓', '+', '-', '%'], '', $value);

                return $negative ? -$number : $number;
            };

            $changeOnScreen = $signed($row['เทียบครั้งก่อน'] ?? '');
            $changeInFile = $signed($file[$date]['เทียบครั้งก่อน'] ?? '');
            if ($changeOnScreen !== null && $changeInFile !== null) {
                $this->assertEqualsWithDelta(
                    $changeOnScreen,
                    $changeInFile,
                    0.05,
                    "เทียบครั้งก่อนของวันที่ {$date} ไม่ตรงกันระหว่างจอกับไฟล์ (รวมทิศทางขึ้น/ลง)"
                );
            }
        }
    }

    /**
     * ⭐ จำนวนเดือนต้องเท่ากันด้วย — ไฟล์ห้ามมีเดือนที่หน้าจอไม่แสดง (และกลับกัน)
     *
     * เดือนอนาคตถูกตัดที่ทั้งสองฝั่งด้วย `$today` ตัวเดียวกัน ถ้าฝั่งใดฝั่งหนึ่งลืมตัด
     * ผู้ใช้จะได้ไฟล์ที่มีแถวมากกว่าที่เห็นบนจอ โดยแถวส่วนเกินเป็นศูนย์ทั้งแถว
     */
    public function testTheFileHasExactlyTheSameMonthsAsTheScreen(): void
    {
        [$userId, $shopId] = $this->seedShop();
        $session = $this->startSession($userId, $shopId);

        $screenMonths = array_keys($this->monthlyProfitOnScreen($session));
        $fileMonths = array_keys($this->monthlyProfitInWorkbook($session));

        $this->assertSame(
            count($screenMonths),
            count($fileMonths),
            'จำนวนเดือนบนหน้าจอ (' . count($screenMonths) . ') ไม่เท่ากับในไฟล์ (' . count($fileMonths) . ')'
        );
    }
}
