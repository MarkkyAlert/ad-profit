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
            '1,234,567.89', '1.234.567,89', '1 234,56', '฿1,234.56', '0', '0.00', '0.56',
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

            if (preg_match($sharedShape, trim($typed)) !== 1) {
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
            foreach (array_merge($blockDeclared[1], $blockFunctions[1]) as $name) {
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
}
