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

    /**
     * วันแรกที่แต่ละร้านมีข้อมูล — ใช้ตัดสินว่า ณ วันนั้นร้านไหน "กำลังถูกติดตาม" อยู่บ้าง
     *
     * ใช้แทน shops.created_at เพราะร้านที่สร้างวันนี้แล้ว import ประวัติย้อนหลังเข้ามา
     * ก็ถือว่าถูกติดตามตั้งแต่วันที่ข้อมูลระบุ ไม่ใช่ตั้งแต่วันที่สร้างร้าน
     *
     * @param int[] $shopIds
     * @return array<int,string> shop_id => วันแรกที่มีข้อมูล (Y-m-d)
     */
    public function getFirstRecordDateByShopIds(array $shopIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $shopIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $shopId) {
            $placeholder = ':shop_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $shopId;
        }

        $sql = 'SELECT shop_id, MIN(record_date) AS first_date
                FROM daily_records
                WHERE shop_id IN (' . implode(', ', $placeholders) . ')
                GROUP BY shop_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['shop_id']] = (string)$row['first_date'];
        }

        return $result;
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

    /**
     * @param string|null $notAfterDate ตัดไม่ให้เลยวันนี้ (Y-m-d) — ผู้เรียกเป็นคนส่งมา
     *
     * ⚠️⚠️ ระบบอนุญาตให้ลงข้อมูลวันล่วงหน้า ยอดของ "เดือนนี้" จึงรวมวันที่ยังมาไม่ถึง
     * ถ้าไม่ตัด · เกิดขึ้นจริง: แดชบอร์ดบอก "ทำได้ ฿4,000 จาก ฿10,000 (40%)" ขณะที่
     * หน้ารายปีกับไฟล์ Excel บอก "✓ ถึงเป้าแล้ว 100%" เดือนเดียวกัน ข้อมูลชุดเดียวกัน
     * (แดชบอร์ดตัดวันอยู่แล้ว ที่อื่นไม่ได้ตัด)
     *
     * รับเป็นพารามิเตอร์ ไม่ใช่ให้ repository อ่านวันนี้เอง เพราะ service ทุกตัวมี
     * seam `$today` ให้เทสต์ล็อกวันได้ — ถ้าอ่านเองจะมีบางส่วนอ้างวันจริงขณะที่ส่วนอื่น
     * ใช้วันที่เทสต์กำหนด แล้วเทสต์เรื่องวันที่จะพิสูจน์อะไรไม่ได้เลย
     */
    public function getMonthlyTotalsByMonthRange(
        int $shopId,
        string $startMonth,
        string $endMonth,
        ?string $notAfterDate = null
    ): array {
        $startDate = $startMonth . '-01';
        $endDateObject = DateTime::createFromFormat('Y-m-d', $endMonth . '-01');
        if (!$endDateObject) {
            return [];
        }

        $endDate = $endDateObject->format('Y-m-t');

        if ($notAfterDate !== null && $notAfterDate < $endDate) {
            $endDate = $notAfterDate;
        }

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
     * ⚠️ `$notAfterDate` ทำหน้าที่เดียวกับใน `getMonthlyTotalsByMonthRange()` — **ผู้เรียกส่งวันมาเอง**
     * ห้ามให้ repository อ่านวันนี้เอง ไม่งั้น seam `$today` ของ service พัง
     *
     * เดิมเมธอดนี้ **ไม่มีพารามิเตอร์นี้เลย** ทั้งที่ฝาแฝดแบบร้านเดียวมี ผลคือทุกจุดที่
     * เรียกมันได้ยอด "ทั้งเดือน" ขณะที่ตัวเลขข้าง ๆ บนจอเดียวกันตัดที่วันนี้
     *
     * @param int[] $shopIds
     */
    public function getMonthlyTotalsByShopIdsAndMonthRange(
        array $shopIds,
        string $startMonth,
        string $endMonth,
        ?string $notAfterDate = null
    ): array {
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

        if ($notAfterDate !== null && $notAfterDate < $endDate) {
            $endDate = $notAfterDate;
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
