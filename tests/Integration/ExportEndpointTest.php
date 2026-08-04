<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ไฟล์ที่ดาวน์โหลดออกไปจริง — ชั้นที่ CLAUDE.md ระบุว่า **ต้อง** เทสต์ที่ endpoint
 *
 * ตัวกัน "สูตรใน Excel" อยู่ใน `api/export.php` (closure `$sanitizeCsvCell`) ไม่ใช่ใน
 * `ExportService` → unit test ที่ระดับ service แตะไม่ถึง · เทสต์ฝั่ง service พิสูจน์แค่ว่า
 * payload ส่งโน้ตดิบ ๆ ออกมา แต่ไม่มีใครพิสูจน์ว่ามีอะไรมากรองมันต่อ
 *
 * ทำไมถึงสำคัญ: โน้ตคือช่องเดียวที่ผู้ใช้พิมพ์อะไรก็ได้ ถ้าพิมพ์ `=1+1` แล้วส่งไฟล์
 * ให้คนอื่นเปิด Excel จะ *รันสูตรนั้น* — รวมถึงสูตรที่ดึงข้อมูลออกไปข้างนอกได้
 */
final class ExportEndpointTest extends ControllerTestCase
{
    /** @return array<int,array<int,string>> แถวทั้งหมดของไฟล์ CSV ที่ดาวน์โหลดมา */
    private function downloadCsv(string $session, string $month = '2026-08'): array
    {
        $response = $this->get('/api/export.php?month=' . $month, $session);
        $this->assertSame(200, $response['status'], 'ดาวน์โหลด CSV ไม่สำเร็จ');

        $body = $response['body'];
        // ตัด BOM ที่ใส่ไว้ให้ Excel อ่านภาษาไทยออกก่อน parse
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;

        $rows = [];
        foreach (explode("\n", trim($body)) as $line) {
            $parsed = str_getcsv(rtrim($line, "\r"), ',', '"', '');
            if ($parsed !== [null]) {
                $rows[] = array_map(static fn($cell): string => (string)$cell, $parsed);
            }
        }

        return $rows;
    }

    /**
     * ⭐ โน้ตที่ขึ้นต้นด้วยอักขระที่ Excel ถือว่าเป็นสูตร ต้องถูกทำให้เป็นข้อความ
     *
     * @return array<string,array{0:string}>
     */
    public static function dangerousNoteProvider(): array
    {
        return [
            'เท่ากับ' => ['=1+1'],
            'บวก' => ['+1+1'],
            'ลบ' => ['-1+1'],
            'แอท' => ['@SUM(A1)'],
            'แท็บ' => ["\t=1+1"],
            'สูตรดึงข้อมูลออกนอกเครื่อง' => ['=HYPERLINK("http://evil.example.com")'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousNoteProvider')]
    public function testADangerousNoteIsNeutralisedInTheDownloadedFile(string $note): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, $note);
        $session = $this->startSession($userId, $shopId);

        $rows = $this->downloadCsv($session);
        $noteCell = '';
        foreach ($rows as $row) {
            if (($row[0] ?? '') === '2026-08-01') {
                $noteCell = (string)($row[6] ?? '');
            }
        }

        $this->assertNotSame('', $noteCell, 'ไม่พบแถวของวันที่ทดสอบในไฟล์');
        $this->assertStringStartsWith(
            "'",
            $noteCell,
            'โน้ต "' . $note . '" ออกไปดิบ ๆ — Excel จะรันเป็นสูตร'
        );
        $this->assertStringContainsString($note, $noteCell, 'ข้อความที่ผู้ใช้พิมพ์ถูกตัดทิ้ง');
    }

    /** โน้ตธรรมดาต้องไม่ถูกเติมอะไรนำหน้า (ไม่งั้นทุกโน้ตมี ' โผล่มาให้รำคาญ) */
    public function testAnOrdinaryNoteIsLeftAlone(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, 'ยอดดีเพราะยิงแอดใหม่');
        $session = $this->startSession($userId, $shopId);

        foreach ($this->downloadCsv($session) as $row) {
            if (($row[0] ?? '') === '2026-08-01') {
                $this->assertSame('ยอดดีเพราะยิงแอดใหม่', (string)($row[6] ?? ''));
            }
        }
    }

