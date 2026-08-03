<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ShopRepository;
use ShopService;
use UserRepository;

/**
 * เพดาน 20 ร้านต้องตัดสินด้วยค่าที่อ่าน "ใต้ล็อก" ไม่ใช่ค่าที่อ่านก่อนเปิดทรานแซกชัน
 *
 * ShopServiceTest เดิมส่ง $db = null จึงข้ามสาขา transaction/lock ทั้งหมด
 * เทสต์นี้ส่ง PDO จำลองเข้าไปเพื่อให้สาขานั้นถูกรันจริง
 */
final class ShopServiceLimitLockTest extends TestCase
{
    /**
     * @param int $countBeforeLock ค่าที่เห็นก่อนเข้าล็อก
     * @param int $countUnderLock  ค่าจริงหลังได้ล็อก (แท็บอื่น commit ไปแล้ว)
     */
    private function makeService(int $countBeforeLock, int $countUnderLock): ShopService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('countByUserId')->willReturn($countBeforeLock);
        $shopRepository->method('countByUserIdForUpdate')->willReturn($countUnderLock);
        $shopRepository->method('findByNameAndUserId')->willReturn(null);
        $shopRepository->method('create')->willReturn(99);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        return new ShopService($shopRepository, $this->createStub(UserRepository::class), $pdo);
    }

    /**
     * เคสแข่งกัน: ตอนอ่านครั้งแรกยังมี 19 ร้าน แต่พอได้ล็อกจริงกลายเป็น 20 แล้ว
     * (อีกแท็บสร้างสำเร็จไปก่อน) — ต้องปฏิเสธ ไม่ใช่สร้างเป็นร้านที่ 21
     */
    public function testRejectsWhenTheCountReachesTheLimitWhileWaitingForTheLock(): void
    {
        $result = $this->makeService(19, ShopService::MAX_SHOPS_PER_USER)->createShop(1, 'ร้านใหม่');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('20', $result['error']);
    }

    public function testAllowsCreationWhenStillUnderTheLimitUnderLock(): void
    {
        $result = $this->makeService(18, 19)->createShop(1, 'ร้านใหม่');

        $this->assertTrue($result['success']);
        $this->assertSame(99, $result['shop_id']);
    }

    /** ถึงเพดานตั้งแต่ก่อนเข้าล็อก → ตอบเลยไม่ต้องเปิดทรานแซกชัน */
    public function testRejectsImmediatelyWhenAlreadyAtTheLimit(): void
    {
        $result = $this->makeService(ShopService::MAX_SHOPS_PER_USER, ShopService::MAX_SHOPS_PER_USER)
            ->createShop(1, 'ร้านใหม่');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('20', $result['error']);
    }
}
