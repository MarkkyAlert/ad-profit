<?php

declare(strict_types=1);

class LogCleanupRepository
{
    /**
     * ชื่อไฟล์นี้เป็นไฟล์ log ไหม — รับ `xxx.log` และไฟล์ที่หมุนแล้ว (`xxx.log.1`, `xxx.log.gz`)
     */
    private static function looksLikeLogFile(string $fileName): bool
    {
        return preg_match('/\.log(\.[A-Za-z0-9]+)?$/', $fileName) === 1;
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
