<?php

declare(strict_types=1);

class ExportService
{
    /** ชื่อเดือนไทย — เก็บในนี้เองเพื่อไม่ให้ service ผูกกับ view helper */
    private const THAI_MONTHS = [
        1 => 'ม.ค.',
        2 => 'ก.พ.',
        3 => 'มี.ค.',
        4 => 'เม.ย.',
        5 => 'พ.ค.',
        6 => 'มิ.ย.',
        7 => 'ก.ค.',
        8 => 'ส.ค.',
        9 => 'ก.ย.',
        10 => 'ต.ค.',
        11 => 'พ.ย.',
        12 => 'ธ.ค.',
    ];

    private RecordService $recordService;
    private ShopRepository $shopRepository;

    public function __construct(RecordService $recordService, ShopRepository $shopRepository)
    {
        $this->recordService = $recordService;
        $this->shopRepository = $shopRepository;
    }

    public function buildMonthlyCsvPayload(int $userId, int $shopId, string $month): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        try {
            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        } catch (Throwable $exception) {
            error_log('[export] buildMonthlyCsvPayload shop lookup failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลร้านค้าได้',
            ];
        }

        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        try {
            $monthlyResult = $this->recordService->getMonthlyRecords($userId, $shopId, $month);
        } catch (Throwable $exception) {
            error_log('[export] buildMonthlyCsvPayload getMonthlyRecords failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้',
            ];
        }

        if (($monthlyResult['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($monthlyResult['error'] ?? 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้'),
            ];
        }

        $records = (array)($monthlyResult['data']['records'] ?? []);
        $totals = (array)($monthlyResult['data']['totals'] ?? []);
        $rows = [];

        foreach ($records as $record) {
            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);
            $profit = (float)($record['profit'] ?? ($revenue - $adCost));
            $roas = isset($record['roas']) && $record['roas'] !== null ? (float)$record['roas'] : null;
            $comparePercent = isset($record['compare_revenue_percent']) && $record['compare_revenue_percent'] !== null
                ? (float)$record['compare_revenue_percent']
                : null;
            $note = (string)($record['note'] ?? '');

            // ค่าที่ไม่มี → เซลล์ว่าง (ให้ Excel มองเป็น blank ไม่ใช่ข้อความ '–')
            $compareText = '';
            if ($comparePercent !== null) {
                $compareText = ($comparePercent > 0 ? '+' : '') . number_format($comparePercent, 1) . '%';
            }

