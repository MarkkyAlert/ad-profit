<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecordService;

/**
 * helper กลางที่หลายหน้าใช้ร่วมกัน แต่ไม่เคยมีตาข่ายของตัวเอง
 *
 * ⚠️ ทั้งหมดในไฟล์นี้พิสูจน์แล้วว่า **ทำให้พังได้โดยที่เทสต์ทั้งชุดยังเขียว**
 * (ทดลองด้วยการแก้โค้ดจริงให้พังทีละอัน แล้วรันชุดเทสต์ทั้งหมด)
 */
final class SharedHelperContractTest extends TestCase
{
    /**
     * ⭐⭐ วันตัดของ "เดือนนี้" ต้องหดให้พอดีเมื่อเดือนที่เทียบสั้นกว่า
     *
     * ⚠️ `OverviewService` เอาวันตัดของ **เดือนปัจจุบัน** ไปใช้กับ **เดือนก่อน**
     * ถ้าไม่หด วันที่ 31 มี.ค. เทียบกับ ก.พ. จะได้ปลายช่วงเป็น `2026-03-03`
     * ซึ่งเลยเข้ามาในเดือนปัจจุบัน 3 วัน → ยอด "เดือนก่อน" มีข้อมูลของเดือนนี้ปน
     *
     * ⚠️ เทสต์เดิมทั้งหมดปักวันไว้ที่ 4 ส.ค. ซึ่งเดือนก่อน (ก.ค.) มี 31 วัน
     * `min(4, 31)` กับ `4` เฉย ๆ ให้ผลเท่ากันพอดี — **แยกสองพฤติกรรมไม่ได้เลย**
     * ต้องเลือกวันที่เดือนก่อนสั้นกว่าวันตัดเท่านั้น
     *
     * @return array<string,array{0:string,1:int,2:string}>
     */
    public static function shortMonthProvider(): array
    {
        return [
            'ก.พ. ปีปกติ (28 วัน) ตัดที่วันที่ 31' => ['2026-02', 31, '2026-02-28'],
            'ก.พ. ปีอธิกสุรทิน (29 วัน) ตัดที่ 31' => ['2024-02', 31, '2024-02-29'],
            'เม.ย. (30 วัน) ตัดที่วันที่ 31' => ['2026-04', 31, '2026-04-30'],
            'เดือนที่ยาวพอ ไม่ต้องหด' => ['2026-07', 4, '2026-07-04'],
            'ไม่ได้กำหนดวันตัด = ทั้งเดือน' => ['2026-02', 0, '2026-02-28'],
        ];
    }

    #[DataProvider('shortMonthProvider')]
    public function testTheCutoffDayShrinksToFitAShorterMonth(string $month, int $cutoffDay, string $expected): void
    {
        $this->assertSame(
            $expected,
            comparison_range_end($month, $cutoffDay > 0 ? $cutoffDay : null),
            'วันตัดไม่ได้หดให้พอดีเดือน — ยอด "เดือนก่อน" จะมีข้อมูลของเดือนนี้ปน'
        );
    }

    /**
     * ⭐ แสดงสตางค์เฉพาะตอนมีเศษ
     *
     * ⚠️ ไม่เคยมีเทสต์ไหนเรียก `formatMoney()` เลยสักตัว · บั๊กเดิมคือตัดสตางค์ทิ้ง
     * ทุกช่อง ทำให้แถวรวมไม่เท่ากับผลบวกของแถวที่เห็น (฿100 สามแถว แต่รวม ฿301)
     *
     * @return array<string,array{0:float,1:string}>
     */
    public static function moneyProvider(): array
    {
        return [
            'ลงตัว = ไม่โชว์สตางค์' => [100.0, '฿100'],
            'มีเศษ = โชว์สตางค์' => [100.40, '฿100.40'],
            'ผลรวมที่มีเศษ' => [301.20, '฿301.20'],
            'หลักพันมีตัวคั่น' => [1234.50, '฿1,234.50'],
            'ศูนย์' => [0.0, '฿0'],
            // (สัญลักษณ์อยู่หน้าเครื่องหมายลบ — พฤติกรรมเดิมของระบบ)
            'ติดลบมีเศษ' => [-100.40, '฿-100.40'],
        ];
    }

    #[DataProvider('moneyProvider')]
    public function testMoneyShowsSatangOnlyWhenThereIsAny(float $amount, string $expected): void
    {
        $this->assertSame($expected, formatMoney($amount), 'รูปแบบเงินเพี้ยน — แถวรวมจะไม่เท่ากับผลบวกของแถวที่เห็น');
    }

