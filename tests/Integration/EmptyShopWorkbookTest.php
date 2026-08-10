<?php

declare(strict_types=1);

namespace Tests\Integration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ⭐⭐ ร้านที่ยังไม่เคยกรอกอะไรเลย — **ไฟล์ Excel ต้องเว้นว่าง ไม่ใช่เขียน 0**
 *
 * ⚠️⚠️ กติกานี้ถูกแก้ทีละจุดมาหลายรอบแล้วไปไม่ถึงที่เหลือทุกครั้ง:
 *   รอบแรกแก้หน้าจอ (`annual.php` ซ่อนการ์ด · `overview.php` แสดงขีดทั้งแถว)
 *   และแก้ **ชีตรายเดือน** ของ xlsx — แต่ **ชีตรายปี · แถวรวมชีตรายวัน · ชีตเทียบร้าน**
 *   ยังเขียน 0 อยู่ · วัดจริงจากไฟล์ที่ดาวน์โหลด:
 *     ชีตรายปี   `A5:0  C5:0  E5:0` คู่กับ `G5:–` ในแถวเดียวกัน
 *     ชีตรายวัน  `รวมทั้งปี | 0 | 0 | 0` ทั้งที่ไม่มีแถวข้อมูลสักแถว
 *     เทียบร้าน  `ร้าน C | 0 | 0 | 0`
 *
 * ⚠️ ชีตเทียบร้านคือตารางที่ใช้ตัดสินว่า "ร้านไหนคุ้ม" — คนอ่านเทียบ "ร้าน C กำไร 0"
 * กับ "ร้าน D ขาดทุน -5,000" แล้วสรุปว่า C ดีกว่า ทั้งที่ C แค่ยังไม่มีข้อมูล
 *
 * ⚠️⚠️ เทสต์นี้ต้องมี **ทางตรงข้าม** ด้วย — ปีที่กรอกครบแต่เท่าทุนพอดีต้องยังเขียน 0
 * เพราะนั่นคือความจริง ไม่ใช่ "ยังไม่รู้"
 */
final class EmptyShopWorkbookTest extends ControllerTestCase
{
    private const YEAR_BE = 2569;

