<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ShopRepository;
use ShopService;
use UserRepository;

/**
 * renameShop / switchShop / getShopContext — สามเมธอดนี้ไม่เคยมีเทสต์ครอบเลย
 * ทั้งที่ getShopContext เป็นตัวกัน current_shop_id ที่ค้างอยู่ไม่ให้ชี้ร้านของคนอื่น
 */
final class ShopServiceRenameSwitchTest extends TestCase
{
    /**
     * @param array<string,mixed>|null $ownShop ร้านที่ค้นแล้วเป็นของผู้ใช้ (null = ไม่ใช่ของเขา)
     * @param array<int,array<string,mixed>> $allShops
     */
    private function makeService(
        ?array $ownShop = ['id' => 1, 'user_id' => 1, 'name' => 'ร้านเดิม'],
        array $allShops = [],
        bool $renameSucceeds = true
    ): ShopService {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn($ownShop);
        $shopRepository->method('getFirstByUserId')->willReturn($ownShop);
        $shopRepository->method('listByUserId')->willReturn($allShops);
        $shopRepository->method('findByNameAndUserId')->willReturn(null);
        $shopRepository->method('updateNameByIdAndUserId')->willReturn($renameSucceeds);

        // renameShop ล็อกแถวด้วย $pdo->prepare(...)->execute() จริง → ต้อง stub statement ด้วย
        // ไม่งั้นจะระเบิดแล้วถูก catch กลายเป็น "ไม่สามารถอัปเดตชื่อร้านค้าได้" ทุกเคส
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        return new ShopService($shopRepository, $this->createStub(UserRepository::class), $pdo);
    }

    // ── renameShop ──────────────────────────────────────────────────────────

    public function testRenameRejectsEmptyName(): void
    {
        $result = $this->makeService()->renameShop(1, 1, '   ');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ชื่อร้าน', $result['error']);
    }

    public function testRenameRejectsNameLongerThanLimit(): void
    {
        $result = $this->makeService()->renameShop(1, 1, str_repeat('ก', 101));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('100', $result['error']);
    }

    public function testRenameRejectsShopThatIsNotOwned(): void
    {
        $result = $this->makeService(null)->renameShop(1, 999, 'ชื่อใหม่');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    /** ชื่อที่บันทึกต้องถูก trim ก่อน ไม่งั้นชื่อร้านมีช่องว่างหัวท้ายติดไปด้วย */
    public function testRenameTrimsTheNameBeforeSaving(): void
    {
        $shop = ['id' => 1, 'user_id' => 1, 'name' => 'ร้านเดิม'];

        $shopRepository = $this->createMock(ShopRepository::class);
        $shopRepository->method('findByIdAndUserId')->willReturn($shop);
        $shopRepository->method('findByNameAndUserId')->willReturn(null);
        $shopRepository->expects($this->once())
            ->method('updateNameByIdAndUserId')
            ->with(1, 1, 'ชื่อใหม่')
            ->willReturn(true);

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        $service = new ShopService($shopRepository, $this->createStub(UserRepository::class), $pdo);
        $result = $service->renameShop(1, 1, '  ชื่อใหม่  ');

        $this->assertTrue($result['success']);
    }

    // ── switchShop ──────────────────────────────────────────────────────────

    public function testSwitchRejectsShopThatIsNotOwned(): void
    {
        $result = $this->makeService(null)->switchShop(1, 999);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ไม่มีสิทธิ์', $result['error']);
    }

    public function testSwitchReturnsTheShop(): void
    {
        $result = $this->makeService()->switchShop(1, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int)$result['shop']['id']);
    }

    // ── getShopContext ──────────────────────────────────────────────────────

    /** ⭐ current_shop_id ที่ไม่ใช่ของผู้ใช้ ต้องตกกลับไปร้านแรกของตัวเอง */
    public function testForeignCurrentShopFallsBackToOwnFirstShop(): void
    {
        $shops = [
            ['id' => 7, 'name' => 'ร้านของฉัน A'],
            ['id' => 8, 'name' => 'ร้านของฉัน B'],
        ];

        $context = $this->makeService(null, $shops)->getShopContext(1, 999);

        $this->assertSame(7, (int)$context['current_shop']['id']);
        $this->assertCount(2, $context['shops']);
    }

    public function testKeepsCurrentShopWhenItBelongsToTheUser(): void
    {
        $shops = [
            ['id' => 7, 'name' => 'ร้านของฉัน A'],
            ['id' => 8, 'name' => 'ร้านของฉัน B'],
        ];

        $context = $this->makeService(null, $shops)->getShopContext(1, 8);

        $this->assertSame(8, (int)$context['current_shop']['id']);
    }

    public function testNoShopsGivesNullCurrentShop(): void
    {
        $context = $this->makeService(null, [])->getShopContext(1, 5);

        $this->assertNull($context['current_shop']);
        $this->assertSame([], $context['shops']);
    }
}
