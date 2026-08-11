<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

use RecordService;

/**
 * โค้ดในเบราว์เซอร์ต้องตัดสินเหมือนฝั่งเซิร์ฟเวอร์เป๊ะ ๆ — ชั้นที่เดิมไม่มีตาข่ายเลย
 *
 * `add-record.php` มี JS ที่อ่าน "จำนวนเงิน" และ "วันที่" จากข้อมูลที่วางจาก Excel
 * ด้วยกติกาที่ **คัดลอกมาจาก PHP** (`normalize_money_string()`, `isAmbiguousSlashDate()`)
 * ซึ่งเป็นรูปแบบที่พลาดซ้ำที่สุดในโปรเจกต์นี้: แก้ฝั่งหนึ่ง ลืมอีกฝั่ง
 *
 * ผลของการไม่ตรงกันคือสิ่งที่ผู้ใช้เจอโดยตรง:
 *  · JS เข้มกว่า → ค่าที่ระบบรับได้ถูกทิ้งตั้งแต่ยังไม่ส่ง ผู้ใช้ไม่รู้ว่าหายไปไหน
 *  · JS หลวมกว่า → ช่องถูกเติมค่าที่เดาเอง แล้วเซิร์ฟเวอร์ปฏิเสธทั้งไฟล์ทีหลัง
 *
 * วิธีทำงาน: ดึงตัวฟังก์ชัน JS ออกมาจาก `add-record.php` ตรง ๆ แล้วรันด้วย node
 * (ไม่มี node → skip) จึงไม่ต้องคัดลอกโค้ดมาไว้ในเทสต์ให้เพี้ยนได้อีกที่
 */
final class BrowserScriptParityTest extends ControllerTestCase
{
    private const PAGE = __DIR__ . '/../../add-record.php';

    private static ?string $nodeBinary = null;
    private static bool $nodeResolved = false;

    private function node(): string
    {
        if (!self::$nodeResolved) {
            self::$nodeResolved = true;
            $found = trim((string)@shell_exec('command -v node 2>/dev/null'));
            self::$nodeBinary = $found !== '' ? $found : null;
        }

        if (self::$nodeBinary === null) {
            $this->markTestSkipped('ไม่มี node ในเครื่องนี้ — ข้ามการเทียบ JS กับ PHP');
        }

        return self::$nodeBinary;
    }

    /**
     * ดึงบล็อก `const <ชื่อ> = ... ;` ออกมาจากหน้าเว็บจริง
     *
     * ⚠️ ถ้าเปลี่ยนวิธีประกาศฟังก์ชันในหน้า (เช่นไปเป็น `function foo()`) เทสต์นี้จะแดง
     * ซึ่งถูกแล้ว — ต้องมาอัปเดตตัวดึงให้ตรง ไม่ใช่ปล่อยให้เทียบของเก่าไปเรื่อย ๆ
     */
    private function extractJs(string $name): string
    {
        $source = (string)file_get_contents(self::PAGE);
        $start = strpos($source, 'const ' . $name . ' = ');
        $this->assertNotFalse($start, "หา const {$name} ใน add-record.php ไม่เจอ");

        // อ่านไปจนเจอ `;` ตัวแรกที่อยู่นอกวงเล็บ/ปีกกาทั้งหมด — รองรับทั้งฟังก์ชัน
        // บรรทัดเดียว (`const pad2 = (v) => ...;`) และแบบหลายบรรทัด
        // ⚠️ เดิมใช้การหาสตริงปิดแบบตายตัว ซึ่งกลืนฟังก์ชันถัดไป หรือตัดกลางฟังก์ชัน
        $rest = substr($source, $start);
        $depth = 0;
        $length = strlen($rest);

        for ($index = 0; $index < $length; $index++) {
            $character = $rest[$index];

            if ($character === '(' || $character === '{') {
                $depth++;
            } elseif ($character === ')' || $character === '}') {
                $depth--;
            } elseif ($character === ';' && $depth === 0) {
                return substr($rest, 0, $index + 1);
            }
        }

        $this->fail("หาจุดจบของ const {$name} ไม่เจอ");
    }

