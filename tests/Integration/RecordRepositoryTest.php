<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/IntegrationTestCase.php';

use RecordRepository;

final class RecordRepositoryTest extends IntegrationTestCase
{
    public function testUpsertSameShopAndDateUpdatesInsteadOfInserting(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);

        $repo = new RecordRepository($this->pdo);
        $repo->upsert($shopId, '2024-01-15', 1000.0, 200.0, 'first');
        $repo->upsert($shopId, '2024-01-15', 1500.0, 300.0, 'updated'); // shop + date เดิม → ต้องเป็น UPDATE

        // unique(shop_id, record_date) → ยังมีแถวเดียว
        $this->assertSame(1, $this->countRows('daily_records'));

        $stmt = $this->pdo->prepare(
            'SELECT revenue, ad_cost, note FROM daily_records WHERE shop_id = ? AND record_date = ?'
        );
        $stmt->execute([$shopId, '2024-01-15']);
        $row = $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame(1500.0, (float)$row['revenue']); // ค่าถูกอัปเดต
        $this->assertSame(300.0, (float)$row['ad_cost']);
        $this->assertSame('updated', $row['note']);
    }
}
