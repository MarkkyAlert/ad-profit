<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

// PasswordResetRepository::deleteExpired() มีมาตั้งแต่แรกแต่ไม่เคยถูกเรียกจากที่ไหน
// แถว token ที่หมดอายุจึงค้างสะสมไปเรื่อย ๆ (ใช้ไม่ได้แล้ว แต่ยังเก็บ hash ไว้)
$passwordResetRepository = new PasswordResetRepository($pdo);

try {
    $deletedRows = $passwordResetRepository->deleteExpired();

    $message = sprintf('[cron][cleanup-password-reset-tokens] deleted_rows=%d', $deletedRows);

    error_log($message);
    echo $message . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $message = sprintf(
        '[cron][cleanup-password-reset-tokens] failed error=%s',
        $exception->getMessage()
    );

    error_log($message);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
