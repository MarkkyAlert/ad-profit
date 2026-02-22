<?php

declare(strict_types=1);

class ShopRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(int $userId, string $shopName): int
    {
        $sql = 'INSERT INTO shops (user_id, name)
                VALUES (:user_id, :name)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $shopName,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function getFirstByUserId(int $userId): ?array
    {
        $sql = 'SELECT id, user_id, name, created_at, updated_at
                FROM shops
                WHERE user_id = :user_id
                ORDER BY id ASC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $shop = $stmt->fetch();

        return $shop ?: null;
    }

    public function findByIdAndUserId(int $shopId, int $userId): ?array
    {
        $sql = 'SELECT id, user_id, name, created_at, updated_at
                FROM shops
                WHERE id = :shop_id AND user_id = :user_id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':user_id' => $userId,
        ]);
        $shop = $stmt->fetch();

        return $shop ?: null;
    }

    public function listByUserId(int $userId): array
    {
        $sql = 'SELECT id, user_id, name, created_at, updated_at
                FROM shops
                WHERE user_id = :user_id
                ORDER BY id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function countByUserId(int $userId): int
    {
        $sql = 'SELECT COUNT(*) AS total
                FROM shops
                WHERE user_id = :user_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $result = $stmt->fetch();

        return (int)($result['total'] ?? 0);
    }

    public function updateNameByIdAndUserId(int $shopId, int $userId, string $name): bool
    {
        $sql = 'UPDATE shops
                SET name = :name
                WHERE id = :shop_id AND user_id = :user_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':shop_id' => $shopId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByIdAndUserId(int $shopId, int $userId): bool
    {
        $sql = 'DELETE FROM shops
                WHERE id = :shop_id AND user_id = :user_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