    /**
     * @param list<string> $inputs
     * @return list<string>
     */
    private function runJs(string $declarations, string $call, array $inputs): array
    {
        $script = $declarations . "\n"
            . 'const inputs = ' . json_encode($inputs, JSON_UNESCAPED_UNICODE) . ";\n"
            . 'console.log(JSON.stringify(inputs.map((raw) => { const out = ' . $call . '; '
            . 'return out === null || out === undefined ? "<<null>>" : String(out); })));';

        $file = tempnam(sys_get_temp_dir(), 'adprofit-js-') . '.js';
        file_put_contents($file, $script);

        $output = (string)shell_exec(escapeshellarg($this->node()) . ' ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded, 'รัน JS ไม่สำเร็จ: ' . $output);

        /** @var list<string> $decoded */
        return $decoded;
    }

    /**
     * ค่าที่ PHP รับได้ทั้งหมด (ชุดเดียวกับ MoneyInputParsingTest) + ค่าที่ PHP ปฏิเสธ
     *
     * @return list<string>
     */
    private static function moneySamples(): array
    {
        return [
            '1234', '1234.56', '1234,56', '1234,5', '1,234.56', '1.234,56',
            '1,234,567.89', '1.234.567,89', '1 234,56', "1\u{202F}234,56", '฿1,234.56', '0', '0.00', '0.56',
            // ⚠️ U+FEFF — เบราว์เซอร์ตัดให้ (`\s` ของ JS ครอบ) แต่ `\s` ของ PHP ไม่ครอบ
            // ค่าเดียวกันจึง "วางแล้วอ่านออก แต่นำเข้าเป็นไฟล์แล้วอ่านไม่ออก"
            "1,234.56\u{FEFF}", "\u{FEFF}1,234.56",
            '1.234', '1,234', '12.3456', '1..2', '1.2.3', '.5.5', '1e3', '--5', 'abc', '12abc',
        ];
    }

    /**
     * ⭐ ทุกค่า: JS ต้องตัดสิน "รับได้/ไม่ได้" เหมือน PHP และได้ตัวเลขเดียวกัน
     *
     * กติกาของ JS: รับได้ → คืนตัวเลขที่ normalize แล้ว · รับไม่ได้ → คืน "ค่าดิบ"
     * กลับไปให้เซิร์ฟเวอร์เป็นคนปฏิเสธ (จะได้เห็นข้อความอธิบายเดียวกันทุกทาง)
     */
    public function testMoneyParsingMatchesTheServer(): void
    {
        $samples = self::moneySamples();
        $fromJs = $this->runJs($this->extractJs('cleanAmountCell'), 'cleanAmountCell(raw)', $samples);

        foreach ($samples as $index => $typed) {
            $fromPhp = normalize_money_string($typed);
            $jsValue = $fromJs[$index];

            if ($fromPhp === null) {
                $this->assertSame(
                    trim($typed),
                    $jsValue,
                    "PHP ปฏิเสธ \"{$typed}\" แต่ JS แปลงเป็น \"{$jsValue}\" แล้วส่งไป"
                );
                continue;
            }

            $this->assertSame(
                (float)$fromPhp,
                (float)$jsValue,
                "JS อ่าน \"{$typed}\" เป็น {$jsValue} แต่ PHP อ่านเป็น {$fromPhp}"
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function dateSamples(): array
    {
        return [
            '2026-08-01', '2026-8-1', '01/08/2026', '31/12/2026', '13/08/2026',
            '05/03/2026',   // กำกวม — ทั้งสองเลข ≤ 12
            '12/12/2026',   // กำกวม
            '2569-08-01',   // พ.ศ.
            '31/12/2569',
            '2026-13-01', '2026-08-32', '', 'ยอดขาย', '1234',
            // ⚠️ ติดช่องว่างที่มองไม่เห็นจากการก๊อปวาง — สองฝั่งต้องตอบเหมือนกัน
            // (`.trim()` ของ JS กับ `trim()` ของ PHP ตัดคนละชุด จึงเคยเงียบ ๆ ไม่ตรงกัน)
            "2026-08-01\u{00A0}", "\u{00A0}2026-08-01", "2026-08-01\u{3000}",
            "2026-08-01\u{200B}", "\u{FEFF}2026-08-01", "01/08/2026\u{00A0}",
        ];
    }

    /**
     * เรียกตัวอ่านวันที่ของ PHP ตัวจริง — ไม่ใช่สำเนากติกาที่เขียนไว้ในเทสต์
     *
     * ⚠️ ต้องใช้ `parseImportDate()` ไม่ใช่ `isAmbiguousSlashDate()` — ตัวหลังตอบแค่
     * "กำกวมไหม" ซึ่งเป็นจริงกับ 3 จาก 14 ตัวอย่างเท่านั้น ที่เหลือจึงไม่ถูกเทียบเลย
     * (เทสต์เคยผ่านแม้ทำให้ JS คืน null ทุกกรณี)
     */
    private function phpParseDate(string $value): ?string
    {
        $service = new RecordService(
            $this->createStub(\RecordRepository::class),
            $this->createStub(\ShopRepository::class),
            null
        );

        // ⚠️ ต้องรวม 2 กติกาเหมือนที่ `parseImportCsv()` ทำจริง — ตัวปฏิเสธวันกำกวม
        // อยู่คนละเมธอดกับตัวแปลงวันที่ (RecordService.php:279 แล้วค่อย :287)
        // ใช้ตัวใดตัวหนึ่งเดี่ยว ๆ จะได้คำตอบคนละอย่างกับที่ระบบทำจริง
        $ambiguous = new \ReflectionMethod(RecordService::class, 'isAmbiguousSlashDate');
        if ((bool)$ambiguous->invoke($service, $value)) {
            return null;
        }

        $method = new \ReflectionMethod(RecordService::class, 'parseImportDate');
        $parsed = $method->invoke($service, $value);

        return is_string($parsed) ? $parsed : null;
    }

    /**
     * ⭐ ทุกตัวอย่างในรูปแบบที่ทั้งสองฝั่งรองรับ ต้องได้ผลเหมือนกัน **เป๊ะ ๆ**
     *
     * รูปแบบที่ใช้ร่วมกันคือ `YYYY-M-D` และ `D/M/YYYY` (รวมปี พ.ศ.) — JS ใช้ตอนวาง
     * จาก Excel, PHP ใช้ตอนนำเข้าไฟล์ · ถ้าสองฝั่งไม่ตรงกัน ผู้ใช้จะเจอว่าวางแล้วได้
     * อย่างหนึ่ง แต่นำเข้าไฟล์เดียวกันได้อีกอย่าง
     *
     * ⚠️ วันที่รูปแบบไทย ("2 ส.ค. 2569") PHP รับได้ฝ่ายเดียวโดยตั้งใจ — ไม่นับรวม
     */
    public function testDateParsingMatchesTheServer(): void
    {
        $samples = self::dateSamples();
        $fromJs = $this->runJs(
            $this->extractJs('pad2') . "\n" . $this->extractJs('parseDateCell'),
            'parseDateCell(raw)',
            $samples
        );

        $sharedShape = '#^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/\d{4})$#';
        $compared = 0;

        foreach ($samples as $index => $typed) {
            $jsValue = $fromJs[$index] === '<<null>>' ? null : $fromJs[$index];
            $phpValue = $this->phpParseDate($typed);

            // ⚠️ ต้องตัดช่องว่างยูนิโค้ดก่อนดูรูปแบบ — "2026-08-01<NBSP>" **คือ**
            // วันที่ในรูปแบบร่วม เพียงแต่มีตัวอักษรที่มองไม่เห็นห่อไว้ · ถ้าใช้ `trim()`
            // ธรรมดา ตัวอย่างพวกนี้จะหลุดไปกิ่ง "ทั้งคู่ต้องปฏิเสธ" ทั้งที่สิ่งที่ต้องพิสูจน์
            // คือ "ทั้งคู่ต้องอ่านออกและได้ค่าเท่ากัน"
            if (preg_match($sharedShape, trim_unicode_whitespace($typed)) !== 1) {
                // นอกรูปแบบร่วม: ทั้งคู่ต้องปฏิเสธ (ยกเว้นรูปแบบไทยที่ PHP รับฝ่ายเดียว)
                $this->assertNull($jsValue, "JS ยอมรับค่าที่ไม่ใช่วันที่: \"{$typed}\" → {$fromJs[$index]}");
                continue;
            }

            $compared++;
            $this->assertSame(
                $phpValue,
                $jsValue,
                "\"{$typed}\": PHP อ่านเป็น " . var_export($phpValue, true)
                    . ' แต่ JS อ่านเป็น ' . var_export($jsValue, true)
            );
        }

        $this->assertGreaterThanOrEqual(6, $compared, 'ตัวอย่างในรูปแบบร่วมน้อยเกินกว่าจะพิสูจน์อะไรได้');
    }

    /**
     * ⭐ ฟังก์ชันทุกตัวที่ JS เรียก ต้องมีนิยามอยู่จริงในไฟล์
     *
     * ⚠️ เคยเกิดจริง: การรวมกติกาอ่านตัวเลขรอบก่อนลบ `const isNumericCell = …` ทิ้ง
     * แต่ทิ้งจุดที่เรียกมันไว้ · ผลคือ **วางยอดจาก Excel ลงคอลัมน์รายได้แล้วไม่มีอะไร
     * เกิดขึ้นเลย** (ReferenceError กลางคัน) ซึ่งเป็นท่าที่ผู้ใช้ทำบ่อยที่สุด
     * และไม่มีเทสต์ตัวไหนจับได้เพราะ JS ไม่มี test runner
     */
    /**
     * ⭐ JS ที่เบราว์เซอร์ได้รับจริง ต้อง parse ผ่าน — syntax error ทำให้ทั้งหน้า "ตาย" เงียบ ๆ
     *
     * ปุ่มกดไม่ติด ตารางไม่ทำงาน ไม่มีข้อความอะไรบอกผู้ใช้เลย · `php -l` ตรวจ PHP
     * แต่ไม่แตะ JS
     *
     * ⚠️ **ต้องดึงหน้าจากเซิร์ฟเวอร์จริง ไม่ใช่อ่านไฟล์ดิบ** — โค้ดที่ PHP แทรกเข้ามา
     * (เช่น `json_encode(...)`) เป็นส่วนหนึ่งของ JS ที่เบราว์เซอร์ได้รับ · เวอร์ชันแรก
     * อ่านไฟล์ดิบแล้วแทนที่ `<?= … ?>` ด้วย `null` จึงไม่มีวันจับได้เลยว่า PHP ปล่อย
     * JS ที่ผิดไวยากรณ์ออกมา ทั้งที่ docblock อ้างว่าครอบเคสนั้นอยู่ (พิสูจน์แล้ว)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renderedPageProvider')]
    public function testTheRenderedScriptOfEveryPageParses(string $path, bool $needsLogin = true): void
    {
        $session = null;
        if ($needsLogin) {
            $userId = $this->createUser();
            $shopId = $this->createShop($userId, 'ร้านทดสอบ');
            $this->createRecord($shopId, date('Y-m-01'), 5000.0, 1200.0, 'โน้ต');
            $this->createShop($userId, 'ร้านที่สอง');
            $session = $this->startSession($userId, $shopId);
        }

        $response = $this->get($path, $session);
        $this->assertSame(200, $response['status'], $path . ': เปิดไม่ขึ้น');

        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $response['body'], $blocks);
        $this->assertNotSame([], $blocks[1], $path . ': หาบล็อก <script> ไม่เจอ');

        $file = tempnam(sys_get_temp_dir(), 'adprofit-syntax-') . '.js';
        file_put_contents($file, implode("\n", $blocks[1]));
        $output = (string)shell_exec(escapeshellarg($this->node()) . ' --check ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);

        $this->assertSame('', trim($output), $path . ': JS parse ไม่ผ่าน — ' . $output);
    }

    /**
     * หน้าที่เปิดผ่านเซิร์ฟเวอร์ได้ (ไฟล์ร่วมอย่าง header/footer ถูกรวมมาในหน้าพวกนี้อยู่แล้ว)
     *
     * @return array<string,array{0:string,1?:bool}>
     */
    public static function renderedPageProvider(): array
    {
        return [
            'บันทึกข้อมูล' => ['/add-record.php'],
            'แดชบอร์ด' => ['/dashboard.php'],
            'ประวัติรายการ' => ['/history.php'],
            'สรุปประจำปี' => ['/annual.php'],
            'รวมทุกร้าน' => ['/overview.php'],
            'จัดการร้าน' => ['/shops.php'],
            'โปรไฟล์' => ['/profile.php'],
            'เข้าสู่ระบบ' => ['/login.php', false],
        ];
    }

    /**
     * ลบคอมเมนต์และสตริงออกจากโค้ด JS โดยเดินทีละตัวอักษร
     *
     * วิธีนี้รู้เสมอว่า "ตอนนี้อยู่ในสตริงหรือในคอมเมนต์" จึงไม่มีทางที่อันหนึ่ง
     * จะกลืนอีกอันได้ · แทนที่ด้วยช่องว่างเพื่อรักษาตำแหน่งบรรทัดไว้
     */
    private static function stripCommentsAndStrings(string $code): string
    {
        $out = '';
        $length = strlen($code);
        $state = 'code';   // code | line-comment | block-comment | string
        $quote = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];
            $next = $i + 1 < $length ? $code[$i + 1] : '';

            if ($state === 'code') {
                if ($char === '/' && $next === '/') {
                    $state = 'line-comment';
                    $out .= '  ';
                    $i++;
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $state = 'block-comment';
                    $out .= '  ';
                    $i++;
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $state = 'string';
                    $quote = $char;
                    $out .= ' ';
                    continue;
                }

                $out .= $char;
                continue;
            }

            if ($state === 'line-comment') {
                if ($char === "\n") {
                    $state = 'code';
                    $out .= "\n";
                    continue;
                }

                $out .= ' ';
                continue;
            }

            if ($state === 'block-comment') {
                if ($char === '*' && $next === '/') {
                    $state = 'code';
                    $out .= '  ';
                    $i++;
                    continue;
                }

                $out .= $char === "\n" ? "\n" : ' ';
                continue;
            }

            // state === 'string'
            if ($char === '\\') {
                $out .= '  ';
                $i++;
                continue;
            }

            if ($char === $quote) {
                $state = 'code';
                $quote = '';
            }

            $out .= $char === "\n" ? "\n" : ' ';
        }

        return $out;
    }

    /**
     * ⭐ ไฟล์ทุกไฟล์ที่มี `<script>` ฝังอยู่ ต้องอยู่ในรายชื่อที่ถูกตรวจ
     *
     * ⚠️ เดิมรายชื่อเป็นค่าตายตัว — เพิ่มสคริปต์ลงหน้าที่ไม่อยู่ในรายชื่อ (เช่น
     * `profile.php`) แล้วมันพังยังไงก็ไม่มีใครรู้ · ทำแบบเดียวกับที่ฝั่ง `api/` ทำ
     * คือให้ "การลืม" เป็นสิ่งที่ทำให้ชุดเทสต์แดง
     */
    public function testEveryFileWithInlineScriptIsChecked(): void
    {
        $root = dirname(__DIR__, 2);
        $known = array_map(
            static fn(array $row): string => ltrim((string)$row[0], '/'),
            array_values(self::scriptedPageProvider())
        );

        $withScript = [];
        foreach (array_merge((array)glob($root . '/*.php'), (array)glob($root . '/includes/*.php')) as $file) {
            $source = (string)file_get_contents((string)$file);
            if (preg_match('#<script\b(?![^>]*\bsrc=)[^>]*>#', $source) === 1) {
                $withScript[] = str_replace($root . '/', '', (string)$file);
            }
        }

        $this->assertNotSame([], $withScript, 'หาไฟล์ที่มีสคริปต์ไม่เจอเลย — ตัวตรวจน่าจะเสีย');
        $this->assertSame(
            [],
            array_values(array_diff($withScript, $known)),
            'มีไฟล์ที่ฝัง <script> แต่ไม่ได้อยู่ในรายชื่อที่ตรวจ — เพิ่มใน scriptedPageProvider()'
        );

        // ⚠️ ต้องอยู่ใน **ทั้งสอง** รายชื่อ — `scriptedPageProvider` คุมเรื่อง "เรียกฟังก์ชัน
        // ที่ไม่มีนิยาม" ส่วน `renderedPageProvider` คุมเรื่อง "parse ผ่านไหม"
        // เพิ่มหน้าเข้าแค่รายชื่อเดียวแล้วอีกด้านยังบอดอยู่ (พิสูจน์แล้ว)
        $rendered = array_map(
            static fn(array $row): string => ltrim((string)$row[0], '/'),
            array_values(self::renderedPageProvider())
        );

        $pagesOnly = array_values(array_filter(
            $withScript,
            static fn(string $file): bool => !str_starts_with($file, 'includes/')
        ));

        $this->assertSame(
            [],
            array_values(array_diff($pagesOnly, $rendered)),
            'มีหน้าที่ฝัง <script> แต่ไม่ได้อยู่ในรายชื่อตรวจ parse — เพิ่มใน renderedPageProvider()'
        );
    }

    /**
     * ทุกหน้าที่มี `<script>` ฝังอยู่ — ไม่ใช่แค่หน้าบันทึก
     *
     * @return array<string,array{0:string}>
     */
    public static function scriptedPageProvider(): array
    {
        return [
            'บันทึกข้อมูล' => ['add-record.php'],
            'แดชบอร์ด' => ['dashboard.php'],
            'ประวัติรายการ' => ['history.php'],
            'สรุปประจำปี' => ['annual.php'],
            'รวมทุกร้าน' => ['overview.php'],
            'เข้าสู่ระบบ' => ['login.php'],
            'ส่วนหัวร่วม' => ['includes/header.php'],
            'ส่วนท้ายร่วม' => ['includes/footer.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scriptedPageProvider')]
    public function testEveryFunctionTheScriptCallsIsDefined(string $relativePath): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

        // เอาเฉพาะเนื้อใน <script> (ไม่ใช่ทั้งไฟล์ — ไม่งั้นไปเจอโค้ด PHP กับ CSS)
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = implode("\n", $blocks[1]);
        $this->assertNotSame('', trim($source), 'หาบล็อก <script> ในหน้าไม่เจอ');

        // ตัดส่วนที่ PHP แทรกเข้ามาออก — นั่นเป็นโค้ด PHP ไม่ใช่ JS
        // (เขียนแท็กปิดแบบต่อสตริง ไม่งั้นมันจะไปปิดแท็ก PHP ของไฟล์เทสต์เอง)
        $source = (string)preg_replace('/<\?[=php].*?\?' . '>/s', '0', $source);

        // ⚠️ ต้องเดินทีละตัวอักษร ไม่ใช่ใช้ regex ตัดคอมเมนต์กับสตริงทีละชั้น
        //
        // ตัดสตริงก่อน → คอมเมนต์ที่มี ' อยู่ข้างในจับคู่กับคำพูดตัวถัดไปในโค้ดจริง
        // ตัดคอมเมนต์ก่อน → สตริงที่มี // อยู่ข้างในถูกตัดกลางคัน เหลือคำพูดค้าง
        // แล้วไปจับคู่กับตัวถัดไปแทน · **ทั้งสองลำดับกลืนโค้ดหายไปเงียบ ๆ** (พิสูจน์ทั้งคู่แล้ว)
        $source = self::stripCommentsAndStrings($source);

        // เก็บชื่อที่ประกาศไว้ (const/let/var/function) ในไฟล์ทั้งหมด
        // รวมชื่อที่ประกาศในไฟล์ร่วมด้วย — หน้าอื่นเรียกข้ามไฟล์ได้ (header/footer โหลดคู่กันเสมอ)
        $shared = '';
        foreach (['includes/header.php', 'includes/footer.php'] as $sharedFile) {
            $sharedSource = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $sharedFile);
            preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $sharedSource, $sharedBlocks);
            $shared .= "\n" . implode("\n", $sharedBlocks[1]);
        }

        // ⚠️⚠️ **ต้องดูทีละก้อน `<script>` ตามลำดับ ไม่ใช่เอาทุกก้อนมาต่อกัน**
        //
        // เวอร์ชันแรกต่อทุกก้อนเป็นสตริงเดียวแล้วเก็บชื่อที่ประกาศจากทั้งก้อน ผลคือชื่อที่
        // ประกาศใน **ก้อนหลัง** ถูกนับว่า "มีนิยามแล้ว" สำหรับจุดเรียกใน **ก้อนก่อนหน้า**
        // ซึ่งเบราว์เซอร์ไม่คิดแบบนั้น — จุดเรียกจะได้ ReferenceError ทันที
        //
        // เกิดขึ้นจริง: ย้าย `todayIso` ไปประกาศใน IIFE ของก้อนล่าง คำเตือน "วันอนาคต"
        // ของตารางกรอกหลายวัน (อยู่ก้อนบน) พังทั้งหมด แล้วขึ้นข้อความผิดว่า
        // "โหลดข้อมูลเดิมไม่สำเร็จ" แทน เพราะ error ถูกกลืนด้วย catch · เทสต์ยังเขียว
        //
        // กติกาของ JS: ตัวแปรระดับบนสุดของก้อนก่อนหน้า มองเห็นได้จากก้อนถัดไป
        // แต่ **ก้อนถัดไปมองย้อนกลับไม่ได้**
        //
        // ⚠️ ยังไม่ครอบกรณี "ก้อนเดียวกันแต่คนละ IIFE" — ต้องมีตัวแยก scope จริงถึงจะทำได้
        preg_match_all('/\bfunction\s+([A-Za-z_$][\w$]*)\s*\(/', $shared, $sharedFunctions);
        preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/', $shared, $sharedDeclared);
        $known = array_flip(array_merge($sharedDeclared[1], $sharedFunctions[1]));

        $called = [1 => []];
        $visibleWhenCalled = [];
        foreach ($blocks[1] as $rawBlock) {
            $block = (string)preg_replace('/<\?[=php].*?\?' . '>/s', '0', $rawBlock);
            $block = self::stripCommentsAndStrings($block);

            // จุดเรียกในก้อนนี้ ต้องหานิยามได้จากก้อนนี้หรือก้อนก่อนหน้าเท่านั้น
            preg_match_all('/(?<![\w$.])([a-z_$][\w$]*)\s*\(/', $block, $blockCalls);
            $called[1] = array_merge($called[1], $blockCalls[1]);

            // ชื่อที่ก้อนนี้ประกาศ — เพิ่มเข้าคลังหลังจากเก็บจุดเรียกของก้อนนี้แล้ว
            preg_match_all('/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/', $block, $blockDeclared);
            preg_match_all('/\bfunction\s+([A-Za-z_$][\w$]*)\s*\(/', $block, $blockFunctions);

            /* ⚠️ พารามิเตอร์ของฟังก์ชันก็ถูกเรียกแบบฟังก์ชันได้ (callback ที่รับเข้ามา)
               เดิมนับแต่ตัวที่ประกาศด้วย const/let/var/function จึงรายงานว่า `onClose`
               ของ `setupAccessibleModal(modal, onClose)` เป็นฟังก์ชันที่ไม่มีนิยาม
               (ตัวกวาดฝั่ง "ตัวแปร" รู้จักพารามิเตอร์อยู่แล้ว — ฝั่ง "ฟังก์ชัน" ตกสำรวจ) */
            preg_match_all('/\(([^)(]*)\)\s*=>/', $block, $arrowParams);
            preg_match_all('/\bfunction\s*[A-Za-z_$\w]*\s*\(([^)(]*)\)/', $block, $fnParams);
            $paramNames = [];
            foreach (array_merge($arrowParams[1], $fnParams[1]) as $list) {
                foreach (explode(',', $list) as $piece) {
                    $piece = trim(explode('=', $piece)[0]);
                    if (preg_match('/^[A-Za-z_$][\w$]*$/', $piece) === 1) {
                        $paramNames[] = $piece;
                    }
                }
            }

            foreach (array_merge($blockDeclared[1], $blockFunctions[1], $paramNames) as $name) {
                $known[$name] = true;
            }

            // ⚠️ เก็บ "สิ่งที่ก้อนนี้มองเห็นได้" ไว้ ณ ตอนนั้น — ก้อนหลังประกาศเพิ่ม
            // ไม่ทำให้ก้อนนี้มองเห็นย้อนหลัง
            // ⚠️ ถ้ามีจุดเรียก **ที่ไหนก็ได้** ที่มองไม่เห็นนิยาม ต้องถือว่าพัง
            // เขียนเป็น `= isset(...)` เฉย ๆ ไม่ได้ — จุดเรียกในก้อนหลัง (ซึ่งมองเห็น)
            // จะไปลบผลของก้อนหน้า (ซึ่งมองไม่เห็น) ทิ้ง แล้วเทสต์กลับมาเขียวอีก
            foreach (array_unique($blockCalls[1]) as $name) {
                $visibleWhenCalled[$name] = ($visibleWhenCalled[$name] ?? true) && isset($known[$name]);
            }
        }

        // ของที่เบราว์เซอร์/ภาษามีให้อยู่แล้ว หรือเป็นคีย์เวิร์ด
        $builtIns = array_flip([
            'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof', 'new',
            'parseInt', 'parseFloat', 'isNaN', 'encodeURIComponent', 'decodeURIComponent',
            'alert', 'confirm', 'fetch', 'require', 'setTimeout', 'setInterval', 'clearTimeout',
            'padStart', 'trim', 'do', 'else', 'in', 'of', 'await', 'async', 'try', 'throw',
            'querySelector', 'querySelectorAll', 'addEventListener', 'getElementById',
            'requestAnimationFrame', 'cancelAnimationFrame', 'structuredClone', 'queueMicrotask',
            // อ่านค่าสไตล์ที่เรนเดอร์จริง — ใช้ตอนหาว่าตัวควบคุมในหน้าต่างซ้อนตัวไหน "มองเห็นอยู่"
            'getComputedStyle',
        ]);

        $missing = [];
        foreach (array_unique($called[1]) as $name) {
            if (($visibleWhenCalled[$name] ?? false) || isset($builtIns[$name])) {
                continue;
            }

            // เรียกแบบ `xxx(` ที่ไม่ใช่ทั้งของเราและของภาษา = น่าสงสัย
            $missing[] = $name;
        }

        $this->assertSame(
            [],
            $missing,
            $relativePath . ' เรียกฟังก์ชัน JS ที่ไม่มีนิยาม: ' . implode(', ', $missing)
        );
    }

