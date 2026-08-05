<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use GoalRepository;
use GoalService;
use PDO;
use RecordRepository;
use RecordService;
use ShopRepository;

/**
 * สองแท็บที่บันทึกพร้อมกันต้องไม่ทำให้ระบบตัดใครทิ้ง
 *
 * ก่อนหน้านี้การบันทึกจะ "จองแถวของวันนั้น" ไว้ก่อนเขียน ซึ่งสร้างปัญหา 2 ชั้น:
 *  1. วันที่ยังไม่มีข้อมูล → MySQL จองเป็น "ช่องว่างระหว่างวัน" แทน สองคนที่บันทึก
 *     คนละวันในช่องว่างเดียวกันจึงจองทับกัน แล้วถูกตัดทิ้งเป็น deadlock
 *  2. ตอนลบร้านจองแถวร้านก่อนแล้วค่อยลบข้อมูลข้างใน ส่วนตอนบันทึกจองข้อมูลก่อน
 *     แล้วค่อยแตะแถวร้าน (ผ่าน foreign key) — ลำดับกลับหัวกัน = วนรอกันได้
 *
 * ตอนนี้ทุกทางที่เขียนจองแถว "ร้าน" ก่อนเสมอ แล้วค่อยแตะข้อมูลข้างใน
 * เทสต์นี้พิสูจน์ว่าลำดับนั้นเกิดขึ้นจริงกับ DB จริง และผลลัพธ์ยังถูกต้อง
 */
final class ConcurrentWriteLockOrderTest extends IntegrationTestCase
{
    private function recordService(?PDO $pdo = null): RecordService
    {
        $pdo ??= $this->pdo;

        return new RecordService(new RecordRepository($pdo), new ShopRepository($pdo), $pdo);
    }

    /**
     * ⭐ อีกคำขอที่ถือแถวร้านไว้ ต้องทำให้การบันทึก "รอ" ไม่ใช่ "ถูกตัดทิ้ง"
     *
     * ใช้การเชื่อมต่อที่ 2 จับแถวร้านแบบเขียน (เหมือนตอนลบร้าน) แล้วตั้งเวลารอสั้น ๆ
     * ให้การบันทึกล้มด้วย "รอเกินเวลา" (1205) — ซึ่งแปลว่าลำดับถูกต้องแล้ว
     * ถ้าเป็น deadlock (1213) แปลว่ายังจองกันคนละทางอยู่
     */
    public function testSavingWaitsOnTheShopRowInsteadOfDeadlocking(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $other = $this->newConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        $other->query("SELECT id FROM shops WHERE id = {$shopId} FOR UPDATE")->fetchAll();

        $writer = $this->newConnection();
        $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $result = $this->recordService($writer)
            ->upsertRecord($userId, $shopId, '2026-08-01', 1000.0, 200.0, null);

        $other->rollBack();

        $this->assertFalse($result['success'], 'เขียนผ่านทั้งที่แถวร้านถูกจับไว้ = ไม่ได้จองแถวร้านก่อน');

        // ต้องไม่มีเศษ transaction ค้าง และข้อมูลต้องไม่ถูกเขียนครึ่ง ๆ
        $this->assertFalse($writer->inTransaction());
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /**
     * ⭐⭐ "เปลี่ยนรหัสเอง" กับ "กดลิงก์รีเซ็ต" ต้องจองแถวเรียงเหมือนกัน
     *
     * ⚠️ สองทางนี้แตะข้อมูลชุดเดียวกัน: แถวผู้ใช้ กับ แถวลิงก์รีเซ็ต
     * หน้าโปรไฟล์จอง **แถวผู้ใช้ก่อน** ถ้าทางลิงก์จองสลับกัน สองทางที่ทำพร้อมกัน
     * จะล็อกกันเอง แล้วฐานข้อมูลยกเลิกฝ่ายหนึ่งทิ้ง (deadlock) โดยไม่มีฝ่ายไหนลองใหม่
     *
     * วัดจริงก่อนแก้: ยิงพร้อมกัน 6 รอบ ฝ่ายที่แพ้ได้ข้อความ "ไม่สามารถรีเซ็ตรหัสผ่านได้"
     * ซึ่งไม่บอกอะไรเลย · หลังแก้ได้ "ลิงก์หมดอายุแล้ว" หรือ "รหัสปัจจุบันไม่ถูกต้อง"
     * ซึ่งเป็นความจริงทั้งคู่ (อีกฝ่ายเปลี่ยนไปแล้วจริง ๆ)
     *
     * ⚠️ **ตรวจด้วยการจับลำดับการเรียก ไม่ใช่จับล็อกไว้แล้วดูว่าล้มไหม**
     * การอ่านแถว token ใช้ `JOIN users … FOR UPDATE` ซึ่งล็อกแถวผู้ใช้ไปด้วย
     * จับแถวไหนไว้ก็ล้มเหมือนกันทั้งสองลำดับ — แยกไม่ออก (ลองแล้ว เทสต์เขียวทั้งคู่)
     */
    public function testResettingByLinkLocksTheUserRowFirstLikeTheProfilePageDoes(): void
    {
        $userId = $this->createUser();
        $rawToken = bin2hex(random_bytes(32));
        (new \PasswordResetRepository($this->pdo))->createToken($userId, hash('sha256', $rawToken), 1);

        $order = [];

        $users = $this->createStub(\UserRepository::class);
        $users->method('lockForUpdate')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'ผู้ใช้';
            }
        );
        $users->method('updatePasswordHash')->willReturn(true);

