<?php

declare(strict_types=1);

class UserRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findById(int $userId): ?array
    {
        $sql = 'SELECT id, email, password_hash, last_login_at, created_at, updated_at
                FROM users
                WHERE id = :id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $sql = 'SELECT id, email, password_hash, last_login_at, created_at, updated_at
                FROM users
                WHERE email = :email
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function create(string $email, string $passwordHash): int
    {
        $sql = 'INSERT INTO users (email, password_hash)
                VALUES (:email, :password_hash)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $passwordHash,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function updateLastLoginAt(int $userId): void
    {
        $sql = 'UPDATE users
                SET last_login_at = NOW()
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): bool
    {
        $sql = 'UPDATE users
                SET password_hash = :password_hash
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $userId,
            ':password_hash' => $passwordHash,
        ]);

        return $stmt->rowCount() > 0;
    }
}
