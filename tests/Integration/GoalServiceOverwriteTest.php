<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use GoalRepository;
use GoalService;
use ShopRepository;

/**
 * เป้าหมายที่ตั้งไว้ต้องไม่หายเพราะกดบันทึกจากฟอร์มที่เปิดค้างไว้
 *
 * `daily_records` มีตัวกัน "เว้นช่องแล้วของเดิมหาย" แล้ว แต่ `monthly_goals` ไม่มี —
 * เปิดหน้าแดชบอร์ดตอนที่ยังตั้งแค่เป้ารายได้ → ตั้งเป้ากำไรจากอีกแท็บ → กลับมากดบันทึก
 * ที่แท็บเดิม → เป้ากำไรหายไปพร้อมข้อความ "บันทึกเป้าหมายเรียบร้อยแล้ว"
 *
 * ตัดสินแล้ว: ช่องว่าง = คงค่าเดิมไว้ · จะล้างทั้งเดือนใช้ปุ่ม "ลบเป้าหมาย"
 */
final class GoalServiceOverwriteTest extends IntegrationTestCase
{
    private function makeService(): GoalService
    {
        return new GoalService(new GoalRepository($this->pdo), new ShopRepository($this->pdo), $this->pdo);
    }

    /**
     * @return array{revenue:float|null,profit:float|null}
     */
    private function storedGoal(int $shopId): array
    {
        $row = $this->pdo
            ->query("SELECT target_revenue, target_profit FROM monthly_goals WHERE shop_id = {$shopId}")
            ->fetch();

        return [
            'revenue' => $row['target_revenue'] === null ? null : (float)$row['target_revenue'],
            'profit' => $row['target_profit'] === null ? null : (float)$row['target_profit'],
        ];
    }

    /** ⭐ ส่งมาแค่เป้ารายได้ → เป้ากำไรเดิมต้องอยู่ครบ */
    public function testSubmittingOnlyOneTargetKeepsTheOther(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $service = $this->makeService();

        $service->upsertGoal($userId, $shopId, '2026-08', 50000.0, 20000.0);
        $result = $service->upsertGoal($userId, $shopId, '2026-08', 60000.0, null);

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertSame(
            ['revenue' => 60000.0, 'profit' => 20000.0],
            $this->storedGoal($shopId),
            'เป้ากำไรหายไปทั้งที่ผู้ใช้ไม่ได้ตั้งใจลบ'
        );
    }

    /** ส่งมาแค่เป้ากำไร → เป้ารายได้เดิมต้องอยู่ครบ */
    public function testTheSameHoldsForTheOtherField(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $service = $this->makeService();

        $service->upsertGoal($userId, $shopId, '2026-08', 50000.0, 20000.0);
        $service->upsertGoal($userId, $shopId, '2026-08', null, 30000.0);

        $this->assertSame(['revenue' => 50000.0, 'profit' => 30000.0], $this->storedGoal($shopId));
    }

    /** เดือนใหม่ที่ยังไม่เคยตั้งเป้า — ช่องว่างยังเป็น null ตามเดิม */
    public function testANewMonthStillStoresNullForTheBlankField(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $this->makeService()->upsertGoal($userId, $shopId, '2026-09', 40000.0, null);

        $this->assertSame(['revenue' => 40000.0, 'profit' => null], $this->storedGoal($shopId));
    }

    /** ตั้งทั้งสองช่องยังเขียนทับได้ตามปกติ */
    public function testSubmittingBothTargetsOverwritesBoth(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $service = $this->makeService();

        $service->upsertGoal($userId, $shopId, '2026-08', 50000.0, 20000.0);
        $service->upsertGoal($userId, $shopId, '2026-08', 70000.0, 35000.0);

        $this->assertSame(['revenue' => 70000.0, 'profit' => 35000.0], $this->storedGoal($shopId));
    }

    /** ล้างทั้งเดือนยังทำได้ผ่านการลบเป้าหมาย */
    public function testDeletingTheGoalStillClearsEverything(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $service = $this->makeService();

        $service->upsertGoal($userId, $shopId, '2026-08', 50000.0, 20000.0);
        $service->deleteGoal($userId, $shopId, '2026-08');

        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /**
     * ⭐ เป้าติดลบต้องถูกปฏิเสธ — ตัวเลขติดลบไม่มีความหมายในฐานะเป้าหมาย
     *
     * ⚠️ ต้องเทสต์ที่ชั้น Service ด้วย ไม่ใช่แค่ endpoint — ผ่านหน้าเว็บ ตัวอ่านตัวเลข
     * อาจกรองไปก่อน แต่กติกาธุรกิจเป็นของ Service และมีทางเรียกอื่นได้ในอนาคต
     */
    public function testANegativeTargetIsRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        foreach ([[-1.0, null], [null, -0.01]] as [$revenue, $profit]) {
            $result = $this->makeService()->upsertGoal($userId, $shopId, '2026-08', $revenue, $profit);

            $this->assertFalse($result['success'], 'เป้าติดลบผ่านเข้าไปได้');
            $this->assertStringContainsString('ติดลบ', (string)$result['error']);
        }

        $this->assertSame(0, $this->countRows('monthly_goals'));
    }

    /** ⭐ ตั้ง/ลบเป้าของร้านที่ไม่ใช่ของตัวเองไม่ได้ (ด่านสิทธิ์ในชั้น Service) */
    public function testAForeignShopIsRejectedForBothUpsertAndDelete(): void
    {
        $userId = $this->createUser();
        $strangerId = $this->createUser('stranger@example.com');
        $strangerShop = $this->createShop($strangerId, 'ร้านของคนอื่น');
        $this->createGoal($strangerShop, '2026-08', 50000.0, null);

        $service = $this->makeService();

        $upsert = $service->upsertGoal($userId, $strangerShop, '2026-08', 99999.0, null);
        $this->assertFalse($upsert['success'], 'ตั้งเป้าลงร้านของคนอื่นได้');
        $this->assertStringContainsString('ไม่มีสิทธิ์', (string)$upsert['error']);

        $delete = $service->deleteGoal($userId, $strangerShop, '2026-08');
        $this->assertFalse($delete['success'], 'ลบเป้าของร้านคนอื่นได้');
        $this->assertStringContainsString('ไม่มีสิทธิ์', (string)$delete['error']);

        $this->assertSame(
            ['revenue' => 50000.0, 'profit' => null],
            $this->storedGoal($strangerShop),
            'เป้าของคนอื่นถูกแก้หรือถูกลบ'
        );
    }

    /** ไม่กรอกเลยทั้งสองช่องตอนที่ยังไม่มีเป้า ยังต้องถูกปฏิเสธเหมือนเดิม */
    public function testSubmittingNothingOnAMonthWithoutAGoalIsStillRejected(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $result = $this->makeService()->upsertGoal($userId, $shopId, '2026-08', null, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('อย่างน้อย 1', (string)$result['error']);
    }
}
