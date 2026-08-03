<?php

declare(strict_types=1);

namespace Tests\Unit;

use GoalRepository;
use GoalService;
use PHPUnit\Framework\TestCase;
use ShopRepository;

/**
 * การตรวจรูปแบบเดือนของ GoalService
 *
 * เคยเป็น dead code: controller เรียก normalize_month_input() ก่อน ซึ่ง "ซ่อม" ค่าที่ผิด
 * รูปแบบให้กลายเป็นเดือนปัจจุบันเงียบ ๆ → service ได้รับค่าที่ถูกต้องเสมอ
 * ผลคือ POST เดือนพัง ๆ ไปที่ action=delete จะลบเป้าของ "เดือนปัจจุบัน" แทน
 */
final class GoalServiceMonthValidationTest extends TestCase
{
    /** เดือนที่ต้องถูกปฏิเสธ — รวมเคสที่ผ่าน regex \d{4}-\d{2} แต่ไม่มีอยู่จริง */
    private const MALFORMED_MONTHS = [
        '2024-13',      // เดือนเกิน 12
        '2024-00',      // เดือน 0
        '2024-99',
        '2024-1',       // ไม่ zero-pad
        '24-01',
        '2024/01',
        '',
        'ไม่ใช่เดือน',
    ];

    private function makeService(): GoalService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'name' => 'ร้านของฉัน',
        ]);

        return new GoalService($this->createStub(GoalRepository::class), $shopRepository, null);
    }

    public function testUpsertRejectsMalformedMonth(): void
    {
        $service = $this->makeService();

        foreach (self::MALFORMED_MONTHS as $month) {
            $result = $service->upsertGoal(1, 1, $month, 1000.0, 500.0);

            $this->assertFalse($result['success'], "ยอมรับเดือน '{$month}' ทั้งที่ไม่ถูกต้อง");
            $this->assertStringContainsString('เดือน', $result['error']);
        }
    }

    public function testDeleteRejectsMalformedMonth(): void
    {
        $service = $this->makeService();

        foreach (self::MALFORMED_MONTHS as $month) {
            $result = $service->deleteGoal(1, 1, $month);

            $this->assertFalse($result['success'], "ยอมลบด้วยเดือน '{$month}' ทั้งที่ไม่ถูกต้อง");
            $this->assertStringContainsString('เดือน', $result['error']);
        }
    }

    public function testAcceptsEveryValidMonthOfTheYear(): void
    {
        $service = $this->makeService();

        foreach (range(1, 12) as $month) {
            $result = $service->upsertGoal(1, 1, sprintf('2026-%02d', $month), 1000.0, null);

            $this->assertTrue($result['success'], sprintf('ปฏิเสธเดือน 2026-%02d ที่ถูกต้อง', $month));
        }
    }
}
