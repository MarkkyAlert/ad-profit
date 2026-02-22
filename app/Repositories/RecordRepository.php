<?php

declare(strict_types=1);

class RecordRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function upsert(int $shopId, string $recordDate, float $revenue, float $adCost, ?string $note): bool
    {
        $sql = 'INSERT INTO daily_records (shop_id, record_date, revenue, ad_cost, note)
                VALUES (:shop_id, :record_date, :revenue, :ad_cost, :note)
                ON DUPLICATE KEY UPDATE
                    revenue = VALUES(revenue),
                    ad_cost = VALUES(ad_cost),
                    note = VALUES(note),
                    updated_at = CURRENT_TIMESTAMP';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':shop_id' => $shopId,
            ':record_date' => $recordDate,
            ':revenue' => $revenue,
            ':ad_cost' => $adCost,
            ':note' => $note,
        ]);
    }

    public function getRecentByShopId(int $shopId, int $limit = 7): array
    {
        $sql = 'SELECT id, shop_id, record_date, revenue, ad_cost, note, created_at, updated_at
                FROM daily_records
                WHERE shop_id = :shop_id
                ORDER BY record_date DESC
                LIMIT :limit_rows';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getByDateRange(int $shopId, string $startDate, string $endDate): array
    {
        $sql = 'SELECT id, shop_id, record_date, revenue, ad_cost, note, created_at, updated_at
                FROM daily_records
                WHERE shop_id = :shop_id
                  AND record_date BETWEEN :start_date AND :end_date
                ORDER BY record_date ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);

        return $stmt->fetchAll();
    }

    public function findByIdAndShopId(int $recordId, int $shopId): ?array
    {
        $sql = 'SELECT id, shop_id, record_date, revenue, ad_cost, note, created_at, updated_at
                FROM daily_records
                WHERE id = :record_id AND shop_id = :shop_id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':record_id' => $recordId,
            ':shop_id' => $shopId,
        ]);
        $record = $stmt->fetch();

        return $record ?: null;
    }

    public function findByIdAndShopIdForUpdate(int $recordId, int $shopId): ?array
    {
        $sql = 'SELECT id, shop_id, record_date, revenue, ad_cost, note, created_at, updated_at
                FROM daily_records
                WHERE id = :record_id AND shop_id = :shop_id
                LIMIT 1
                FOR UPDATE';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':record_id' => $recordId,
            ':shop_id' => $shopId,
        ]);
        $record = $stmt->fetch();

        return $record ?: null;
    }

    public function getMonthlyTotalsByMonthRange(int $shopId, string $startMonth, string $endMonth): array
    {
        $startDate = $startMonth . '-01';
        $endDateObject = DateTime::createFromFormat('Y-m-d', $endMonth . '-01');
        if (!$endDateObject) {
            return [];
        }

        $endDate = $endDateObject->format('Y-m-t');

        $sql = "SELECT DATE_FORMAT(record_date, '%Y-%m') AS month_key,
                       SUM(revenue) AS total_revenue,
                       SUM(ad_cost) AS total_ad_cost,
                       COUNT(*) AS days_count
                FROM daily_records
                WHERE shop_id = :shop_id
                  AND record_date BETWEEN :start_date AND :end_date
                GROUP BY DATE_FORMAT(record_date, '%Y-%m')
                ORDER BY month_key ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);

        return $stmt->fetchAll();
    }

    public function deleteByIdAndShopId(int $recordId, int $shopId): bool
    {
        $sql = 'DELETE FROM daily_records
                WHERE id = :record_id AND shop_id = :shop_id';

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':record_id' => $recordId,
            ':shop_id' => $shopId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
