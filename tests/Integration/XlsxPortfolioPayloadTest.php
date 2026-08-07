<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use OverviewAnnualService;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RecordRepository;
use ShopRepository;
use XlsxReportService;

/**
 * integration test ของ payload ที่ป้อน sheet "เทียบร้าน" — DB จริง
 * (ตัวตัดสิน can_view / share / days / best-worst / yoy อยู่ที่ OverviewAnnualService)
 */
final class XlsxPortfolioPayloadTest extends IntegrationTestCase
{
    private const TODAY = '2026-08-15';

    /**
     * หาแถวของบล็อกสรุปจาก "ป้ายกำกับ" ไม่ใช่เลขแถวตายตัว
     *
     * ⚠️ เดิมล็อก B7/B8/… ไว้ พอเพิ่มแถวรวมท้ายตาราง (ซึ่งหน้าเว็บมีอยู่แล้ว)
     * บล็อกสรุปเลื่อนลง 1 แถว เทสต์แดงทั้งที่พฤติกรรมที่ผู้ใช้เห็นถูกต้อง
     * — ล็อกเลขแถวคือล็อกการจัดวาง ไม่ใช่ล็อกสิ่งที่ต้องเป็นจริง
     */
    private function summaryCell(Worksheet $sheet, string $labelPrefix): mixed
    {
        foreach ($sheet->getRowIterator() as $row) {
            $index = $row->getRowIndex();
            $label = trim((string)$sheet->getCell('A' . $index)->getValue());
            if ($label !== '' && str_starts_with($label, $labelPrefix)) {
                return $sheet->getCell('B' . $index)->getValue();
            }
        }

        return null;
    }

    /** แถวรวมท้ายตาราง — มีในไฟล์เหมือนที่หน้าเว็บมี */
    private function totalsRowIndex(Worksheet $sheet): ?int
    {
        foreach ($sheet->getRowIterator() as $row) {
            if (trim((string)$sheet->getCell('A' . $row->getRowIndex())->getValue()) === 'รวมทุกร้าน') {
                return $row->getRowIndex();
            }
        }

        return null;
    }

    private function makeService(): OverviewAnnualService
    {
        return new OverviewAnnualService(
            new RecordRepository($this->pdo),
            new ShopRepository($this->pdo)
        );
    }

    public function testPortfolioPayloadFeedsTheSheetCorrectly(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        // A: 2 วัน กำไร 6000 · B: 1 วัน ขาดทุน 1000 → รวม 5000
        $this->createRecord($shopA, '2026-01-05', 5000.0, 1000.0);
        $this->createRecord($shopA, '2026-01-06', 4000.0, 2000.0);
        $this->createRecord($shopB, '2026-07-10', 1000.0, 2000.0);

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];

        $this->assertTrue($data['can_view']);

        $sheet = $this->renderSheet($data);

        $this->assertSame('เทียบทุกร้าน ปี 2569', $sheet->getCell('A1')->getValue());
        // เรียงกำไร — A ก่อน B
        $this->assertSame('ร้าน A', $sheet->getCell('A4')->getValue());
        $this->assertSame(6000.0, $sheet->getCell('D4')->getValue());
        $this->assertSame(120.0, $sheet->getCell('G4')->getValue());   // 6000 / 5000
        $this->assertSame(2, $sheet->getCell('H4')->getValue());

        $this->assertSame('ร้าน B', $sheet->getCell('A5')->getValue());
        $this->assertSame(-1000.0, $sheet->getCell('D5')->getValue());
        $this->assertSame(-20.0, $sheet->getCell('G5')->getValue());
        $this->assertSame(1, $sheet->getCell('H5')->getValue());

        // สัดส่วนรวม = 100%
        $this->assertSame(100.0, $sheet->getCell('G4')->getValue() + $sheet->getCell('G5')->getValue());