    /**
     * ⭐ เซลล์ที่ระบบสร้างเอง (วันที่/ตัวเลข/%) ต้องออกดิบ ไม่มี ' นำหน้า
     *
     * ตั้งใจให้ Excel อ่านเป็นตัวเลขและวันที่ ไม่ใช่ข้อความ — ไม่งั้น sort/sum ไม่ได้
     */
    public function testSystemGeneratedCellsAreNotQuoted(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 100.0, 200.0, null);      // กำไรติดลบ
        $this->createRecord($shopId, '2026-08-02', 1000000.0, 1.0, null);    // % โต ๆ
        $session = $this->startSession($userId, $shopId);

        $rows = $this->downloadCsv($session);
        $this->assertNotSame([], $rows);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $cell) {
                if ($columnIndex === 6) {
                    continue;   // คอลัมน์โน้ต — เป็นของผู้ใช้ มีกติกาของตัวเอง
                }

                $this->assertStringStartsNotWith("'", $cell, "แถว {$rowIndex} คอลัมน์ {$columnIndex} ถูกทำเป็นข้อความ");
                $this->assertDoesNotMatchRegularExpression(
                    '/^[=+@\t\r]/',
                    $cell,
                    "แถว {$rowIndex} คอลัมน์ {$columnIndex} กลายเป็นสูตรใน Excel: {$cell}"
                );
            }
        }
    }

    /** ⭐ ไฟล์ต้องมี BOM ไม่งั้น Excel บน Windows อ่านภาษาไทยเป็นตัวยึกยือ */
    public function testTheFileStartsWithAByteOrderMark(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, 'โน้ตภาษาไทย');
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/api/export.php?month=2026-08', $session);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $response['body']);
        $this->assertStringContainsString('text/csv', $response['headers']['content-type'] ?? '');
        $this->assertStringContainsString('attachment', $response['headers']['content-disposition'] ?? '');
    }

    /** ⭐ ดาวน์โหลดข้อมูลของร้านคนอื่นไม่ได้ */
    public function testAnotherUsersDataCannotBeDownloaded(): void
    {
        $userId = $this->createUser();
        $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');
        $this->createRecord($strangerShop, '2026-08-01', 999999.0, 1.0, 'ความลับ');

        $session = $this->startSession($userId, $strangerShop);
        $response = $this->getJson('/api/export.php?month=2026-08', $session);

        $this->assertStringNotContainsString('ความลับ', $response['body'], 'ข้อมูลของคนอื่นหลุดออกไปในไฟล์');
        $this->assertStringNotContainsString('999999', $response['body']);
    }

    /** ⭐ ไฟล์ Excel ดาวน์โหลดได้จริงและเป็น xlsx จริง (ไม่ใช่หน้า error) */
    public function testTheXlsxReportDownloads(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-08-01', 5000.0, 1200.0, null);
        $session = $this->startSession($userId, $shopId);

        $response = $this->get('/api/export-xlsx.php?year=2026', $session);

        $this->assertSame(200, $response['status']);
        // xlsx = ไฟล์ zip → ต้องขึ้นต้นด้วยลายเซ็น PK
        $this->assertStringStartsWith('PK', $response['body'], 'สิ่งที่ดาวน์โหลดมาไม่ใช่ไฟล์ xlsx');
        $this->assertStringContainsString(
            'spreadsheetml',
            $response['headers']['content-type'] ?? '',
            'ประกาศชนิดไฟล์ผิด Excel จะไม่ยอมเปิด'
        );
    }
}
