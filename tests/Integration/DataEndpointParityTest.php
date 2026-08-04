<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

/**
 * `*-data.php` ต้องตอบเรื่องเดียวกับหน้าเว็บที่คู่กัน
 *
 * CLAUDE.md เขียนไว้ว่า `dashboard.php` กับ `api/dashboard-data.php`
 * "ต้องเขียนเหมือนกันเป๊ะ ๆ ไม่งั้นหน้าเว็บกับ endpoint ตอบคนละอย่าง" — แต่ที่ผ่านมา
 * มีแค่ **คอมเมนต์** บังคับ ไม่มีเทสต์ · endpoint เหล่านี้ถูกแตะเพียงเพื่อเช็กว่า
 * คนไม่ล็อกอินเข้าไม่ได้ ไม่เคยมีใครรันเนื้อในเลย
 *
 * จุดที่พลาดง่ายที่สุดคือ `month=` **ที่ว่าง** ซึ่งแปลว่า "ไม่ได้เลือกเดือน"
 * ต้องต่างจาก "เลือกเดือนปัจจุบัน" — ถ้าสองไฟล์ตีความไม่ตรงกัน ตัวเลขบนจอ
 * กับตัวเลขที่ endpoint ตอบจะคนละชุดโดยไม่มีอะไรเตือน
 */
final class DataEndpointParityTest extends ControllerTestCase
{
    /** @return array{userId:int,shopId:int,session:string} */
    private function seedTwoMonths(): array
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createShop($userId, 'ร้านที่สอง');   // หน้ารวมร้านต้องมี ≥ 2 ร้าน

        $thisMonth = date('Y-m');
        $lastMonth = date('Y-m', strtotime('first day of last month'));
        $this->createRecord($shopId, $thisMonth . '-01', 5000.0, 1200.0, null);
        $this->createRecord($shopId, $lastMonth . '-01', 9000.0, 2000.0, null);

