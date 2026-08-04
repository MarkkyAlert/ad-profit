<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

$logCleanupRepository = new LogCleanupRepository();

// ⚠️ ต้องเป็นโฟลเดอร์เดียวกับที่ระบบเขียน log จริง (bootstrap.php ตั้ง error_log = LOG_FILE)
// เดิม hardcode เป็น <project>/logs ซึ่ง default ไม่ใช่ที่เดียวกับ LOG_FILE
// (default = sys_get_temp_dir()/ad-profit/php-error.log) → cron กวาดโฟลเดอร์ที่มีแต่
// .gitkeep ส่วน log จริงโตขึ้นเรื่อย ๆ ไม่มีใครลบ
$logDirectory = dirname(LOG_FILE);
$retentionDays = 30;

try {
    $summary = $logCleanupRepository->deleteFilesOlderThanDays($logDirectory, $retentionDays);

    $message = sprintf(
        '[cron][cleanup-logs] dir=%s days=%d scanned_files=%d deleted_files=%d error_count=%d',
        (string)($summary['directory'] ?? $logDirectory),
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
