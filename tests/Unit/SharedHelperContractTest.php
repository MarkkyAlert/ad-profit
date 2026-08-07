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
            'เปลี่ยนน้อยจนปัดเป็นศูนย์' => [100.05, 100.0, 0.0],
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
            $code = (string)file_get_contents((string)$file);
            if (preg_match('/\/\s*abs\(\$\w+\)\)\s*\*\s*100/', $code) === 1) {
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
}
