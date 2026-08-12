<?php

declare(strict_types=1);

namespace Tests\Integration;

// ⚠️ คลาสฐานไม่ได้อยู่ใน autoload — ไฟล์ที่ชื่อมาก่อน ControllerTestCase ตามตัวอักษรต้อง require เอง
require_once __DIR__ . '/ControllerTestCase.php';

/**
 * ⭐⭐ **ข้อความที่สคริปต์เปลี่ยนสด ต้องถูกประกาศ ไม่ใช่เปลี่ยนเงียบ ๆ**
 *
 * ⚠️⚠️ ในหน้าบันทึกข้อมูล ข้อความสำคัญที่สุดของหน้าถูกเขียนด้วยสคริปต์ทั้งหมด:
 *   · "กำลังโหลดข้อมูลเดิมของวันที่ …"
 *   · "มีข้อมูลอยู่แล้ว — **กดบันทึกจะเป็นการแก้ไขทับ**"
 *   · "โหลดข้อมูลเดิมไม่สำเร็จ — ตรวจสอบก่อนกดบันทึก"
 * ข้อความกลางไม่ใช่ของประดับ — มันคือคำเตือนว่า **การกดบันทึกครั้งนี้จะเขียนทับของเดิม**
 * ถ้าไม่ประกาศ คนที่ใช้โปรแกรมอ่านหน้าจอจะกดบันทึกโดยไม่รู้เลย
 *
 * ⚠️ ใช้ `role="status"` (สุภาพ) ไม่ใช่ `alert` — ข้อความเปลี่ยนทุกครั้งที่เลือกวัน
 * ถ้าขัดจังหวะทุกครั้งจะรบกวนจนใช้งานไม่ได้
 */
final class DynamicMessageAccessibilityTest extends ControllerTestCase
{
    private function addRecordPage(): string
    {
        $userId = $this->createUser('a11y@example.com', 'A11yPass123456');
        $shopId = $this->createShop($userId, 'ร้านทดสอบ');

        return (string)$this->get('/add-record.php', $this->startSession($userId, $shopId))['body'];
    }

