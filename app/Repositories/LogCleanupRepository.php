<?php

declare(strict_types=1);

class LogCleanupRepository
{
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
