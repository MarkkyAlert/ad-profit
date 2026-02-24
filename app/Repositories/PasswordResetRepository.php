<?php

declare(strict_types=1);

class PasswordResetRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createToken(int $userId, string $tokenHash, string $expiresAt): bool
    {
        $sql = 'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                VALUES (:user_id, :token_hash, :expires_at)
                ON DUPLICATE KEY UPDATE
                    token_hash = VALUES(token_hash),
                    expires_at = VALUES(expires_at),
                    created_at = CURRENT_TIMESTAMP';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        $sql = 'SELECT prt.id, prt.user_id, prt.token_hash, prt.expires_at, prt.created_at,
                       u.email
                FROM password_reset_tokens prt
                JOIN users u ON u.id = prt.user_id
                WHERE prt.token_hash = :token_hash
                  AND prt.expires_at > NOW()
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash]);
        $token = $stmt->fetch();

        return $token ?: null;
    }

    public function findByTokenHashForUpdate(string $tokenHash): ?array
    {
        $sql = 'SELECT prt.id, prt.user_id, prt.token_hash, prt.expires_at, prt.created_at,
                       u.email
                FROM password_reset_tokens prt
                JOIN users u ON u.id = prt.user_id
                WHERE prt.token_hash = :token_hash
                  AND prt.expires_at > NOW()
                LIMIT 1
                FOR UPDATE';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash]);
        $token = $stmt->fetch();

        return $token ?: null;
    }

    public function deleteByUserId(int $userId): bool
    {
        $sql = 'DELETE FROM password_reset_tokens
                WHERE user_id = :user_id';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([':user_id' => $userId]);
    }

    public function deleteByTokenHash(string $tokenHash): bool
    {
        $sql = 'DELETE FROM password_reset_tokens
                WHERE token_hash = :token_hash';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token_hash' => $tokenHash]);

        return $stmt->rowCount() > 0;
    }

    public function deleteExpired(): int
    {
        $sql = 'DELETE FROM password_reset_tokens
                WHERE expires_at < NOW()';

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
