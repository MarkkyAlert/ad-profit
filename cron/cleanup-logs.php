<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

$logCleanupRepository = new LogCleanupRepository();
$logDirectory = dirname(__DIR__) . '/logs';
$retentionDays = 30;

try {
    $summary = $logCleanupRepository->deleteFilesOlderThanDays($logDirectory, $retentionDays);

    $message = sprintf(
        '[cron][cleanup-logs] days=%d scanned_files=%d deleted_files=%d error_count=%d',
        (int)($summary['days'] ?? $retentionDays),
        (int)($summary['scanned_count'] ?? 0),
        (int)($summary['deleted_count'] ?? 0),
        (int)($summary['error_count'] ?? 0)
    );

    error_log($message);

    $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
    foreach ($errors as $errorMessage) {
        error_log('[cron][cleanup-logs] detail=' . (string)$errorMessage);
    }

    echo $message . PHP_EOL;

    if (($summary['success'] ?? false) !== true) {
        fwrite(STDERR, '[cron][cleanup-logs] completed with errors' . PHP_EOL);
        exit(1);
    }

    exit(0);
} catch (Throwable $exception) {
    $message = sprintf(
        '[cron][cleanup-logs] failed error=%s',
        $exception->getMessage()
    );

    error_log($message);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