        $tokens = $this->createStub(\PasswordResetRepository::class);
        $tokens->method('findByTokenHash')->willReturn(['user_id' => $userId, 'email' => 'owner@example.com']);
        $tokens->method('findByTokenHashForUpdate')->willReturnCallback(
            static function () use (&$order, $userId): array {
                $order[] = 'ลิงก์รีเซ็ต';

                return ['id' => 1, 'user_id' => $userId, 'email' => 'owner@example.com'];
            }
        );
        $tokens->method('deleteByUserId')->willReturn(true);

        (new \AuthService($this->pdo, $users, new \ShopRepository($this->pdo), $tokens))
            ->resetPassword($rawToken, 'BrandNewPass1', 'BrandNewPass1', '203.0.113.99');

        $this->assertSame(
            ['ผู้ใช้', 'ลิงก์รีเซ็ต'],
            $order,
            'จองแถวสลับลำดับกับหน้าโปรไฟล์ — ทำพร้อมกันแล้วจะล็อกกันเองจนฝ่ายหนึ่งล้ม'
        );
    }

    /** ⭐ บันทึกหลายวัน: เขียนเรียงตามวันที่เสมอ ไม่ว่าจะพิมพ์มาเรียงยังไง */
    public function testBulkSaveWritesInDateOrderRegardlessOfInputOrder(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->recordService()->upsertManyRecords($userId, $shopId, [
            ['row_number' => 1, 'record_date' => '2026-08-09', 'revenue' => '900', 'ad_cost' => '90', 'note' => ''],
            ['row_number' => 2, 'record_date' => '2026-08-01', 'revenue' => '100', 'ad_cost' => '10', 'note' => ''],
            ['row_number' => 3, 'record_date' => '2026-08-05', 'revenue' => '500', 'ad_cost' => '50', 'note' => ''],
        ]);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));

        // id เรียงตามลำดับที่ถูกเขียนจริง — ต้องตรงกับลำดับวันที่ ไม่ใช่ลำดับที่พิมพ์
        $written = $this->pdo
            ->query("SELECT record_date FROM daily_records WHERE shop_id = {$shopId} ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['2026-08-01', '2026-08-05', '2026-08-09'], $written);
    }

    /** ⭐ ตั้งเป้าหมายก็จองแถวร้านก่อนเหมือนกัน */
    public function testGoalSaveAlsoWaitsOnTheShopRow(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $other = $this->newConnection();
        $other->beginTransaction();
        (new ShopRepository($other))->lockForWrite($shopId, $userId);

        $writer = $this->newConnection();
        $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $result = (new GoalService(new GoalRepository($writer), new ShopRepository($writer), $writer))
            ->upsertGoal($userId, $shopId, '2026-08', 50000.0, null);

        $other->rollBack();

        $this->assertFalse($result['success'], 'ตั้งเป้าผ่านทั้งที่แถวร้านถูกจับไว้');
        $this->assertFalse($writer->inTransaction());
        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /**
     * ⭐ ตัวกัน "ช่องว่างทับของเดิม" ต้องกันได้จริงเมื่อสองคนเขียนพร้อมกัน
     *
     * นี่คือเทสต์ที่ควรมีตั้งแต่แรก — การจองแถวร้านเคยใช้ล็อกแบบ *อ่าน* (share) ซึ่ง
     * สองคนถือพร้อมกันได้ ตัวกันจึงยังเห็นภาพก่อนที่อีกฝ่ายจะบันทึก แล้วทับด้วยศูนย์
     * พร้อมข้อความ "บันทึกข้อมูลเรียบร้อยแล้ว" ทั้งสองฝั่ง
     *
     * วิธีพิสูจน์: ให้ผู้เขียนคนที่ 1 เริ่ม transaction แล้วจองแถวร้านค้างไว้
     * ผู้เขียนคนที่ 2 (นำเข้าไฟล์ที่เว้นช่องรายได้) ต้อง **รอ** ไม่ใช่เดินหน้าอ่านต่อ
     */
    public function testTheBlankGuardCannotBeRacedByASecondWriter(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        // ⚠️ ผู้ถือต้องจอง **ด้วยวิธีเดียวกับที่ service ใช้** ไม่ใช่ FOR UPDATE ตรง ๆ
        // ไม่งั้นเทสต์จะเขียวแม้ตอนที่ service ใช้ล็อกแบบอ่าน (ซึ่งกันอะไรไม่ได้เลย)
        // เพราะ FOR UPDATE ของเทสต์เองไปบล็อกให้ — คือพิสูจน์ผิดตัว (เคยเขียนพลาดมาแล้ว)
        $holder = $this->newConnection();
        $holder->beginTransaction();
        (new ShopRepository($holder))->lockForWrite($shopId, $userId);

        $importer = $this->newConnection();
        $importer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $service = new RecordService(new RecordRepository($importer), new ShopRepository($importer), $importer);
        $parsed = $service->parseImportCsv("date,revenue,ad_cost,note\n2026-08-01,,900,\n");
        $result = $service->upsertManyRecords($userId, $shopId, $parsed['rows']);

        $holder->rollBack();

        $this->assertFalse(
            $result['success'],
            'ผู้เขียนคนที่สองเดินหน้าต่อได้ทั้งที่แถวร้านถูกจองไว้ = ล็อกไม่ได้กันอะไรเลย'
        );
        $this->assertFalse($importer->inTransaction());
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /**
     * ⭐ ตั้งเป้าคนละเดือนพร้อมกัน ต้องเข้าคิว ไม่ใช่ชนกันเอง
     *
     * `monthly_goals` ยังต้องจองแถวเป้าด้วย FOR UPDATE (อ่านค่าเดิมมาคงไว้) ซึ่งกลายเป็น
     * การจอง "ช่องว่าง" เมื่อเดือนนั้นยังไม่มีเป้า สองแท็บที่ตั้งเป้าคนละเดือนของร้านเดียวกัน
     * จึงเคยจองไขว้กันจน MySQL ตัดทิ้ง · การจองแถวร้านก่อนทำให้ไปถึงตรงนั้นทีละคน
     */
    public function testTwoGoalsForDifferentMonthsDoNotCollide(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $first = $this->newConnection();
        $second = $this->newConnection();
        $second->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $firstResult = (new GoalService(new GoalRepository($first), new ShopRepository($first), $first))
            ->upsertGoal($userId, $shopId, '2026-05', 10000.0, null);
        $secondResult = (new GoalService(new GoalRepository($second), new ShopRepository($second), $second))
            ->upsertGoal($userId, $shopId, '2026-07', 20000.0, null);

        $this->assertTrue($firstResult['success'], (string)($firstResult['error'] ?? ''));
        $this->assertTrue($secondResult['success'], (string)($secondResult['error'] ?? ''));
        $this->assertSame(2, $this->countRows('monthly_goals'));
    }

    /**
     * ⭐ ตัวจองแถวร้านต้องบอกได้ว่า "จองไม่ได้" ไม่ใช่เงียบแล้วปล่อยให้ไปตายที่ foreign key
     *
     * เป็นค่าที่ service ใช้ตัดสินว่าจะตอบ "ร้านนี้ถูกลบไปแล้ว" (ข้อความที่ทำอะไรต่อได้)
     * แทน "ไม่สามารถบันทึกข้อมูลได้" (ข้อความที่อ่านแล้วไม่รู้จะทำยังไง) เมื่อร้านหายไป
     * ในจังหวะระหว่างการตรวจสิทธิ์กับการเขียนจริง
     */
    public function testTheShopLockReportsWhetherItGotTheRow(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');

        $repository = new ShopRepository($this->pdo);
        $this->pdo->beginTransaction();

        $this->assertTrue($repository->lockForWrite($shopId, $userId), 'จองร้านของตัวเองไม่ได้');
        $this->assertFalse(
            $repository->lockForWrite($strangerShop, $userId),
            'จองร้านของคนอื่นได้ — ข้อมูลจะไปตกร้านผิดคน'
        );
        $this->assertFalse($repository->lockForWrite($shopId + 9999, $userId), 'จองร้านที่ไม่มีอยู่ได้');

        $this->pdo->rollBack();
    }

    /** ร้านที่ถูกลบไปก่อนแล้ว → ปฏิเสธตั้งแต่ตรวจสิทธิ์ และต้องไม่เขียนอะไรเลย */
    public function testSavingIntoADeletedShopWritesNothing(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createShop($userId, 'ร้านที่ยังอยู่');
        $this->pdo->exec("DELETE FROM shops WHERE id = {$shopId}");

        $result = $this->recordService()->upsertRecord($userId, $shopId, '2026-08-01', 1000.0, 200.0, null);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->countRows('daily_records'));
    }

    /** ทางปกติ (ไม่มีใครแย่ง) ยังบันทึกได้ตามเดิม */
    public function testTheNormalPathIsUnaffected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->recordService()->upsertRecord($userId, $shopId, '2026-08-01', 1000.0, 200.0, 'โน้ต');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(1, $this->countRows('daily_records'));
    }
}
