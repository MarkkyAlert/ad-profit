<?php

declare(strict_types=1);

class RecordService
{
    /** จำนวนแถวสูงสุดต่อการบันทึกแบบหลายวัน 1 ครั้ง (ครอบคลุม 1 เดือน) */
    public const BULK_MAX_ROWS = 31;

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
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
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

    /**
     * บันทึกรายวันหลายแถวในครั้งเดียว (atomic — สำเร็จทั้งหมด หรือไม่เขียนเลย)
     *
     * @param array<int,array{record_date?: mixed, revenue?: mixed, ad_cost?: mixed, note?: mixed}> $rows
     */
    public function upsertManyRecords(int $userId, int $shopId, array $rows): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        // ตัดแถวว่างสนิททิ้ง (ผู้ใช้เว้นแถวไว้ในตารางได้)
        $filledRows = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $recordDate = trim((string)($row['record_date'] ?? ''));
            $revenueRaw = trim((string)($row['revenue'] ?? ''));
            $adCostRaw = trim((string)($row['ad_cost'] ?? ''));
            $noteRaw = trim((string)($row['note'] ?? ''));

            if ($recordDate === '' && $revenueRaw === '' && $adCostRaw === '' && $noteRaw === '') {
                continue;
            }

            $filledRows[] = [
                'row_number' => (int)$index + 1,
                'record_date' => $recordDate,
                'revenue' => $row['revenue'] ?? null,
                'ad_cost' => $row['ad_cost'] ?? null,
                'note' => $noteRaw === '' ? null : $noteRaw,
            ];
        }

        if ($filledRows === []) {
            return [
                'success' => false,
                'error' => 'กรุณากรอกข้อมูลอย่างน้อย 1 แถว',
            ];
        }

        if (count($filledRows) > self::BULK_MAX_ROWS) {
            return [
                'success' => false,
                'error' => 'กรอกได้สูงสุด ' . self::BULK_MAX_ROWS . ' แถวต่อครั้ง',
            ];
        }

        // validate ทุกแถวให้ครบก่อน แล้วค่อยเขียน (กันเขียนครึ่ง ๆ กลาง ๆ)
        $payloads = [];
        $seenDates = [];
        foreach ($filledRows as $row) {
            $rowNumber = (int)$row['row_number'];

            if (!is_numeric($row['revenue']) || !is_numeric($row['ad_cost'])) {
                return [
                    'success' => false,
                    'error' => 'แถวที่ ' . $rowNumber . ': กรุณากรอกรายได้และค่าแอดให้ถูกต้อง',
                ];
            }

            $validation = $this->validateRecordPayload(
                (string)$row['record_date'],
                (float)$row['revenue'],
                (float)$row['ad_cost'],
                $row['note']
            );

            if (($validation['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => 'แถวที่ ' . $rowNumber . ': ' . (string)($validation['error'] ?? 'ข้อมูลไม่ถูกต้อง'),
                ];
            }

            $payload = (array)$validation['data'];
            $recordDate = (string)$payload['record_date'];

            if (isset($seenDates[$recordDate])) {
                return [
                    'success' => false,
                    'error' => 'มีวันที่ซ้ำกันในตาราง (' . $recordDate . ') กรุณากรอกวันละ 1 แถว',
                ];
            }

            $seenDates[$recordDate] = true;
            $payloads[] = $payload;
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

            foreach ($payloads as $payload) {
                if ($canLockRows) {
                    // Serialize concurrent upserts for the same shop+date.
                    $this->recordRepository->findByShopIdAndRecordDateForUpdate($shopId, (string)$payload['record_date']);
                }

                $this->recordRepository->upsert(
                    $shopId,
                    (string)$payload['record_date'],
                    (float)$payload['revenue'],
                    (float)$payload['ad_cost'],
                    $payload['note']
                );
            }

            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db instanceof PDO && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[record] upsertManyRecords failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถบันทึกข้อมูลได้',
            ];
        }

        $savedCount = count($payloads);

        return [
            'success' => true,
            'message' => 'บันทึกข้อมูล ' . $savedCount . ' วันเรียบร้อยแล้ว',
            'saved_count' => $savedCount,
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
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
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
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [];
        }

        return $this->recordRepository->getRecentByShopId($shopId, $limit);
    }

    public function getMonthlyRecords(int $userId, int $shopId, string $month): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
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

    /**
     * เทียบยอดของวันหนึ่ง กับ "วันเดียวกันในสัปดาห์" วันอื่น ๆ ของเดือนนั้น
     *
     * ใช้ตอบว่า "ยอดวันนี้ตกเพราะเป็นวันที่คนซื้อน้อยอยู่แล้ว หรือผิดปกติ"
     * avg_roas คิดแบบ ratio of sums (รวมรายได้ ÷ รวมค่าแอด) ไม่ใช่เฉลี่ยของ ROAS รายวัน
     *
     * @param string|null $targetDate รูปแบบ Y-m-d — ไม่ส่ง = วันล่าสุดที่กรอกไว้
     */
    public function getWeekdayContext(int $userId, int $shopId, ?string $targetDate = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $emptyResult = [
            'success' => true,
            'data' => [
                'has_data' => false,
                'target_date' => null,
                'weekday' => null,
                'target_revenue' => null,
                'target_profit' => null,
                'target_roas' => null,
                'sample_count' => 0,
                'avg_revenue' => null,
                'avg_profit' => null,
                'avg_roas' => null,
                'comparable' => false,
            ],
        ];

        $resolvedDate = is_string($targetDate) ? trim($targetDate) : '';
        if ($resolvedDate !== '') {
            $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $resolvedDate);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $resolvedDate) {
                return [
                    'success' => false,
                    'error' => 'รูปแบบวันที่ไม่ถูกต้อง',
                ];
            }
        } else {
            // ไม่ระบุ → ใช้วันล่าสุดที่กรอกไว้
            try {
                $recentRecords = $this->recordRepository->getRecentByShopId($shopId, 1);
            } catch (Throwable $exception) {
                error_log('[record] getWeekdayContext failed: ' . $exception->getMessage());
                return [
                    'success' => false,
                    'error' => 'ไม่สามารถโหลดข้อมูลเปรียบเทียบได้',
                ];
            }

            $latest = $recentRecords[0] ?? null;
            $resolvedDate = is_array($latest) ? trim((string)($latest['record_date'] ?? '')) : '';
            if ($resolvedDate === '') {
                return $emptyResult;
            }

            $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $resolvedDate);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $resolvedDate) {
                return $emptyResult;
            }
        }

        $dateObject = $dateObject->setTime(0, 0, 0);
        $weekday = (int)$dateObject->format('N');
        $monthStart = $dateObject->format('Y-m-01');
        $monthEnd = $dateObject->format('Y-m-t');

        try {
            $monthRecords = $this->recordRepository->getByDateRange($shopId, $monthStart, $monthEnd);
        } catch (Throwable $exception) {
            error_log('[record] getWeekdayContext failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลเปรียบเทียบได้',
            ];
        }

        $targetRevenue = 0.0;
        $targetAdCost = 0.0;
        $sampleCount = 0;
        $sampleRevenueTotal = 0.0;
        $sampleAdCostTotal = 0.0;

        foreach ($monthRecords as $record) {
            $recordDate = trim((string)($record['record_date'] ?? ''));
            if ($recordDate === '') {
                continue;
            }

            $recordObject = DateTimeImmutable::createFromFormat('Y-m-d', $recordDate);
            if (!$recordObject || $recordObject->format('Y-m-d') !== $recordDate) {
                continue;
            }

            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);

            if ($recordDate === $resolvedDate) {
                $targetRevenue = $revenue;
                $targetAdCost = $adCost;
                continue; // ตัดตัวเองออกจากกลุ่มเทียบ
            }

            if ((int)$recordObject->format('N') !== $weekday) {
                continue;
            }

            $sampleCount++;
            $sampleRevenueTotal += $revenue;
            $sampleAdCostTotal += $adCost;
        }

        $sampleProfitTotal = $sampleRevenueTotal - $sampleAdCostTotal;

        return [
            'success' => true,
            'data' => [
                'has_data' => true,
                'target_date' => $resolvedDate,
                'weekday' => $weekday,
                'target_revenue' => $targetRevenue,
                'target_profit' => $targetRevenue - $targetAdCost,
                'target_roas' => $targetAdCost > 0 ? round($targetRevenue / $targetAdCost, 2) : null,
                'sample_count' => $sampleCount,
                'avg_revenue' => $sampleCount > 0 ? round($sampleRevenueTotal / $sampleCount, 2) : null,
                'avg_profit' => $sampleCount > 0 ? round($sampleProfitTotal / $sampleCount, 2) : null,
                // ratio of sums — ไม่ใช่ค่าเฉลี่ยของ ROAS รายวัน
                'avg_roas' => ($sampleCount > 0 && $sampleAdCostTotal > 0)
                    ? round($sampleRevenueTotal / $sampleAdCostTotal, 2)
                    : null,
                'comparable' => $sampleCount >= 1,
            ],
        ];
    }

    /**
     * คำนวณช่วงวันที่ของตารางกำไรตามวัน ตามโหมดที่เลือก
     *
     *  - '8w'    → 56 วันล่าสุด (today ย้อนกลับ 55 วัน) = แต่ละวันในสัปดาห์ปรากฏ 8 ครั้ง
     *  - 'month' → ตั้งแต่วันที่ 1 ของเดือน today ถึง today (ไม่นับวันอนาคตที่ยังไม่ถึง)
     *
     * โหมดที่ไม่รู้จัก → fallback เป็น '8w'
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์
     * @return array{mode: string, start_date: string, end_date: string}
     */
    public function resolveWeekdayWindow(string $mode, ?string $today = null): array
    {
        $normalizedMode = $mode === 'month' ? 'month' : '8w';

        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

        $endDate = $todayObject->format('Y-m-d');
        $startDate = $normalizedMode === 'month'
            ? $todayObject->format('Y-m-01')
            : $todayObject->modify('-55 days')->format('Y-m-d');

        return [
            'mode' => $normalizedMode,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /**
     * สรุปกำไรเฉลี่ยแยกตามวันในสัปดาห์ ภายในช่วงวันที่ที่กำหนด
     *
     * ใช้คู่กับ resolveWeekdayWindow() ที่ controller เป็นคนเลือกโหมด
     * avg_roas คิดแบบ ratio of sums (Σรายได้ ÷ Σค่าแอด)
     */
    public function getWeekdayBreakdown(int $userId, int $shopId, string $startDate, string $endDate): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $startObject = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $endObject = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if (!$startObject || $startObject->format('Y-m-d') !== $startDate
            || !$endObject || $endObject->format('Y-m-d') !== $endDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบวันที่ไม่ถูกต้อง',
            ];
        }

        if ($startDate > $endDate) {
            return [
                'success' => false,
                'error' => 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
            ];
        }

        try {
            $records = $this->recordRepository->getByDateRange($shopId, $startDate, $endDate);
        } catch (Throwable $exception) {
            error_log('[record] getWeekdayBreakdown failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลสรุปตามวันได้',
            ];
        }

        // เตรียมถัง 1..7 ไว้ก่อน เพื่อให้ output เรียง จันทร์→อาทิตย์ เสมอ
        $buckets = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $buckets[$weekday] = [
                'count' => 0,
                'revenue_total' => 0.0,
                'ad_cost_total' => 0.0,
            ];
        }

        $hasData = false;

        foreach ($records as $record) {
            $recordDate = trim((string)($record['record_date'] ?? ''));
            if ($recordDate === '') {
                continue;
            }

            $recordObject = DateTimeImmutable::createFromFormat('Y-m-d', $recordDate);
            if (!$recordObject || $recordObject->format('Y-m-d') !== $recordDate) {
                continue;
            }

            $weekday = (int)$recordObject->format('N');
            if (!isset($buckets[$weekday])) {
                continue;
            }

            $buckets[$weekday]['count']++;
            $buckets[$weekday]['revenue_total'] += (float)($record['revenue'] ?? 0);
            $buckets[$weekday]['ad_cost_total'] += (float)($record['ad_cost'] ?? 0);
            $hasData = true;
        }

        $weekdays = [];
        foreach ($buckets as $weekday => $bucket) {
            $count = (int)$bucket['count'];
            $revenueTotal = (float)$bucket['revenue_total'];
            $adCostTotal = (float)$bucket['ad_cost_total'];
            $profitTotal = $revenueTotal - $adCostTotal;

            $weekdays[] = [
                'weekday' => $weekday,
                'sample_count' => $count,
                'avg_profit' => $count > 0 ? round($profitTotal / $count, 2) : null,
                'avg_revenue' => $count > 0 ? round($revenueTotal / $count, 2) : null,
                'avg_roas' => ($count > 0 && $adCostTotal > 0)
                    ? round($revenueTotal / $adCostTotal, 2)
                    : null,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'has_data' => $hasData,
                'weekdays' => $weekdays,
            ],
        ];
    }

    /**
     * นับจำนวนวันนับจากรายการล่าสุดที่กรอกไว้ (ใช้เตือนว่าไม่ได้กรอกนานแล้ว)
     *
     * แยก 2 เคสให้ชัด:
     *  - ไม่เคยกรอกเลย  → has_records = false, days_since = null
     *  - กรอกวันนี้      → has_records = true,  days_since = 0
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์, ไม่ส่ง = วันนี้จริง
     */
    public function getDaysSinceLastRecord(int $userId, int $shopId, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

        try {
            $records = $this->recordRepository->getRecentByShopId($shopId, 1);
        } catch (Throwable $exception) {
            error_log('[record] getDaysSinceLastRecord failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถตรวจสอบข้อมูลล่าสุดได้',
            ];
        }

        $emptyResult = [
            'success' => true,
            'data' => [
                'has_records' => false,
                'last_record_date' => null,
                'days_since' => null,
            ],
        ];

        $lastRecord = $records[0] ?? null;
        if (!is_array($lastRecord)) {
            return $emptyResult;
        }

        $lastDate = trim((string)($lastRecord['record_date'] ?? ''));
        if ($lastDate === '') {
            return $emptyResult;
        }

        $lastObject = DateTimeImmutable::createFromFormat('Y-m-d', $lastDate);
        if (!$lastObject || $lastObject->format('Y-m-d') !== $lastDate) {
            return $emptyResult;
        }
        $lastObject = $lastObject->setTime(0, 0, 0);

        $daysSince = (int)$lastObject->diff($todayObject)->format('%r%a');
        if ($daysSince < 0) {
            // รายการลงวันที่ล่วงหน้า — ไม่ถือว่าขาดการกรอก
            $daysSince = 0;
        }

        return [
            'success' => true,
            'data' => [
                'has_records' => true,
                'last_record_date' => $lastDate,
                'days_since' => $daysSince,
            ],
        ];
    }

    /**
     * หาวันที่ "ยังไม่ได้กรอก" ของเดือนที่เลือก
     *
     * ช่วงที่พิจารณา = วันที่ 1 ของเดือน ถึง "วันตัด":
     *  - เดือนปัจจุบัน → วันตัด = today (ไม่นับวันอนาคต)
     *  - เดือนอดีต     → วันตัด = วันสิ้นเดือน
     *  - เดือนอนาคต    → คืน [] (ยังไม่ถึงกำหนดกรอก)
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์, ไม่ส่ง = วันนี้จริง
     */
    public function getUnfilledDatesForMonth(int $userId, int $shopId, string $month, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
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
        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$monthStart || $monthStart->format('Y-m-d') !== $startDate) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }
        $monthStart = $monthStart->setTime(0, 0, 0);

        // $today ไม่ถูกต้อง/ไม่ส่ง → ใช้วันนี้จริง
        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

        $selectedMonthKey = $monthStart->format('Y-m');
        $todayMonthKey = $todayObject->format('Y-m');

        // เดือนอนาคต — ยังไม่ถึงกำหนดกรอก
        if ($selectedMonthKey > $todayMonthKey) {
            return [
                'success' => true,
                'data' => [
                    'month' => $selectedMonthKey,
                    'missing_dates' => [],
                    'count' => 0,
                ],
            ];
        }

        $capObject = $selectedMonthKey === $todayMonthKey
            ? $todayObject                                    // เดือนปัจจุบัน: ตัดที่วันนี้
            : $monthStart->modify('last day of this month');  // เดือนอดีต: ตัดที่สิ้นเดือน

        $capDate = $capObject->format('Y-m-d');

        if ($capDate < $startDate) {
            return [
                'success' => true,
                'data' => [
                    'month' => $selectedMonthKey,
                    'missing_dates' => [],
                    'count' => 0,
                ],
            ];
        }

        try {
            $records = $this->recordRepository->getByDateRange($shopId, $startDate, $capDate);
        } catch (Throwable $exception) {
            error_log('[record] getUnfilledDatesForMonth failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถตรวจสอบวันที่ยังไม่ได้กรอกได้',
            ];
        }

        $filledDates = [];
        foreach ($records as $record) {
            $filledDate = (string)($record['record_date'] ?? '');
            if ($filledDate !== '') {
                $filledDates[$filledDate] = true;
            }
        }

        $missingDates = [];
        for ($cursor = $monthStart; $cursor->format('Y-m-d') <= $capDate; $cursor = $cursor->modify('+1 day')) {
            $dateKey = $cursor->format('Y-m-d');
            if (!isset($filledDates[$dateKey])) {
                $missingDates[] = $dateKey;
            }
        }

        return [
            'success' => true,
            'data' => [
                'month' => $selectedMonthKey,
                'missing_dates' => $missingDates,
                'count' => count($missingDates),
            ],
        ];
    }

    public function deleteRecord(int $userId, int $shopId, int $recordId): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
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
}
