<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ⭐⭐ หน้าจอกับไฟล์ต้องตรงกัน **ทุกคอลัมน์ ทุกเดือน — รวมช่องที่เว้นว่างด้วย**
 *
 * ⚠️⚠️ ตาข่ายเดิม (`ScreenExportParityTest`) เทียบเฉพาะ **คอลัมน์กำไร** และเทียบเฉพาะ
 * เดือนที่ "มีตัวเลขทั้งสองฝั่ง" — มันจึง**ข้ามเดือนที่ฝั่งหนึ่งเว้นว่างอีกฝั่งเขียน 0**
 * ซึ่งเป็นบั๊กชนิดที่โปรเจกต์นี้เจอซ้ำที่สุด (`empty ≠ zero`)
 * · และไม่เคยแตะ ยอดขาย · ค่าแอด · ROAS · จำนวนวัน · กำไรต่อวัน · เทียบปีก่อน เลยสักคอลัมน์
 *
 * เทสต์นี้เทียบ **ทุกช่องของทุกเดือน** และถือว่า "ช่องว่าง" เป็นค่าที่ต้องตรงกันด้วย
 * ไม่ใช่เหตุผลให้ข้าม
 *
 * ⚠️ ข้อมูลตั้งต้นตั้งใจให้มีทุกสภาพที่ทำให้สองฝั่งเถียงกันได้:
 *   · เดือนที่ไม่เคยกรอกเลย → ต้องเว้นว่างทั้งคู่
 *   · เดือนที่ค่าแอด ฿0 → ROAS ไม่มีค่า แต่กำไรมีค่า
 *   · เดือนที่ปีก่อนมีข้อมูล / ไม่มีข้อมูล → มี % เทียบปีก่อน กับไม่มี
 *   · ยอดมีเศษสตางค์ → การปัดที่ต่างกันจะโผล่ทันที
 */
final class AnnualMetricParityTest extends ControllerTestCase
{
    private const YEAR_BE = 2569;

    /**
     * คอลัมน์บนจอ → คอลัมน์ในไฟล์
     *
     * ⚠️ "อัตรากำไร" มีเฉพาะบนจอ · "วันที่กรอก" เทียบแยกต่างหาก (ดู
     * `testTheDaysColumnKeepsItsDeliberateDifference()` ซึ่งอธิบายว่าทำไมสองฝั่งต่างกัน)
     */
    private const COLUMN_MAP = [
        'รายได้' => 'B',
        'ค่าแอด' => 'C',
        'กำไร' => 'D',
        'ROAS' => 'E',
        'กำไร/วัน' => 'G',
    ];

