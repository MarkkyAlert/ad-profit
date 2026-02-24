<?php

declare(strict_types=1);

class GoalService
{
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

        if (!preg_match('/^\d{4}-\d{2}$/', $goalMonth)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        if ($targetRevenue !== null && $targetRevenue < 0) {
            return [
                'success' => false,
                'error' => 'เป้ารายได้ต้องไม่ติดลบ',
            ];
        }

        if ($targetProfit !== null && $targetProfit < 0) {
            return [
                'success' => false,
                'error' => 'เป้ากำไรต้องไม่ติดลบ',
            ];
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

        if (!preg_match('/^\d{4}-\d{2}$/', $goalMonth)) {
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

            $deleted = $this->goalRepository->deleteByShopAndMonth($shopId, $goalMonth);

            if (!$deleted) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบเป้าหมายที่ต้องการลบ',
                ];
            }

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
}
