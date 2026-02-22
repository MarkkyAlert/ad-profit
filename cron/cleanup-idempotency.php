<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit(1);
}

$idempotencyRequestRepository = new IdempotencyRequestRepository($pdo);

try {
    $deletedRows = $idempotencyRequestRepository->deleteExpiredRequests();

    $message = sprintf(
        '[cron][cleanup-idempotency] deleted_rows=%d',
        $deletedRows
    );

    error_log($message);
    echo $message . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $message = sprintf(
        '[cron][cleanup-idempotency] failed error=%s',
        $exception->getMessage()
    );

    error_log($message);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
