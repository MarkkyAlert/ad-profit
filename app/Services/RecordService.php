<?php

declare(strict_types=1);

class RecordService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;
    private ?PDO $db;

    public function __construct(RecordRepository $recordRepository, ShopRepository $shopRepository, ?PDO $db = null)
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
        $this->db = $db;
    }

    public function upsertRecord(
        int $userId,
        int $shopId,
        string $recordDate,
        float $revenue,
        float $adCost,
        ?string $note
    ): array {
        if (!$this->canAccessShop($userId, $shopId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $validation = $this->validateRecordPayload($recordDate, $revenue, $adCost, $note);
        if (($validation['success'] ?? false) !== true) {
            return $validation;
        }

        $payload = $validation['data'];

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
                    // Serialize concurrent upserts/updates for the same shop+date.
                    $this->recordRepository->findByShopIdAndRecordDateForUpdate($shopId, (string)$payload['record_date']);
                }
            }

            $this->recordRepository->upsert(
                $shopId,
                (string)$payload['record_date'],
                (float)$payload['revenue'],
                (float)$payload['ad_cost'],
                $payload['note']
            );

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[record] upsertRecord failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถบันทึกข้อมูลได้',
            ];
        }

        return [
            'success' => true,
            'message' => 'บันทึกข้อมูลเรียบร้อยแล้ว',
        ];
    }

    public function updateRecord(
        int $userId,
        int $shopId,
        int $recordId,
        string $recordDate,
        float $revenue,
        float $adCost,
        ?string $note
    ): array {
        if (!$this->canAccessShop($userId, $shopId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if ($recordId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบรายการที่ต้องการแก้ไข',
            ];
        }

        $validation = $this->validateRecordPayload($recordDate, $revenue, $adCost, $note);
        if (($validation['success'] ?? false) !== true) {
            return $validation;
        }

        $payload = $validation['data'];

        $startedTransaction = false;
        try {
            if ($this->db instanceof PDO && !$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTransaction = true;
            }

            $existingRecord = $this->recordRepository->findByIdAndShopIdForUpdate($recordId, $shopId);
            if ($existingRecord === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบรายการที่ต้องการแก้ไข',
                ];
            }

            $oldDate = (string)($existingRecord['record_date'] ?? '');
            $newDate = (string)$payload['record_date'];

            if ($oldDate !== $newDate) {
                $conflictRecord = $this->recordRepository->findByShopIdAndRecordDateForUpdate($shopId, $newDate);
                if ($conflictRecord !== null && (int)($conflictRecord['id'] ?? 0) !== $recordId) {
                    if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    return [
                        'success' => false,
                        'error' => 'วันที่ที่เลือกมีข้อมูลอยู่แล้ว กรุณาแก้ไขรายการของวันที่ดังกล่าวแทน',
                    ];
                }
            }

            $this->recordRepository->updateByIdAndShopId(
                $recordId,
                $shopId,
                $newDate,
                (float)$payload['revenue'],
                (float)$payload['ad_cost'],
                $payload['note']
            );

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (PDOException $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ((string)$exception->getCode() === '23000') {
                return [
                    'success' => false,
                    'error' => 'วันที่ที่เลือกมีข้อมูลอยู่แล้ว กรุณาแก้ไขรายการของวันที่ดังกล่าวแทน',
                ];
            }

            error_log('[record] updateRecord failed: ' . $exception->getMessage());

            return [
                'success' => false,
                'error' => 'ไม่สามารถแก้ไขรายการได้',
            ];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[record] updateRecord failed: ' . $exception->getMessage());

            return [
                'success' => false,
                'error' => 'ไม่สามารถแก้ไขรายการได้',
            ];
        }

        return [
            'success' => true,
            'message' => 'แก้ไขรายการเรียบร้อยแล้ว',
        ];
    }

    public function getRecentRecords(int $userId, int $shopId, int $limit = 7): array
    {
        if (!$this->canAccessShop($userId, $shopId)) {
            return [];
        }

        return $this->recordRepository->getRecentByShopId($shopId, $limit);
    }

    public function getMonthlyRecords(int $userId, int $shopId, string $month): array
    {
        if (!$this->canAccessShop($userId, $shopId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $startDate = $month . '-01';
        $dateObject = DateTime::createFromFormat('Y-m-d', $startDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $startDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $endDate = $dateObject->format('Y-m-t');
        $records = $this->recordRepository->getByDateRange($shopId, $startDate, $endDate);

        $mappedRecords = [];
        $previousRevenue = null;
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $roasTotal = 0.0;
        $roasCount = 0;

        foreach ($records as $record) {
            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);
            $profit = $revenue - $adCost;
            $roas = $adCost > 0 ? round($revenue / $adCost, 2) : null;

            $compareRevenuePercent = null;
            if ($previousRevenue !== null && $previousRevenue > 0) {
                $compareRevenuePercent = round((($revenue - $previousRevenue) / $previousRevenue) * 100, 1);
            }

            $mappedRecords[] = [
                'id' => (int)($record['id'] ?? 0),
                'record_date' => (string)($record['record_date'] ?? ''),
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'profit' => $profit,
                'roas' => $roas,
                'compare_revenue_percent' => $compareRevenuePercent,
                'note' => (string)($record['note'] ?? ''),
            ];

            $previousRevenue = $revenue;
            $totalRevenue += $revenue;
            $totalAdCost += $adCost;

            if ($roas !== null) {
                $roasTotal += $roas;
                $roasCount++;
            }
        }

        $totalProfit = $totalRevenue - $totalAdCost;

        return [
            'success' => true,
            'data' => [
                'month' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'records' => $mappedRecords,
                'totals' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'total_profit' => $totalProfit,
                    'avg_roas' => $roasCount > 0 ? round($roasTotal / $roasCount, 2) : null,
                ],
            ],
        ];
    }

    public function deleteRecord(int $userId, int $shopId, int $recordId): array
    {
        if (!$this->canAccessShop($userId, $shopId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if ($recordId <= 0) {
            return [
                'success' => false,
                'error' => 'ไม่พบรายการที่ต้องการลบ',
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
            }

            $existingRecord = $canLockRows
                ? $this->recordRepository->findByIdAndShopIdForUpdate($recordId, $shopId)
                : $this->recordRepository->findByIdAndShopId($recordId, $shopId);

            if ($existingRecord === null) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบรายการที่ต้องการลบ',
                ];
            }

            $deleted = $this->recordRepository->deleteByIdAndShopId($recordId, $shopId);
            if (!$deleted) {
                if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [
                    'success' => false,
                    'error' => 'ไม่พบรายการที่ต้องการลบ',
                ];
            }

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[record] deleteRecord failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถลบรายการได้',
            ];
        }

        return [
            'success' => true,
            'message' => 'ลบรายการเรียบร้อยแล้ว',
        ];
    }

    private function canAccessShop(int $userId, int $shopId): bool
    {
        return $this->shopRepository->findByIdAndUserId($shopId, $userId) !== null;
    }

    private function validateRecordPayload(string $recordDate, float $revenue, float $adCost, ?string $note): array
    {
        $dateObject = DateTime::createFromFormat('Y-m-d', $recordDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $recordDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบวันที่ไม่ถูกต้อง',
            ];
        }

        if ($revenue < 0 || $adCost < 0) {
            return [
                'success' => false,
                'error' => 'รายได้และค่าแอดต้องไม่ติดลบ',
            ];
        }

        $normalizedNote = $note === null ? null : trim($note);
        if ($normalizedNote !== null && $normalizedNote !== '') {
            $length = function_exists('mb_strlen') ? mb_strlen($normalizedNote) : strlen($normalizedNote);
            if ($length > 255) {
                return [
                    'success' => false,
                    'error' => 'โน้ตยาวเกิน 255 ตัวอักษร',
                ];
            }
        } else {
            $normalizedNote = null;
        }

        return [
            'success' => true,
            'data' => [
                'record_date' => $recordDate,
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'note' => $normalizedNote,
            ],
        ];
    }

    private function beginTransactionIfAvailable(): void
    {
        if ($this->db instanceof PDO && !$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    private function commitTransactionIfAvailable(): void
    {
        if ($this->db instanceof PDO && $this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    private function rollBackTransactionIfAvailable(): void
    {
        if ($this->db instanceof PDO && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
