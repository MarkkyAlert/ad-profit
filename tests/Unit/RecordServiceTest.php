<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use ShopRepository;

final class RecordServiceTest extends TestCase
{
    /**
     * สร้าง service โดย mock repository ทั้งคู่ และส่ง db = null (ข้าม transaction/lock)
     * userCanAccessShop คุมได้ผ่านพารามิเตอร์ $canAccess
     */
    private function makeService(bool $canAccess = true): RecordService
    {
        $recordRepository = $this->createStub(RecordRepository::class);

        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn($canAccess);

        return new RecordService($recordRepository, $shopRepository, null);
    }

    public function testUpsertFailsWhenRevenueIsNegative(): void
    {
        $service = $this->makeService();

        $result = $service->upsertRecord(1, 1, '2024-01-15', -1.0, 0.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ติดลบ', $result['error']);
    }

    public function testUpsertFailsWhenAdCostIsNegative(): void
    {
        $service = $this->makeService();

        $result = $service->upsertRecord(1, 1, '2024-01-15', 100.0, -5.0, null);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ติดลบ', $result['error']);
    }

    public function testUpsertFailsWhenNoteExceeds255Chars(): void
    {
        $service = $this->makeService();

        $longNote = str_repeat('ก', 256); // 256 ตัวอักษร (mb) > 255

        $result = $service->upsertRecord(1, 1, '2024-01-15', 100.0, 10.0, $longNote);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('255', $result['error']);
    }
}
