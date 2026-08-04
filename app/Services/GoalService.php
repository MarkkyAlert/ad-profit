<?php

declare(strict_types=1);

class GoalService
{
    /**
     * เพดานเดียวกับ RecordService::MAX_AMOUNT — monthly_goals.target_* เป็น DECIMAL(12,2)
     * เท่ากับ daily_records ถ้าจะขยับต้องขยับทั้งสองฝั่งพร้อม schema
     */
    public const MAX_AMOUNT = RecordService::MAX_AMOUNT;

    private GoalRepository $goalRepository;
    private ShopRepository $shopRepository;
    private ?PDO $db;

    public function __construct(GoalRepository $goalRepository, ShopRepository $shopRepository, ?PDO $db = null)
    {
        $this->goalRepository = $goalRepository;
        $this->shopRepository = $shopRepository;
        $this->db = $db;
    }

    public function upsertGoal(
        int $userId,
        int $shopId,
        string $goalMonth,
        ?float $targetRevenue,
        ?float $targetProfit
    ): array {
        $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!$this->isValidGoalMonth($goalMonth)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        foreach ([['เป้ารายได้', $targetRevenue], ['เป้ากำไร', $targetProfit]] as [$label, $value]) {
            if ($value === null) {
                continue;
            }

            // INF/NAN ผ่าน is_float ได้ แต่ลงคอลัมน์ DECIMAL ไม่ได้ — กันไว้ก่อนถึง SQL
            if (!is_finite($value)) {
                return [
                    'success' => false,
                    'error' => $label . 'ต้องเป็นตัวเลข',
                ];
            }

            if ($value < 0) {
                return [
                    'success' => false,
                    'error' => $label . 'ต้องไม่ติดลบ',
                ];
            }

            // เกินเพดานแล้ว MySQL strict mode จะ throw ออกมาเป็น "ไม่สามารถบันทึกเป้าหมายได้"
            // ที่ไม่บอกสาเหตุ ส่วน non-strict จะตัดค่าเงียบแล้วรายงานว่าสำเร็จ
            if ($value > self::MAX_AMOUNT) {
                return [
                    'success' => false,
                    'error' => $label . 'ต้องไม่เกิน ' . number_format(self::MAX_AMOUNT, 2),
                ];
            }

            // คอลัมน์ชนิดเดียวกับ daily_records — ใช้เกณฑ์ทศนิยมตัวเดียวกัน
            if (RecordService::hasTooManyDecimals($value)) {
                return [
                    'success' => false,
                    'error' => $label . 'ใส่ทศนิยมได้ไม่เกิน ' . RecordService::AMOUNT_DECIMALS . ' ตำแหน่ง',
                ];
            }
        }

        if ($targetRevenue === null && $targetProfit === null) {
            return [
                'success' => false,
                'error' => 'กรุณากรอกอย่างน้อย 1 เป้าหมาย',
            ];
        }

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $this->goalRepository->upsert($shopId, $goalMonth, $targetRevenue, $targetProfit);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[goal] upsertGoal failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถบันทึกเป้าหมายได้',
            ];
        }

        return [
            'success' => true,
        ];
    }

    public function deleteGoal(int $userId, int $shopId, string $goalMonth): array
    {
        $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!$this->isValidGoalMonth($goalMonth)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            // ไม่มีเป้าของเดือนนั้นแล้ว = ผลลัพธ์ตรงกับที่ผู้ใช้ขอ → ตอบสำเร็จ (idempotent)
            // กด back แล้ว submit ใหม่ไม่ควรได้ error แดง · ownership ตรวจไปแล้วด้านบน
            $this->goalRepository->deleteByShopAndMonth($shopId, $goalMonth);

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[goal] deleteGoal failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถลบเป้าหมายได้',
            ];
        }

        return [
            'success' => true,
        ];
    }

    /**
     * เดือนเป้าหมายต้องเป็น YYYY-MM ที่มีอยู่จริง
     *
     * regex \d{4}-\d{2} อย่างเดียวไม่พอ — '2024-00' และ '2024-13' ผ่านได้แล้วถูกบันทึกลง DB
     * (CHECK ใน schema ก็ตรวจแค่รูปแบบเหมือนกัน) กลายเป็นแถวที่ไม่มีหน้าไหนแสดง
     */
    private function isValidGoalMonth(string $goalMonth): bool
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $goalMonth) !== 1) {
            return false;
        }

        $year = (int)substr($goalMonth, 0, 4);

        return $year >= 2000 && $year <= 2100;
    }
}
