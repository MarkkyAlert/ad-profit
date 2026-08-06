<?php

declare(strict_types=1);

/**
 * ตัวนับ rate limit แบบ persistent บนตาราง auth_rate_limits
 *
 * ใช้กับ flow ที่ต้องจำกัดข้าม session/browser โดยยังใช้ schema เดิมของระบบ.
 */
class RateLimitRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * จอง 1 attempt แบบ atomic แล้วคืนจำนวน attempt ล่าสุดใน window นั้น
     */
    public function reserveAttempt(
        string $action,
        string $clientIp,
        string $subject,
        int $windowSeconds
    ): int {
        $marked = false;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                if (!$marked) {
                    $this->markAttempt($action, $clientIp, $subject, $windowSeconds);
                    $marked = true;
                }

                return $this->currentAttempts($action, $clientIp, $subject, $windowSeconds);
            } catch (Throwable $exception) {
                if ($attempt < 3 && $this->isRetryableLockError($exception)) {
                    usleep(random_int(2000, 12000));
                    continue;
                }

                error_log('[rate_limit] reserveAttempt failed, denying by default: ' . $exception->getMessage());

                return PHP_INT_MAX;
            }
        }

        return PHP_INT_MAX;
    }

    public function releaseAttempt(string $action, string $clientIp, string $subject): void
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE auth_rate_limits
                 SET attempts = GREATEST(0, attempts - 1), updated_at = NOW()
                 WHERE bucket_key = :bucket_key'
            );
            $stmt->execute([':bucket_key' => $this->bucketKey($action, $clientIp, $subject)]);
        } catch (Throwable $exception) {
            error_log('[rate_limit] releaseAttempt failed: ' . $exception->getMessage());
        }
    }

    private function currentAttempts(string $action, string $clientIp, string $subject, int $windowSeconds): int
    {
        $stmt = $this->db->prepare(
            'SELECT attempts, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS window_age_seconds
             FROM auth_rate_limits
             WHERE bucket_key = :bucket_key
             LIMIT 1'
        );
        $stmt->execute([':bucket_key' => $this->bucketKey($action, $clientIp, $subject)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return 0;
        }

        return (int)($row['window_age_seconds'] ?? 0) >= $windowSeconds
            ? 0
            : (int)($row['attempts'] ?? 0);
    }

    private function markAttempt(string $action, string $clientIp, string $subject, int $windowSeconds): void
    {
        $sql = 'INSERT INTO auth_rate_limits (bucket_key, action_type, client_ip, attempts, started_at, updated_at)
                VALUES (:bucket_key, :action_type, :client_ip, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempts = CASE
                        WHEN TIMESTAMPDIFF(SECOND, started_at, NOW()) >= :window_attempts THEN 1
                        ELSE attempts + 1
                    END,
                    started_at = CASE
                        WHEN TIMESTAMPDIFF(SECOND, started_at, NOW()) >= :window_started THEN NOW()
                        ELSE started_at
                    END,
                    updated_at = NOW()';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':bucket_key' => $this->bucketKey($action, $clientIp, $subject),
            ':action_type' => $action,
            ':client_ip' => $clientIp,
            ':window_attempts' => $windowSeconds,
            ':window_started' => $windowSeconds,
        ]);
    }

    private function bucketKey(string $action, string $clientIp, string $subject): string
    {
        return hash('sha256', $action . '|' . $clientIp . '|' . strtolower(trim($subject)));
    }

    private function isRetryableLockError(Throwable $exception): bool
    {
        $code = $exception instanceof PDOException ? (string)($exception->errorInfo[1] ?? '') : '';

        return $code === '1213' || $code === '1205';
    }
}
