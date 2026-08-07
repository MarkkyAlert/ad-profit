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
}
