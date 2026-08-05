<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;
use RecordRepository;
use RecordService;
use RuntimeException;
use ShopRepository;

/**
 * ชนคิวกับอีกหน้าจอ ≠ ระบบพัง — ข้อความต้องบอกให้กดใหม่
 *
 * ⚠️ ตั้งแต่ทุกทางที่เขียนข้อมูลจองแถวร้านก่อน (`lockForWrite`) การรอคิวเป็นเรื่องปกติ
 * ที่เกิดได้ทุกวัน เช่น กดแก้รายการตอนที่อีกแท็บกำลังนำเข้าไฟล์ CSV ชุดใหญ่
 *
 * `upsertRecord` / `upsertManyRecords` / `GoalService::upsertGoal` แปลงให้แล้ว
 * แต่ `updateRecord` / `deleteRecord` ตกหล่น ตอบว่า "ไม่สามารถลบรายการได้" ลอย ๆ
 * ซึ่งอ่านแล้วนึกว่าข้อมูลมีปัญหา ทั้งที่แค่กดใหม่อีกครั้งก็ผ่าน
 */
final class WriteFailureMessageTest extends TestCase
{
    private const RETRY_MESSAGE = 'มีการบันทึกจากอีกหน้าจอในเวลาเดียวกัน กรุณากดบันทึกอีกครั้ง';

    /** สร้าง error แบบเดียวกับที่ MySQL ส่งมาตอนชนคิว */
    private function lockError(string $code): PDOException
    {
        $exception = new PDOException('SQLSTATE[HY000]: General error: ' . $code);
        $exception->errorInfo = ['HY000', (int)$code, 'lock wait'];

        return $exception;
    }

    private function serviceThatFailsWith(\Throwable $exception): RecordService
    {
        $shopRepository = $this->createStub(ShopRepository::class);
        $shopRepository->method('userCanAccessShop')->willReturn(true);
        $shopRepository->method('lockForWrite')->willReturn(true);

        // ⚠️ ต้องให้ repository เป็นตัวโยน ไม่ใช่ shop repository — service รับ `?PDO`
        // และเมื่อส่ง null มันจะข้ามบล็อกทรานแซกชัน/ล็อกไปเลย ตัวที่โยนตรงนั้นจึงไม่ถูกเรียก
        $recordRepository = $this->createStub(RecordRepository::class);
        $recordRepository->method('findByIdAndShopId')->willThrowException($exception);
        $recordRepository->method('findByIdAndShopIdForUpdate')->willThrowException($exception);

        return new RecordService($recordRepository, $shopRepository);
    }

    /**
     * ⭐ รอคิวนานเกินไป (1205) และชนกันจนต้องยกเลิกฝ่ายหนึ่ง (1213)
     *
     * @return array<string,array{0:string}>
     */
    public static function lockErrorProvider(): array
    {
        return [
            'รอคิวนานเกินไป' => ['1205'],
            'ชนกันจนต้องยกเลิกฝ่ายหนึ่ง' => ['1213'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lockErrorProvider')]
    public function testUpdatingARecordTellsTheUserToTryAgain(string $code): void
    {
        $result = $this->serviceThatFailsWith($this->lockError($code))
            ->updateRecord(1, 1, 1, '2026-08-01', 1000.0, 100.0, null);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame(self::RETRY_MESSAGE, $result['error'] ?? '', "รหัส {$code} ไม่ได้บอกให้กดใหม่");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lockErrorProvider')]
    public function testDeletingARecordTellsTheUserToTryAgain(string $code): void
    {
        $result = $this->serviceThatFailsWith($this->lockError($code))->deleteRecord(1, 1, 1);

        $this->assertFalse($result['success'] ?? true);
        $this->assertSame(self::RETRY_MESSAGE, $result['error'] ?? '', "รหัส {$code} ไม่ได้บอกให้กดใหม่");
    }

    /**
     * ⭐ ปัญหาอื่นที่ไม่ใช่การชนคิว ต้องไม่ถูกแปลงเป็น "กดใหม่อีกครั้ง"
     *
     * ไม่งั้นผู้ใช้จะกดซ้ำไปเรื่อย ๆ กับปัญหาที่กดกี่ครั้งก็ไม่ผ่าน
     */
    public function testARealFailureIsNotDisguisedAsARetry(): void
    {
        $result = $this->serviceThatFailsWith(new RuntimeException('ดิสก์เต็ม'))
            ->deleteRecord(1, 1, 1);

        $this->assertFalse($result['success'] ?? true);
        $this->assertNotSame(
            self::RETRY_MESSAGE,
            $result['error'] ?? '',
            'ปัญหาที่กดใหม่ก็ไม่หาย กลับบอกให้กดใหม่'
        );
    }
}