        $this->assertSame(5000.0, $this->summaryCell($sheet, 'กำไรรวมทุกร้าน'));
        $this->assertSame('ม.ค. (฿6,000)', $this->summaryCell($sheet, 'เดือนกำไรดีสุด'));
        $this->assertSame('ก.ค. (฿-1,000)', $this->summaryCell($sheet, 'เดือนกำไรแย่สุด'));
        $this->assertSame('ไม่มีข้อมูลปีก่อน', $this->summaryCell($sheet, 'เทียบ 2568'));
    }

    public function testYearOverYearReachesTheSheet(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-01-05', 5000.0, 1000.0);   // ปีนี้ +4000
        $this->createRecord($shopB, '2025-01-05', 3000.0, 1000.0);   // ปีก่อน +2000 (ในช่วงเทียบ)
        // ปีก่อน ต.ค. — นอกช่วง same-period ต้องไม่ถูกนับ
        $this->createRecord($shopB, '2025-10-05', 90000.0, 0.0);

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];
        $sheet = $this->renderSheet($data);

        // 2 ร้าน → ตารางจบแถว 5 · สรุปเริ่มแถว 7 → YoY อยู่แถว 10
        $this->assertSame('↑100.0% (+฿2,000) · ปีก่อน ฿2,000', $this->summaryCell($sheet, 'เทียบ 2568'));
    }

    public function testFutureMonthsAreExcludedFromThePortfolio(): void
    {
        $userId = $this->createUser();
        $shopA = $this->createShop($userId, 'ร้าน A');
        $shopB = $this->createShop($userId, 'ร้าน B');

        $this->createRecord($shopA, '2026-08-10', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-11-10', 90000.0, 0.0);   // เดือนหน้า

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];
        $sheet = $this->renderSheet($data);

        $this->assertSame(2000.0, $sheet->getCell('D4')->getValue());
        $this->assertSame(0.0, $sheet->getCell('D5')->getValue());
        $this->assertSame(2000.0, $this->summaryCell($sheet, 'กำไรรวมทุกร้าน'));
    }

    public function testSingleShopUserCannotViewPortfolio(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านเดียว');
        $this->createRecord($shopId, '2026-01-05', 5000.0, 1000.0);

        $data = $this->makeService()->buildYearlyOverview($userId, 2026, self::TODAY)['data'];

        // controller ใช้ธงนี้ตัดสินว่าจะเพิ่มแท็บไหม
        $this->assertFalse($data['can_view']);
        $this->assertArrayNotHasKey('shops', $data);
    }

    public function testAnotherUsersShopsAreNotInThePortfolio(): void
    {
        $ownerId = $this->createUser('owner@example.com');
        $shopA = $this->createShop($ownerId, 'ร้าน A');
        $shopB = $this->createShop($ownerId, 'ร้าน B');
        $this->createRecord($shopA, '2026-01-05', 3000.0, 1000.0);
        $this->createRecord($shopB, '2026-01-05', 2000.0, 1000.0);

        $otherId = $this->createUser('other@example.com');
        $otherShop = $this->createShop($otherId, 'ร้านคนอื่น A');
        $this->createShop($otherId, 'ร้านคนอื่น B');
        $this->createRecord($otherShop, '2026-01-05', 99999.0, 0.0);

        $data = $this->makeService()->buildYearlyOverview($ownerId, 2026, self::TODAY)['data'];
        $sheet = $this->renderSheet($data);

        $this->assertCount(2, $data['shops']);
        $this->assertSame('ร้าน A', $sheet->getCell('A4')->getValue());
        $this->assertSame('ร้าน B', $sheet->getCell('A5')->getValue());
        // แถว 6 = แถวรวม (ไม่ใช่ร้านที่สาม) — ยืนยันว่ามีแค่ 2 ร้านจริง
        $this->assertSame(6, $this->totalsRowIndex($sheet), 'มีแถวร้านเกินมา');
        $this->assertSame(3000.0, $this->summaryCell($sheet, 'กำไรรวมทุกร้าน'));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function renderSheet(array $data): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $service = new XlsxReportService();
        $spreadsheet = $service->buildDailySheet([
            'rows' => [],
            'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
            'note_column_index' => 6,
        ]);
        $service->buildShopComparisonSheet($spreadsheet, $data);

        $sheet = $spreadsheet->getSheetByName('เทียบร้าน');
        $this->assertNotNull($sheet);

        return $sheet;
    }
}
