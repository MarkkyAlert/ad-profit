<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ **ช่องเลือกเดือนทุกช่องต้องมีเดือนไทยกำกับไว้ข้าง ๆ**
 *
 * ⚠️ `<input type="month">` เขียนเดือนเป็นภาษาอังกฤษและปี ค.ศ. เสมอ ("August 2026")
 * ตามภาษาของเบราว์เซอร์ — **แก้ที่ตัวช่องไม่ได้** เป็นข้อจำกัดของช่องมาตรฐาน
 *
 * ผลที่เกิดจริง: ตัวกรองบอก "August 2026" ขณะที่ตาราง/การ์ด/ไฟล์ทั้งหน้าเขียน "ส.ค. 2569"
 * ผู้ใช้จึงต้องแปลงทั้งชื่อเดือนและปีไปมาบนจอเดียวกัน · หน้าบันทึกข้อมูลเขียนกำกับไว้แล้ว
 * ตั้งแต่แรก **หน้ารายงานทั้งสามหน้าตกสำรวจ** ทั้งที่เป็นหน้าที่ใช้ตัวกรองนี้จริงจัง
 *
 * ⚠️⚠️ ตัวกวาดต้องไล่จาก **ไฟล์จริง** ไม่ใช่รายชื่อที่พิมพ์ไว้ตายตัว — เพิ่มช่องเลือกเดือน
 * ในหน้าใหม่แล้วลืมกำกับ ต้องแดงทันทีโดยไม่ต้องมาแก้เทสต์
 */
final class ThaiMonthLabelTest extends ControllerTestCase
{
    /** ชื่อไฟล์หน้าเว็บทั้งหมดที่ราก (ไม่รวมเทสต์/สคริปต์) */
    private function rootPages(): array
    {
        return array_filter(
            (array)glob(dirname(__DIR__, 2) . '/*.php'),
            static fn(string $path): bool => !str_ends_with($path, '/error.php')
        );
    }

