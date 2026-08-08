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

    /**
     * ⭐⭐ ชื่อร้านต้องแทรก header ของคำตอบไม่ได้
     *
     * ⚠️ ชื่อร้านเป็นข้อความที่ผู้ใช้พิมพ์เอง และถูกเอาไปต่อเข้ากับ header
     * `Content-Disposition` ตรง ๆ · ถ้าใส่ `\r\n` ลงไปได้ ผู้ใช้จะแทรก header
     * อะไรก็ได้เข้าไปในคำตอบ (เช่น `Set-Cookie`) = HTTP response splitting
     *
     * ⚠️⚠️ **มีตัวกัน 2 ชั้น — วัดแล้วว่าเหลือชั้นเดียวก็ยังกันอยู่**
     *   ชั้น 1 `ExportService::sanitizeFilenameBase()` — ยุบช่องว่างทุกชนิดเหลือเว้นวรรคเดียว
     *   ชั้น 2 `api/export.php` — จำกัดส่วน ASCII เหลือ `[A-Za-z0-9._-]` + `rawurlencode` ส่วน UTF-8
     *
     * power-proof แล้ว: ถอดชั้นใดชั้นหนึ่ง → เทสต์ยัง **เขียว** (อีกชั้นรับไว้)
     * ถอดทั้งสองชั้นพร้อมกัน → **แดง** · ใครจะแก้ตรงนี้ต้องรู้ว่าเหลือชั้นเดียวได้
     * แต่ถอดทั้งคู่ไม่ได้ — และทั้งสองชั้นเพิ่งมีเทสต์คุมเป็นครั้งแรกที่นี่
     */
    public function testAShopNameCannotInjectAResponseHeader(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, "ร้าน\r\nSet-Cookie: stolen=1");
        $this->createRecord($shopId, '2026-08-01', 1000.0, 100.0, null);
        $session = $this->startSession($userId, $shopId);

        $response = $this->getJson('/api/export.php?month=2026-08', $session);

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey(
            'set-cookie',
            $response['headers'],
            'ชื่อร้านแทรก header Set-Cookie เข้ามาในคำตอบได้'
        );

        $disposition = (string)($response['headers']['content-disposition'] ?? '');
        $this->assertNotSame('', $disposition, 'ไม่ได้ส่ง Content-Disposition');
        $this->assertStringNotContainsString("\r", $disposition, 'ชื่อไฟล์ยังมีตัวขึ้นบรรทัดใหม่ติดอยู่');
        $this->assertStringNotContainsString("\n", $disposition, 'ชื่อไฟล์ยังมีตัวขึ้นบรรทัดใหม่ติดอยู่');
        $this->assertStringNotContainsString(
            'stolen=1',
            $response['body'],
            'ชื่อร้านที่พยายามแทรก header หลุดไปอยู่ในเนื้อไฟล์แบบดิบ ๆ'
        );
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

    /**
     * ⭐⭐ ไฟล์ที่ดาวน์โหลดต้องเป็นเดือนเดียวกับที่หน้าจอกำลังแสดงอยู่
     *
     * ⚠️ `history.php` ตั้งใจเปิด "เดือนอนาคตที่มีข้อมูลจริง" ได้ (แถวเก่าที่ลงล่วงหน้าไว้
     * ก่อนกติกา actuals — ไม่งั้นแก้/ลบแถวพวกนั้นไม่ได้เลย) แต่ `api/export.php`
     * เรียก `resolve_calendar_month()` ซึ่งหดเดือนอนาคตกลับเป็นเดือนปัจจุบัน **เงียบ ๆ**
     *
     * วัดจริงก่อนแก้: ดูหน้าประวัติเดือนหน้า (2 รายการ ฿110,000) กด Export CSV
     * แล้วได้ไฟล์ของเดือนปัจจุบัน (3 แถว ฿3,000) ชื่อไฟล์ก็เป็นเดือนปัจจุบัน
     * โดยไม่มีข้อความบอกอะไรเลย
     */
    public function testExportingAFutureMonthWithDataReturnsThatMonthNotThisOne(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');
        $session = $this->startSession($userId, $shopId);

        $thisMonth = date('Y-m');
        $futureMonth = (new \DateTimeImmutable('first day of next month'))->format('Y-m');

        $this->createRecord($shopId, $thisMonth . '-01', 1000.0, 400.0, 'เดือนนี้');
        // แถวเก่าที่เคยลงล่วงหน้าไว้ (สร้างใหม่ไม่ได้แล้ว แต่ของเก่ายังอยู่ในฐานได้)
        $this->createRecord($shopId, $futureMonth . '-05', 55000.0, 15000.0, 'ลงล่วงหน้าไว้');

        $response = $this->get('/api/export.php?month=' . $futureMonth, $session);
        $body = (string)$response['body'];

        $this->assertStringContainsString(
            'ลงล่วงหน้าไว้',
            $body,
            'ได้ไฟล์ของเดือนอื่นแทนเดือนที่กำลังดูอยู่ โดยไม่มีข้อความบอก'
        );
        $this->assertStringNotContainsString('เดือนนี้', $body, 'ไฟล์มีข้อมูลของเดือนที่ไม่ได้ขอ');
    }

    /** เดือนอนาคตที่ไม่มีข้อมูลต้องยังถูกหดกลับเหมือนเดิม (ไม่ใช่ไฟล์เปล่า) */
    public function testExportingAnEmptyFutureMonthStillFallsBackToThisMonth(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');
        $session = $this->startSession($userId, $shopId);

        $thisMonth = date('Y-m');
        $futureMonth = (new \DateTimeImmutable('first day of next month'))->format('Y-m');
        $this->createRecord($shopId, $thisMonth . '-01', 1000.0, 400.0, 'เดือนนี้');

        $body = (string)$this->get('/api/export.php?month=' . $futureMonth, $session)['body'];

        $this->assertStringContainsString('เดือนนี้', $body, 'ขอเดือนอนาคตที่ว่างเปล่าแล้วได้ไฟล์เปล่า');
    }
}
