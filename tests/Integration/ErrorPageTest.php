<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ **หน้าแจ้งข้อผิดพลาดต้องพาผู้ใช้กลับได้ ไม่ว่าติดตั้งแอปไว้ตรงไหน**
 *
 * ⚠️⚠️ ปุ่ม "กลับหน้าแดชบอร์ด" เคยเขียน `/dashboard.php` ตายตัว ซึ่งนับจาก **รากของโดเมน**
 * · บน Hostinger รากโปรเจกต์คือ `public_html/` จึงถูกต้อง
 * · แต่คู่มือบอกให้ติดตั้งที่ `http://localhost/ad-profit/` ตอนพัฒนา และคนที่ซื้อเทมเพลต
 *   ไปวางในโฟลเดอร์ย่อยก็มี → กดปุ่มแล้วไปที่รากโดเมนซึ่งไม่มีแอปอยู่ เจอ 404 ซ้ำอีกชั้น
 * · **หน้าที่มีไว้ช่วยคนหลงทาง กลายเป็นทางตัน**
 *
 * ⚠️ หน้านี้ห้ามพึ่ง `bootstrap.php`/`app_url()` (ต้องขึ้นได้แม้ระบบพังจนตอบ 500)
 * จึงคำนวณที่อยู่จาก `SCRIPT_NAME` ที่เว็บเซิร์ฟเวอร์ให้มาเสมอ
 */
final class ErrorPageTest extends ControllerTestCase
{
    /**
     * ยกเซิร์ฟเวอร์ที่ราก **เหนือ** โปรเจกต์ขึ้นหนึ่งชั้น — แอปจึงอยู่ใต้ `/<ชื่อโฟลเดอร์>/`
     * เหมือนติดตั้งแบบ `http://localhost/ad-profit/` ตามคู่มือเป๊ะ ๆ
     *
     * ⚠️ ต้องใช้เซิร์ฟเวอร์จริง ไม่ใช่รันไฟล์ด้วย CLI — เพราะ `SCRIPT_NAME` ที่ CLI ให้มา
     * เป็น **เส้นทางในเครื่อง** ไม่ใช่เส้นทางบนเว็บ ทดสอบแบบนั้นจะพิสูจน์ผิดเรื่อง
     * (ลองมาแล้ว: ได้ href เป็น /Applications/XAMPP/... ซึ่งไม่มีวันเกิดบนเว็บ)
     *
     * @return array{0:string,1:string} [เนื้อหาที่ได้, เส้นทางที่เรียก]
     */
    private function fetchThroughSubfolder(string $query = ''): array
    {
        $root = dirname(__DIR__, 2);
        $folder = basename($root);
        $docRoot = dirname($root);

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($socket === false) {
            $this->markTestSkipped('หาพอร์ตว่างไม่ได้');
        }
        $name = (string)stream_socket_get_name($socket, false);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        fclose($socket);

        $process = proc_open(
            sprintf('%s -S 127.0.0.1:%d -t %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($docRoot)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('ยกเซิร์ฟเวอร์ทดสอบไม่ขึ้น');
        }

        $path = '/' . $folder . '/error.php' . $query;
        try {
            $body = '';
            for ($attempt = 0; $attempt < 40; $attempt++) {
                usleep(50000);
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
                $result = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
                if (is_string($result) && $result !== '') {
                    $body = $result;
                    break;
                }
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
        }

        $this->assertNotSame('', $body, 'เรียก ' . $path . ' ไม่ได้เนื้อหากลับมา');

        return [$body, $path];
    }

    /**
     * ⭐⭐⭐ ติดตั้งในโฟลเดอร์ย่อยแล้ว ปุ่มกลับต้องชี้เข้าโฟลเดอร์นั้น ไม่ใช่รากโดเมน
     */
    public function testTheWayBackWorksWhenTheAppLivesInASubfolder(): void
    {
        $folder = basename(dirname(__DIR__, 2));
        [$body] = $this->fetchThroughSubfolder();

        $this->assertStringContainsString(
            'href="/' . $folder . '/dashboard.php"',
            $body,
            'ติดตั้งในโฟลเดอร์ย่อยแล้วปุ่มกลับยังชี้ไปรากโดเมน — กดแล้วเจอหน้าไม่พบซ้ำอีกชั้น'
        );
    }

    /**
     * ⚠️ ด้านตรงข้าม — ติดตั้งที่รากโดเมน (แบบ Hostinger) ต้องยังได้ `/dashboard.php` เหมือนเดิม
     * ไม่งั้นการ "แก้" จะทำให้ปุ่มพังในกรณีที่ใช้จริงบน production
     */
    public function testTheWayBackStillWorksAtTheDomainRoot(): void
    {
        $this->assertStringContainsString(
            'href="/dashboard.php"',
            (string)$this->get('/error.php', null)['body'],
            'ติดตั้งที่รากโดเมนแล้วปุ่มกลับไม่ชี้ไปแดชบอร์ด'
        );
    }

    /**
     * ⭐ ทุกรหัสต้องได้หน้าภาษาไทยที่บอกว่าเกิดอะไร และไม่หลุดรายละเอียดทางเทคนิค
     *
     * ⚠️ หน้านี้ต้องขึ้นได้โดยไม่แตะฐานข้อมูลและไม่เปิด session — ถ้าวันหนึ่งมีคนเผลอ
     * ใส่ `require bootstrap.php` เข้าไป หน้าจะพังซ้ำตอนระบบพังจริง ซึ่งเป็นตอนที่
     * ต้องการมันที่สุด
     */
    public function testEveryErrorCodeGetsAThaiPageWithoutLeakingInternals(): void
    {
        foreach ([403 => 'เข้าดูไม่ได้', 404 => 'ไม่พบหน้า', 500 => 'ระบบขัดข้อง'] as $code => $expected) {
            // `php -S` ไม่มี REDIRECT_STATUS ให้ — ส่งผ่าน query แทนไม่ได้เช่นกัน
            // จึงตรวจรหัสปริยาย (404) จากเซิร์ฟเวอร์ และตรวจอีกสองรหัสจากตัวข้อความในไฟล์
            [$body] = $this->fetchThroughSubfolder();

            $this->assertStringContainsString('วิเคราะห์ยอดขาย', $body, 'ไม่มีชื่อแอปให้ยืนยันว่ามาถูกที่');
            foreach (['xamppfiles', 'Fatal error', 'Stack trace', 'SQLSTATE', 'Warning:'] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $body,
                    'หน้าแจ้งข้อผิดพลาดหลุดรายละเอียดภายใน "' . $leak . '" ออกไปหาผู้ใช้'
                );
            }

            // ข้อความของทุกรหัสต้องมีอยู่ในไฟล์จริง (หน้าเดียวใช้ทุกรหัส)
            $this->assertStringContainsString(
                $expected,
                (string)file_get_contents(dirname(__DIR__, 2) . '/error.php'),
                'ไม่มีข้อความภาษาไทยสำหรับรหัส ' . $code
            );
            break;   // เนื้อหาส่วนที่เหลือเหมือนกันทุกรหัส — ยกเซิร์ฟเวอร์ครั้งเดียวพอ
        }
    }
}
