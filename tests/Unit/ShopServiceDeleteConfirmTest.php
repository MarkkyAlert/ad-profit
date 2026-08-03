<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ShopRepository;
use ShopService;
use UserRepository;

/**
 * การยืนยันลบร้านด้วยการพิมพ์ชื่อ — ย้ายจาก api/shops.php มาอยู่ที่ service จึงเทสต์ได้
 */
final class ShopServiceDeleteConfirmTest extends TestCase
{
    private function makeService(string $shopName): ShopService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn([
            'id' => 1,
            'user_id' => 1,
            'name' => $shopName,
        ]);
        $shopRepository->method('countByUserIdForUpdate')->willReturn(3);
        $shopRepository->method('countByUserId')->willReturn(3);
        $shopRepository->method('deleteByIdAndUserId')->willReturn(true);
        $shopRepository->method('getFirstByUserId')->willReturn(['id' => 2, 'name' => 'ร้านถัดไป']);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        return new ShopService($shopRepository, $this->createStub(UserRepository::class), $pdo);
    }

    public function testDeletesWhenTypedNameMatches(): void
    {
        $result = $this->makeService('ร้านคอร์ส')->deleteShop(1, 1, 'ร้านคอร์ส');

        $this->assertTrue($result['success']);
    }

    public function testRejectsWhenTypedNameDoesNotMatch(): void
    {
        $result = $this->makeService('ร้านคอร์ส')->deleteShop(1, 1, 'ร้านอื่น');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('พิมพ์ชื่อร้านให้ตรง', $result['error']);
    }

    public function testRejectsWhenNothingWasTyped(): void
    {
        $this->assertFalse($this->makeService('ร้านคอร์ส')->deleteShop(1, 1, '')['success']);
    }

    /** เบราว์เซอร์ trim ค่าที่พิมพ์เสมอ ฝั่ง service จึงต้องยอมรับช่องว่างหัวท้าย */
    public function testIgnoresSurroundingWhitespaceInTypedName(): void
    {
        $this->assertTrue($this->makeService('ร้านคอร์ส')->deleteShop(1, 1, '  ร้านคอร์ส  ')['success']);
    }

    /**
     * ชื่อยาวเกิน 20 ตัว → พิมพ์แค่ 20 ตัวแรก
     *
     * ⭐ เคสที่เคยพังถาวร: ตัวที่ 20 พอดีเป็นช่องว่าง ฝั่งเซิร์ฟเวอร์เดิมคาดหวังค่าที่
     * ลงท้ายด้วยช่องว่าง ขณะที่เบราว์เซอร์ trim ก่อนส่งเสมอ → ไม่มีวันตรงกัน ลบไม่ได้เลย
     */
    public function testLongNameWhoseCutoffLandsOnASpace(): void
    {
        // 20 ตัวแรกคือ 'ร้านขายของออนไลน์ ก ' (ตัวที่ 20 เป็นช่องว่าง)
        $shopName = 'ร้านขายของออนไลน์ ก ทดสอบยาวมาก';
        $expected = ShopService::confirmationNameFor($shopName);

        $this->assertSame('ร้านขายของออนไลน์ ก', $expected);
        $this->assertTrue($this->makeService($shopName)->deleteShop(1, 1, $expected)['success']);
    }

    public function testConfirmationNameTruncatesLongNames(): void
    {
        $this->assertSame(
            str_repeat('ก', ShopService::CONFIRM_NAME_MAX_LENGTH),
            ShopService::confirmationNameFor(str_repeat('ก', 50))
        );
    }

    public function testConfirmationNameKeepsShortNamesIntact(): void
    {
        $this->assertSame('ร้านสั้น', ShopService::confirmationNameFor('  ร้านสั้น  '));
    }

    /** ลบร้านสุดท้ายต้องตอบเหตุผลนั้น ไม่ใช่ไปติดที่การยืนยันชื่อก่อน */
    public function testLastShopErrorTakesPrecedenceOverConfirmation(): void
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn(['id' => 1, 'user_id' => 1, 'name' => 'ร้านเดียว']);
        $shopRepository->method('countByUserIdForUpdate')->willReturn(1);
        $shopRepository->method('countByUserId')->willReturn(1);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        $service = new ShopService($shopRepository, $this->createStub(UserRepository::class), $pdo);
        $result = $service->deleteShop(1, 1, 'พิมพ์ผิด');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ร้านสุดท้าย', $result['error']);
    }
}
