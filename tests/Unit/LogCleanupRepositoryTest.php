<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogCleanupRepository;
use PHPUnit\Framework\TestCase;

/**
 * ตัวลบไฟล์ log เก่า — เดิมไม่มีเทสต์เลยแม้แต่เคสเดียว
 *
 * ปัญหาที่เจอ: ไม่กรองชื่อไฟล์ จึงลบ "ทุกไฟล์" ในโฟลเดอร์ที่เก่ากว่ากำหนด
 * ถ้าผู้ดูแลวาง .htaccess ไว้กันไม่ให้เข้าถึง log ผ่านเว็บ ไฟล์นั้นจะถูกลบทิ้ง
 * แล้ว log กลับมาเปิดให้เข้าถึงได้อีกครั้งโดยไม่มีใครรู้
 */
final class LogCleanupRepositoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ad-profit-log-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        // ใช้ scandir ไม่ใช่ glob — glob ไม่เห็นไฟล์ที่ขึ้นต้นด้วยจุด (.htaccess/.gitkeep)
        foreach ((array)scandir($this->directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->directory . '/' . $entry;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function makeFile(string $name, int $ageDays): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, time() - ($ageDays * 86400));

        return $path;
    }

    /** ⭐ ลบเฉพาะไฟล์ log — ไฟล์อื่นในโฟลเดอร์เดียวกันต้องอยู่ครบ */
    public function testOnlyLogFilesAreDeleted(): void
    {
        $oldLog = $this->makeFile('php-error.log', 60);
        $rotated = $this->makeFile('php-error.log.3', 60);
        $htaccess = $this->makeFile('.htaccess', 60);
        $gitkeep = $this->makeFile('.gitkeep', 60);
        $other = $this->makeFile('backup.sql', 60);

        $summary = (new LogCleanupRepository())->deleteFilesOlderThanDays($this->directory, 30);

        $this->assertTrue($summary['success']);
        $this->assertFileDoesNotExist($oldLog);
        $this->assertFileDoesNotExist($rotated);
        $this->assertFileExists($htaccess, '.htaccess ถูกลบ — log จะกลับมาเข้าถึงผ่านเว็บได้');
        $this->assertFileExists($gitkeep);
        $this->assertFileExists($other, 'ไฟล์ที่ไม่เกี่ยวกับ log ถูกลบ');
    }

    /** ไฟล์ที่ยังไม่ถึงกำหนดต้องอยู่ */
    public function testRecentLogsAreKept(): void
    {
        $recent = $this->makeFile('php-error.log', 5);

        (new LogCleanupRepository())->deleteFilesOlderThanDays($this->directory, 30);

        $this->assertFileExists($recent);
    }

    public function testCountsOnlyWhatItActuallyConsidered(): void
    {
        $this->makeFile('php-error.log', 60);
        $this->makeFile('backup.sql', 60);

        $summary = (new LogCleanupRepository())->deleteFilesOlderThanDays($this->directory, 30);

        $this->assertSame(1, $summary['scanned_count'], 'นับไฟล์ที่ไม่ใช่ log รวมเข้าไปด้วย');
        $this->assertSame(1, $summary['deleted_count']);
    }

    public function testMissingDirectoryIsReportedNotFatal(): void
    {
        $summary = (new LogCleanupRepository())->deleteFilesOlderThanDays($this->directory . '/nope', 30);

        $this->assertFalse($summary['success']);
        $this->assertSame(1, $summary['error_count']);
    }
}
