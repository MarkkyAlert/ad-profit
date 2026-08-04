<?php

declare(strict_types=1);

class GoalRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * เขียนทับทั้ง 2 ฟิลด์เสมอ — ตั้งใจ ไม่ใช่ partial update
     *
     * ฟอร์มตั้งเป้า (dashboard.php) prefill ทั้งสองช่องและส่งมาครบทุกครั้ง การล้างช่องหนึ่ง
     * ให้ว่างจึงแปลว่า "ลบเป้านั้น" ซึ่งต้องกลายเป็น NULL จริง ๆ
     * ⚠️ ถ้าจะเพิ่มผู้เรียกที่ส่งมาไม่ครบ ต้องเปลี่ยน contract ตรงนี้ก่อน ไม่งั้นฟิลด์ที่
     * ไม่ได้ส่งจะถูกล้างเงียบ ๆ
     */
    public function upsert(int $shopId, string $goalMonth, ?float $targetRevenue, ?float $targetProfit): bool
    {
        $sql = 'INSERT INTO monthly_goals (shop_id, goal_month, target_revenue, target_profit)
                VALUES (:shop_id, :goal_month, :target_revenue, :target_profit)
                ON DUPLICATE KEY UPDATE
                    target_revenue = VALUES(target_revenue),
                    target_profit = VALUES(target_profit),
                    updated_at = CURRENT_TIMESTAMP';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':shop_id' => $shopId,
            ':goal_month' => $goalMonth,
            ':target_revenue' => $targetRevenue,
            ':target_profit' => $targetProfit,
        ]);
    }

    public function findByShopAndMonth(int $shopId, string $goalMonth): ?array
    {
        $sql = 'SELECT id, shop_id, goal_month, target_revenue, target_profit, created_at, updated_at
                FROM monthly_goals
                WHERE shop_id = :shop_id AND goal_month = :goal_month
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':goal_month' => $goalMonth,
        ]);
        $goal = $stmt->fetch();

        return $goal ?: null;
    }

    /**
     * ดึงเป้าหลายเดือนในครั้งเดียว — goal_month เป็น CHAR(7) 'YYYY-MM' จึงเทียบ BETWEEN แบบ string ได้
     * mirror pattern ของ RecordRepository::getMonthlyTotalsByMonthRange
     *
     * @return array<int,array<string,mixed>>
     */
    public function getByShopAndMonthRange(int $shopId, string $startMonth, string $endMonth): array
    {
        $sql = 'SELECT id, shop_id, goal_month, target_revenue, target_profit, created_at, updated_at
                FROM monthly_goals
                WHERE shop_id = :shop_id
                  AND goal_month BETWEEN :start_month AND :end_month
                ORDER BY goal_month ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':start_month' => $startMonth,
            ':end_month' => $endMonth,
        ]);

        return $stmt->fetchAll();
    }

    public function deleteByShopAndMonth(int $shopId, string $goalMonth): bool
    {
        $sql = 'DELETE FROM monthly_goals
                WHERE shop_id = :shop_id AND goal_month = :goal_month';

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':shop_id' => $shopId,
            ':goal_month' => $goalMonth,
        ]);

        return $stmt->rowCount() > 0;
    }
}
