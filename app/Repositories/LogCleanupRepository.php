<?php

declare(strict_types=1);

class LogCleanupRepository
{
    /**
     * ชื่อไฟล์นี้เป็นไฟล์ log ไหม
     *
     * รับ `xxx.log` · ไฟล์ที่หมุนแล้ว (`xxx.log.1`, `xxx.log.gz`, `xxx.log-20260101`)
     * และชื่อที่โฮสต์สร้างเองแบบไม่มีนามสกุล (`error_log` ของ cPanel/Hostinger)
     *
     * ⚠️ `LOG_FILE` ตั้งได้จาก env — ถ้าชื่อไม่เข้าเกณฑ์เหล่านี้ cron จะรายงานว่าทำงาน
     * สำเร็จทุกวันโดยไม่ลบอะไรเลย ซึ่งเป็นอาการเดิมที่รอบก่อนตั้งใจแก้ แค่เงียบกว่า
     */
    private static function looksLikeLogFile(string $fileName): bool
    {
        $name = strtolower($fileName);

        // ชื่อที่โฮสต์สร้างเองโดยไม่มีนามสกุล (cPanel/Hostinger ใช้ `error_log`)
        if ($name === 'error_log' || $name === 'php_errorlog') {
            return true;
        }

        // `app.log` · `app.log.1` · `app.log.gz` · `app.log-20260101` (logrotate dateext)
        return preg_match('/\.log([.\-][a-z0-9]+)?$/', $name) === 1;
    }

    /**
     * @return array{
     *   success: bool,
     *   directory: string,
     *   days: int,
     *   scanned_count: int,
     *   deleted_count: int,
     *   error_count: int,
     *   errors: array<int, string>
     * }
     */
    public function deleteFilesOlderThanDays(string $directory, int $days): array
    {
        $normalizedDays = max(1, $days);
        $summary = [
            'success' => true,
            'directory' => $directory,
            'days' => $normalizedDays,
            'scanned_count' => 0,
            'deleted_count' => 0,
            'error_count' => 0,
            'errors' => [],
        ];

        if (!is_dir($directory)) {
            $summary['success'] = false;
            $summary['error_count'] = 1;
            $summary['errors'][] = 'Log directory not found: ' . $directory;
            return $summary;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            $summary['success'] = false;
            $summary['error_count'] = 1;
            $summary['errors'][] = 'Unable to read log directory: ' . $directory;
            return $summary;
        }

        $thresholdUnixTime = time() - ($normalizedDays * 86400);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $filePath = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($filePath)) {
                continue;
            }

            // ⚠️ ลบเฉพาะไฟล์ log — เดิมไม่กรองชื่อเลย จึงลบทุกไฟล์ในโฟลเดอร์
            // ถ้าผู้ดูแลวาง .htaccess ไว้กันไม่ให้เข้าถึง log ผ่านเว็บ ไฟล์นั้นจะถูกลบทิ้ง
            // แล้ว log กลับมาเปิดให้เข้าถึงได้อีกครั้งโดยไม่มีใครรู้
            if (!self::looksLikeLogFile($entry)) {
                continue;
            }

            $summary['scanned_count']++;

            $modifiedTime = filemtime($filePath);
            if ($modifiedTime === false) {
                $summary['error_count']++;
                $summary['errors'][] = 'Unable to read modified time: ' . $filePath;
                continue;
            }

            if ($modifiedTime >= $thresholdUnixTime) {
                continue;
            }

            if (@unlink($filePath) !== true) {
                $summary['error_count']++;
                $summary['errors'][] = 'Unable to delete log file: ' . $filePath;
                continue;
            }

            $summary['deleted_count']++;
        }

        $summary['success'] = $summary['error_count'] === 0;

        return $summary;
    }
}