    /**
     * ⭐⭐⭐ ใช้ "ตัวแปร" ที่ไม่มีนิยาม — ตัวกวาดเดิมจับได้แค่ "ฟังก์ชัน" ที่ไม่มีนิยาม
     *
     * ⚠️⚠️ เกิดขึ้นจริงและอยู่บนเซิร์ฟเวอร์มาแล้ว: ตัวยิง event หลังวางจาก Excel เขียนว่า
     * `rows.forEach(...)` แต่ `rows` ถูกประกาศไว้ **ข้างใน** callback ของ `grid.forEach`
     * จึงมองไม่เห็นจากตรงนั้น → **ReferenceError ทุกครั้งที่วาง** แล้วโค้ดหลังจากนั้นตายหมด
     *
     * ผลที่ผู้ใช้เจอ (วัดจริงบนเบราว์เซอร์):
     *   · วางทับวันที่เคยมีโน้ต → ช่องโน้ตว่างเปล่า (ทางเลือกวันที่เองเติมให้ปกติ)
     *     กดบันทึกแล้วโน้ตเดิมหายไปพร้อมข้อความว่าสำเร็จ
     *   · คำเตือนทั้ง 3 แบบไม่ขึ้นเลย: วางเกิน 31 แถว · วางกว้างเกินตาราง · วันที่อ่านไม่ออก
     *
     * ⚠️ `php -l` ไม่เห็น · ตัวกวาดฟังก์ชันไม่เห็น (เพราะ `rows` ไม่ได้ตามด้วยวงเล็บ)
     * · ไม่มี test runner ของ JS · จับได้ตอนเปิดเบราว์เซอร์จริงเท่านั้น
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scriptedPageProvider')]
    public function testEveryVariableTheScriptReadsIsDefined(string $relativePath): void
    {
        $blocks = self::scriptBlocksOf($relativePath);
        $this->assertNotSame([], $blocks, 'หาบล็อก <script> ในหน้าไม่เจอ');

        $sharedNames = self::declaredNamesIn(self::sharedScriptSource());
        $seenEarlier = $sharedNames;
        $problems = [];

        // ก้อนก่อนหน้ามองเห็นได้ · ก้อนหลังมองย้อนไม่ได้ (กติกาเดียวกับตัวกวาดฟังก์ชัน)
        foreach ($blocks as $rawBlock) {
            $block = (string)preg_replace('/<\?[=php].*?\?' . '>/s', '0', $rawBlock);
            $block = self::stripCommentsAndStrings($block);

            $scopes = self::scopePathByOffset($block);
            $declarations = self::declarationsWithScope($block, $scopes);

            preg_match_all(
                '/(?<![\w$.\d])([a-z_$][\w$]*)\s*\./',
                $block,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            foreach ($matches[1] as [$name, $offset]) {
                if (isset(self::BROWSER_GLOBALS[$name]) || isset($seenEarlier[$name])) {
                    continue;
                }

                $readScope = $scopes[$offset] ?? '|';
                $visible = false;
                foreach ($declarations[$name] ?? [] as $declaredScope) {
                    // ⚠️⚠️ ต้องเป็น "ขอบเขตแม่หรือขอบเขตเดียวกัน" เท่านั้น
                    //
                    // ดูแค่ความลึกไม่พอ: `const rows = …` มีอยู่ 2 ที่คนละฟังก์ชัน
                    // ที่หนึ่งลึกกว่าจุดที่ใช้ อีกที่ลึกเท่ากันแต่เป็นฟังก์ชันข้าง ๆ
                    // ตัวกวาดที่วัดความลึกจึงตอบว่า "เห็น" ทั้งที่เบราว์เซอร์พังจริง
                    if (str_starts_with($readScope, $declaredScope)) {
                        $visible = true;
                        break;
                    }
                }

                if (!$visible) {
                    $problems[$name] = $name;
                }
            }

            foreach (array_keys($declarations) as $name) {
                $seenEarlier[$name] = true;
            }
        }

        $problems = array_values($problems);
        sort($problems);

        $this->assertSame(
            [],
            $problems,
            $relativePath . ' ใช้ตัวแปร JS ที่มองไม่เห็นจากตรงนั้น: ' . implode(', ', $problems)
        );
    }

    /**
     * "เส้นทางขอบเขต" ของทุกตำแหน่งในโค้ด เช่น `|12|340|` = อยู่ในปีกกาที่เปิดที่ 12 แล้ว 340
     *
     * ใช้เทียบว่าตัวแปรที่ประกาศไว้ **มองเห็นได้จากตรงนั้นไหม** — ประกาศต้องอยู่ในขอบเขตแม่
     * หรือขอบเขตเดียวกันเท่านั้น (เส้นทางของตัวประกาศต้องเป็นคำนำหน้าของเส้นทางจุดที่ใช้)
     *
     * @return array<int,string>
     */
    private static function scopePathByOffset(string $code): array
    {
        $paths = [];
        $stack = [];
        $current = '|';
        $length = strlen($code);

        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];