            $rows[] = [
                // ISO YYYY-MM-DD — Excel sort/filter/pivot ได้ตรง (record_date จาก DB เป็น Y-m-d อยู่แล้ว)
                (string)($record['record_date'] ?? ''),
                number_format($revenue, 2, '.', ''),
                number_format($adCost, 2, '.', ''),
                number_format($profit, 2, '.', ''),
                $roas === null ? '' : number_format($roas, 2, '.', ''),
                $compareText,
                $note,
            ];
        }

        $totalsRow = [
            'รวม',
            number_format((float)($totals['total_revenue'] ?? 0), 2, '.', ''),
            number_format((float)($totals['total_ad_cost'] ?? 0), 2, '.', ''),
            number_format((float)($totals['total_profit'] ?? 0), 2, '.', ''),
            isset($totals['avg_roas']) && $totals['avg_roas'] !== null ? number_format((float)$totals['avg_roas'], 2, '.', '') : '',
            '',
            '',
        ];

        return [
            'success' => true,
            'data' => [
                'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                'month' => $month,
                'headers' => ['วันที่', 'รายได้', 'ค่าแอด', 'กำไร', 'ROAS', 'เทียบเมื่อวาน', 'โน้ต'],
                'rows' => $rows,
                'totals_row' => $totalsRow,
                // คอลัมน์เดียวที่มาจากผู้ใช้ → controller sanitize เฉพาะช่องนี้
                'note_column_index' => 6,
                // ให้ controller เว้น 1 บรรทัดก่อนแถวรวม เพื่อให้ Excel ตัดขอบตารางตรงนั้น
                'blank_row_before_totals' => true,
            ],
        ];
    }

    /**
     * ข้อมูลดิบสำหรับ sheet "รายวัน" ของ xlsx ทั้งปี
     *
     * แยกจากการเขียนไฟล์โดยตั้งใจ — ชั้นนี้เทสต์ได้ตรง ๆ ส่วนการเขียน binary
     * อยู่ที่ controller (api/export-xlsx.php) ซึ่ง unit test ไม่ได้
     *
     * ตัดเดือนอนาคตด้วยขอบเขตเดียวกับ sheet รายเดือน เพื่อให้ยอดรวมสองแท็บตรงกัน
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildYearlyDailyPayload(int $userId, int $shopId, int $year, ?string $today = null): array
    {
        if ($year < 2000 || $year > 2100) {
            return [
                'success' => false,
                'error' => 'ปีที่เลือกไม่ถูกต้อง',
            ];
        }

        try {
            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        } catch (Throwable $exception) {
            error_log('[export] buildYearlyDailyPayload shop lookup failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลร้านค้าได้',
            ];
        }

        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $lastMonth = $this->resolveLastMonth($year, $today);

        if ($lastMonth === 0) {
            return [
                'success' => true,
                'data' => [
                    'year' => $year,
                    'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                    'rows' => [],
                    'totals' => ['revenue' => 0.0, 'ad_cost' => 0.0, 'profit' => 0.0, 'roas' => null],
                    'note_column_index' => 6,
                ],
            ];
        }

        // ตัดที่ "สิ้นเดือนปัจจุบัน" ไม่ใช่ "วันนี้" — เรคอร์ดที่ลงล่วงหน้าภายในเดือนนี้ยังเก็บครบ
        // ตรงกับ sheet รายเดือนและหน้า annual ที่นับทั้งเดือนปัจจุบัน
        $endOfLastMonth = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $lastMonth)))->format('Y-m-t');

        try {
            $rangeResult = $this->recordService->getRecordsByDateRange(
                $userId,
                $shopId,
                sprintf('%04d-01-01', $year),
                $endOfLastMonth
            );
        } catch (Throwable $exception) {
            error_log('[export] buildYearlyDailyPayload fetch failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้',
            ];
        }

        if (($rangeResult['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($rangeResult['error'] ?? 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้'),
            ];
        }

        $rows = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;

        foreach ((array)($rangeResult['data'] ?? []) as $record) {
            $revenue = (float)($record['revenue'] ?? 0);
            $adCost = (float)($record['ad_cost'] ?? 0);

            $rows[] = [
                // ISO YYYY-MM-DD — controller แปลงเป็น Excel date serial ให้เรียง/กรองได้จริง
                'record_date' => (string)($record['record_date'] ?? ''),
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'profit' => $revenue - $adCost,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
                'note' => (string)($record['note'] ?? ''),
            ];

            $totalRevenue += $revenue;
            $totalAdCost += $adCost;
        }

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'shop_name' => (string)($shop['name'] ?? 'ร้านค้า'),
                'rows' => $rows,
                'totals' => [
                    'revenue' => $totalRevenue,
                    'ad_cost' => $totalAdCost,
                    'profit' => $totalRevenue - $totalAdCost,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                ],
                // ตำแหน่งคอลัมน์โน้ต (1-based) — controller กัน formula injection เฉพาะช่องนี้
                // (ช่องอื่นระบบสร้างเอง ปล่อยดิบเพื่อให้ Excel อ่านเป็นวันที่/ตัวเลข)
                'note_column_index' => 6,
            ],
        ];
    }

    /**
     * ยอดรวมรายเดือนทั้งปีสำหรับ sheet "รายเดือน" + กราฟ
     *
     * ตัดเดือนอนาคตแบบเดียวกับ AnnualService (ไม่ให้แท่งกราฟดิ่ง ฿0)
     * แต่ยิง monthly query ตรง ๆ — ไม่เรียก AnnualService เพราะจะ over-fetch (YoY/heatmap/goal)
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildYearlyMonthlyPayload(int $userId, int $shopId, int $year, ?string $today = null): array
    {
        if ($year < 2000 || $year > 2100) {
            return [
                'success' => false,
                'error' => 'ปีที่เลือกไม่ถูกต้อง',
            ];
        }

        try {
            $shop = $this->shopRepository->findByIdAndUserId($shopId, $userId);
        } catch (Throwable $exception) {
            error_log('[export] buildYearlyMonthlyPayload shop lookup failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลร้านค้าได้',
            ];
        }

        if ($shop === null) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $lastMonth = $this->resolveLastMonth($year, $today);

        if ($lastMonth === 0) {
            return [
                'success' => true,
                'data' => [
                    'year' => $year,
                    'last_month' => 0,
                    'months' => [],
                ],
            ];
        }

        try {
            $totalsResult = $this->recordService->getMonthlyTotals(
                $userId,
                $shopId,
                sprintf('%04d-01', $year),
                sprintf('%04d-%02d', $year, $lastMonth)
            );
        } catch (Throwable $exception) {
            error_log('[export] buildYearlyMonthlyPayload fetch failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้',
            ];
        }

        if (($totalsResult['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($totalsResult['error'] ?? 'ไม่สามารถโหลดข้อมูลที่ต้องการ export ได้'),
            ];
        }

        $totalsByMonthKey = [];
        foreach ((array)($totalsResult['data'] ?? []) as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $totalsByMonthKey[$monthKey] = $row;
            }
        }

        $months = [];
        for ($month = 1; $month <= $lastMonth; $month++) {
            $totals = $totalsByMonthKey[sprintf('%04d-%02d', $year, $month)] ?? null;
            $revenue = (float)($totals['total_revenue'] ?? 0);
            $adCost = (float)($totals['total_ad_cost'] ?? 0);

            $months[] = [
                'month' => $month,
                'month_label' => self::THAI_MONTHS[$month] ?? (string)$month,
                'revenue' => $revenue,
                'ad_cost' => $adCost,
                'profit' => $revenue - $adCost,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'last_month' => $lastMonth,
                'months' => $months,
            ],
        ];
    }

    public function buildYearlyXlsxFilename(string $shopName, int $year): string
    {
        return $this->sanitizeFilenameBase($shopName) . '_' . $year . '.xlsx';
    }

    public function buildMonthlyCsvFilename(string $shopName, string $month): string
    {
        return $this->sanitizeFilenameBase($shopName) . '_' . $month . '.csv';
    }

    /**
     * เดือนสุดท้ายที่รายงานควรครอบคลุม — ปีอดีต 12 · ปีปัจจุบันถึงเดือนนี้ · ปีอนาคต 0
     *
     * ใช้ร่วมกันทั้ง sheet รายวันและรายเดือน เพื่อไม่ให้สองแท็บนิยามขอบเขตต่างกัน
     * (เคยเป็นบั๊ก: รายวันดึงเต็มปี รายเดือนตัด → ยอดรวมในไฟล์เดียวกันไม่ตรง)
     */
    private function resolveLastMonth(int $year, ?string $today): int
    {
        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }

        $currentYear = (int)$todayObject->format('Y');

        if ($year < $currentYear) {
            return 12;
        }

        if ($year === $currentYear) {
            return (int)$todayObject->format('n');
        }

        return 0;
    }

    private function sanitizeFilenameBase(string $shopName): string
    {
        $baseName = trim($shopName);
        if ($baseName === '') {
            return 'shop';
        }

        $baseName = preg_replace('/[\\\\\/\:\*\?"<>\|]+/u', '_', $baseName) ?? $baseName;
        $baseName = preg_replace('/\s+/u', ' ', $baseName) ?? $baseName;
        $baseName = trim($baseName);

        return $baseName === '' ? 'shop' : $baseName;
    }
}