    /** ตัด PHP กับสคริปต์ออก เหลือแต่ HTML ที่เบราว์เซอร์ได้รับ */
    private function markupOnly(string $code): string
    {
        $code = (string)preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $code);
        $code = (string)preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $code);

        /* ⚠️ ต้องยอมให้ไม่มีแท็กปิดท้าย (ไฟล์ PHP ล้วนไม่ปิด) และแทนด้วยตัวคั่นที่ไม่ใช่ช่องว่าง
           ⚠️⚠️ คอมเมนต์ตรงนี้ต้องเป็นแบบครอบ ไม่ใช่ `//` — แท็กปิดของ PHP ที่อยู่ใน
              คอมเมนต์บรรทัดเดียว **ปิดโหมด PHP จริง ๆ** ทั้งไฟล์จะพังตั้งแต่ตรงนี้ */
        return (string)preg_replace('/<\?(php|=)(.*?)(\?>|$)/s', '__PHP__', $code);
    }

    /**
     * ⭐⭐⭐ ช่องเลือกเดือนทุกช่องในทุกหน้า ต้องมีป้ายเดือนไทยผูกไว้
     *
     * ⚠️ ผูกด้วย `data-thai-month-for="<id ของช่อง>"` — สคริปต์ร่วมใน `includes/footer.php`
     * กวาดจากตัวนี้แล้วเขียนใหม่ทุกครั้งที่ผู้ใช้เปลี่ยนเดือน
     */
    public function testEveryMonthPickerHasAThaiLabelBesideIt(): void
    {
        $checked = 0;

        foreach ($this->rootPages() as $path) {
            $markup = $this->markupOnly((string)file_get_contents($path));

            preg_match_all('/<input\b[^>]*>/is', $markup, $inputs);

            foreach ($inputs[0] as $tag) {
                if (!preg_match('/type\s*=\s*"month"/i', $tag)) {
                    continue;
                }

                $checked++;

                $this->assertMatchesRegularExpression(
                    '/id\s*=\s*"([^"]+)"/i',
                    $tag,
                    basename($path) . ': ช่องเลือกเดือนไม่มี id จึงผูกป้ายเดือนไทยไม่ได้'
                );

                preg_match('/id\s*=\s*"([^"]+)"/i', $tag, $matchedId);
                $inputId = $matchedId[1];

                $this->assertStringContainsString(
                    'data-thai-month-for="' . $inputId . '"',
                    $markup,
                    basename($path) . ': ช่องเลือกเดือน "' . $inputId . '" ไม่มีเดือนไทยกำกับ — '
                    . 'ผู้ใช้จะเห็น "August 2026" คู่กับตารางที่เขียน "ส.ค. 2569" บนจอเดียวกัน'
                );
            }
        }

        // ⚠️ กันตัวกวาดที่ไม่เจออะไรเลยแล้วเขียวไปเฉย ๆ
        $this->assertGreaterThanOrEqual(4, $checked, 'ตัวกวาดหาช่องเลือกเดือนไม่เจอ — regex คงพัง');
    }

    /**
     * ⭐⭐ ป้ายที่เสิร์ฟออกไปต้องตรงกับตัวจัดรูปแบบฝั่งเซิร์ฟเวอร์เป๊ะ ๆ
     *
     * ยิงหน้าจริงด้วยเดือนที่กำหนดเอง แล้วอ่านข้อความในป้ายมาเทียบกับ `formatThaiMonth()`
     * — ไม่ใช่เทียบกับสตริงที่พิมพ์ไว้ในเทสต์ (ซึ่งจะกลายเป็นสำเนาที่สาม)
     */
    public function testTheLabelSaysExactlyWhatTheServerFormatterSays(): void
    {
        $userId = $this->createUser('thaimonth@example.com', 'ThaiMonth12345');
        $shopId = $this->createShop($userId, 'ร้านเดือนไทย');
        $this->createShop($userId, 'ร้านที่สอง');           // หน้ารวมร้านต้องมี ≥ 2 ร้าน
        $session = $this->startSession($userId, $shopId);

        $month = '2026-04';
        $expected = \formatThaiMonth($month);               // "เม.ย. 2569"

        $pages = [
            '/history.php?month=' . $month => 'month',
            '/overview.php?month=' . $month => 'overview-month',
            '/dashboard.php?range=month_pick&month=' . $month => 'month-pick-input',
        ];

        foreach ($pages as $url => $inputId) {
            $body = (string)$this->get($url, $session)['body'];

            $found = preg_match(
                '/<span[^>]*data-thai-month-for="' . preg_quote($inputId, '/') . '"[^>]*>([^<]*)</',
                $body,
                $matched
            );

            $this->assertSame(1, $found, $url . ': หาป้ายเดือนไทยของช่อง "' . $inputId . '" ไม่เจอในหน้าจริง');
            $this->assertSame(
                $expected,
                trim($matched[1]),
                $url . ': ป้ายเดือนไทยเขียน "' . trim($matched[1]) . '" แต่ระบบจัดรูปแบบเดือนนี้ว่า "' . $expected . '"'
            );
        }
    }

    /**
     * ⚠️⚠️ ชื่อเดือนฝั่งเบราว์เซอร์ต้องมาจากตัวจัดรูปแบบของ PHP — ห้ามพิมพ์ชื่อเดือนใหม่
     *
     * โปรเจกต์นี้เคยมีกติกาเดียวกันเขียนซ้ำหลายที่แล้วเพี้ยนมาแล้วหลายรอบ · ชื่อเดือน
     * ก็เหมือนกัน: พิมพ์ซ้ำเมื่อไหร่ วันที่มีใครแก้ที่หนึ่ง อีกที่จะไม่ตาม
     *
     * ตัวกวาดนี้ไล่หา "รายชื่อเดือนไทยแบบพิมพ์มือ" ในทุกไฟล์ แล้วบังคับให้ตรงกับ
     * `formatThaiMonth()` ทุกตัว — จะพิมพ์ซ้ำก็ได้ แต่เพี้ยนไม่ได้
     */
    public function testEveryThaiMonthNameInTheBrowserMatchesThePhpFormatter(): void
    {
        $expected = array_map(
            static fn(int $month): string => (string)preg_replace(
                '/\s*\d+$/u',
                '',
                \formatThaiMonth(sprintf('2000-%02d', $month))
            ),
            range(1, 12)
        );

        $files = array_merge(
            (array)glob(dirname(__DIR__, 2) . '/*.php'),
            (array)glob(dirname(__DIR__, 2) . '/includes/*.php')
        );

        $lists = 0;

        foreach ($files as $path) {
            $code = (string)file_get_contents($path);

            // รายชื่อ 12 ชื่อเรียงติดกันในเครื่องหมายคำพูดเดี่ยว/คู่ = รายชื่อเดือน
            preg_match_all(
                '/\[\s*((?:[\'"][^\'"]{2,7}[\'"]\s*,\s*){11}[\'"][^\'"]{2,7}[\'"]\s*,?\s*)\]/u',
                $code,
                $found
            );

            foreach ($found[1] as $raw) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/u', $raw, $names);
                if (($names[1][0] ?? '') !== $expected[0]) {
                    continue;   // ไม่ใช่รายชื่อเดือน (เช่นรายชื่อสีของกราฟ)
                }

                $lists++;
                $this->assertSame(
                    $expected,
                    $names[1],
                    basename($path) . ': รายชื่อเดือนฝั่งเบราว์เซอร์ไม่ตรงกับที่ระบบใช้จริง'
                );
            }
        }

        $this->assertGreaterThanOrEqual(1, $lists, 'ตัวกวาดหารายชื่อเดือนฝั่งเบราว์เซอร์ไม่เจอเลย — regex คงพัง');
    }
}
