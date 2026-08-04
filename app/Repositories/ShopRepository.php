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

    public function findByNameAndUserId(string $name, int $userId): ?array
    {
        $sql = 'SELECT id, user_id, name, created_at, updated_at
                FROM shops
                WHERE user_id = :user_id AND name = :name
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
        ]);
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

    /**
     * จองแถวร้านแบบ "คนเดียวเท่านั้น" ก่อนเขียนข้อมูลลูก (daily_records / monthly_goals)
     *
     * ทำสองหน้าที่พร้อมกัน:
     *  1. **ลำดับล็อก** — การเขียนแถวลูกต้องแตะแถวแม่อยู่แล้ว (InnoDB ตรวจ foreign key)
     *     ถ้าไม่จองก่อน ลำดับจะกลับหัวกับตอนลบร้าน (ล็อกร้าน → ลบข้อมูลลูกแบบ cascade)
     *     สองคำขอที่มาพร้อมกันจะวนรอกันจน MySQL ตัดฝ่ายหนึ่งทิ้ง
     *  2. **เข้าคิวการเขียนของร้านเดียวกัน** — ตัวกัน "ช่องว่างทับของเดิม" อ่านแล้วค่อยเขียน
     *     ถ้าสองคำขอเดินพร้อมกันได้ ตัวกันจะเห็นภาพก่อนที่อีกฝ่ายจะบันทึก แล้วทับด้วยศูนย์
     *     พร้อมข้อความ "สำเร็จ" ทั้งสองฝั่ง
     *
     * ⚠️ **ต้องเป็น `FOR UPDATE` ห้ามใช้ `LOCK IN SHARE MODE`** — ล็อกแบบอ่านสองคน
     * ถือพร้อมกันได้ ข้อ 2 จึงไม่เกิดขึ้นเลย (เคยเขียนผิดมาแล้ว และเทสต์รอบแรกก็จับไม่ได้
     * เพราะเทสต์ไปจอง FOR UPDATE เอง — ดู ConcurrentWriteLockOrderTest)
     * · 1 ร้านมีเจ้าของคนเดียว การเข้าคิวจึงกระทบแค่เจ้าของที่เปิดสองแท็บพร้อมกัน
     */
    public function lockForWrite(int $shopId, int $userId): bool
    {
        $sql = 'SELECT id
                FROM shops
                WHERE id = :shop_id AND user_id = :user_id
                LIMIT 1
                FOR UPDATE';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':user_id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function countByUserIdForUpdate(int $userId): int
    {
        $sql = 'SELECT COUNT(*) AS total
                FROM shops
                WHERE user_id = :user_id
                FOR UPDATE';

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

    public function userCanAccessShop(int $shopId, int $userId): bool
    {
        return $this->findByIdAndUserId($shopId, $userId) !== null;
    }
}