    /**
     * ⭐⭐ เส้นทางที่รับมาจากผู้ใช้ต้องพาออกนอกเว็บไม่ได้
     *
     * ⚠️ helper นี้ไม่มีเทสต์เลยทั้งตัว ทั้งที่ 3 endpoint ใช้มันคุม `Location:`
     * และค่านั้นถูกคำนวณ **ก่อน** ด่าน CSRF (คำตอบตอน CSRF ไม่ผ่านก็ redirect ด้วยค่านี้)
     *
     * @return array<string,array{0:string}>
     */
    public static function hostileRedirectProvider(): array
    {
        return [
            'โดเมนเต็ม' => ['https://evil.example.com/steal'],
            'ไม่ระบุโปรโตคอล' => ['//evil.example.com/steal'],
            'สแลชกลับหัว (เบราว์เซอร์อ่านเหมือน //)' => ['/\\evil.example.com/steal'],
            // ⚠️ เบราว์เซอร์ **ลบแท็บ/ขึ้นบรรทัดทิ้งก่อนแปลง URL** ตามสเปก
            // `/<TAB>/evil.com` จึงกลายเป็น `//evil.com` = ออกนอกเว็บได้
            // (วัดจริง: new URL("/\t/evil.example") → https://evil.example/)
            'แท็บคั่นกลาง' => ["/\t/evil.example.com/steal"],
            'แท็บคั่นกลาง + สแลชกลับหัว' => ["/\t\\evil.example.com/steal"],
            'ขึ้นบรรทัดคั่นกลาง' => ["/\n/evil.example.com/steal"],
            'โปรโตคอลแปลก' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('hostileRedirectProvider')]
    public function testAHostileRedirectNeverLeavesTheSite(string $candidate): void
    {
        $resolved = resolve_safe_redirect_path('/dashboard.php', $candidate);

        $this->assertStringStartsWith('/', $resolved, "เส้นทางออกนอกเว็บได้: {$candidate}");
        $this->assertStringNotContainsString('evil.example.com', $resolved, "พาไปโดเมนอื่นได้: {$candidate}");
        $this->assertStringNotContainsString('javascript:', $resolved, "รันสคริปต์ได้: {$candidate}");
        // ⚠️ ไม่ตรวจ `..` — เส้นทางแบบ `/../x` เบราว์เซอร์ยุบให้อยู่ในโดเมนเดิมอยู่แล้ว
        // จึงไม่ใช่ช่องพาออกนอกเว็บ · สิ่งที่ต้องกันคือ "ออกนอกโดเมน" เท่านั้น
    }

    /** ⭐ เส้นทางปกติต้องผ่านตามเดิม (ไม่ใช่กันจนใช้งานไม่ได้) */
    public function testNormalPathsStillWork(): void
    {
        $this->assertSame('/history.php', resolve_safe_redirect_path('/dashboard.php', '/history.php'));
        $this->assertSame('/dashboard.php', resolve_safe_redirect_path('/dashboard.php', ''));
        $this->assertSame('/dashboard.php', resolve_safe_redirect_path('/dashboard.php', null));
    }

    /**
     * ⭐⭐ ตัดช่องว่างต้องได้ผลเหมือน `.trim()` ของเบราว์เซอร์
     *
     * ⚠️ `trim()` ของ PHP ไม่ตัดช่องว่างยูนิโค้ด (NBSP ฯลฯ) แต่เบราว์เซอร์ตัด
     * ผลคือ: ก๊อปชื่อร้านมาจาก LINE/Word แล้ววาง (ติด NBSP มาโดยมองไม่เห็น)
     * → กล่องยืนยันตอนลบร้านแสดงชื่อที่เบราว์เซอร์ตัดแล้ว ผู้ใช้พิมพ์ตามที่เห็นเป๊ะ ๆ
     * ก็ยังไม่ตรงกับที่เก็บไว้ — **ลบร้านนั้นไม่ได้ตลอดกาล** และยังกินโควตา 1 ใน 20
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function unicodeSpaceProvider(): array
    {
        return [
            'ช่องว่างไม่ตัดคำ (NBSP) ท้ายชื่อ — ติดมาจากการก๊อปวาง' => ["ร้านเสื้อ\u{00A0}", 'ร้านเสื้อ'],
            'ช่องว่างญี่ปุ่นหน้าชื่อ' => ["\u{3000}ร้านเสื้อ", 'ร้านเสื้อ'],
            'zero-width ท้ายชื่อ' => ["ร้านเสื้อ\u{FEFF}", 'ร้านเสื้อ'],
            'ช่องว่างธรรมดายังตัดเหมือนเดิม' => ['  ร้านเสื้อ  ', 'ร้านเสื้อ'],
            'ช่องว่างยูนิโค้ดล้วน = ค่าว่าง' => ["\u{3000}\u{00A0}", ''],
            'ช่องว่างกลางชื่อไม่แตะ' => ['ร้าน เสื้อ', 'ร้าน เสื้อ'],
        ];
    }

    #[DataProvider('unicodeSpaceProvider')]
    public function testWhitespaceIsTrimmedTheSameWayTheBrowserDoes(string $input, string $expected): void
    {
        $this->assertSame(
            $expected,
            trim_unicode_whitespace($input),
            'ตัดช่องว่างไม่เหมือนเบราว์เซอร์ — ชื่อร้านที่ติดช่องว่างซ่อนอยู่จะลบไม่ได้'
        );
    }

    /**
     * ⚠️⚠️ **อีเมลก็ต้องตัดช่องว่างยูนิโค้ดเหมือนชื่อร้าน — เดิมใช้ `trim()` ธรรมดา**
     *
     * ก๊อปอีเมลจาก LINE/แชท/Word มาวางมักติดช่องว่างที่มองไม่เห็นมาด้วย
     * `is_valid_email()` จึงตอบว่ารูปแบบผิด ทั้งที่บนจอดูถูกทุกตัวอักษร:
     *   · สมัครสมาชิก → "กรุณากรอกอีเมลที่ถูกต้อง"
     *   · **ลืมรหัสผ่าน → "กรุณากรอกอีเมลที่ถูกต้อง"** (คนที่มาถึงหน้านี้เข้าระบบไม่ได้แล้ว)
     *   · เข้าสู่ระบบ (ติดข้างหน้า) → "อีเมลหรือรหัสผ่านไม่ถูกต้อง" ทั้งที่ทั้งคู่ถูก
     *     แล้วการลองซ้ำ ๆ จะไปชนตัวจำกัดจำนวนครั้ง
     *
     * ⚠️ เดิมเข้าระบบได้เมื่อช่องว่างอยู่ **ท้ายสุด** — แต่นั่นเป็นความบังเอิญของ
     * collation ของ MySQL ที่มองข้ามตัวท้ายให้ ไม่ใช่การออกแบบ · ฝั่ง PHP ยังถือว่า
     * รูปแบบผิดอยู่ดี ทางอื่นจึงล้มหมด · **ห้ามพึ่งพฤติกรรมนั้น**
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function pastedEmailProvider(): array
    {
        return [
            'NBSP ท้าย — ก๊อปมาจากแชท' => ["owner@example.com\u{00A0}", 'owner@example.com'],
            'NBSP หน้า' => ["\u{00A0}owner@example.com", 'owner@example.com'],
            'zero-width ท้าย' => ["owner@example.com\u{200B}", 'owner@example.com'],
            'ช่องว่างญี่ปุ่นท้าย' => ["owner@example.com\u{3000}", 'owner@example.com'],
            'ช่องว่างธรรมดายังตัดเหมือนเดิม' => ['  owner@example.com  ', 'owner@example.com'],
            'ตัวพิมพ์ใหญ่ยังถูกลดรูปเหมือนเดิม' => ['OWNER@EXAMPLE.COM', 'owner@example.com'],
        ];
    }

    #[DataProvider('pastedEmailProvider')]
    public function testAPastedEmailIsStillReadAsAnEmail(string $input, string $expected): void
    {
        $normalized = normalize_email($input);

        $this->assertSame($expected, $normalized, 'ตัดช่องว่างที่มองไม่เห็นออกจากอีเมลไม่ได้');
        $this->assertTrue(
            is_valid_email($normalized),
            'อีเมลที่ก๊อปมาวางถูกตีว่ารูปแบบผิด — ผู้ใช้เห็นแต่ตัวอักษรที่ถูกต้องบนจอ'
        );
    }

    /** ⭐ ชื่อที่ต้องพิมพ์ยืนยันตอนลบร้าน ต้องตรงกับที่เบราว์เซอร์แสดง */
    public function testTheDeleteConfirmationMatchesWhatTheBrowserShows(): void
    {
        $stored = "ร้านเสื้อ\u{00A0}";

        $this->assertSame(
            'ร้านเสื้อ',
            \ShopService::confirmationNameFor($stored),
            'ผู้ใช้พิมพ์ตามที่เห็นบนจอแล้วยังไม่ตรง — ลบร้านนั้นไม่ได้ตลอดกาล'
        );
    }

    /**
     * ⭐ เกณฑ์ "มีตัวอย่างพอจะฟันธงไหม" ต้องมากกว่า 1
     *
     * ⚠️ คำว่า `trend_reliable` ไม่ปรากฏในไฟล์เทสต์ไหนเลย — ลดเกณฑ์เหลือ 1
     * แล้วชุดเทสต์ทั้งหมดยังเขียว · แดชบอร์ดจะสรุปว่า "วันจันทร์ทำได้ต่ำกว่าปกติ"
     * จากตัวอย่างวันจันทร์วันเดียว
     */
    public function testTheWeekdayVerdictNeedsMoreThanOneSample(): void
    {
        $this->assertGreaterThan(
            1,
            RecordService::WEEKDAY_MIN_SAMPLE,
            'ฟันธงแนวโน้มวันในสัปดาห์จากตัวอย่างวันเดียว'
        );
    }

    /**
     * ⭐⭐ `month_is_unfinished()` — จุดเดียวของกติกา "เดือนนี้จบหรือยัง"
     *
     * ⚠️ เคยเขียนซ้ำ 3 ที่ แล้วที่หนึ่งลืมเงื่อนไข "ยังไม่ถึงวันสุดท้ายของเดือน"
     * ผลคือ **ในวันสุดท้ายของทุกเดือน** (1 วัน/เดือน) การ์ด "เดือนกำไรดีสุด" ยกให้
     * เดือนนี้ ขณะที่กริดฤดูกาลใต้การ์ดบนจอเดียวกันยังระบายเทาว่า "ยังตัดสินไม่ได้"
     *
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    public static function unfinishedMonthProvider(): array
    {
        return [
            'กลางเดือน' => ['2026-08', '2026-08-07', true],
            'วันก่อนวันสุดท้าย' => ['2026-08', '2026-08-30', true],
            'วันสุดท้ายของเดือน' => ['2026-08', '2026-08-31', false],
            'เดือนที่ผ่านไปแล้ว' => ['2026-07', '2026-08-07', false],
            'เดือนถัดไป' => ['2026-09', '2026-08-07', false],
            'ก.พ. ปีปกติ วันสุดท้าย' => ['2026-02', '2026-02-28', false],
            'ก.พ. ปีปกติ วันที่ 27' => ['2026-02', '2026-02-27', true],
            'ก.พ. ปีอธิกสุรทิน วันที่ 28' => ['2028-02', '2028-02-28', true],
            'ก.พ. ปีอธิกสุรทิน วันสุดท้าย' => ['2028-02', '2028-02-29', false],
            'วันแรกของเดือน' => ['2026-08', '2026-08-01', true],
            'ธ.ค. วันสุดท้ายของปี' => ['2026-12', '2026-12-31', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unfinishedMonthProvider')]
    public function testMonthIsUnfinishedKnowsWhenTheMonthHasEnded(
        string $monthKey,
        string $today,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            month_is_unfinished($monthKey, new \DateTimeImmutable($today)),
            sprintf('เดือน %s เมื่อวันนี้คือ %s ตอบผิด', $monthKey, $today)
        );
    }

    /**
     * ⭐⭐ ห้ามมีใครเขียนกติกานี้ซ้ำอีก
     *
     * ⚠️ นิพจน์ `format('j') < ... format('t')` คือกติกา "เดือนยังไม่จบ" ถ้าเห็นมันอยู่
     * นอก `month_is_unfinished()` แปลว่ามีคนกำลังเขียนกติกาซ้ำ ซึ่งเป็นรากของบั๊ก
     * ที่เจอซ้ำ ๆ ในโปรเจกต์นี้ (แก้ที่หนึ่งแล้วอีกที่ไม่ตาม)
     */
    public function testNobodyReimplementsTheUnfinishedMonthRule(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/app/Services/*.php'),
            (array)glob($root . '/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            $code = (string)file_get_contents((string)$file);
            if (preg_match("/format\('j'\)\s*<\s*\(int\)\\$\w+->format\('t'\)/", $code) === 1) {
                $offenders[] = basename((string)$file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'เขียนกติกา "เดือนยังไม่จบ" ซ้ำใน: ' . implode(', ', $offenders)
            . ' — ให้เรียก month_is_unfinished() แทน'
        );
    }

    /**
     * ⭐⭐ `compare_shop_rows_for_ranking()` — ร้านที่ยังไม่มีข้อมูลต้องอยู่ท้ายเสมอ
     *
     * ⚠️ กำไร ฿0 ของร้านที่ไม่ได้กรอก "มากกว่า" ร้านที่ขาดทุนจริง เรียงด้วยกำไรล้วน
     * มันจะขึ้นอันดับ 1 · เคยเขียนซ้ำ 2 ที่ แล้วมุมรายปีไม่มีกติกานี้เลย
     */
    public function testShopsWithoutDataAlwaysSortLast(): void
    {
        $rows = [
            ['shop_name' => 'ไม่เคยกรอก', 'profit' => 0.0, 'days_count' => 0],
            ['shop_name' => 'ขาดทุนหนัก', 'profit' => -65700.0, 'days_count' => 219],
            ['shop_name' => 'ขาดทุนน้อย', 'profit' => -21900.0, 'days_count' => 219],
        ];

        usort($rows, 'compare_shop_rows_for_ranking');

        $this->assertSame(
            ['ขาดทุนน้อย', 'ขาดทุนหนัก', 'ไม่เคยกรอก'],
            array_column($rows, 'shop_name'),
            'ร้านที่ไม่มีข้อมูลไม่ได้อยู่ท้าย — กำไร ฿0 ชนะร้านที่ขาดทุนจริง'
        );
    }

    /** กำไรเท่ากันเป๊ะต้องได้ลำดับเดิมทุกครั้ง (query ไม่การันตีลำดับ) */
    public function testEqualProfitsSortStably(): void
    {
        $make = static fn(): array => [
            ['shop_name' => 'ร้าน ข', 'profit' => 5000.0, 'days_count' => 10],
            ['shop_name' => 'ร้าน ก', 'profit' => 5000.0, 'days_count' => 10],
        ];

        $first = $make();
        usort($first, 'compare_shop_rows_for_ranking');
        $second = $make();
        usort($second, 'compare_shop_rows_for_ranking');

        $this->assertSame(array_column($first, 'shop_name'), array_column($second, 'shop_name'));
        $this->assertSame('ร้าน ก', $first[0]['shop_name'], 'กำไรเท่ากันแล้วไม่ได้เรียงตามชื่อ');
    }

    /**
     * ⭐ `extremes_are_comparable()` — "ดีสุด" กับ "แย่สุด" ต้องเป็นคนละอันจริง ๆ
     *
     * ⚠️ ข้อมูลชุดเดียว (หรือทุกอันเท่ากัน) ทำให้อันเดียวกันโผล่สองการ์ด
     * ที่หนึ่งเขียว ที่หนึ่งแดง — เป็นสิ่งแรกที่ผู้ใช้ใหม่เห็น
     *
     * @return array<string,array{0:array<string,mixed>|null,1:array<string,mixed>|null,2:string,3:bool}>
     */
    public static function extremesProvider(): array
    {
        return [
            'วันเดียวกัน' => [['record_date' => '2026-08-07'], ['record_date' => '2026-08-07'], 'record_date', false],
            'คนละวัน' => [['record_date' => '2026-08-07'], ['record_date' => '2026-08-01'], 'record_date', true],
            'เดือนเดียวกัน' => [['month' => 7], ['month' => 7], 'month', false],
            'คนละเดือน' => [['month' => 7], ['month' => 3], 'month', true],
            'ไม่มีดีสุด' => [null, ['month' => 7], 'month', false],
            'ไม่มีแย่สุด' => [['month' => 7], null, 'month', false],
            'ไม่มีทั้งคู่' => [null, null, 'month', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('extremesProvider')]
    public function testExtremesAreComparableOnlyWhenTheyDiffer(
        ?array $best,
        ?array $worst,
        string $key,
        bool $expected
    ): void {
        $this->assertSame($expected, extremes_are_comparable($best, $worst, $key));
    }

    /**
     * ⭐⭐ `change_percent()` — จุดเดียวของสูตร "% เปลี่ยนแปลงเทียบช่วงก่อน"
     *
     * ⚠️ เคยเขียนซ้ำ 4 ที่ · จุดที่ละเอียดอ่อนคือ **หารด้วย `abs()`** เพื่อให้เครื่องหมาย
     * สื่อทิศทางจริง (ฐานติดลบแล้วขาดทุนน้อยลง = ดีขึ้น = ค่าบวก) และ **ฐาน ≈ 0 คืน null**
     * ไม่ใช่ 0 หรือ 100 เพราะเพิ่มจาก ฿0 คิดเป็น % ไม่ได้
     *
     * @return array<string,array{0:float|null,1:float|null,2:float|null}>
     */
    public static function changePercentProvider(): array
    {
        return [
            'เพิ่มขึ้นเท่าตัว' => [200.0, 100.0, 100.0],
            'ลดลงครึ่งหนึ่ง' => [50.0, 100.0, -50.0],
            'เท่าเดิม' => [100.0, 100.0, 0.0],
            'ขาดทุนน้อยลง = ดีขึ้น' => [-50.0, -100.0, 50.0],
            'ขาดทุนมากขึ้น = แย่ลง' => [-150.0, -100.0, -50.0],
            'จากขาดทุนเป็นกำไร' => [100.0, -100.0, 200.0],
            'ฐานเป็นศูนย์' => [5000.0, 0.0, null],
            'ฐานเกือบศูนย์' => [5000.0, 0.000001, null],
            'ทั้งคู่เป็นศูนย์' => [0.0, 0.0, null],
            'ปัจจุบันเป็น null' => [null, 100.0, null],
            'ก่อนหน้าเป็น null' => [100.0, null, null],
            'ปัดทศนิยม 1 ตำแหน่ง' => [100.5, 100.0, 0.5],
            // ⚠️ เปลี่ยนแปลงน้อยมากปัดเป็น 0.0% — ป้าย ↑↓ ต้องตัดสินจากค่าหลังปัด
            // ไม่ใช่ค่าดิบ ไม่งั้นจะได้ลูกศรขึ้นคู่กับเลข 0.0% (มี format_change_badge คุมอยู่)
            'เปลี่ยนน้อยจนปัดเป็นศูนย์' => [100.04, 100.0, 0.0],
            /* ⚠️⚠️ **ตรงครึ่งหน่วยพอดีต้องปัดขึ้น** — ข้อมูลชุดนี้เดิมใช้ `100.05` แล้วคาดหวัง
               `0.0` ซึ่งเป็นการล็อก **ผลของบั๊ก** ไว้: `100.05 − 100.00` ในภาษา PHP ได้
               `0.04999999999999716` ไม่ใช่ `0.05` การปัดจึงตกลงข้างล่าง
               · คำตอบที่ถูกคือ **+0.1%** · ตอนนี้ปัดผลต่างเป็นสตางค์ก่อนหาร
               · เก็บทั้งสองแถวไว้ให้เห็นเส้นแบ่งชัด ๆ (0.04 ลง · 0.05 ขึ้น) */
            'ตรงครึ่งหน่วยพอดีต้องปัดขึ้น' => [100.05, 100.0, 0.1],
            'ฐานติดลบและตรงครึ่งหน่วย' => [-99.95, -100.0, 0.1],
            /* ⚠️⚠️⚠️ **ค่าที่เข้ามาไม่ได้เป็นจำนวนเงินเสมอไป** — แดชบอร์ดส่ง ROAS
               ซึ่งเป็นอัตราส่วนเข้ามาด้วย · เคยแก้บั๊กการปัดด้วยการปัดผลต่างเป็น "สตางค์"
               แล้วพังหนักกว่าเดิม: ROAS 1.000 → 1.006 คือ +0.6% แต่ `0.006` ถูกปัดเป็น
               `0.01` แล้วรายงาน **+1.0%** (วัดจริง) · แถวสองแถวนี้กันไม่ให้กลับไปทำแบบนั้น */
            'ROAS ขยับนิดเดียว (ไม่ใช่จำนวนเงิน)' => [1.006, 1.000, 0.6],
            'ROAS ขยับน้อยกว่าครึ่งหน่วย' => [1.0004, 1.0000, 0.0],
            /* ⚠️⚠️ **การปัดชั้นแรกต้องไม่เปลี่ยนค่าจริง** — ยอดระดับสิบล้าน (ซึ่งคอลัมน์
               DECIMAL(12,2) รองรับ) ทำให้ % จริงเป็น 0.049999999950 ซึ่งควรแสดง 0.0%
               ถ้าปัดชั้นแรกหยาบไป (10 ตำแหน่ง) มันจะถูกดันขึ้นเป็น 0.05 แล้วแสดง 0.1% */
            'ยอดใหญ่ที่ใกล้เกณฑ์แต่ยังไม่ถึง' => [20010000.02, 20000000.02, 0.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changePercentProvider')]
    public function testChangePercentHandlesNegativeAndZeroBases(
        ?float $current,
        ?float $previous,
        ?float $expected
    ): void {
        $this->assertSame(
            $expected,
            change_percent($current, $previous),
            sprintf('เทียบ %s กับฐาน %s ได้ผลผิด', var_export($current, true), var_export($previous, true))
        );
    }

    /**
     * ⭐⭐ ห้ามมีใครเขียนสูตรนี้ซ้ำอีก
     *
     * ⚠️ นิพจน์ `/ abs($…)) * 100` คือลายเซ็นของสูตรนี้ ถ้าเห็นมันอยู่นอก
     * `change_percent()` แปลว่ามีคนกำลังก๊อปสูตรไปไว้ที่อื่น
     *
     * ⚠️⚠️ **เวอร์ชันแรกมองหาเฉพาะแบบที่ใช้ `abs()`** — สำเนาใน `RecordService` เขียนว่า
     * `(($revenue - $previousRevenue) / $previousRevenue) * 100` (ไม่มี `abs()` เพราะยอดขาย
     * ไม่ติดลบ) จึงลอดมาได้ และทำให้คอลัมน์ "เทียบครั้งก่อน" ปัดคนละแบบกับป้าย % ที่เหลือ
     * ทั้งระบบ · ตอนนี้จับรูปแบบ "ผลต่างของสองตัว หารด้วยตัวตั้งต้น คูณ 100" ทั้งสองแบบ
     * ⚠️ ต้องไม่จับ `profit / revenue * 100` (อัตรากำไร) ซึ่งเป็นคนละสูตร — ตัวหารต้องมา
     *    คู่กับการลบในวงเล็บเดียวกันเท่านั้น
     */
    public function testNobodyReimplementsTheChangePercentFormula(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/app/Services/*.php'),
            (array)glob($root . '/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            $code = $this->codeWithoutComments((string)file_get_contents((string)$file));

            $withAbs = preg_match('/\/\s*abs\(\$\w+\)\)\s*\*\s*100/', $code) === 1;
            $withoutAbs = preg_match(
                '/\(\s*\(\s*\$\w+\s*-\s*\$\w+\s*\)\s*\/\s*\$\w+\s*\)\s*\*\s*100/',
                $code
            ) === 1;

            if ($withAbs || $withoutAbs) {
                $offenders[] = basename((string)$file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'เขียนสูตร "% เปลี่ยนแปลง" ซ้ำใน: ' . implode(', ', $offenders) . ' — ให้เรียก change_percent() แทน'
        );
    }

    /**
     * ⭐⭐ ข้อความ "ยังเทียบไม่ได้" ต้องไม่บอกสาเหตุที่เดาเอา
     *
     * ⚠️ เดิมเขียนตายตัวว่า "มีเดือนที่จบแล้วเดือนเดียว" ซึ่งไม่จริงในกรณีที่พบบ่อยพอกัน:
     * **ทุกเดือนที่จบแล้วกำไรเท่ากันหมด** (best กับ worst จึงชี้เดือนเดียวกัน)
     *
     * วัดจริง: ร้านที่กรอก ม.ค.–ก.ค. ได้กำไรเดือนละ ฿19,800 เท่ากันเป๊ะ → การ์ดขึ้น
     * "มีเดือนที่จบแล้วเดือนเดียว" ขณะที่บรรทัดถัดมาบนจอเดียวกันเขียน "8 เดือนมีข้อมูล"
     * และตารางใต้การ์ดแสดง 7 แถวของเดือนที่จบแล้ว
     */
    public function testTheNotComparableMessageDoesNotClaimThereIsOnlyOneMonth(): void
    {
        $this->assertStringNotContainsString(
            'เดือนเดียว',
            extremes_not_comparable_text(),
            'ข้อความอ้างสาเหตุที่ไม่จริงเมื่อทุกเดือนที่จบแล้วให้ผลเท่ากัน'
        );
        $this->assertStringContainsString('ยังเทียบไม่ได้', extremes_not_comparable_text());
    }

    /** ⚠️ ถ้อยคำนี้เคยถูกคัดลอกไว้ 3 ที่ (หน้ารายปี · หน้ารวมร้าน · ไฟล์ Excel) */
    public function testNobodyHardcodesTheNotComparableMessage(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/app/Services/*.php'),
            (array)glob($root . '/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            $code = (string)file_get_contents((string)$file);
            if (str_contains($code, "'ยังเทียบไม่ได้")) {
                $offenders[] = basename((string)$file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'เขียนข้อความ "ยังเทียบไม่ได้" ตายตัวใน: ' . implode(', ', $offenders)
            . ' — ให้เรียก extremes_not_comparable_text() แทน'
        );
    }

    /**
     * ⭐⭐ `money_total()` — ยอดที่บวกในภาษา PHP ต้องตรงกับ `SUM()` ของฐานข้อมูล
     *
     * ⚠️ ปัดครั้งเดียวตอนจบเท่านั้น · ต้องไม่เปลี่ยนค่าที่ละเอียดถึงสตางค์อยู่แล้ว
     */
    public function testMoneyTotalRemovesOnlyTheBinaryLeftovers(): void
    {
        $this->assertSame(500000.0, money_total(499999.99999999994));
        $this->assertSame(499999.99, money_total(499999.99), 'ค่าที่ละเอียดถึงสตางค์อยู่แล้วถูกเปลี่ยน');
        $this->assertSame(-1234.56, money_total(-1234.56), 'ค่าติดลบเพี้ยน');
        $this->assertSame(0.0, money_total(0.0));
    }

    /**
     * ⭐⭐ `resolve_month_allowing_legacy_future()` — เดือนอนาคตเปิดได้เมื่อมีข้อมูลจริงเท่านั้น
     *
     * ⚠️ เคยอยู่ที่ `history.php` ที่เดียว ปุ่ม Export ไม่มี → กดแล้วได้ไฟล์คนละเดือน
     * กับที่หน้าจอแสดงอยู่ (ดูหน้าประวัติเดือน ก.ย. ฿110,000 กด Export ได้ไฟล์ ส.ค. ฿3,000)
     */
    public function testAFutureMonthOpensOnlyWhenItActuallyHasRecords(): void
    {
        $withRecords = static fn(string $month): bool => true;
        $empty = static fn(string $month): bool => false;

        $this->assertSame(
            '2026-09',
            resolve_month_allowing_legacy_future('2026-09', '2026-08', $withRecords),
            'เดือนอนาคตที่มีรายการเก่าอยู่จริงเปิดไม่ได้ — แถวพวกนั้นจะแก้/ลบไม่ได้ตลอดกาล'
        );
        $this->assertSame(
            '2026-08',
            resolve_month_allowing_legacy_future('2026-09', '2026-08', $empty),
            'เดือนอนาคตที่ว่างเปล่าไม่ถูกหดกลับ'
        );
    }

    /** ⚠️ เดือนในอดีต/ปัจจุบัน ต้องไม่ไปถามฐานข้อมูลเลย (helper เดิมหดให้อยู่แล้ว) */
    public function testAPastOrCurrentMonthNeverAsksTheDatabase(): void
    {
        $asked = 0;
        $counter = static function (string $month) use (&$asked): bool {
            $asked++;

            return true;
        };

        foreach (['2026-07', '2026-08', '', 'abc', '2026-13', '2026-1'] as $requested) {
            $this->assertSame(
                '2026-08',
                resolve_month_allowing_legacy_future($requested, '2026-08', $counter),
                'ค่า ' . var_export($requested, true) . ' ไม่ควรเปลี่ยนเดือนที่เลือก'
            );
        }

        $this->assertSame(0, $asked, 'ไปถามฐานข้อมูลทั้งที่ยังไม่ใช่เดือนอนาคตที่ถูกรูปแบบ');
    }

    /** ⚠️ กติกานี้เคยถูกคัดลอกไว้ 2 ที่ แล้วที่หนึ่งตกสำรวจ */
    public function testNobodyReimplementsTheLegacyFutureMonthRule(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/*.php'),
            (array)glob($root . '/api/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            $code = (string)file_get_contents((string)$file);
            if (preg_match('/\$requestedMonth\s*>\s*\$selectedMonth/', $code) === 1) {
                $offenders[] = basename((string)$file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'เขียนกติกา "เดือนอนาคตที่มีข้อมูล" ซ้ำใน: ' . implode(', ', $offenders)
            . ' — ให้เรียก resolve_month_allowing_legacy_future() แทน'
        );
    }

    /**
     * ⭐⭐ การแปลง "ข้อความ error → รหัสสถานะ HTTP" ต้องมีที่เดียว
     *
     * ⚠️ เดิมมี 2 วิธีปนกัน: `infer_http_status_from_error()` (8 จุด ใน 3 ไฟล์)
     * กับ inline `str_contains($err, 'ไม่มีสิทธิ์') ? 403 : 422` (7 จุด ใน 5 ไฟล์)
     * — `api/export.php` กับ `api/export-xlsx.php` ทำงานคู่กันแต่ใช้คนละวิธี
     *
     * ⚠️ **ตอนที่เจอ ทั้งสองวิธีให้ผลเท่ากันในทางปฏิบัติ** เพราะ service ที่ endpoint
     * กลุ่ม inline เรียกใช้ คืนเฉพาะข้อความที่มีคำว่า "ไม่มีสิทธิ์" เท่านั้น
     * (ตรวจแล้ว: มีแต่ `ProfileService` ที่คืน "Unauthorized" และ `api/profile.php`
     * ไม่ได้ใช้ inline) — ปัญหาคือ**กับดักในอนาคต**: วันที่ service ตัวใดคืนข้อความ
     * ที่ helper รู้จัก (Unauthorized → 401 · วิธีเรียกหน้านี้ไม่ถูกต้อง → 405 ·
     * หมดเวลาทำรายการ → 403) endpoint กลุ่ม inline จะตอบ 422 เงียบ ๆ ผิดความหมาย
     */
    public function testNobodyMapsErrorsToStatusCodesByHand(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge((array)glob($root . '/api/*.php'), (array)glob($root . '/*.php'));

        $offenders = [];
        foreach ($files as $file) {
            $code = (string)file_get_contents((string)$file);
            if (preg_match('/str_contains\([^)]*ไม่มีสิทธิ์[^)]*\)\s*\?\s*403/u', $code) === 1) {
                $offenders[] = basename((string)$file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'แปลง error เป็นรหัสสถานะเองใน: ' . implode(', ', $offenders)
            . ' — ให้เรียก infer_http_status_from_error() แทน (มีอยู่แล้วและรู้จัก 401/403/405)'
        );
    }

    /**
     * ⭐⭐ Service ห้ามเขียน SQL เอง — ข้อมูลต้องผ่าน Repository
     *
     * ⚠️ กฎนี้เขียนไว้ใน CLAUDE.md ("Repository = SQL ล้วน") แต่ไม่เคยมีอะไรบังคับ
     * ผลคือ `AuthService` เขียน SQL ของตาราง `auth_rate_limits` เอง 8 จุด
     * ขณะที่ `RateLimitRepository` ที่ทำเรื่องเดียวกันมีอยู่แล้ว (แต่ถูกใช้แค่โดย
     * `ProfileService`) → มี 2 ที่ที่รู้โครงสร้างตารางเดียวกัน แก้ที่หนึ่งลืมอีกที่ได้
     *
     * ⚠️⚠️ `AuthService` ถูกยกเว้นไว้ **ชั่วคราว** — การย้าย SQL ออกไม่ใช่งานกลไก
     * เพราะตัวมันมี fallback ไปนับใน session เมื่อตารางไม่พร้อม ซึ่งเป็น "นโยบาย"
     * ไม่ใช่ "การเข้าถึงข้อมูล" · ต้องตัดสินใจก่อนว่าจะวางชั้นนั้นไว้ตรงไหน
     * **ห้ามเพิ่มชื่อไฟล์ใหม่เข้ารายการยกเว้นนี้** — รายการนี้มีไว้ให้สั้นลง ไม่ใช่ยาวขึ้น
     */
    public function testServicesDoNotWriteSqlThemselves(): void
    {
        $knownException = 'AuthService.php';

        $offenders = [];
        foreach ((array)glob(dirname(__DIR__, 2) . '/app/Services/*.php') as $file) {
            $name = basename((string)$file);
            if ($name === $knownException) {
                continue;
            }

            $code = (string)file_get_contents((string)$file);
            $sqlCall = '/->(prepare|query|exec)\s*\(\s*[\x27"]?\s*(SELECT|INSERT|UPDATE|DELETE|\$sql)/i';
            if (preg_match($sqlCall, $code) === 1) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Service เขียน SQL เองใน: ' . implode(', ', $offenders)
            . ' — ให้ย้ายไป Repository ตามกฎในคู่มือ'
        );
    }

    /**
     * ⚠️⚠️ สีเทาที่จางเกินไปห้ามใช้กับข้อความที่ต้องอ่าน
     *
     * คู่มือห้าม `text-slate-500` ไว้แล้ว (วัดได้ 3.69:1 เกณฑ์ 4.5) แต่ห้ามไว้เป็น
     * "ตัวหนังสือในเอกสาร" เฉย ๆ ไม่มีอะไรบังคับ · ผลคือ **`text-slate-600` ซึ่งจางกว่านั้นอีก
     * หลุดเข้ามา 8 จุด** โดยไม่มีอะไรทัก
     *
     * วัดจากสีที่เรนเดอร์จริงบนเบราว์เซอร์: `text-slate-600` = rgb(71,85,105)
     * บนพื้นการ์ด rgb(11,23,57) ได้ **2.32 : 1** ซึ่งต่ำกว่าครึ่งของเกณฑ์
     * จุดที่โดนหนักสุดคือเครื่องหมาย "ไม่มีข้อมูล" ในกริดฤดูกาล 16 ช่อง —
     * ช่องที่บอกว่า "ปีนั้นยังไม่มีข้อมูล" กลายเป็นช่องที่มองแทบไม่เห็น
     *
     * ⚠️ เทสต์นี้กวาด "ชื่อคลาส" ไม่ได้วัดสีจริง — ถ้าวันหนึ่งเปลี่ยนชุดสีของ Tailwind
     * ต้องกลับมาวัดใหม่ด้วยเบราว์เซอร์ ไม่ใช่เชื่อรายชื่อนี้อย่างเดียว
     */
    public function testNobodyUsesGreyTooFaintToRead(): void
    {
        $banned = ['text-slate-500', 'text-slate-600', 'text-slate-700', 'text-gray-500', 'text-gray-600'];

        $offenders = [];
        foreach ($this->everyPhpFileThatRendersHtml() as $file) {
            $code = (string)file_get_contents($file);
            foreach ($banned as $class) {
                if (preg_match('/\b' . preg_quote($class, '/') . '\b/', $code) === 1) {
                    $offenders[] = basename($file) . ' → ' . $class;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ใช้สีเทาที่จางเกินเกณฑ์คอนทราสต์: ' . implode(', ', $offenders)
            . ' — ใช้ text-slate-400 แทน (ดูหัวข้อ UI ในคู่มือ)'
        );
    }

    /**
     * ⚠️⚠️ ปุ่มที่ตัวหนังสือเป็นสีขาว พื้นต้องเข้มพอ
     *
     * ⚠️⚠️ **รอบแรกตกสำรวจทั้งชุด** เพราะเครื่องวัดคอนทราสต์อ่าน `background-color`
     * ซึ่งของ element ที่ใช้ "พื้นไล่สี" จะเป็นโปร่งใสเสมอ → เครื่องวัดเดินผ่านปุ่มทั้งใบ
     * ไปใช้สีการ์ดข้างหลังแทน แล้วรายงานว่าผ่าน ทั้งที่ตัวอักษรอยู่บนสีสด
     *
     * วัดจริงจากสีที่เรนเดอร์ (หลังแก้เครื่องวัดให้อ่าน `background-image` ด้วย):
     *   ปุ่มหลักสีส้ม "บันทึกข้อมูล" 2.80:1 · ฟ้า 2.43:1 · แดง 3.76:1 · ม่วง 4.23:1
     * ทั้งสี่ตกเกณฑ์ 4.5 — และตัวที่แย่ที่สุดคือปุ่มที่ผู้ใช้กดทุกวัน
     *
     * ⚠️ เทสต์นี้คำนวณคอนทราสต์จริงจากเลขสีใน CSS ไม่ได้เทียบกับรายชื่อสีที่อนุญาต
     * เปลี่ยนเป็นสีอะไรก็ได้ ขอแค่ผ่านเกณฑ์
     */
    public function testWhiteTextOnColouredButtonsIsDarkEnough(): void
    {
        $root = dirname(__DIR__, 2);
        $palette = (string)file_get_contents($root . '/includes/brand-colors.php');

        preg_match_all('/--btn-([a-z]+)-(from|to):\s*#([0-9a-f]{6})/i', $palette, $vars, PREG_SET_ORDER);
        $this->assertNotEmpty($vars, 'ไม่พบนิยามสีปุ่มใน includes/brand-colors.php');

        $offenders = [];
        foreach ($vars as [$_, $name, $end, $hex]) {
            $ratio = $this->contrastWithWhite($hex);
            if ($ratio < 4.5) {
                $offenders[] = sprintf('--btn-%s-%s #%s = %.2f:1', $name, $end, $hex, $ratio);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ตัวหนังสือขาวบนพื้นที่สว่างเกินเกณฑ์ 4.5:1 — ' . implode(' | ', $offenders)
        );
    }

    /**
     * ⚠️⚠️⚠️ ห้ามไฟล์ไหนเขียนสีปุ่มเอง — ต้องอ้าง `var(--btn-…)` จาก `brand-colors.php`
     *
     * ⚠️⚠️ **เทสต์ตัวบนเคยมีรูโหว่ตรงนี้พอดี** — มันอ่านแค่ `includes/header.php`
     * ไฟล์เดียว จึง **เขียวอยู่ทั้งที่ปุ่ม "เข้าสู่ระบบ" ยังตกเกณฑ์ที่ 2.80 : 1**
     * เพราะ `login.php` · `forgot-password.php` · `reset-password.php` ไม่ได้ใช้ header.php
     * (มี `<head>` ของตัวเอง) แล้วนิยาม `.btn-orange` ซ้ำไว้เองด้วยสีชุดเก่า
     *
     * นี่คือรูปแบบที่คู่มือเตือนไว้ทั้งเล่ม — "กติกาถูกบังคับใช้ที่หนึ่งแต่ไปไม่ถึงอีกที่หนึ่ง" —
     * และรอบนั้นเกิดกับ **ตัวกวาดที่เขียนขึ้นมาเพื่อกันเรื่องนี้โดยเฉพาะ**
     */
    public function testNobodyWritesButtonColoursByHand(): void
    {
        $offenders = [];
        foreach ($this->everyPhpFileThatRendersHtml() as $file) {
            if (basename($file) === 'brand-colors.php') {
                continue;
            }

            $code = $this->codeWithoutComments((string)file_get_contents($file));
            foreach (['btn-primary', 'btn-orange', 'btn-teal', 'btn-danger'] as $name) {
                if (preg_match('/\.' . $name . '\s*\{(.*?)\}/s', $code, $rule) !== 1) {
                    continue;
                }

                $background = (string)preg_replace('/box-shadow[^;]*;/', '', $rule[1]);
                if (preg_match('/background:[^;]*#[0-9a-f]{6}/i', $background) === 1) {
                    $offenders[] = basename($file) . ' → .' . $name;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'เขียนสีปุ่มเองแทนที่จะใช้ var(--btn-…) จาก includes/brand-colors.php: '
            . implode(' | ', $offenders)
        );
    }

    /** คอนทราสต์ของสีหนึ่งกับสีขาว ตามสูตร WCAG 2.x */
    private function contrastWithWhite(string $hex): float
    {
        $channel = static function (int $value): float {
            $v = $value / 255;

            return $v <= 0.03928 ? $v / 12.92 : (float)(((($v + 0.055) / 1.055)) ** 2.4);
        };

        $r = $channel((int)hexdec(substr($hex, 0, 2)));
        $g = $channel((int)hexdec(substr($hex, 2, 2)));
        $b = $channel((int)hexdec(substr($hex, 4, 2)));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return 1.05 / ($luminance + 0.05);
    }

    /**
     * ⚠️⚠️ ทุกช่องที่ผู้ใช้กรอกต้องมีชื่อให้โปรแกรมอ่านหน้าจอประกาศ
     *
     * วัดจริงก่อนแก้: จาก 27 ช่องทั้งระบบ มี **1 ช่องที่ไม่มีชื่อเลย** คือช่องเลือกไฟล์ CSV
     * ในหน้าบันทึก — โปรแกรมอ่านหน้าจอพูดแค่ "เลือกไฟล์" โดยไม่บอกว่าไฟล์อะไร
     *
     * ⚠️ นับ `<label for>` · `aria-label` · `aria-labelledby` เป็นชื่อที่ใช้ได้
     * แต่ **ไม่นับ `placeholder`** เพราะมันหายทันทีที่เริ่มพิมพ์
     */
    public function testEveryInputTheUserTypesIntoHasAName(): void
    {
        $offenders = [];
        foreach ($this->everyPhpFileThatRendersHtml() as $file) {
            $code = (string)file_get_contents($file);

            /* ⚠️ ต้องตัดโค้ด PHP แบบเดียวกับตอนหาแท็ก ไม่งั้น id ที่สร้างจากตัวแปร
               จะเทียบกับ `for` ไม่ติด แล้วรายงานว่าช่องนั้นไม่มีชื่อทั้งที่มี */
            $labelledIds = [];
            if (preg_match_all('/<label[^>]*\bfor="([^"]+)"/i', $this->markupOnly($code), $labels) > 0) {
                $labelledIds = $labels[1];
            }

            foreach ($this->htmlTagsIn($file, 'input|select|textarea') as $tag) {
                if (preg_match('/type="(hidden|submit|button|image)"/i', $tag) === 1) {
                    continue;
                }

                if (preg_match('/aria-label(ledby)?="/i', $tag) === 1) {
                    continue;
                }

                preg_match('/\bid="([^"]+)"/i', $tag, $idMatch);
                $id = $idMatch[1] ?? '';
                if ($id !== '' && in_array($id, $labelledIds, true)) {
                    continue;
                }

                $offenders[] = basename($file) . ' → ' . mb_substr(preg_replace('/\s+/', ' ', $tag) ?? '', 0, 60);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ช่องกรอกที่ไม่มีชื่อให้โปรแกรมอ่านหน้าจอ: ' . implode(' | ', $offenders)
        );
    }

    /**
     * ⚠️⚠️ ช่องอีเมล/รหัสผ่านต้องบอกเบราว์เซอร์ว่าเป็นช่องอะไร
     *
     * วัดจริงก่อนแก้: `profile.php` ใส่ `autocomplete` ครบถูกต้อง แต่ `login.php`
     * (ทั้งแท็บเข้าสู่ระบบและสมัครสมาชิก) กับ `forgot-password.php` **ไม่มีเลยสักช่อง**
     * — หน้าที่ผู้ใช้เข้าบ่อยที่สุดกลับเป็นหน้าที่โปรแกรมจำรหัสผ่านช่วยไม่ได้
     * ผลคือต้องพิมพ์อีเมลกับรหัสผ่านเองทุกครั้งบนมือถือ
     */
    public function testPasswordAndEmailFieldsTellTheBrowserWhatTheyAre(): void
    {
        $offenders = [];
        foreach ($this->everyPhpFileThatRendersHtml() as $file) {
            foreach ($this->htmlTagsIn($file, 'input') as $tag) {
                if (preg_match('/type="(email|password)"/i', $tag) !== 1) {
                    continue;
                }

                if (preg_match('/\bautocomplete="/i', $tag) === 1) {
                    continue;
                }

                /* ช่องที่ถูกปิดไว้ให้ดูเฉย ๆ (เช่นอีเมลปัจจุบันในหน้าโปรไฟล์) ไม่ได้รับค่าจากผู้ใช้ */
                if (preg_match('/\b(disabled|readonly)\b/i', $tag) === 1) {
                    continue;
                }

                $offenders[] = basename($file) . ' → ' . mb_substr(preg_replace('/\s+/', ' ', $tag) ?? '', 0, 60);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ช่องอีเมล/รหัสผ่านที่ไม่มี autocomplete: ' . implode(' | ', $offenders)
        );
    }

    /**
     * ⚠️⚠️ หน้าต่างซ้อนต้องเรียกกติกากลาง ห้ามเขียนการดักโฟกัส/Escape ขึ้นใหม่เอง
     *
     * `setupAccessibleModal()` ใน `includes/header.php` เป็นจุดเดียวที่ทำ 4 อย่าง:
     * ย้ายโฟกัสเข้า · ขังโฟกัสไว้ข้างใน · Escape ปิด · ปิดแล้วคืนโฟกัสกลับที่เดิม
     *
     * วัดจริงก่อนมี helper (กดแป้นพิมพ์จริง หน้าประวัติเดือนที่มี 31 รายการ):
     * กด "ลบ" แล้วโฟกัสค้างอยู่หลังฉากมืด ต้องกด Tab อีก **67 ครั้ง** กว่าจะถึงปุ่มยืนยัน
     * และ 60 ครั้งในนั้นเป็นปุ่มลบของรายการอื่นที่มองไม่เห็น
     *
     * ⚠️ เทสต์นี้แดงแม้โค้ดที่เขียนซ้ำจะทำงานถูกต้อง — เพราะปัญหาอยู่ที่วันที่มีคน
     * แก้ที่หนึ่งแล้วอีกที่ไม่ตาม (หลักเดียวกับ testNobodyReimplementsTheUnfinishedMonthRule)
     */
    public function testEveryModalUsesTheSharedKeyboardRules(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string)file_get_contents($root . '/includes/header.php');
        $this->assertStringContainsString(
            'const setupAccessibleModal',
            $helper,
            'ไม่พบกติกากลางของหน้าต่างซ้อนใน includes/header.php'
        );

        $offenders = [];
        foreach ($this->everyPhpFileThatRendersHtml() as $file) {
            $name = basename($file);
            if ($name === 'header.php') {
                continue;
            }

            /* ⚠️⚠️ ต้องตัดคอมเมนต์ทิ้งก่อนตรวจ — คอมเมนต์ที่อธิบายกติกามักเอ่ยชื่อ
               `setupAccessibleModal()` อยู่แล้ว · วัดจริงตอนยังไม่ตัด: ถอดการเรียกจริง
               ออกทั้ง 3 บรรทัด เทสต์ยัง **เขียว** เพราะไปเจอชื่อในคอมเมนต์ที่อยู่เหนือมันพอดี */
            $code = $this->codeWithoutComments((string)file_get_contents($file));
            if (preg_match('/id="[a-z-]*modal"/i', $code) !== 1) {
                continue;
            }

            if (preg_match('/\bsetupAccessibleModal\s*\(/', $code) !== 1) {
                $offenders[] = $name . ' → มีหน้าต่างซ้อนแต่ไม่ได้เรียก setupAccessibleModal()';
            }

            /* ⚠️ ต้องรับทั้งเครื่องหมายคำพูดเดี่ยวและคู่ — เขียนไว้แบบเดียวแล้ววัดจริง
               พบว่ามิวเทชันที่ใช้ `"Escape"` หลุดไปได้ทั้งที่เป็นการเขียนกติกาซ้ำเหมือนกัน */
            if (preg_match('/key\s*===\s*[\x27"]Escape[\x27"]/', $code) === 1) {
                $offenders[] = $name . ' → เขียนตัวดัก Escape เอง (ต้องใช้ของกลาง)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            implode(' | ', $offenders)
        );
    }

    /**
     * ดึงแท็ก HTML ออกมาจากไฟล์ PHP
     *
     * ⚠️⚠️ ต้อง **ตัดโค้ด PHP ทิ้งก่อน** แล้วค่อยหาแท็ก ไม่ใช่ทางกลับกัน
     * `<?= e($v) ?>` มีเครื่องหมาย `>` อยู่ข้างใน — regex ที่หาแท็กตรง ๆ จะคิดว่าแท็กจบตรงนั้น
     * แล้วตัดครึ่งแท็กทิ้ง · วัดจริง: ตัวกวาดเวอร์ชันแรกรายงานว่ามี 8 ช่องไม่มีชื่อ
     * ทั้งที่เปิดหน้าจริงบนเบราว์เซอร์แล้วทุกช่องมีชื่อครบ — **ตัวกวาดผิด ไม่ใช่โค้ดผิด**
     * (บทเรียนเดียวกับ BrowserScriptParityTest ที่ต้องตัดคอมเมนต์ก่อนตัดสตริง)
     *
     * ⚠️ และต้องยอมให้แท็กขึ้นหลายบรรทัด — โปรเจกต์นี้เขียน `<input` กระจายบรรทัดเป็นปกติ
     *
     * @return list<string>
     */
    private function htmlTagsIn(string $file, string $tagNames): array
    {
        $code = $this->markupOnly((string)file_get_contents($file));

        if (preg_match_all('/<(' . $tagNames . ')\b[^>]*>/is', $code, $matches) === 0) {
            return [];
        }

        return array_values($matches[0]);
    }

    /**
     * เหลือไว้เฉพาะ HTML ที่ผู้ใช้ได้รับจริง
     *
     * ⚠️ ทั้งสามขั้นนี้จำเป็นทั้งหมด — ตัดขั้นไหนออกก็ได้ผลลวง (ลองมาแล้วทีละขั้น):
     *  1. `<script>` — คอมเมนต์ JS ในโปรเจกต์นี้พูดถึง `<input type="number">` ตรง ๆ
     *     (เป็นกติกาที่ต้องเตือนคนอ่านโค้ด) ถ้าไม่ตัด ตัวกวาดจะนับคอมเมนต์เป็นช่องกรอก
     *  2. โค้ด PHP — ⚠️⚠️ **ต้องยอมให้ไม่มี `?>` ปิดท้าย** เพราะไฟล์ที่เป็น PHP ล้วน
     *     (เช่น `includes/functions.php`) เปิด `<?php` บรรทัดแรกแล้วไม่ปิดเลยทั้งไฟล์
     *     regex ที่บังคับให้มี `?>` จะไม่ตัดอะไรเลย แล้ว docblock ทั้งไฟล์ถูกนับเป็น HTML
     *  3. แทนที่ด้วย `__PHP__` ไม่ใช่ช่องว่าง — `id="shop-name-<?= $id ?>"` กับ
     *     `for="shop-name-<?= $id ?>"` ต้องยังเทียบกันติดหลังตัด ไม่งั้นช่องที่ id
     *     สร้างจากตัวแปรจะถูกหาว่าไม่มีชื่อทั้งที่มี
     */
    /**
     * ตัดคอมเมนต์ทุกแบบที่โปรเจกต์นี้ใช้ออก เหลือไว้แต่โค้ดที่ทำงานจริง
     *
     * ⚠️ ตัวกวาดที่ตรวจว่า "เรียกกติกากลางหรือยัง" จะถูกคอมเมนต์หลอกได้ง่ายมาก
     * เพราะคอมเมนต์ที่ดีมักอ้างชื่อฟังก์ชันที่มันอธิบายอยู่ — วัดจริงแล้วเจอ (ดูที่จุดเรียกใช้)
     */
    private function codeWithoutComments(string $code): string
    {
        $code = (string)preg_replace('#/\*.*?\*/#s', ' ', $code);
        $code = (string)preg_replace('#^\s*//.*$#m', ' ', $code);

        return (string)preg_replace('/<!--.*?-->/s', ' ', $code);
    }

    private function markupOnly(string $code): string
    {
        $code = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $code);
        $code = (string)preg_replace('/<\?(php|=)(.*?)(\?>|$)/s', '__PHP__', $code);

        return (string)preg_replace('/<!--.*?-->/s', ' ', $code);
    }

    /**
     * ทุกไฟล์ PHP ที่พ่น HTML ออกไปหาผู้ใช้ — หน้าเว็บที่รากกับไฟล์ include
     *
     * ⚠️ กวาดจากดิสก์จริง ไม่ใช่รายชื่อที่พิมพ์ไว้ตายตัว — เพิ่มหน้าใหม่แล้วลืมตรวจ
     * จะไม่มีวันรู้ (บทเรียนเดียวกับ SchemaGuardTest ที่เคยเทียบกับรายชื่อตายตัว)
     *
     * @return list<string>
     */
    private function everyPhpFileThatRendersHtml(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/*.php'),
            (array)glob($root . '/includes/*.php')
        );

        $out = [];
        foreach ($files as $file) {
            $path = (string)$file;
            $code = (string)file_get_contents($path);
            if (preg_match('/<(input|select|textarea|button|main|div|table)\b/i', $code) === 1) {
                $out[] = $path;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * ⭐⭐⭐ **ตัวกวาด** — กำไรที่คำนวณจากการลบ ต้องผ่าน `money_total()` ทุกจุด
     *
     * ⚠️⚠️ กฎเดิมในคู่มือเขียนไว้ว่า "ยอดรวมที่**บวก**ต้องปัดสตางค์" — จุดที่ตกสำรวจจึงเป็น
     * `รายได้ − ค่าแอด` ซึ่งเป็นที่มาของกำไรทั้งระบบ · **เขียนกฎไว้แล้วไม่มีใครไล่ตรวจ
     * ว่ามีที่อื่นละเมิดอยู่ไหม คือรูปแบบความผิดพลาดที่เกิดซ้ำที่สุดในโปรเจกต์นี้**
     *
     * วัดจริง: `0.30 − 0.20` กับ `0.20 − 0.10` แสดงเป็น ฿0.10 เท่ากันบนจอ แต่โปรแกรม
     * มองว่าต่างกัน → วันเดียวกันโผล่เป็นทั้ง "วันกำไรดีสุด" และ "วันกำไรแย่สุด"
     *
     * ⚠️ ตัวกวาดต้องข้าม `->` (ลูกศรเรียกเมธอด) ไม่งั้นจะนับว่าเป็นเครื่องหมายลบ
     * — เขียนรอบแรกแล้วได้ผลบวกลวง 2 บรรทัด
     */
    public function testEveryProfitSubtractionIsRoundedToSatang(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/app/Services/*.php'),
            (array)glob($root . '/includes/*.php'),
            (array)glob($root . '/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            /* ⚠️⚠️ ต้องตัด **คอมเมนต์และสตริง** ก่อนเสมอ — บทเรียนที่โปรเจกต์นี้จดไว้แล้ว
               และตัวกวาดตัวนี้เองก็เกือบพลาดซ้ำ: `'text-green-400'` มีขีดกลางอยู่ข้างใน
               จึงถูกนับว่าเป็นเครื่องหมายลบ (วัดจริง — ได้ผลบวกลวงจาก dashboard.php) */
            $source = $this->codeWithoutComments((string)file_get_contents((string)$file));

            $lines = explode("\n", $source);
            foreach ($lines as $number => $line) {
                $code = trim($line);
                if ($code === '') {
                    continue;
                }

                /* ⚠️⚠️ ตรวจ **สองชั้นจากคนละร่าง** ของบรรทัดเดียวกัน:
                   · "นี่คือการตั้งค่าให้กำไรหรือเปล่า" ดูจากบรรทัดเดิม — เพราะรูปแบบ
                     `'profit' => …` ใช้ชื่อคีย์ที่เป็นสตริง ถ้าลบสตริงทิ้งก่อนจะมองไม่เห็นเลย
                     (เขียนรอบแรกแบบนั้น แล้วมิวเทชันพิสูจน์ว่าตัวกวาด **จับไม่ได้**)
                   · "มีเครื่องหมายลบไหม" ดูจากร่างที่ลบสตริงและลูกศรออกแล้ว — เพราะสตริง
                     อย่าง `'text-green-400'` มีขีดกลางอยู่ข้างใน และ `->` ก็หน้าตาเหมือนลบ */
                if (preg_match('/(\$[A-Za-z_]*[Pp]rofit[A-Za-z_]*|\x27profit\x27)\s*(=>|=[^=>])/', $code) !== 1) {
                    continue;
                }

                $withoutText = (string)preg_replace('/([\x27"])(?:\\\\.|(?!\\1).)*\\1/s', 'TEXT', $code);
                if (!str_contains(str_replace('->', '', $withoutText), '-')) {
                    continue;
                }

                if (str_contains($code, 'money_total(') || str_contains($code, 'change_percent(')) {
                    continue;
                }

                $offenders[] = basename((string)$file) . ':' . ($number + 1) . '  ' . $code;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "กำไรที่คำนวณจากการลบ แต่ไม่ได้ปัดเป็นสตางค์:\n  " . implode("\n  ", $offenders)
            . "\n\n(เศษทศนิยมของ PHP จะไปตัดสินว่าวันไหน/เดือนไหน/ร้านไหนดีกว่ากัน"
            . ' ทั้งที่ตัวเลขบนจอเท่ากันเป๊ะ — ให้ห่อด้วย money_total())'
        );
    }

    /**
     * ⭐⭐⭐ **ตัวกวาด** — เทสต์ที่ชื่อบอกว่า "เป๊ะ" ห้ามยอมรับความคลาดเคลื่อน
     *
     * ⚠️⚠️ **เกิดขึ้นจริงและปล่อยบั๊กหลุดไปแล้ว** — เทสต์ชื่อ `...AddsUpToExactlyOneHundred()`
     * ใช้ `assertEqualsWithDelta(100.0, $sum, 0.05)` จึงปล่อย **99.99** ผ่านมาตลอด
     * ทั้งที่ทั้งชื่อเทสต์และกติกาบอกว่าต้องเป๊ะ · ซ้ำร้ายข้อมูลทดสอบยังเป็น 2 ร้าน
     * ที่หารลงตัวพอดี ค่า delta จึงไม่เคยมีโอกาสทำงานเลยด้วยซ้ำ
     *
     * **เทสต์แบบนี้แย่กว่าไม่มีเทสต์** เพราะมันทำให้คนอ่านคิดว่าตรงนั้นถูกคุมไว้แล้ว
     *
     * ⚠️ ห้ามใช้กับทุกที่ — การเทียบทศนิยมด้วย delta ถูกต้องในหลายกรณี (เช่นค่าเฉลี่ย)
     * ตัวกวาดนี้จับเฉพาะเทสต์ที่ **ชื่อประกาศว่าเป๊ะ** หรือ **เทียบกับ 100 ตรง ๆ**
     * ซึ่งคือสัญญาที่ delta ทำให้เป็นเท็จ
     */
    public function testNoTestClaimsExactnessWhileAllowingATolerance(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/tests/Unit/*.php'),
            (array)glob($root . '/tests/Integration/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            /* ⚠️ ตัดคอมเมนต์ก่อน — docblock ของตัวกวาดตัวนี้เองยกตัวอย่างโค้ดที่ห้ามเขียน
               ไว้เต็ม ๆ ถ้าไม่ตัด มันจะรายงานตัวเองเป็นผู้กระทำผิด (เกิดขึ้นจริงตอนเขียน) */
            $code = $this->codeWithoutComments((string)file_get_contents((string)$file));
            $chunks = preg_split('/(?=public function test)/', $code);
            if (!is_array($chunks)) {
                continue;
            }

            foreach ($chunks as $chunk) {
                if (preg_match('/public function (test\w+)/', $chunk, $match) !== 1) {
                    continue;
                }

                if (!str_contains($chunk, 'assertEqualsWithDelta')) {
                    continue;
                }

                /* ⚠️ ชื่อต้องแคบพอ — คำว่า "exactly" ในภาษาอังกฤษมักขยายฉากทดสอบ
                   ("เดือนที่กรอกครึ่งเดือนพอดี" · "ทำตามคำแนะนำเป๊ะ ๆ") ไม่ได้ประกาศว่า
                   การเทียบต้องเป๊ะ · จับกว้างแล้วได้ผลบวกลวง 2 ตัวทันที (วัดแล้ว)
                   ⚠️ ข้อจำกัดที่ยอมรับ: ชื่ออื่นที่ประกาศความเป๊ะแบบไม่ตรงรูปนี้ยังหลุดได้
                      — ด่านหลักคือกฎ "ห้ามเทียบกับ 100 ด้วยค่าคลาดเคลื่อน" ข้างล่าง */
                $nameClaimsExactness = preg_match('/ExactlyOneHundred|ExactlySame|ExactValue/i', $match[1]) === 1;
                $comparesToOneHundred = preg_match('/assertEqualsWithDelta\(\s*100(\.0)?\s*,/', $chunk) === 1;

                if ($nameClaimsExactness || $comparesToOneHundred) {
                    $offenders[] = basename((string)$file) . '::' . $match[1] . '()';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "เทสต์ที่ชื่อบอกว่า \"เป๊ะ\" (หรือเทียบกับ 100) แต่ยอมให้คลาดเคลื่อนได้:\n  "
            . implode("\n  ", $offenders)
            . "\n\n(ใช้ assertSame กับค่าที่ปัดแล้วแทน — ไม่งั้นเทสต์จะรายงานว่าผ่าน"
            . ' ทั้งที่ผลรวมได้ 99.99 ซึ่งคือบั๊กที่มันควรจับ)'
        );
    }

    /**
     * ⭐⭐ **ตัวกวาด** — ห้ามเขียน `$x['key'] ?? 'อะไรก็ตาม'` แล้ว `assertNull()`
     *
     * ⚠️⚠️ `??` ถือว่า `null` คือ "ไม่มีค่า" จึงคืน **ตัวสำรอง** แทน `null` ที่กำลังจะตรวจพอดี
     * → เทสต์แดงทั้งที่โค้ดถูก · เขียนพลาดมาแล้วหลายรอบในโปรเจกต์นี้
     * · วิธีที่ถูก: `assertArrayHasKey()` ก่อน แล้วค่อย `assertNull()` กับค่าตรง ๆ
     */
    public function testNobodyChecksForNullThroughACoalescingFallback(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            (array)glob($root . '/tests/Unit/*.php'),
            (array)glob($root . '/tests/Integration/*.php')
        );

        $offenders = [];
        foreach ($files as $file) {
            $code = $this->codeWithoutComments((string)file_get_contents((string)$file));

            /* รูปแบบ: assertNull( … ?? … ) — ไม่ว่าจะขึ้นบรรทัดใหม่หรือไม่
               ⚠️ `?? null` ไม่นับผิด เพราะให้ผลเหมือนไม่มี `??` */
            if (preg_match_all('/assertNull\(\s*[^;]*?\?\?\s*(?!null)/s', $code, $matches) < 1) {
                continue;
            }

            $offenders[] = basename((string)$file);
        }

        $this->assertSame(
            [],
            $offenders,
            'ตรวจ null ผ่าน `??` ใน: ' . implode(', ', $offenders)
            . ' — `??` จะคืนตัวสำรองแทน null ที่กำลังจะตรวจ ทำให้เทสต์แดงทั้งที่โค้ดถูก'
            . ' (ใช้ assertArrayHasKey ก่อน แล้วค่อย assertNull)'
        );
    }

    /**
     * ⭐⭐ `format_share_percent()` — สัดส่วนกำไรต้องแสดง **สองตำแหน่ง**
     *
     * ⚠️⚠️ คอลัมน์นี้เป็นคอลัมน์เดียวที่ "ผลรวมมีความหมาย" · ค่าที่คำนวณไว้รวมกันได้
     * 100.00 พอดี แต่ถ้าแสดงตำแหน่งเดียว 3 ร้านเท่ากันจะกลายเป็น **33.3 × 3 = 99.9%**
     * บนหน้าจอ — คนอ่านบวกตามที่เห็นแล้วไม่ครบ · ไฟล์ Excel ใช้สองตำแหน่งอยู่แล้ว
     *
     * @return array<string,array{0:?float,1:string}>
     */
    public static function shareDisplayProvider(): array
    {
        return [
            'สามร้านเท่ากัน — ตัวที่ได้เศษ' => [33.34, '33.34%'],
            'สามร้านเท่ากัน — อีกสองตัว' => [33.33, '33.33%'],
            'เล็กมากแต่ไม่ใช่ศูนย์' => [0.01, '0.01%'],
            'เล็กจนสองตำแหน่งยังไม่พอ' => [0.001, '<0.01%'],
            'ศูนย์จริง' => [0.0, '0.00%'],
            'ไม่มีค่า' => [null, '–'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shareDisplayProvider')]
    public function testProfitShareIsShownWithTwoDecimals(?float $value, string $expected): void
    {
        $this->assertSame($expected, format_share_percent($value));
    }

    /**
     * ⭐⭐⭐ สามร้านที่กำไรเท่ากัน — ตัวเลขที่ **ผู้ใช้เห็น** ต้องบวกได้ 100.00
     *
     * ⚠️ เทสต์ที่ตรวจแค่ค่าดิบผ่านมาแล้ว (`distribute_profit_share()` ถูกต้อง)
     * แต่สิ่งที่ผู้ใช้บวกคือ **ข้อความบนจอ** ไม่ใช่ค่าในหน่วยความจำ
     */
    public function testWhatTheUserSeesAlsoAddsUpToOneHundred(): void
    {
        $shares = distribute_profit_share([1000.0, 1000.0, 1000.0], 3000.0);

        $shown = array_map(
            static fn(?float $share): float => (float)str_replace('%', '', format_share_percent($share)),
            $shares
        );

        $this->assertSame(
            100.0,
            round(array_sum($shown), 2),
            'ตัวเลขที่ผู้ใช้เห็นบวกกันแล้วไม่ได้ 100.00: [' . implode(', ', array_map(
                static fn(?float $share): string => format_share_percent($share),
                $shares
            )) . ']'
        );
    }

    /**
     * ⭐⭐⭐ ร้านที่มีกำไร (หรือขาดทุน) จริง **ห้ามลงเอยที่ 0.00%**
     *
     * ⚠️⚠️ การปัดลงเป็น 2 ตำแหน่งทำให้สัดส่วนที่เล็กกว่า 0.005% กลายเป็น 0.00 เป๊ะ
     * และถ้าเศษของมันแพ้แถวอื่นตอนแจก มันจะค้างที่ 0.00 ตลอด
     * · วัดจริง: ร้านเล็กกำไร ฿0.20 คู่กับร้านใหญ่ ฿408,000 → หน้าจอพิมพ์ **"0.00%"**
     *   ผู้ใช้อ่านว่า "ร้านนี้ไม่ทำกำไรเลย" ทั้งที่คอลัมน์กำไรข้าง ๆ บอกว่ามี
     * · ด่าน `<0.01%` ใน `format_share_percent()` เช็ก `!== 0.0` จึงไม่มีวันทำงาน
     *   เมื่อค่าถูกปัดจนเป็นศูนย์เป๊ะมาก่อนแล้ว
     *
     * ⚠️⚠️ **เทสต์เดิมผ่านเพราะข้อมูลอ่อนไป** — `testATinyShareIsNotReportedAsZero()`
     * ใช้อัตราส่วน 10,000:1 ซึ่งเศษของร้านเล็กยังชนะการแจก · ที่ 2,000,000:1 มันแพ้
     *
     * ⚠️ **ศูนย์จริง ๆ (เท่าทุนพอดี) ต้องยังเป็น 0.00%** — ไม่งั้นการ "แก้" จะกลบความจริง
     *
     * @return array<string,array{0:list<float>,1:float,2:int,3:string}>
     */
    public static function tinyShareProvider(): array
    {
        return [
            'กำไรเล็กมากคู่ร้านใหญ่มาก' => [[0.2, 408000.0], 408000.2, 0, '0.01%'],
            'ขาดทุนเล็กมากคู่ร้านใหญ่มาก' => [[-0.2, 408000.0], 407999.8, 0, '-0.01%'],
            'สองร้านเล็กมากพร้อมกัน' => [[0.2, 0.3, 408000.0], 408000.5, 1, '0.01%'],
            'เท่าทุนพอดี — ต้องเป็นศูนย์จริง' => [[0.0, 1000.0], 1000.0, 0, '0.00%'],
        ];
    }

    /** @param list<float> $profits */
    #[\PHPUnit\Framework\Attributes\DataProvider('tinyShareProvider')]
    public function testAShopWithRealProfitNeverShowsZeroShare(
        array $profits,
        float $total,
        int $indexToCheck,
        string $expected
    ): void {
        $shares = distribute_profit_share($profits, $total);

        $this->assertSame(
            $expected,
            format_share_percent($shares[$indexToCheck]),
            'สัดส่วนที่ผู้ใช้เห็นไม่ตรงกับความจริงของแถวนั้น'
        );

        // สัญญาเดิมต้องไม่เสีย — ผลรวมยังต้องเป็น 100.00 เป๊ะ
        $this->assertSame(
            100.0,
            round(array_sum(array_map(static fn($share): float => (float)$share, $shares)), 2),
            'แก้เรื่องร้านเล็กแล้วผลรวมไม่ได้ 100.00 อีกต่อไป'
        );
    }
}