        return ['userId' => $userId, 'shopId' => $shopId, 'session' => $this->startSession($userId, $shopId)];
    }

    /** @return array<string,mixed> */
    private function json(string $path, string $session): array
    {
        $response = $this->getJson($path, $session);
        $this->assertSame(200, $response['status'], $path . ' ตอบไม่สำเร็จ: ' . $response['body']);

        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded, $path . ' ตอบกลับมาไม่ใช่ JSON: ' . $response['body']);

        return $decoded;
    }

    /**
     * ⭐ หน้าเว็บกับ endpoint ต้องตอบช่วงเดียวกัน ทุกรูปแบบของ query
     *
     * นี่คือกฎที่ CLAUDE.md บังคับไว้ ("สองไฟล์นี้ต้องเขียนเหมือนกันเป๊ะ ๆ")
     *
     * ⚠️ **หมายเหตุที่พิสูจน์แล้ว:** "`month=` ว่าง" กับ "`month=` เดือนปัจจุบัน" ให้ผลลัพธ์
     * ที่เหมือนกันทุกไบต์ทั้งบนหน้าเว็บและใน endpoint (ทางที่ไม่ได้เลือกเดือนจะตกไปใช้
     * เดือนปัจจุบันอยู่ดี) จึง **แยกจากภายนอกไม่ได้** — เทสต์เวอร์ชันแรกอ้างว่าแยกได้
     * และเขียวโดยไม่ได้ตรวจอะไรเลย · สิ่งที่ตรวจได้จริงคือ "สองไฟล์ตอบตรงกันไหม"
     *
     * @return array<string,array{0:string}>
     */
    public static function queryShapeProvider(): array
    {
        $thisMonth = date('Y-m');
        $lastMonth = date('Y-m', strtotime('first day of last month'));

        return [
            'ไม่ส่งอะไรเลย' => [''],
            'month ว่าง' => ['?range=month_pick&month='],
            'เดือนปัจจุบัน' => ['?range=month_pick&month=' . $thisMonth],
            'เดือนที่แล้ว' => ['?range=month_pick&month=' . $lastMonth],
            'เดือนอนาคต' => ['?range=month_pick&month=2099-12'],
            'เดือนผิดรูป' => ['?range=month_pick&month=%E0%B9%84%E0%B8%A1%E0%B9%88%E0%B9%83%E0%B8%8A%E0%B9%88%E0%B9%80%E0%B8%94%E0%B8%B7%E0%B8%AD%E0%B8%99'],
            'สัปดาห์นี้' => ['?range=week_this'],
            'เดือนที่แล้วแบบ preset' => ['?range=month_last'],
            'ช่วงที่ไม่รู้จัก' => ['?range=%E0%B9%84%E0%B8%A1%E0%B9%88%E0%B8%A1%E0%B8%B5%E0%B8%8A%E0%B9%88%E0%B8%A7%E0%B8%87%E0%B8%99%E0%B8%B5%E0%B9%89'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queryShapeProvider')]
    public function testThePageAndTheEndpointNeverDisagree(string $query): void
    {
        ['session' => $session] = $this->seedTwoMonths();

        $range = (array)($this->json('/api/dashboard-data.php' . $query, $session)['data']['range'] ?? []);
        $pageBody = $this->get('/dashboard.php' . $query, $session)['body'];

        $start = (string)($range['start_date'] ?? '');
        $end = (string)($range['end_date'] ?? '');
        $this->assertNotSame('', $start, $query . ': endpoint ไม่ได้บอกช่วงเริ่มต้น');
        $this->assertLessThanOrEqual($end, $start, $query . ': endpoint ให้ช่วงกลับหัว');

        // หน้าเว็บพิมพ์ช่วงเดียวกันนี้ไว้ใต้หัวข้อ — ต้องเป็นวันเดียวกับที่ endpoint ตอบ
        $this->assertStringContainsString(
            formatThaiDate($start),
            $pageBody,
            $query . ': หน้าเว็บเริ่มนับคนละวันกับ endpoint'
        );
        $this->assertStringContainsString(
            formatThaiDate($end),
            $pageBody,
            $query . ': หน้าเว็บจบคนละวันกับ endpoint'
        );
    }

    /** ⭐ เลือกเดือนเดียวกัน หน้าเว็บกับ endpoint ต้องได้ช่วงวันเดียวกันเป๊ะ */
    public function testTheDashboardPageAndItsEndpointAgreeOnTheRange(): void
    {
        ['session' => $session] = $this->seedTwoMonths();
        $lastMonth = date('Y-m', strtotime('first day of last month'));

        $fromEndpoint = $this->json('/api/dashboard-data.php?range=month_pick&month=' . $lastMonth, $session);
        $range = (array)($fromEndpoint['data']['range'] ?? []);

        $pageBody = $this->get('/dashboard.php?range=month_pick&month=' . $lastMonth, $session)['body'];

        $this->assertSame($lastMonth . '-01', (string)($range['start_date'] ?? ''));
        $this->assertStringContainsString(
            'value="' . $lastMonth . '"',
            $pageBody,
            'หน้าเว็บไม่ได้เลือกเดือนเดียวกับที่ endpoint ตอบ'
        );
    }

    /** ⭐ เดือนอนาคตต้องถูกหดทั้งสองทาง ไม่ใช่หน้าเว็บหดแต่ endpoint ไม่หด */
    public function testBothPathsClampAFutureMonth(): void
    {
        ['session' => $session] = $this->seedTwoMonths();

        $fromEndpoint = $this->json('/api/dashboard-data.php?range=month_pick&month=2099-12', $session);
        $range = (array)($fromEndpoint['data']['range'] ?? []);

        // ⚠️ นี่เป็นเทสต์ระดับ "ผลลัพธ์ปลายทาง" ไม่ใช่ตัวล็อกจุดใดจุดหนึ่ง — การหด
        // เดือนอนาคตมีอยู่ 3 ที่ (`api/dashboard-data.php`, `dashboard.php`,
        // `DashboardService`) ถอดออกทีละที่แล้วอีกสองที่ยังคุมไว้ เทสต์นี้จึงไม่แดง
        // **แต่ละชั้นมีเทสต์ของตัวเองแยกไว้แล้ว** (`CalendarMonthResolutionTest`,
        // `FutureRowsConsistencyTest`) ตัวนี้กันแค่ "หลุดพร้อมกันทั้งหมด"
        // · ดู start_date ด้วย เพราะ end_date ถูกตัดด้วยตัวกัน "ห้ามเลยวันนี้" อยู่แล้ว
        $this->assertSame(
            date('Y-m-01'),
            (string)($range['start_date'] ?? ''),
            'endpoint ไม่ได้หดเดือนอนาคตกลับมาเป็นเดือนปัจจุบัน'
        );
        $this->assertLessThanOrEqual(
            date('Y-m-d'),
            (string)($range['end_date'] ?? '9999-12-31'),
            'endpoint ยอมให้ช่วงเลยวันนี้'
        );
        $this->assertStringNotContainsString(
            'value="2099-12"',
            $this->get('/dashboard.php?range=month_pick&month=2099-12', $session)['body'],
            'หน้าเว็บยอมให้เลือกเดือนอนาคต'
        );
    }

    /** ⭐ endpoint รายปีต้องแปลงปี พ.ศ. เหมือนหน้าเว็บ */
    public function testTheAnnualEndpointConvertsBuddhistYearsLikeThePage(): void
    {
        ['session' => $session] = $this->seedTwoMonths();

        // ⚠️ ต้องใช้ปี พ.ศ. ใน **อดีต** — ถ้าใช้ปีปัจจุบัน (เช่น 2569) แล้วถอดการแปลง
        // ปีออก ค่าจะตกนอกช่วง 2000–2100 แล้วกลับไปใช้ปีปัจจุบันเป็นค่าปริยายพอดี
        // ได้คำตอบเดียวกันโดยบังเอิญ เทสต์จึงเขียวทั้งที่การแปลงหายไปแล้ว
        $fromEndpoint = $this->json('/api/annual-data.php?year=2565', $session);

        $this->assertSame(
            2022,
            (int)($fromEndpoint['data']['year'] ?? 0),
            'endpoint ไม่ได้แปลงปี พ.ศ. เป็น ค.ศ. เหมือนหน้าเว็บ'
        );
    }

    /** ⭐ ปีอนาคตต้องถูกหดทั้งสองทาง */
    public function testTheAnnualEndpointClampsFutureYears(): void
    {
        ['session' => $session] = $this->seedTwoMonths();

        $fromEndpoint = $this->json('/api/annual-data.php?year=2099', $session);

        $this->assertSame((int)date('Y'), (int)($fromEndpoint['data']['year'] ?? 0), 'endpoint ยอมรับปีอนาคต');
    }

    /** ⭐ endpoint รวมร้านต้องใช้เกณฑ์ "ต้องมี ≥ 2 ร้าน" เดียวกับหน้าเว็บ */
    public function testTheOverviewEndpointUsesTheSameTwoShopRule(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);

        $oneShop = $this->json('/api/overview-data.php', $session);
        $this->assertFalse((bool)($oneShop['data']['can_view'] ?? true), 'ร้านเดียวก็ดูภาพรวมได้');

        $this->createShop($userId, 'ร้านที่สอง');
        $twoShops = $this->json('/api/overview-data.php', $session);
        $this->assertTrue((bool)($twoShops['data']['can_view'] ?? false), 'มี 2 ร้านแล้วยังดูภาพรวมไม่ได้');
    }

    /** ⭐ endpoint ต้องไม่ปล่อยข้อมูลของร้านคนอื่นออกมา */
    public function testTheDataEndpointsNeverLeakAnotherUsersNumbers(): void
    {
        $userId = $this->createUser();
        $ownShop = $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');
        $this->createRecord($strangerShop, date('Y-m') . '-01', 987654.0, 1.0, 'ความลับ');

        $session = $this->startSession($userId, $ownShop);

        foreach (['/api/dashboard-data.php', '/api/annual-data.php?year=' . date('Y')] as $path) {
            $body = $this->getJson($path, $session)['body'];
            $this->assertStringNotContainsString('987654', $body, $path . ' ปล่อยยอดของร้านคนอื่น');
            $this->assertStringNotContainsString('ความลับ', $body, $path . ' ปล่อยโน้ตของร้านคนอื่น');
        }
    }
}
