<?php

declare(strict_types=1);

class GoalService
{
    /**
     * เพดานเดียวกับ RecordService::MAX_AMOUNT — monthly_goals.target_* เป็น DECIMAL(12,2)
     * เท่ากับ daily_records ถ้าจะขยับต้องขยับทั้งสองฝั่งพร้อม schema
     */
    public const MAX_AMOUNT = RecordService::MAX_AMOUNT;

    /**
     * % ความคืบหน้าเทียบเป้า — **ปัดลง** เสมอ
     *
     * ⚠️ ต้องใช้ตัวนี้ทุกที่ที่แสดง % เป้า (แดชบอร์ด · หน้ารายปี · ไฟล์ Excel)
     * `round` ทำให้ 99.996% ขึ้นเป็น 100.0% คู่กับป้าย "ยังไม่ถึงเป้า" ในการ์ดเดียวกัน
     * เดิมแดชบอร์ดปัดลงแต่หน้ารายปีปัดขึ้น สองหน้าจึงบอกคนละตัวเลขจากข้อมูลชุดเดียวกัน
     */
    public static function progressPercent(float $actual, ?float $target): ?float
    {
        if ($target === null || $target <= 0) {
            return null;
        }

        return floor(($actual / $target) * 1000) / 10;
    }

    /**
     * ถึงเป้าแล้วหรือยัง — เทียบค่าจริง ไม่ใช่ % ที่ปัดแล้ว
     *
     * เป้า 0 ไม่นับว่า "ถึงเป้า" (ยังไม่ได้ตั้งเป้าจริง) — เดิมหน้ารายปีตอบว่าถึงเป้า
     * ขณะที่แดชบอร์ดตอบว่ายังไม่ถึง สำหรับเป้าเดียวกัน
     */
    public static function isReached(float $actual, ?float $target): ?bool
    {
        if ($target === null) {
            return null;
        }

        return $target > 0 && $actual >= $target;
    }

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
        $canLockRows = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows) {
                    // จองแถวร้านก่อนแตะแถวเป้าหมาย — ลำดับเดียวกับตอนลบร้าน
                    // (ร้าน → ข้อมูลในร้าน) ไม่งั้นสองคำขอจะวนรอกันจนถูกตัดทิ้ง
                    //
                    // จองไม่ได้ = ร้านหายไประหว่างที่หน้านี้เปิดค้างอยู่ · ถ้าปล่อยผ่าน
                    // จะไปตายที่ foreign key แล้วผู้ใช้เห็นแค่ "ไม่สามารถบันทึกเป้าหมายได้"
                    // ⚠️ ยกเลิกได้เฉพาะทรานแซกชันที่เมธอดนี้เปิดเอง
                    if (!$this->shopRepository->lockForWrite($shopId, $userId)) {
                        if ($startedTransaction && $this->db->inTransaction()) {
                            $this->db->rollBack();
                        }

                        return [
                            'success' => false,
                            'error' => 'ร้านนี้ถูกลบไปแล้ว กรุณาโหลดหน้าใหม่แล้วเลือกร้านอีกครั้ง',
                        ];
                    }
                }
            }

            // ⚠️ ช่องที่ไม่ได้กรอกมา = "ไม่แก้" ไม่ใช่ "ล้างทิ้ง"
            //
            // ฟอร์มเป้าหมายที่เปิดค้างไว้ตอนยังตั้งแค่ช่องเดียว แล้วอีกแท็บไปตั้งอีกช่อง
            // พอกดบันทึกที่แท็บเดิม ค่าที่อีกแท็บเพิ่งตั้งจะหายพร้อมข้อความ "สำเร็จ"
            // (`daily_records` กันเคสนี้อยู่แล้ว — `monthly_goals` เพิ่งมาตามทีหลัง)
            // ถ้าจะล้างทั้งเดือนให้ใช้ปุ่ม "ลบเป้าหมาย"
            $existingGoal = $canLockRows
                ? $this->goalRepository->findByShopAndMonthForUpdate($shopId, $goalMonth)
                : $this->goalRepository->findByShopAndMonth($shopId, $goalMonth);

            if ($existingGoal !== null) {
                if ($targetRevenue === null && ($existingGoal['target_revenue'] ?? null) !== null) {
                    $targetRevenue = (float)$existingGoal['target_revenue'];
                }
                if ($targetProfit === null && ($existingGoal['target_profit'] ?? null) !== null) {
                    $targetProfit = (float)$existingGoal['target_profit'];
                }
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
                'error' => write_failure_message($exception, 'ไม่สามารถบันทึกเป้าหมายได้'),
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
        $canLockRows = false;
        try {
            if ($this->db instanceof PDO) {
                if (!$this->db->inTransaction()) {
                    $this->db->beginTransaction();
                    $startedTransaction = true;
                }

                $canLockRows = $this->db->inTransaction();
                if ($canLockRows && !$this->shopRepository->lockForWrite($shopId, $userId)) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    return [
                        'success' => false,
                        'error' => 'ร้านนี้ถูกลบไปแล้ว กรุณาโหลดหน้าใหม่แล้วเลือกร้านอีกครั้ง',
                    ];
                }
            }

            // ต้องจองแถวร้านก่อนแตะแถวเป้าหมาย เหมือน upsertGoal() และ deleteShop()
            // เพื่อให้การลบร้าน (ร้าน → ข้อมูลลูก) กับการลบเป้าใช้ลำดับล็อกเดียวกัน
            // ไม่เช่นนั้นอาจลบเป้าพร้อมกับอีกคำขอที่กำลังลบร้านได้โดยไม่เข้าคิว
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