    private function insert(int $shopId, string $date, float $revenue, float $adCost): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note, created_at, updated_at)
             VALUES (:shop, :date, :revenue, :ad, \'\', NOW(), NOW())'
        );
        $statement->execute(['shop' => $shopId, 'date' => $date, 'revenue' => $revenue, 'ad' => $adCost]);
    }

    /** ดาวน์โหลดไฟล์จริงผ่าน endpoint แล้วเปิดกลับมาอ่าน */
    private function workbookFor(string $session): array
    {
        $response = $this->get('/api/export-xlsx.php?year=' . self::YEAR_BE, $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลดไฟล์ Excel ไม่สำเร็จ');

        $path = tempnam(sys_get_temp_dir(), 'empty') . '.xlsx';
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

    private function findRow(Worksheet $sheet, string $label): ?int
    {
        foreach ($sheet->getRowIterator() as $row) {
            $value = $sheet->getCell('A' . $row->getRowIndex())->getValue();
            if (is_string($value) && mb_strpos(trim($value), $label) === 0) {
                return $row->getRowIndex();
            }
        }

        return null;
    }

    /**
     * ⭐ ทั้งสามชีตต้องเว้นว่างสำหรับร้านที่ไม่เคยกรอก
     */
    public function testEveryMoneyCellIsBlankForAShopThatNeverRecorded(): void
    {
        $userId = $this->createUser('emptybook@example.com', 'EmptyBookPass123');
        $usedShop = $this->createShop($userId, 'ร้านที่กรอกแล้ว');
        $emptyShop = $this->createShop($userId, 'ร้านที่ไม่เคยกรอก');

        $this->insert($usedShop, '2026-08-01', 5000.0, 3000.0);

        $sheets = $this->workbookFor($this->startSession($userId, $emptyShop));

        // ── ชีตรายปี: การ์ดกำไร/รายได้/ค่าแอด
        $annual = $sheets['รายปี'] ?? null;
        $this->assertNotNull($annual, 'ไม่พบชีตรายปี');
        foreach (['A5' => 'กำไรทั้งปี', 'C5' => 'รายได้', 'E5' => 'ค่าแอด'] as $cell => $label) {
            $value = $annual->getCell($cell)->getValue();
            $this->assertFalse(
                is_numeric($value),
                "การ์ด \"{$label}\" ในชีตรายปีเขียนตัวเลข ({$value}) ให้ร้านที่ยังไม่เคยกรอก"
            );
        }

        // ── ชีตรายวัน: แถวรวม
        $daily = $sheets['รายวัน'] ?? null;
        $this->assertNotNull($daily, 'ไม่พบชีตรายวัน');
        $totalsRow = $this->findRow($daily, 'รวมทั้งปี');
        $this->assertNotNull($totalsRow, 'ไม่พบแถวรวมในชีตรายวัน');
        foreach (['B', 'C', 'D'] as $column) {
            $this->assertNull(
                $daily->getCell($column . $totalsRow)->getValue(),
                "แถวรวมของชีตรายวันเขียนตัวเลขให้ร้านที่ไม่มีข้อมูลสักแถว (ช่อง {$column})"
            );
        }

        // ── ชีตเทียบร้าน: แถวของร้านที่ไม่เคยกรอก
        $portfolio = $sheets['เทียบร้าน'] ?? null;
        $this->assertNotNull($portfolio, 'ไม่พบชีตเทียบร้าน');
        $shopRow = $this->findRow($portfolio, 'ร้านที่ไม่เคยกรอก');
        $this->assertNotNull($shopRow, 'ไม่พบแถวของร้านที่ไม่เคยกรอกในชีตเทียบร้าน');
        foreach (['B', 'C', 'D'] as $column) {
            $this->assertNull(
                $portfolio->getCell($column . $shopRow)->getValue(),
                "ชีตเทียบร้านเขียนตัวเลขให้ร้านที่ไม่เคยกรอก (ช่อง {$column}) — "
                . 'ตารางนี้ใช้ตัดสินว่าร้านไหนคุ้ม'
            );
        }
    }

    /**
     * ⭐⭐ ทางตรงข้าม: กรอกครบแต่เท่าทุนพอดี → ต้องเขียน 0 เพราะนั่นคือความจริง
     *
     * ⚠️ ถ้าขาดเทสต์ตัวนี้ การ "แก้" ให้เว้นว่างทุกครั้งที่กำไรเป็นศูนย์จะผ่านหน้าตาเฉย
     * แล้วร้านที่ทำงานจริงจนเท่าทุนจะกลายเป็น "ไม่มีข้อมูล"
     */
    public function testAShopThatBrokeEvenStillWritesZero(): void
    {
        $userId = $this->createUser('breakeven@example.com', 'BreakEvenPass123');
        $shopId = $this->createShop($userId, 'ร้านที่เท่าทุนพอดี');

        $this->insert($shopId, '2026-08-01', 4000.0, 4000.0);

        $sheets = $this->workbookFor($this->startSession($userId, $shopId));

        $this->assertSame(
            0.0,
            $sheets['รายปี']->getCell('A5')->getValue(),
            'ร้านที่กรอกครบแต่เท่าทุนพอดี ต้องเขียนกำไร 0 ไม่ใช่เว้นว่าง'
        );

        $daily = $sheets['รายวัน'];
        $totalsRow = $this->findRow($daily, 'รวมทั้งปี');
        $this->assertNotNull($totalsRow);
        $this->assertSame(
            0.0,
            $daily->getCell('D' . $totalsRow)->getValue(),
            'แถวรวมของร้านที่เท่าทุนต้องเป็น 0 ไม่ใช่เว้นว่าง'
        );
    }
}