            if ($char === '}') {
                array_pop($stack);
                $current = '|' . implode('|', $stack) . ($stack === [] ? '' : '|');
            }

            $paths[$i] = $current;

            if ($char === '{') {
                $stack[] = $i;
                $current = '|' . implode('|', $stack) . '|';
            }
        }

        return $paths;
    }

    /**
     * ชื่อที่ประกาศ พร้อมเส้นทางขอบเขตของแต่ละการประกาศ
     *
     * @param array<int,string> $scopes
     * @return array<string,array<int,string>>
     */
    private static function declarationsWithScope(string $code, array $scopes): array
    {
        $result = [];
        $record = static function (string $name, int $offset) use (&$result, $scopes): void {
            $result[$name][] = $scopes[$offset] ?? '|';
        };

        foreach ([
            '/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)/',
            '/\bfunction\s*\*?\s*([A-Za-z_$][\w$]*)\s*\(/',
            '/\bclass\s+([A-Za-z_$][\w$]*)/',
            '/\bcatch\s*\(\s*([A-Za-z_$][\w$]*)/',
        ] as $pattern) {
            preg_match_all($pattern, $code, $found, PREG_OFFSET_CAPTURE);
            foreach ($found[1] as [$name, $offset]) {
                $record($name, $offset);
            }
        }

        // พารามิเตอร์ — ต้องบันทึกไว้ที่ **ข้างในตัวฟังก์ชัน** ไม่ใช่ตรงที่เขียนชื่อ
        // (ชื่อพารามิเตอร์อยู่นอกปีกกา แต่ขอบเขตของมันคือข้างใน)
        // ⚠️ ต้องข้าม `if (…) {` `while (…) {` ฯลฯ ไม่งั้นทุกชื่อในเงื่อนไขกลายเป็น "ประกาศแล้ว"
        $controlKeywords = array_flip(['if', 'for', 'while', 'switch', 'catch', 'return', 'typeof']);
        preg_match_all(
            '/(\b[A-Za-z_$][\w$]*)?\s*\(([^()]*)\)\s*(?:=>\s*)?(\{)?/',
            $code,
            $found,
            PREG_OFFSET_CAPTURE
        );
        foreach ($found[2] as $index => [$group, $groupOffset]) {
            if (isset($controlKeywords[(string)($found[1][$index][0] ?? '')])) {
                continue;
            }

            $bodyOffset = ($found[3][$index][1] ?? -1) >= 0
                ? $found[3][$index][1] + 1     // ข้างในปีกกาของตัวฟังก์ชัน
                : $groupOffset;                // arrow แบบไม่มีปีกกา — ขอบเขตเดียวกับที่เขียน

            preg_match_all('/[A-Za-z_$][\w$]*/', $group, $names);
            foreach ($names[0] as $name) {
                $record($name, $bodyOffset);
            }
        }

        // `x => …` แบบพารามิเตอร์เดียวไม่มีวงเล็บ
        preg_match_all('/(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>/', $code, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[1] as [$name, $offset]) {
            $record($name, $offset);
        }

        // destructuring: const { a, b } = … · const [a, b] = …
        preg_match_all('/\b(?:const|let|var)\s*[\{\[]([^\}\]]*)[\}\]]/', $code, $found, PREG_OFFSET_CAPTURE);
        foreach ($found[1] as [$group, $groupOffset]) {
            preg_match_all('/[A-Za-z_$][\w$]*/', $group, $names);
            foreach ($names[0] as $name) {
                $record($name, $groupOffset);
            }
        }

        return $result;
    }

    /** ของที่เบราว์เซอร์/ภาษามีให้อยู่แล้ว — ใช้ร่วมกับตัวกวาดตัวแปร */
    private const BROWSER_GLOBALS = [
        'document' => true, 'window' => true, 'console' => true, 'navigator' => true,
        'location' => true, 'history' => true, 'localStorage' => true, 'sessionStorage' => true,
        'JSON' => true, 'Math' => true, 'Object' => true, 'Array' => true, 'Number' => true,
        'String' => true, 'Date' => true, 'Promise' => true, 'Map' => true, 'Set' => true,
        'RegExp' => true, 'Intl' => true, 'URLSearchParams' => true, 'FormData' => true,
        'Chart' => true, 'performance' => true, 'crypto' => true,
        // คีย์เวิร์ดที่ตามด้วยจุดได้ในบางรูปประโยค
        'this' => true, 'super' => true, 'new' => true, 'return' => true, 'typeof' => true,
        'await' => true, 'else' => true, 'do' => true, 'in' => true, 'of' => true, 'case' => true,
    ];

    /**
     * ชื่อทั้งหมดที่ประกาศไว้ในโค้ดก้อนหนึ่ง — ตัวแปร ฟังก์ชัน พารามิเตอร์ และ destructuring
     *
     * ⚠️ ต้องเก็บ **พารามิเตอร์** ด้วย ไม่งั้น `rows.forEach((row) => row.x)` จะถูกรายงานว่า
     * `row` ไม่มีนิยาม ซึ่งเป็นผลบวกลวงที่ทำให้ตัวกวาดใช้งานไม่ได้เลย
     *
     * @return array<string,true>
     */
    private static function declaredNamesIn(string $code): array
    {
        $names = [];

        $patterns = [
            '/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)/',        // const x
            '/\bfunction\s*\*?\s*([A-Za-z_$][\w$]*)\s*\(/',   // function foo(
            '/\bclass\s+([A-Za-z_$][\w$]*)/',                    // class Foo
            '/\bcatch\s*\(\s*([A-Za-z_$][\w$]*)/',              // catch (e)
            '/\bfor\s*\(\s*(?:const|let|var)\s+([A-Za-z_$][\w$]*)/', // for (const x of
            '/([A-Za-z_$][\w$]*)\s*=>/',                          // x => …
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $code, $found);
            foreach ($found[1] as $name) {
                $names[$name] = true;
            }
        }

        // พารามิเตอร์ในวงเล็บ — ทั้ง `function foo(a, b) {`, `(a, b) => …` และ `.forEach((a, b) => …)`
        // ⚠️ ต้องไม่ผูกกับสิ่งที่อยู่ **ก่อน** วงเล็บ · `grid.forEach((cells, i) => …)` มี `(` ติดกัน
        // สองตัว ซึ่งไม่มีขอบเขตคำให้จับ ทำให้ `cells` ถูกรายงานว่าไม่มีนิยาม (ผลบวกลวง)
        preg_match_all('/\(([^()]*)\)\s*(?:=>|\{)/', $code, $paramGroups);
        foreach ($paramGroups[1] as $group) {
            preg_match_all('/[A-Za-z_$][\w$]*/', $group, $paramNames);
            foreach ($paramNames[0] as $name) {
                $names[$name] = true;
            }
        }

        // destructuring: const { a, b } = … · const [a, b] = …
        preg_match_all('/\b(?:const|let|var)\s*[\{\[]([^\}\]]*)[\}\]]/', $code, $destructured);
        foreach ($destructured[1] as $group) {
            preg_match_all('/[A-Za-z_$][\w$]*/', $group, $destructuredNames);
            foreach ($destructuredNames[0] as $name) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /** @return array<int,string> เนื้อในของทุกก้อน `<script>` ตามลำดับในหน้า */
    private static function scriptBlocksOf(string $relativePath): array
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);

        return $blocks[1];
    }

    /** โค้ดที่ทุกหน้าโหลดคู่กันเสมอ (header/footer) */
    private static function sharedScriptSource(): string
    {
        $shared = '';
        foreach (['includes/header.php', 'includes/footer.php'] as $sharedFile) {
            foreach (self::scriptBlocksOf($sharedFile) as $block) {
                $shared .= "\n" . self::stripCommentsAndStrings(
                    (string)preg_replace('/<\?[=php].*?\?' . '>/s', '0', $block)
                );
            }
        }

        return $shared;
    }

    /**
     * ⭐ "หน้าตาเหมือนวันที่" ต้องไม่ใช้ตัวเดียวกับ "แปลงวันที่ได้"
     *
     * `looksLikeDateCell` ใช้แยกแถวหัวตารางเท่านั้น — ถ้าเอา `parseDateCell` ไปใช้แทน
     * แถวข้อมูลแรกที่วันกำกวม (เช่น 05/03/2026) จะถูกนับเป็นหัวตารางแล้วทิ้งทิ้งไป
     */
    public function testHeaderDetectionAcceptsAmbiguousDatesThatParsingRejects(): void
    {
        $ambiguous = ['05/03/2026', '12/12/2026'];

        $looks = $this->runJs($this->extractJs('looksLikeDateCell'), 'looksLikeDateCell(raw)', $ambiguous);
        $parses = $this->runJs(
            $this->extractJs('pad2') . "\n" . $this->extractJs('parseDateCell'),
            'parseDateCell(raw)',
            $ambiguous
        );

        foreach ($ambiguous as $index => $typed) {
            $this->assertSame('true', $looks[$index], "\"{$typed}\" ไม่ถูกมองว่าหน้าตาเหมือนวันที่");
            $this->assertSame('<<null>>', $parses[$index], "\"{$typed}\" ถูกเดาแทนที่จะปฏิเสธ");
        }
    }

    /**
     * ⭐⭐⭐ ทุกทางที่ **ระบบเติมค่าเดิม** ให้ตารางกรอกหลายวัน ต้องติดธง `data-prefilled`
     *
     * ⚠️⚠️ ธงนี้คือสิ่งเดียวที่แยก "ค่าที่ระบบเติม" ออกจาก "ค่าที่ผู้ใช้พิมพ์เอง"
     * ตัวจัดการ "เปลี่ยนวันที่" ล้างเฉพาะช่องที่ติดธง — ทางเติมไหนไม่ติดธง ค่าของวันเก่า
     * จะค้างอยู่บนแถวที่เปลี่ยนวันไปแล้ว แล้วถูกบันทึกลงวันใหม่โดยไม่มีอะไรเตือน
     *
     * ⚠️⚠️ **เกิดขึ้นจริง 2 รอบกับ 2 ทางเติมคนละทาง** — ทางเลือกวันทีละแถวแก้ไปแล้ว
     * แต่ปุ่ม "เติมทั้งเดือน" ยังไม่ติดธง (รูปแบบเดิมของโปรเจกต์นี้: กติกาถูกบังคับใช้
     * ที่หนึ่งแต่ไปไม่ถึงอีกที่หนึ่ง) · ทางนั้นร้ายกว่าเพราะเติมทีเดียวทั้ง 31 แถว
     *
     * ⚠️ JS ไม่มีตัวรันเทสต์และทางนี้ต้องมี DOM จริง — ตัวกวาดระดับซอร์สจึงเป็น
     * ตาข่ายเดียวที่เป็นไปได้ · มันถามคำถามเดียว: บรรทัดที่เอาค่าจากเซิร์ฟเวอร์
     * (`day.revenue` · `day.ad_cost` · `day.note`) ไปใส่ช่องกรอก อยู่ใกล้คำว่า
     * `prefilled` ไหม
     */
    public function testEveryPathThatPrefillsTheBulkTableMarksTheFields(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);

        $raw = explode("\n", implode("\n", $blocks[1]));
        $bare = explode("\n", self::stripCommentsAndStrings(implode("\n", $blocks[1])));

        /* ⚠️⚠️ ตรวจ **สองร่างของบรรทัดเดียวกัน** — เหมือนตัวกวาดกำไรใน SharedHelperContractTest
           · ร่างเดิม (มีสตริง) ใช้ตอบว่า "นี่คือตารางหลายวัน หรือฟอร์มวันเดียว" เพราะ
             ตัวชี้ขาดเป็นสตริงทั้งคู่ (`name="revenue[]"` · `getElementById('revenue')`)
           · ร่างที่ตัดคอมเมนต์แล้ว ใช้ตอบว่า "ติดธงหรือยัง" เพราะคอมเมนต์ที่อธิบายกติกา
             มักเอ่ยคำว่า `prefilled` อยู่แล้ว — วัดจริงแล้วมันทำให้ตัวกวาดเขียวทั้งที่พัง */
        $this->assertSame(count($raw), count($bare), 'ตัวตัดคอมเมนต์ทำจำนวนบรรทัดเพี้ยน');

        $offenders = [];
        $fillSiteCount = 0;

        foreach ($raw as $number => $line) {
            $code = trim($line);
            if ($code === '' || str_starts_with($code, '//') || str_starts_with($code, '*')) {
                continue;
            }

            // บรรทัดที่ "เอาค่าของวันจากเซิร์ฟเวอร์ไปใส่ช่องกรอก"
            if (preg_match('/\bday\.(revenue|ad_cost|note)\b/', $code) !== 1) {
                continue;
            }

            if (!str_contains($code, '.value') && !str_contains($code, 'setValue(')) {
                continue;
            }

            /* ⚠️ กติกานี้ใช้กับ **ตารางกรอกหลายวัน** เท่านั้น — ฟอร์มกรอกวันเดียวเติมทับ
               ทุกช่องเสมอโดยตั้งใจ (หนึ่งฟอร์ม = หนึ่งวัน) จึงไม่มีตรรกะ "ล้างเฉพาะที่ระบบเติม"
               ⚠️ สองที่ใช้ชื่อตัวแปรเดียวกันเป๊ะ แยกจากชื่อไม่ได้ → ไล่ย้อนขึ้นไปดูว่า
                  ตัวชี้ขาดตัวไหนใกล้กว่ากัน */
            $bulkAt = -1;
            $singleAt = -1;
            for ($back = $number; $back >= 0; $back--) {
                if ($bulkAt < 0 && str_contains($raw[$back], 'revenue[]')) {
                    $bulkAt = $back;
                }
                if ($singleAt < 0 && str_contains($raw[$back], "getElementById('revenue')")) {
                    $singleAt = $back;
                }
            }

            if ($singleAt > $bulkAt) {
                continue;
            }

            $fillSiteCount++;

            /* ⚠️⚠️ ต้องตรวจ **ต่อช่อง** ไม่ใช่ "มีคำว่า prefilled อยู่แถวนั้นไหม" —
               สามช่อง (ยอดขาย/ค่าแอด/โน้ต) เขียนติดกัน ถอดธงออกช่องเดียว คำว่า `prefilled`
               ของอีกสองช่องก็ยังอยู่ในระยะที่มองเห็น → ตัวกวาดเขียวทั้งที่พัง (วัดจริงแล้ว)
               · ธงติดได้ 2 แบบ: เขียนตรง ๆ (`revenueInput.dataset.prefilled`) หรือส่งเป็น
                 อาร์กิวเมนต์ให้ตัวช่วยที่ติดธงให้ (`setValue(…, true)`) */
            $passesFlagThrough = preg_match('/setValue\(.*,\s*true\s*\)/', $bare[$number] ?? '') === 1;

            $flagged = $passesFlagThrough;
            if (!$flagged && preg_match('/(\$?\w+)\.value\s*=/', $code, $target) === 1) {
                $nearby = implode("\n", array_slice($bare, max(0, $number - 10), 21));
                $flagged = str_contains($nearby, $target[1] . '.dataset.prefilled');
            }

            if (!$flagged) {
                $offenders[] = 'บรรทัด ' . ($number + 1) . ': ' . $code;
            }
        }

        $this->assertGreaterThanOrEqual(
            6,
            $fillSiteCount,
            'เจอจุดเติมค่าเดิมของตารางหลายวันน้อยกว่าที่ควรมี — ตัวกวาดน่าจะอ่านผิดที่'
        );

        $this->assertSame(
            [],
            $offenders,
            "มีทางเติมค่าเดิมที่ไม่ได้ติดธง data-prefilled:\n  " . implode("\n  ", $offenders)
            . "\n\n(ผู้ใช้แก้วันที่ของแถวนั้นแล้ว ยอดของวันเก่าจะค้างอยู่"
            . ' แล้วถูกบันทึกลงวันใหม่ทับข้อมูลเดิมโดยไม่มีอะไรเตือน)'
        );
    }

    /**
     * ⭐⭐⭐ เปลี่ยนวันที่ในฟอร์มวันเดียว → ค่าของวันเก่าต้องหายทันที ไม่ใช่รอโหลดเสร็จ
     *
     * ⚠️⚠️ **ช่องว่างที่วัดจริงแล้วเขียนข้อมูลผิดวันได้**: ระหว่างรอ `loadMonthData()`
     * ช่องยอด/โน้ตยังถือค่าของวันเก่าอยู่ ถ้าผู้ใช้กดบันทึกในจังหวะนั้น (พิมพ์เสร็จ
     * เปลี่ยนวัน แล้วกดทันที — ท่าที่เกิดได้จริงเพราะเน็ตช้าหรือฐานข้อมูลตอบช้า)
     * ยอดของวันเก่าจะถูกเขียนลงวันใหม่ ทับข้อมูลที่มีอยู่โดยไม่มีอะไรเตือน
     *
     * ⚠️ ทดสอบด้วย DOM จริงไม่ได้ (ไม่มีตัวรัน JS ที่มี DOM) — ตัวกวาดระดับซอร์สจึงยืนยัน
     * **สองอย่างที่ปิดช่องนี้**: ล้างช่องก่อน `await` และปิดปุ่มบันทึกจนกว่าจะรู้ข้อมูลจริง
     */
    public function testChangingTheDateClearsTheSingleDayFormBeforeItWaits(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = self::stripCommentsAndStrings(implode("\n", $blocks[1]));

        /* ตัดเอาเฉพาะตัวจัดการ "เปลี่ยนวันที่" ของฟอร์มวันเดียว
           ⚠️⚠️ ห้ามยึดจาก `dateInput.addEventListener(` — มันมีสองที่ ตัวแรกคือคำเตือน
              วันอนาคตซึ่งไม่ได้รออะไร · ยึดผิดแล้วช่วง "ก่อนรอโหลด" จะกินนิยามของ
              `applyDay()` เข้ามาด้วย ซึ่งมีการเขียนค่าลงช่องอยู่แล้ว → **ตัวกวาดเขียว
              แม้ถอดการล้างช่องออกทั้งหมด** (มิวเทชันจับได้)
           · `pendingRequestId` มีเฉพาะในตัวจัดการของฟอร์มวันเดียว จึงเป็นหมุดที่แม่นกว่า */
        $start = mb_strpos($source, '++pendingRequestId');
        $this->assertNotFalse($start, 'หาตัวจัดการเปลี่ยนวันที่ของฟอร์มวันเดียวไม่เจอ');

        $handler = mb_substr($source, (int)$start);
        $awaitAt = mb_strpos($handler, 'await loadMonthData');
        $this->assertNotFalse($awaitAt, 'ตัวจัดการนี้ไม่ได้รอโหลดข้อมูลเดือน — ตัวกวาดอ่านผิดที่');

        $beforeAwait = mb_substr($handler, 0, (int)$awaitAt);

        foreach (['revenueInput', 'adCostInput', 'noteInput'] as $field) {
            $this->assertMatchesRegularExpression(
                '/' . $field . '\.value\s*=\s*\S/',
                $beforeAwait,
                "ช่อง {$field} ไม่ถูกล้างก่อนเริ่มรอโหลด — ค่าของวันเก่าจะค้างอยู่บนวันใหม่"
            );
        }

        $this->assertStringContainsString(
            'setPending(true)',
            $beforeAwait,
            'ปุ่มบันทึกไม่ถูกปิดระหว่างรอโหลด — กดบันทึกตอนนั้นได้ทั้งที่ยังไม่รู้ข้อมูลของวันใหม่'
        );

        // และต้องปลดล็อกคืนทั้งกิ่งสำเร็จและกิ่งล้มเหลว ไม่งั้นปุ่มค้างปิดตลอดอายุหน้า
        $this->assertSame(
            2,
            preg_match_all('/setPending\(false\)/', $handler),
            'ต้องปลดล็อกปุ่มทั้งตอนโหลดสำเร็จและตอนโหลดล้มเหลว'
        );
    }

    /**
     * ⭐⭐ ตัวช่วย `setValue()` ที่รับธงมา **ต้องติดธงจริง**
     *
     * ⚠️ ตัวกวาด `testEveryPathThatPrefillsTheBulkTableMarksTheFields()` ยอมรับรูปแบบ
     * `setValue(…, true)` ว่า "ติดธงแล้ว" — ถ้าตัวช่วยไม่ได้ทำตามที่รับปากไว้
     * ตัวกวาดตัวนั้นจะเขียวโดยไม่ได้คุ้มครองอะไรเลย
     */
    public function testTheBulkFillHelperActuallySetsTheFlagItPromises(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = self::stripCommentsAndStrings(implode("\n", $blocks[1]));

        $start = mb_strpos($source, 'const setValue = (');
        $this->assertNotFalse($start, 'หาตัวช่วย setValue ของตารางหลายวันไม่เจอ');

        $helper = mb_substr($source, (int)$start, 500);

        $this->assertStringContainsString(
            'dataset.prefilled',
            $helper,
            'setValue() รับธงมาแต่ไม่ได้ติดธงให้ช่อง — ตัวกวาดที่เชื่อมันจะเขียวโดยเปล่าประโยชน์'
        );
    }

    /**
     * ⭐⭐⭐ เปลี่ยนวัน A → B → A ระหว่างโหลด — คำตอบของ B ต้องไม่ตกลงบนวัน A
     *
     * ⚠️⚠️ **ลำดับที่พังจริง**: อยู่ที่วัน A → เปลี่ยนเป็น B (เริ่มโหลด) → เปลี่ยนกลับเป็น A
     * ก่อนที่ B จะตอบ · กิ่ง "วันเดิม ไม่ต้องทำอะไร" ออกทันทีโดยไม่เพิ่มเลขคำขอ
     * คำตอบของ B จึงผ่านด่านกันผลลัพธ์เก่าไปได้ → **ช่องวันที่แสดง A แต่ยอดเป็นของ B**
     * และปุ่มบันทึกเปิดอยู่ · กดบันทึก = ยอดของ B ถูกเขียนลงวัน A
     *
     * ⚠️ ตัวกวาดนี้ยืนยัน **กติกาในโค้ด** ไม่ใช่รันลำดับจริง (ไม่มี DOM ให้รัน):
     * กิ่งลัดต้องพ่วงเงื่อนไข "ไม่มีคำขอค้างอยู่" เสมอ
     */
    public function testReturningToTheSameDateWhileLoadingStillCancelsTheOldRequest(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = self::stripCommentsAndStrings(implode("\n", $blocks[1]));

        $this->assertStringContainsString(
            'pendingDate',
            $source,
            'ไม่มีตัวจำว่ามีคำขอค้างอยู่ — กิ่ง "วันเดิม" จะออกทันทีแล้วปล่อยคำตอบเก่าเข้ามา'
        );

        /* กิ่งลัดต้องเป็น "วันเดิม **และ** ไม่มีคำขอค้าง" ไม่ใช่ "วันเดิม" เฉย ๆ */
        $this->assertMatchesRegularExpression(
            '/value\s*===\s*lastAppliedDate\s*&&\s*pendingDate\s*===\s*null/',
            $source,
            'กิ่ง "วันเดิมไม่ต้องทำอะไร" ยังออกทันทีแม้มีคำขอค้างอยู่ — คำตอบของวันก่อนหน้า'
            . ' จะตกลงบนวันที่แสดงอยู่'
        );

        /* และต้องล้างสถานะค้างทั้งกิ่งสำเร็จและกิ่งล้มเหลว ไม่งั้นกิ่งลัดตายถาวร
           ⚠️ นับ ≥ 2 เพราะบรรทัดประกาศตัวแปร (`let pendingDate = null`) ก็เข้าเงื่อนไขด้วย */
        $this->assertGreaterThanOrEqual(
            3,
            preg_match_all('/pendingDate\s*=\s*null/', $source),
            'ต้องล้างสถานะ "มีคำขอค้าง" ทั้งตอนโหลดสำเร็จและตอนโหลดล้มเหลว'
        );
    }

    /**
     * ⭐⭐ ตัวเลือกเดือนของตารางหลายวันต้องกันคำตอบเก่ามาทับ
     *
     * ⚠️ เลือกเดือน B แล้วเปลี่ยนเป็น C เร็ว ๆ ถ้าคำตอบของ B กลับมาทีหลัง มันจะเติมตาราง
     * ด้วยข้อมูลเดือน B ดึงช่องเลือกกลับไปเป็น B และ **ลบสิ่งที่ผู้ใช้กำลังพิมพ์ในเดือน C ทิ้ง**
     * · ตัวจัดการวันทีละแถวมีด่านนี้อยู่แล้ว ตัวเลือกเดือนตกสำรวจ
     */
    public function testTheBulkMonthPickerIgnoresStaleResponses(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = self::stripCommentsAndStrings(implode("\n", $blocks[1]));

        $this->assertStringContainsString(
            '++monthLoadRequestId',
            $source,
            'ตัวเลือกเดือนไม่ได้จองเลขคำขอ — คำตอบเก่าจะทับตารางที่ผู้ใช้กำลังพิมพ์'
        );

        $this->assertGreaterThanOrEqual(
            2,
            preg_match_all('/monthRequestId\s*!==\s*monthLoadRequestId/', $source),
            'ต้องตรวจเลขคำขอทั้งกิ่งที่ได้คำตอบและกิ่งที่ล้มเหลว'
        );
    }

    /**
     * ⭐⭐⭐ ค่าที่ผู้ใช้ **วางเอง** ต้องปลดธง `prefilled` ทันที
     *
     * ⚠️⚠️ การกำหนด `.value` ด้วยโค้ด **ไม่ทำให้ event `input` ทำงาน** ธงจึงไม่หลุด
     * เหมือนตอนผู้ใช้พิมพ์เอง · แล้วตัววางยิง `change` ต่อท้าย → ตัวจัดการเห็นว่าเป็น
     * "ค่าที่ระบบเติม" แล้ว **ล้างยอดที่เพิ่งวางทิ้ง** · ผู้ใช้วางทับแถวที่มีข้อมูลอยู่แล้ว
     * จะเห็นยอดหายไปเงียบ ๆ
     */
    public function testPastedValuesClearThePrefilledFlag(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $bare = explode("\n", self::stripCommentsAndStrings(implode("\n", $blocks[1])));

        $pasteAt = null;
        foreach ($bare as $number => $line) {
            if (str_contains($line, 'normalizeCell(columnIndex, cell)') && str_contains($line, '.value')) {
                $pasteAt = $number;
            }
        }

        $this->assertNotNull($pasteAt, 'หาจุดที่ตัววางเขียนค่าลงช่องไม่เจอ');

        /* ⚠️ หน้าต่างต้องกว้างพอ — คอมเมนต์ที่อธิบายกติกาถูกแทนที่ด้วยบรรทัดว่างตอนตัด
           จึงกินระยะไปหลายบรรทัดระหว่างจุดเขียนค่ากับจุดปลดธง */
        $nearby = implode("\n", array_slice($bare, $pasteAt, 14));
        $this->assertMatchesRegularExpression(
            '/delete\s+\w+\.dataset\.prefilled|dataset\.prefilled\s*=\s*/',
            $nearby,
            'ตัววางไม่ได้ปลดธง prefilled — ค่าที่ผู้ใช้วางจะถูกล้างทิ้งเหมือนเป็นข้อมูลเก่า'
        );
    }

    /**
     * ⭐⭐ กิ่งล้มเหลวของตัวจัดการวันทีละแถว ต้องกันผลลัพธ์เก่าเหมือนกิ่งสำเร็จ
     *
     * ⚠️ เลือกวัน B (โหลดล้ม) แล้วเลือกวัน C (โหลดสำเร็จ) — ถ้าความล้มเหลวของ B
     * มาถึงทีหลัง มันจะล้างธง `note_checked` ของวัน C ทิ้ง แล้วเซิร์ฟเวอร์ปฏิเสธทั้งชุด
     * ทั้งที่ผู้ใช้เห็นโน้ตเดิมของวัน C แล้วและตั้งใจล้างจริง ๆ
     */
    public function testTheRowHandlerAlsoGuardsItsFailureBranch(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = self::stripCommentsAndStrings(implode("\n", $blocks[1]));

        $this->assertGreaterThanOrEqual(
            2,
            preg_match_all('/bulkRowRequestIds\.get\(row\)\s*!==\s*requestId/', $source),
            'กิ่งล้มเหลวไม่ได้กันผลลัพธ์เก่า — ความล้มเหลวของวันก่อนหน้าจะล้างธงของวันใหม่'
        );
    }

    /**
     * ⭐⭐⭐ ตารางกรอกหลายวันต้อง **แก้ไม่ได้และบันทึกไม่ได้** ระหว่างโหลดเดือนใหม่
     *
     * ⚠️⚠️ คำตอบที่กลับมาเรียก `populateFromDays()` ซึ่งล้างทั้งตารางแล้วสร้างใหม่
     * สิ่งที่ผู้ใช้พิมพ์ระหว่างรอจึงหายไปเฉย ๆ · และถ้ากดบันทึกระหว่างนั้น สิ่งที่ถูกส่ง
     * คือข้อมูลของ **เดือนเดิม** ทั้งที่ช่องเลือกเดือนเปลี่ยนไปแล้ว
     * · ด่าน "เตือนก่อนทับ" ที่มีอยู่ถามก่อน *เริ่ม* โหลด จึงไม่ครอบช่วงที่กำลังโหลด
     *
     * ⚠️ ต้องปลดล็อกทุกทางออก (สำเร็จ · payload ผิดพลาด · โยน error) ไม่งั้นตารางค้าง
     * แก้ไม่ได้ตลอดอายุหน้า
     */
    public function testTheBulkTableIsLockedWhileAMonthIsLoading(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/add-record.php');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $page, $blocks);
        $source = self::stripCommentsAndStrings(implode("\n", $blocks[1]));

        $this->assertStringContainsString(
            'setBulkPending(true)',
            $source,
            'ตารางไม่ถูกล็อกตอนเริ่มโหลดเดือนใหม่ — ผู้ใช้พิมพ์หรือกดบันทึกระหว่างนั้นได้'
        );

        $this->assertGreaterThanOrEqual(
            3,
            preg_match_all('/setBulkPending\(false\)/', $source),
            'ปลดล็อกไม่ครบทุกทางออก (สำเร็จ · payload ผิดพลาด · โยน error) — ตารางจะค้าง'
        );

        // ตัวล็อกต้องแตะทั้งช่องกรอกและปุ่มบันทึก ไม่ใช่แค่ปุ่ม
        $lockAt = mb_strpos($source, 'const setBulkPending');
        $this->assertNotFalse($lockAt, 'หานิยามของตัวล็อกไม่เจอ');

        $body = mb_substr($source, (int)$lockAt, 600);
        $this->assertStringContainsString('disabled', $body, 'ตัวล็อกไม่ได้ปิดช่องกรอก');
        $this->assertStringContainsString('tbody', $body, 'ตัวล็อกไม่ได้แตะแถวในตาราง');
    }
}