    private function insert(int $shopId, string $date, float $revenue, float $adCost): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'date' => $date, 'revenue' => $revenue, 'ad' => $adCost]);
    }

    /** @return array{0:int,1:int} */
    private function seed(): array
    {
        $userId = $this->createUser('metricparity@example.com', 'MetricPass123');
        $shopId = $this->createShop($userId, 'ร้านเทียบทุกคอลัมน์');

        // ปีก่อน — มีเฉพาะ ม.ค. กับ มี.ค. เพื่อให้ % เทียบปีก่อน "มีบ้าง ไม่มีบ้าง"
        $this->insert($shopId, '2025-01-10', 20000.55, 5000.25);
        $this->insert($shopId, '2025-03-10', 15000.45, 4000.15);

        /* ปีนี้ — ⚠️ ต้องมีอย่างน้อย 2 เดือนที่กรอก "เกินครึ่งเดือน" ไม่งั้นแถบประมาณการ
           จะไม่ขึ้นเลยทั้งบนจอและในไฟล์ แล้วเทสต์ประมาณการจะไม่ได้ตรวจอะไร */
        for ($day = 1; $day <= 20; $day++) {
            $this->insert($shopId, sprintf('2026-01-%02d', $day), 12345.67, 2345.89);
            $this->insert($shopId, sprintf('2026-02-%02d', $day), 23456.78, 8765.43);
        }

        // เดือนที่ไม่ได้ยิงแอดเลย — ROAS ไม่มีค่า แต่กำไรมีค่า
        $this->insert($shopId, '2026-03-03', 7654.32, 0.0);
        $this->insert($shopId, '2026-04-28', 34567.02, 1111.11);
        // พ.ค. เป็นต้นไป ไม่กรอกเลย → ต้องเว้นว่างทั้งจอและไฟล์

        return [$userId, $shopId];
    }

    /**
     * ค่าที่ "ไม่มี" ต้องกลายเป็น null เหมือนกันทั้งสองฝั่ง
     *
     * ⚠️ ฝั่งจอเขียนขีด (`–` U+2013 จาก `no_value_text()`) ฝั่งไฟล์เว้นเซลล์ว่าง
     * — คนละหน้าตา แต่ความหมายเดียวกัน
     */
    private function normalize(string|int|float|null $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        if (is_int($raw) || is_float($raw)) {
            return round((float)$raw, 2);
        }

        $text = trim($raw);
        if ($text === '' || !preg_match('/[0-9]/', $text)) {
            return null;
        }

        // ป้ายเทียบปีก่อนเป็น "↓ 12.3%" — ลูกศรลงคือค่าติดลบ
        $negative = mb_strpos($text, '↓') !== false || mb_strpos($text, '-') !== false;
        $digits = (string)preg_replace('/[^0-9.]/u', '', $text);
        if ($digits === '' || $digits === '.') {
            return null;
        }

        return round((float)$digits * ($negative ? -1 : 1), 2);
    }

    /**
     * ตารางรายเดือนที่อยู่บนจอ — คืน [ชื่อเดือน => [ชื่อคอลัมน์ => ค่า|null]]
     *
     * @return array<string,array<string,float|null>>
     */
    private function monthlyTableOnScreen(string $session): array
    {
        $response = $this->get('/annual.php?year=' . self::YEAR_BE, $session);
        $this->assertSame(200, $response['status'], 'เปิดหน้ารายปีไม่สำเร็จ');

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $response['body']);
        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//table') as $table) {
            if (!$table instanceof DOMElement) {
                continue;
            }

            $headings = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headings[] = trim((string)preg_replace('/\s+/u', ' ', $th->textContent));
            }

            if (!in_array('เดือน', $headings, true) || !in_array('ROAS', $headings, true)) {
                continue;
            }

            $rows = [];
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $node) {
                    if ($node->nodeName === 'td') {
                        $cells[] = trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
                    }
                }

                if ($cells === [] || mb_strpos($cells[0], 'รวม') === 0) {
                    continue;
                }

                $row = [];
                foreach ($headings as $index => $heading) {
                    if ($index === 0 || !isset($cells[$index])) {
                        continue;
                    }

                    $row[$heading] = $this->normalize($cells[$index]);
                }

                $rows[$cells[0]] = $row;
            }

            $this->assertNotSame([], $rows, 'อ่านตารางรายเดือนจากหน้าจอไม่ได้เลย');

            return $rows;
        }

        $this->fail('ไม่พบตารางรายเดือนบนหน้ารายปี');
    }

    /** เปิดไฟล์ที่ดาวน์โหลดจริง แล้วคืนชีตตามชื่อ */
    private function workbook(string $session): array
    {
        $response = $this->get('/api/export-xlsx.php?year=' . self::YEAR_BE, $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ');

        $path = tempnam(sys_get_temp_dir(), 'metric') . '.xlsx';
        file_put_contents($path, $response['body']);

        try {
            $book = IOFactory::load($path);
            $sheets = [];
            foreach ($book->getSheetNames() as $index => $name) {
                $sheets[$name] = $book->getSheet($index);
            }

            return $sheets;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string,array<string,float|null>>
     */
    private function monthlyTableInFile(Worksheet $sheet): array
    {
        $rows = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            $index = $row->getRowIndex();
            $label = trim((string)$sheet->getCell('A' . $index)->getValue());
            if ($label === '' || mb_strpos($label, 'รวม') === 0) {
                continue;
            }

            $cells = [];
            foreach (self::COLUMN_MAP as $heading => $column) {
                $value = $sheet->getCell($column . $index)->getValue();
                $cells[$heading] = is_numeric($value) ? round((float)$value, 2) : null;
            }

            $yoy = $sheet->getCell('H' . $index)->getValue();
            $cells['เทียบปีก่อน'] = is_numeric($yoy) ? round((float)$yoy, 2) : null;

            $rows[$label] = $cells;
        }

        $this->assertNotSame([], $rows, 'อ่านตารางรายเดือนจากไฟล์ไม่ได้เลย');

        return $rows;
    }

    /**
     * ⭐⭐ ทุกช่องของทุกเดือนต้องตรงกัน — รวมช่องที่ควรเว้นว่าง
     */
    public function testEveryCellOfEveryMonthAgreesBetweenScreenAndFile(): void
    {
        [$userId, $shopId] = $this->seed();
        $session = $this->startSession($userId, $shopId);

        $screen = $this->monthlyTableOnScreen($session);
        $file = $this->monthlyTableInFile($this->workbook($session)['รายเดือน'] ?? $this->fail('ไม่พบชีตรายเดือน'));

        $this->assertSame(
            array_keys($screen),
            array_keys($file),
            'รายชื่อเดือนบนจอกับในไฟล์ไม่ตรงกัน'
        );

        /* ⚠️ ยืนยันว่าข้อมูลตั้งต้นสร้างสภาพที่ต้องพิสูจน์จริง ไม่งั้นเทสต์นี้อาจเขียว
           เพราะทุกเดือนหน้าตาเหมือนกันหมด */
        $blankMonths = 0;
        $monthsWithoutRoas = 0;
        foreach ($file as $cells) {
            if ($cells['กำไร'] === null) {
                $blankMonths++;
            } elseif ($cells['ROAS'] === null) {
                $monthsWithoutRoas++;
            }
        }
        $this->assertGreaterThan(0, $blankMonths, 'ข้อมูลตั้งต้นไม่มีเดือนที่เว้นว่างเลย');
        $this->assertGreaterThan(0, $monthsWithoutRoas, 'ข้อมูลตั้งต้นไม่มีเดือนที่ ROAS ไม่มีค่าเลย');

        foreach ($screen as $month => $cells) {
            foreach (self::COLUMN_MAP as $heading => $column) {
                $this->assertArrayHasKey($heading, $cells, "หน้าจอไม่มีคอลัมน์ \"{$heading}\"");

                $onScreen = $cells[$heading];
                $inFile = $file[$month][$heading] ?? null;

                $this->assertSame(
                    $inFile,
                    $onScreen,
                    sprintf(
                        'เดือน %s คอลัมน์ "%s" ไม่ตรงกัน — จอ: %s · ไฟล์ (ช่อง %s): %s',
                        $month,
                        $heading,
                        $onScreen === null ? 'เว้นว่าง' : (string)$onScreen,
                        $column,
                        $inFile === null ? 'เว้นว่าง' : (string)$inFile
                    )
                );
            }

            // ── คอลัมน์ "เทียบปีก่อน" หัวตารางบนจอมีเลขปีต่อท้าย จึงหาแบบขึ้นต้นด้วยคำว่า เทียบ
            $screenYoy = null;
            foreach ($cells as $heading => $value) {
                if (mb_strpos($heading, 'เทียบ') === 0) {
                    $screenYoy = $value;
                }
            }

            $this->assertSame(
                $file[$month]['เทียบปีก่อน'] ?? null,
                $screenYoy,
                "เดือน {$month}: % เทียบปีก่อน บนจอกับในไฟล์ไม่ตรงกัน"
            );
        }
    }

    /**
     * ⭐⭐ คอลัมน์ "วันที่กรอก" ต่างกันสองฝั่ง **โดยตั้งใจ** — เทสต์นี้ตรึงไว้ทั้งคู่
     *
     * เดือนที่ไม่เคยกรอก: **จอเขียนขีด · ไฟล์เขียน 0**
     *
     * ⚠️ ทำไมถึงไม่ถือว่าเป็นบั๊ก: ช่องนี้เป็น **หลักฐาน** ไม่ใช่ **ผลงาน**
     * · "กรอกไป 0 วัน" เป็นความจริงที่ตรวจสอบได้ ต่างจากช่องเงินที่ 0 อ่านว่า "ทำได้เท่านี้"
     * · ในไฟล์มันคือคำอธิบายว่าทำไมช่องอื่นทั้งแถวถึงว่าง (คนเปิด Excel ไม่มีสีเทา
     *   หรือข้อความ "ยังไม่มีข้อมูล" ให้ดูเหมือนบนจอ)
     * · บนจอทั้งแถวถูกยุบเป็นบรรทัดเดียว ("ม.ค. · ยังไม่มีข้อมูล") ขีดจึงอ่านรู้เรื่องอยู่แล้ว
     *
     * ⚠️⚠️ **สิ่งที่เทสต์นี้กัน** คือการที่วันหนึ่งมีคนเปลี่ยนข้างใดข้างหนึ่งโดยไม่รู้ว่า
     * อีกข้างจงใจต่าง — จะแดงทันที และมาอ่านเหตุผลตรงนี้ได้
     */
    public function testTheDaysColumnKeepsItsDeliberateDifference(): void
    {
        [$userId, $shopId] = $this->seed();
        $session = $this->startSession($userId, $shopId);

        $screen = $this->monthlyTableOnScreen($session);
        $sheet = $this->workbook($session)['รายเดือน'] ?? $this->fail('ไม่พบชีตรายเดือน');

        $emptyMonths = [];
        foreach ($sheet->getRowIterator(2) as $row) {
            $index = $row->getRowIndex();
            $label = trim((string)$sheet->getCell('A' . $index)->getValue());
            if ($label === '' || mb_strpos($label, 'รวม') === 0) {
                continue;
            }

            if ($sheet->getCell('D' . $index)->getValue() !== null) {
                continue;
            }

            $emptyMonths[] = $label;

            $this->assertSame(
                0,
                $sheet->getCell('F' . $index)->getValue(),
                "ไฟล์: เดือน {$label} ที่ไม่มีข้อมูล ต้องเขียน \"วันที่กรอก\" เป็น 0 (หลักฐานว่าทำไมช่องอื่นว่าง)"
            );
        }

        $this->assertNotSame([], $emptyMonths, 'ข้อมูลตั้งต้นไม่มีเดือนที่ว่างเลย');

        foreach ($emptyMonths as $label) {
            /* ⚠️⚠️ ห้ามเขียน `$x['key'] ?? 'ไม่มีคีย์'` แล้ว assertNull — `??` ถือว่า null
               คือ "ไม่มีค่า" จึงคืนตัวสำรองแทน null ที่กำลังจะตรวจพอดี (เขียนพลาดมาแล้ว) */
            $this->assertArrayHasKey($label, $screen, "หน้าจอไม่มีแถวเดือน {$label}");
            $this->assertArrayHasKey('วันที่กรอก', $screen[$label], 'หน้าจอไม่มีคอลัมน์ "วันที่กรอก"');
            $this->assertNull(
                $screen[$label]['วันที่กรอก'],
                "หน้าจอ: เดือน {$label} ที่ไม่มีข้อมูล ต้องเป็นขีด ไม่ใช่ตัวเลข"
            );
        }
    }

    /**
     * ⭐⭐ การ์ดสรุปทั้งปี และแถบประมาณการ ต้องพูดเลขเดียวกันทั้งสองฝั่ง
     *
     * ⚠️ ประมาณการเป็นข้อความในไฟล์ (`ต่ำ – สูง (กลาง …)`) ส่วนบนจอกระจายอยู่หลายจุด
     * — เทียบด้วย "เลขที่เขียนออกมา" ซึ่งเป็นสิ่งที่ผู้ใช้อ่านจริง
     */
    public function testTheYearCardsAndProjectionSayTheSameNumbers(): void
    {
        [$userId, $shopId] = $this->seed();
        $session = $this->startSession($userId, $shopId);

        $annual = $this->workbook($session)['รายปี'] ?? $this->fail('ไม่พบชีตรายปี');

        $pageText = (string)preg_replace('/\s+/u', ' ', strip_tags((string)preg_replace(
            '#<script.*?</script>#s',
            ' ',
            $this->get('/annual.php?year=' . self::YEAR_BE, $session)['body']
        )));

        // ── การ์ด 3 ใบที่เป็นจำนวนเงิน
        foreach (['A5' => 'กำไรทั้งปี', 'C5' => 'ยอดขายทั้งปี', 'E5' => 'ค่าแอดทั้งปี'] as $cell => $label) {
            $value = $annual->getCell($cell)->getValue();
            $this->assertIsNumeric($value, "การ์ด \"{$label}\" ในไฟล์ไม่ใช่ตัวเลข");

            $this->assertStringContainsString(
                formatMoney((float)$value),
                $pageText,
                "การ์ด \"{$label}\": ไฟล์เขียน " . formatMoney((float)$value) . ' แต่หาเลขนี้บนหน้าจอไม่เจอ'
            );
        }

        // ── แถบประมาณการ: เลขทั้งสามตัวในไฟล์ต้องอยู่บนจอด้วย
        $projectionText = '';
        foreach ($annual->getRowIterator() as $row) {
            $value = (string)$annual->getCell('A' . $row->getRowIndex())->getValue();
            if (mb_strpos($value, 'กลาง') !== false) {
                $projectionText = $value;
            }
        }

        $this->assertNotSame('', $projectionText, 'ไฟล์ไม่มีแถบประมาณการ — ข้อมูลตั้งต้นน่าจะไม่พอ');

        preg_match_all('/฿[0-9,\.]+/u', $projectionText, $matches);
        $this->assertCount(3, $matches[0], 'แถบประมาณการในไฟล์ควรมีสามตัวเลข (ต่ำ · สูง · กลาง)');

        foreach ($matches[0] as $money) {
            $this->assertStringContainsString(
                $money,
                $pageText,
                "ประมาณการในไฟล์เขียน {$money} แต่หน้าจอไม่มีเลขนี้"
            );
        }
    }
}
