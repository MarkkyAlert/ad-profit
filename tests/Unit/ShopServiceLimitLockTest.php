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

    /**
     * ⭐ ชื่อที่มีอยู่แล้ว = สลับไปร้านนั้น ไม่ใช่การสร้างใหม่ → โควตาไม่ควรมาขวาง
     *
     * เดิมเช็กโควตาก่อนเช็กชื่อซ้ำ ผู้ใช้ที่มีครบ 20 ร้านจึงพิมพ์ชื่อร้านที่ตัวเองมีอยู่แล้ว
     * ไม่ได้เลย ได้ข้อความ "สร้างร้านเพิ่มไม่ได้" ทั้งที่ไม่มีแถวใหม่เกิดขึ้นสักแถว
     */
    public function testExistingShopNameStillSwitchesWhenAlreadyAtTheLimit(): void
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('countByUserId')->willReturn(ShopService::MAX_SHOPS_PER_USER);
        $shopRepository->method('countByUserIdForUpdate')->willReturn(ShopService::MAX_SHOPS_PER_USER);
        $shopRepository->method('findByNameAndUserId')->willReturn(['id' => 7, 'name' => 'ร้านหลัก']);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        $result = (new ShopService($shopRepository, $this->createStub(UserRepository::class), $pdo))
            ->createShop(1, 'ร้านหลัก');

        $this->assertTrue($result['success'], (string)($result['error'] ?? ''));
        $this->assertTrue($result['already_exists']);
        $this->assertSame(7, $result['shop_id']);
    }

    /** ชื่อใหม่จริง ๆ ตอนครบโควตา ยังต้องถูกปฏิเสธเหมือนเดิม */
    public function testABrandNewNameIsStillRejectedAtTheLimit(): void
    {
        $result = $this->makeService(
            ShopService::MAX_SHOPS_PER_USER,
            ShopService::MAX_SHOPS_PER_USER
        )->createShop(1, 'ร้านที่ยังไม่มี');

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
