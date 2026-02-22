<?php

declare(strict_types=1);

class IdempotencyRequestRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function deleteExpiredRequests(): int
    {
        $sql = 'DELETE FROM idempotency_requests
                WHERE expires_at IS NOT NULL
                  AND expires_at < NOW()';

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
