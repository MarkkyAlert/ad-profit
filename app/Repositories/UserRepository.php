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
        $sql = 'SELECT id, username, password_hash, last_login_at, created_at, updated_at
                FROM users
                WHERE id = :id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $sql = 'SELECT id, username, password_hash, last_login_at, created_at, updated_at
                FROM users
                WHERE username = :username
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function create(string $username, string $passwordHash): int
    {
        $sql = 'INSERT INTO users (username, password_hash)
                VALUES (:username, :password_hash)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':username' => $username,
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
}
