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

    public function updateByIdAndShopId(
        int $recordId,
        int $shopId,
        string $recordDate,
        float $revenue,
        float $adCost,
        ?string $note
    ): bool {
        $sql = 'UPDATE daily_records
                SET record_date = :record_date,
                    revenue = :revenue,
                    ad_cost = :ad_cost,
                    note = :note,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :record_id
                  AND shop_id = :shop_id';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':record_id' => $recordId,
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

    /**
     * รายการล่าสุดที่วันที่ไม่เกิน $date — ใช้นับ "ไม่ได้กรอกมากี่วัน" โดยข้ามรายการที่ลงล่วงหน้า
     *
     * @return array<string,mixed>|null
     */
    public function findLatestOnOrBeforeDate(int $shopId, string $date): ?array
    {
        $sql = 'SELECT id, shop_id, record_date, revenue, ad_cost, note, created_at, updated_at
                FROM daily_records
                WHERE shop_id = :shop_id
                  AND record_date <= :cutoff_date
                ORDER BY record_date DESC
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':cutoff_date' => $date,
        ]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
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

    /**
     * รายการนี้ยังมีอยู่ในร้านใดร้านหนึ่งของผู้ใช้หรือไม่ (ไม่จำกัดร้านปัจจุบัน)
     *
     * ใช้แยก "ลบไปแล้วจริง" ออกจาก "อยู่ในร้านอื่นของตัวเอง" — เคสหลังเกิดเมื่อ session
     * ถูกสลับร้านในอีกแท็บ ถ้าตอบว่าลบสำเร็จผู้ใช้จะเชื่อว่าแถวหายทั้งที่ยังอยู่
     */
    public function existsByIdAndUserId(int $recordId, int $userId): bool
    {
        $sql = 'SELECT 1
                FROM daily_records dr
                JOIN shops s ON s.id = dr.shop_id
                WHERE dr.id = :record_id
                  AND s.user_id = :user_id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':record_id' => $recordId,
            ':user_id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
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

    public function findByShopIdAndRecordDateForUpdate(int $shopId, string $recordDate): ?array
    {
        $sql = 'SELECT id, shop_id, record_date, revenue, ad_cost, note, created_at, updated_at
                FROM daily_records
                WHERE shop_id = :shop_id
                  AND record_date = :record_date
                LIMIT 1
                FOR UPDATE';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':shop_id' => $shopId,
            ':record_date' => $recordDate,
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

    /**
     * @param int[] $shopIds
     */
    public function getTotalsByShopIdsAndDateRange(array $shopIds, string $startDate, string $endDate): array
    {
        $shopIds = array_values(array_unique(array_filter(array_map('intval', $shopIds), static fn(int $id): bool => $id > 0)));
        if ($shopIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        foreach ($shopIds as $index => $shopId) {
            $key = ':shop_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $shopId;
        }

        $sql = 'SELECT shop_id,
                       SUM(revenue) AS total_revenue,
                       SUM(ad_cost) AS total_ad_cost,
                       COUNT(*) AS days_count
                FROM daily_records
                WHERE shop_id IN (' . implode(', ', $placeholders) . ')
                  AND record_date BETWEEN :start_date AND :end_date
                GROUP BY shop_id
                ORDER BY shop_id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param int[] $shopIds
     */
    public function getDailyTotalsByShopIdsAndDateRange(array $shopIds, string $startDate, string $endDate): array
    {
        $shopIds = array_values(array_unique(array_filter(array_map('intval', $shopIds), static fn(int $id): bool => $id > 0)));
        if ($shopIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        foreach ($shopIds as $index => $shopId) {
            $key = ':shop_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $shopId;
        }

        $sql = 'SELECT record_date,
                       SUM(revenue) AS total_revenue,
                       SUM(ad_cost) AS total_ad_cost,
                       COUNT(*) AS shops_count
                FROM daily_records
                WHERE shop_id IN (' . implode(', ', $placeholders) . ')
                  AND record_date BETWEEN :start_date AND :end_date
                GROUP BY record_date
                ORDER BY record_date ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param int[] $shopIds
     */
    public function getMonthlyTotalsByShopIdsAndMonthRange(array $shopIds, string $startMonth, string $endMonth): array
    {
        $shopIds = array_values(array_unique(array_filter(array_map('intval', $shopIds), static fn(int $id): bool => $id > 0)));
        if ($shopIds === []) {
            return [];
        }

        $startDate = $startMonth . '-01';
        $endDateObject = DateTime::createFromFormat('Y-m-d', $endMonth . '-01');
        if (!$endDateObject) {
            return [];
        }

        $endDate = $endDateObject->format('Y-m-t');

        $placeholders = [];
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        foreach ($shopIds as $index => $shopId) {
            $key = ':shop_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $shopId;
        }

        $sql = "SELECT shop_id,
                       DATE_FORMAT(record_date, '%Y-%m') AS month_key,
                       SUM(revenue) AS total_revenue,
                       SUM(ad_cost) AS total_ad_cost,
                       COUNT(*) AS days_count
                FROM daily_records
                WHERE shop_id IN (" . implode(', ', $placeholders) . ")
                  AND record_date BETWEEN :start_date AND :end_date
                GROUP BY shop_id, DATE_FORMAT(record_date, '%Y-%m')
                ORDER BY shop_id ASC, month_key ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

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