    /** ⭐ ทุกกล่องที่สคริปต์เขียนข้อความลงไป ต้องเป็นพื้นที่ประกาศสด */
    public function testEveryLiveMessageAreaAnnouncesItself(): void
    {
        $body = $this->addRecordPage();

        foreach ([
            'existing-record-hint' => 'คำเตือนว่าจะเขียนทับข้อมูลเดิม (ฟอร์มวันเดียว)',
            'bulk-paste-notice' => 'คำเตือนตอนวางข้อมูลจาก Excel',
            'bulk-fill-notice' => 'ข้อความตอนเติมวันที่ขาด',
        ] as $id => $label) {
            $this->assertMatchesRegularExpression(
                '/<p[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/',
                $body,
                'ไม่พบกล่อง "' . $label . '"'
            );
            preg_match('/<p[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $body, $tag);
            $this->assertStringContainsString(
                'aria-live',
                (string)($tag[0] ?? ''),
                '"' . $label . '" เปลี่ยนข้อความด้วยสคริปต์แต่ไม่ประกาศ — โปรแกรมอ่านหน้าจอจะเงียบ'
            );
        }
    }

    /** ⭐ ช่องวันที่ต้องผูกกับคำเตือน — จะได้ยินตอนโฟกัสช่องนั้น ไม่ต้องเดินหาเอง */
    public function testTheDateFieldPointsAtTheOverwriteWarning(): void
    {
        $body = $this->addRecordPage();
        preg_match('/<input[^>]*\bid="record-date"[^>]*>/', $body, $tag);

        $this->assertStringContainsString(
            'aria-describedby="existing-record-hint"',
            (string)($tag[0] ?? ''),
            'ช่องวันที่ไม่ได้ผูกกับคำเตือน "จะเขียนทับ"'
        );
    }

    /** ⭐ เมนูล่างต้องบอกว่าหน้าไหนคือหน้าปัจจุบัน ไม่ใช่บอกด้วยสีอย่างเดียว */
    public function testTheBottomNavigationSaysWhichPageYouAreOn(): void
    {
        $userId = $this->createUser('nav@example.com', 'NavPass1234567');
        $shopId = $this->createShop($userId, 'ร้านเมนู');
        $session = $this->startSession($userId, $shopId);

        $body = (string)$this->get('/history.php', $session)['body'];
        $this->assertSame(
            1,
            substr_count($body, 'aria-current="page"'),
            'เมนูล่างต้องมีหน้าปัจจุบันหนึ่งเดียวที่ถูกทำเครื่องหมาย'
        );
        $this->assertMatchesRegularExpression(
            '/history\.php"[^>]*aria-current="page"/s',
            (string)preg_replace('/\s+/', ' ', $body),
            'เครื่องหมาย "หน้าปัจจุบัน" ไปอยู่ผิดปุ่ม'
        );
    }

    /** ⭐ แท็บเข้าสู่ระบบ/สมัครสมาชิก ต้องบอกว่ากำลังเลือกอันไหน */
    public function testTheLoginTabsSayWhichOneIsSelected(): void
    {
        $body = (string)$this->get('/login.php', $this->startSession(0, 0))['body'];

        $this->assertSame(
            2,
            substr_count($body, 'role="tab"'),
            'ปุ่มสองอันนี้ทำหน้าที่เป็นแท็บ แต่ไม่ได้ประกาศตัวว่าเป็นแท็บ'
        );
        $this->assertStringContainsString(
            'aria-selected',
            $body,
            'แท็บไม่บอกว่ากำลังเลือกอันไหน — โปรแกรมอ่านหน้าจอจะไม่รู้ว่าฟอร์มที่เห็นเป็นของแท็บไหน'
        );
    }

    /**
     * ⭐⭐ แท็บต้องบอกด้วยว่า **ตัวเองคุมฟอร์มไหน** ไม่ใช่แค่บอกว่ากำลังเลือกอันไหน
     *
     * ⚠️ `role="tab"` + `aria-selected` อย่างเดียวยังไม่พอ — ปุ่มสองอันจะถูกอ่านเป็น
     * ปุ่มลอย ๆ ที่ไม่เกี่ยวอะไรกัน และไม่มีอะไรเชื่อมไปยังฟอร์มที่มันคุมอยู่
     * คนที่ใช้โปรแกรมอ่านหน้าจอจึงต้องไล่อ่านลงมาทีละบรรทัดเพื่อหาว่าฟอร์มอยู่ตรงไหน
     * ทั้งที่หน้านี้คือหน้าจอแรกสุดที่ผู้ใช้ทุกคนต้องผ่าน
     *
     * ต้องครบชุด: `role="tablist"` ที่กล่องครอบ · `aria-controls` ที่แท็บ ·
     * `role="tabpanel"` + `aria-labelledby` ที่ฟอร์ม — และ **ต้องชี้ถึงกันจริง**
     */
    public function testEachTabSaysWhichFormItControls(): void
    {
        $body = (string)$this->get('/login.php', $this->startSession(0, 0))['body'];

        $this->assertStringContainsString(
            'role="tablist"',
            $body,
            'ไม่มีกล่องครอบที่บอกว่าปุ่มสองอันนี้เป็นชุดแท็บเดียวกัน'
        );

        preg_match_all('/<button[^>]*role="tab"[^>]*>/i', $body, $tabs);
        $this->assertCount(2, $tabs[0], 'ต้องมีแท็บ 2 อัน');

        foreach ($tabs[0] as $tab) {
            $this->assertSame(
                1,
                preg_match('/\bid="([^"]+)"/', $tab, $tabId),
                'แท็บไม่มี id ฟอร์มจึงชี้กลับมาหาไม่ได้: ' . $tab
            );
            $this->assertSame(
                1,
                preg_match('/aria-controls="([^"]+)"/', $tab, $controls),
                'แท็บไม่ได้บอกว่าคุมฟอร์มไหน: ' . $tab
            );

            /* ⚠️ ต้องพิสูจน์ว่า **ชี้ถึงกันจริง** ไม่ใช่แค่มีคำว่า aria-controls อยู่
               ชื่อที่ชี้ผิดจะทำให้โปรแกรมอ่านหน้าจอกระโดดไปที่ที่ไม่มีอยู่ ซึ่งแย่กว่าไม่ใส่ */
            $panelPattern = '/<section[^>]*id="' . preg_quote($controls[1], '/') . '"[^>]*>/i';
            $this->assertSame(
                1,
                preg_match($panelPattern, $body, $panel),
                'แท็บชี้ไปที่ "' . $controls[1] . '" แต่ไม่มีฟอร์มที่ใช้ชื่อนี้'
            );
            $this->assertStringContainsString(
                'role="tabpanel"',
                $panel[0],
                'ฟอร์ม "' . $controls[1] . '" ไม่ได้ประกาศตัวว่าเป็นเนื้อหาของแท็บ'
            );
            $this->assertStringContainsString(
                'aria-labelledby="' . $tabId[1] . '"',
                $panel[0],
                'ฟอร์ม "' . $controls[1] . '" ไม่ได้ชี้กลับไปหาแท็บที่คุมมัน'
            );
        }
    }
}
