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
        // ⚠️ ต้องมี display_name ด้วย — `AuthService::login()` เอาไปใส่ session ให้หัวเว็บ
        // แสดงแทนอีเมล · ขาดคอลัมน์นี้แล้วชื่อจะว่างเสมอ โดยไม่มีอะไรฟ้อง (เจอมาแล้ว)
        $sql = 'SELECT id, email, display_name, password_hash, last_login_at, created_at, updated_at
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

    public function findProfileById(int $userId): ?array
    {
        $sql = 'SELECT id, email, display_name, password_hash, last_login_at, created_at, updated_at
                FROM users
                WHERE id = :id
                LIMIT 1';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            return $user ?: null;
        } catch (PDOException $exception) {
            if (!$this->isMissingDisplayNameColumnError($exception)) {
                throw $exception;
            }

            $legacySql = 'SELECT id, email, password_hash, last_login_at, created_at, updated_at
                          FROM users
                          WHERE id = :id
                          LIMIT 1';

            $stmt = $this->db->prepare($legacySql);
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                return null;
            }

            $user['display_name'] = '';
            return $user;
        }
    }

    public function updateDisplayName(int $userId, string $displayName): bool
    {
        $sql = 'UPDATE users
                SET display_name = :display_name
                WHERE id = :id';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $userId,
                ':display_name' => $displayName,
            ]);
        } catch (PDOException $exception) {
            if ($this->isMissingDisplayNameColumnError($exception)) {
                return false;
            }

            throw $exception;
        }

        return $stmt->rowCount() > 0;
    }

    public function updateEmail(int $userId, string $email): bool
    {
        $sql = 'UPDATE users
                SET email = :email
                WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $userId,
            ':email' => $email,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function getSessionVersion(int $userId): int
    {
        if ($userId <= 0) {
            return 1;
        }

        $sql = 'SELECT session_version
                FROM users
                WHERE id = :id
                LIMIT 1';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();
        } catch (PDOException $exception) {
            if ($this->isMissingSessionVersionColumnError($exception)) {
                return 1;
            }

            throw $exception;
        }

        if (!is_array($row)) {
            return 1;
        }

        return max(1, (int)($row['session_version'] ?? 1));
    }

    public function incrementSessionVersion(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $sql = 'UPDATE users
                SET session_version = COALESCE(session_version, 1) + 1
                WHERE id = :id';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $userId]);
        } catch (PDOException $exception) {
            if ($this->isMissingSessionVersionColumnError($exception)) {
                return;
            }

            throw $exception;
        }
    }

    public function lockForUpdate(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $userId]);
    }

    private function isMissingDisplayNameColumnError(PDOException $exception): bool
    {
        if ((string)$exception->getCode() !== '42S22') {
            return false;
        }

        return str_contains(strtolower($exception->getMessage()), 'display_name');
    }

    private function isMissingSessionVersionColumnError(PDOException $exception): bool
    {
        if ((string)$exception->getCode() !== '42S22') {
            return false;
        }

        return str_contains(strtolower($exception->getMessage()), 'session_version');
    }
}
