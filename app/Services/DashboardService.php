<?php

declare(strict_types=1);

class DashboardService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;
    private GoalRepository $goalRepository;

    public function __construct(
        RecordRepository $recordRepository,
        ShopRepository $shopRepository,
        GoalRepository $goalRepository
    )
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
        $this->goalRepository = $goalRepository;
    }

    /**
     * memo ของ getByDateRange ต่อการเรียก buildDashboard 1 ครั้ง
     *
     * โหลดแดชบอร์ดแบบ "เดือนนี้" ยิง query ช่วงเดียวกัน 3 รอบ: สรุปช่วงที่เลือก ·
     * ฐานเทียบเดือนก่อน (ฝั่งเดือนปัจจุบัน) · ความคืบหน้าเป้า — ข้อมูลชุดเดียวกันเป๊ะ
     * เก็บไว้ในอ็อบเจ็กต์ที่สร้างใหม่ทุก request จึงไม่มีปัญหาข้อมูลค้างข้ามคำขอ
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $recordsCache = [];

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchRecords(int $shopId, string $startDate, string $endDate): array
    {
        $key = $shopId . '|' . $startDate . '|' . $endDate;

        return $this->recordsCache[$key]
            ??= $this->recordRepository->getByDateRange($shopId, $startDate, $endDate);
    }

    public function buildDashboard(
        int $userId,
        int $shopId,
        string $rangeType,
        ?string $customStartDate,
        ?string $customEndDate,
        ?string $selectedMonth,
        ?string $today = null
    ): array {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'ไม่มีสิทธิ์เข้าถึงร้านนี้ หรือร้านถูกลบไปแล้ว — กรุณาโหลดหน้าใหม่แล้วเลือกร้านอีกครั้ง',
            ];
        }

        $range = $this->resolveRange($rangeType, $customStartDate, $customEndDate, $selectedMonth, $today);
        if (($range['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($range['error'] ?? 'รูปแบบช่วงเวลาไม่ถูกต้อง'),
            ];
        }

        $rangeData = (array)$range['data'];

        try {
            $records = $this->fetchRecords(
                $shopId,
                (string)$rangeData['start_date'],
                (string)$rangeData['end_date']
            );

            $summary = $this->buildSummaryFromRecords($records);
            $dailyChart = $this->buildDailyChart($records);
            $sixMonthChart = $this->buildSixMonthChart($shopId, (string)$rangeData['end_date'], $today);
            $comparison = $this->buildMonthlyComparison($shopId, $rangeData, $today);
            $goalMonth = $this->resolveGoalMonth($rangeData);
            $goalProgress = $this->buildGoalProgress($shopId, $goalMonth, $today);
        } catch (Throwable $exception) {
            error_log('[dashboard] buildDashboard failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลแดชบอร์ดได้',
            ];
        }

        return [
            'success' => true,
            'data' => [
                'range' => $rangeData,
                'summary' => [
                    'total_revenue' => $summary['total_revenue'],
                    'total_ad_cost' => $summary['total_ad_cost'],
                    'profit' => $summary['profit'],
                    'roas' => $summary['roas'],
                ],
                'statistics' => [
                    'avg_revenue_per_day' => $summary['avg_revenue_per_day'],
                    'avg_profit_per_day' => $summary['avg_profit_per_day'],
                    'profit_margin' => $summary['profit_margin'],
                    'best_day' => $summary['best_day'],
                    'worst_day' => $summary['worst_day'],
                    'days_count' => $summary['days_count'],
                ],
                'comparison' => $comparison,
                'goal' => $goalProgress,
                'charts' => [
                    'daily' => $dailyChart,
                    'six_months' => $sixMonthChart,
                ],
            ],
        ];
    }

    public function getSummary(int $userId, int $shopId, string $startDate, string $endDate): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'ไม่มีสิทธิ์เข้าถึงร้านนี้ หรือร้านถูกลบไปแล้ว — กรุณาโหลดหน้าใหม่แล้วเลือกร้านอีกครั้ง',
            ];
        }

        if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate) || $startDate > $endDate) {
            return [
                'success' => false,
                'error' => 'ช่วงวันที่ไม่ถูกต้อง',
            ];
        }

        $summary = $this->buildSummaryFromRecords($this->fetchRecords($shopId, $startDate, $endDate));

        return [
            'success' => true,
            'data' => [
                'total_revenue' => $summary['total_revenue'],
                'total_ad_cost' => $summary['total_ad_cost'],
                'profit' => $summary['profit'],
                'roas' => $summary['roas'],
                'profit_margin' => $summary['profit_margin'],
                'avg_revenue_per_day' => $summary['avg_revenue_per_day'],
                'days_count' => $summary['days_count'],
            ],
        ];
    }

    /**
     * @param string|null $todayInput รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     *
     * ต้องรับ seam ด้วย ไม่งั้นช่วงวันที่ใช้วันนี้จริงขณะที่การเทียบ same-period ใช้ค่า
     * ที่ส่งเข้ามา → สองส่วนอ้างอิงคนละวันในคำขอเดียวกัน
     */
    private function resolveRange(
        string $rangeType,
        ?string $customStartDate,
        ?string $customEndDate,
        ?string $selectedMonth,
        ?string $todayInput = null
    ): array {
        $normalizedToday = is_string($todayInput) ? trim($todayInput) : '';
        $today = $normalizedToday !== ''
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $normalizedToday)
            : false;
        if (!$today || $today->format('Y-m-d') !== $normalizedToday) {
            $today = new DateTimeImmutable('today');
        }
        $normalizedRangeType = in_array($rangeType, ['week_this', 'week_last', 'month_this', 'month_last', 'month_pick', 'custom'], true)
            ? $rangeType
            : 'month_this';

        if ($normalizedRangeType === 'custom') {
            $startDate = is_string($customStartDate) ? $customStartDate : '';
            $endDate = is_string($customEndDate) ? $customEndDate : '';

            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                return [
                    'success' => false,
                    'error' => 'กรุณาเลือกวันที่เริ่มต้นและสิ้นสุดให้ถูกต้อง',
                ];
            }

            if ($startDate > $endDate) {
                return [
                    'success' => false,
                    'error' => 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
                ];
            }

            $startDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
            $endDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if ($startDateObject && $endDateObject) {
                $maxRangeDays = 366;
                $daysCount = (int)($startDateObject->diff($endDateObject)->days ?? 0) + 1;

                if ($daysCount > $maxRangeDays) {
                    return [
                        'success' => false,
                        'error' => 'ช่วงวันที่กำหนดเองยาวเกินไป (สูงสุด ' . $maxRangeDays . ' วัน)',
                    ];
                }
            }

            return [
                'success' => true,
                'data' => [
                    'type' => 'custom',
                    'label' => 'กำหนดเอง',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_monthly' => false,
                    'selected_month' => null,
                    'previous_month' => null,
                    'custom_start_date' => $startDate,
                    'custom_end_date' => $endDate,
                ],
            ];
        }

        if ($normalizedRangeType === 'week_this' || $normalizedRangeType === 'week_last') {
            $weekday = (int)$today->format('N');
            $weekStart = $today->modify('-' . ($weekday - 1) . ' days');

            if ($normalizedRangeType === 'week_last') {
                $weekStart = $weekStart->modify('-7 days');
            }

            $weekEnd = $this->clampRangeEndToToday($weekStart->modify('+6 days'), $today);

            return [
                'success' => true,
                'data' => [
                    'type' => $normalizedRangeType,
                    'label' => $normalizedRangeType === 'week_this' ? 'สัปดาห์นี้' : 'สัปดาห์ก่อน',
                    'start_date' => $weekStart->format('Y-m-d'),
                    'end_date' => $weekEnd->format('Y-m-d'),
                    'is_monthly' => false,
                    'selected_month' => null,
                    'previous_month' => null,
                    'custom_start_date' => null,
                    'custom_end_date' => null,
                ],
            ];
        }

        if ($normalizedRangeType === 'month_pick') {
            $pickedMonth = is_string($selectedMonth) ? trim($selectedMonth) : '';
            if (!$this->isValidMonth($pickedMonth)) {
                $pickedMonth = $today->format('Y-m');
            }

            // ⚠️ เดือนอนาคตต้องหดมาเป็นเดือนปัจจุบัน ไม่ใช่ปล่อยให้ clamp ทำช่วงกลับหัว
            // (เคยได้ start_date=2027-01-01 end_date=2026-08-04 → ทุกการ์ดเป็น ฿0
            //  ขณะที่ตัวเลือกเดือนยังโชว์ 2027-01) · `max` ของ picker เป็นแค่ฝั่งเบราว์เซอร์
            if ($pickedMonth > $today->format('Y-m')) {
                $pickedMonth = $today->format('Y-m');
            }

            $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $pickedMonth . '-01');
            if (!$monthStart) {
                $monthStart = $today->modify('first day of this month');
                $pickedMonth = $monthStart->format('Y-m');
            }

            $monthEnd = $this->clampRangeEndToToday($monthStart->modify('last day of this month'), $today);
            $previousMonth = $monthStart->modify('-1 month')->format('Y-m');

            return [
                'success' => true,
                'data' => [
                    'type' => 'month_pick',
                    'label' => 'เลือกเดือน',
                    'start_date' => $monthStart->format('Y-m-d'),
                    'end_date' => $monthEnd->format('Y-m-d'),
                    'is_monthly' => true,
                    'selected_month' => $pickedMonth,
                    'previous_month' => $previousMonth,
                    'custom_start_date' => null,
                    'custom_end_date' => null,
                ],
            ];
        }

        $monthStart = $normalizedRangeType === 'month_last'
            ? $today->modify('first day of last month')
            : $today->modify('first day of this month');

        $monthEnd = $this->clampRangeEndToToday($monthStart->modify('last day of this month'), $today);
        $selectedMonth = $monthStart->format('Y-m');
        $previousMonth = $monthStart->modify('-1 month')->format('Y-m');

        return [
            'success' => true,
            'data' => [
                'type' => $normalizedRangeType,
                'label' => $normalizedRangeType === 'month_last' ? 'เดือนก่อน' : 'เดือนนี้',
                'start_date' => $monthStart->format('Y-m-d'),
                'end_date' => $monthEnd->format('Y-m-d'),
                'is_monthly' => true,
                'selected_month' => $selectedMonth,
                'previous_month' => $previousMonth,
                'custom_start_date' => null,
                'custom_end_date' => null,
            ],
        ];
    }

    private function buildSummaryFromRecords(array $records): array
    {
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestDay = null;
        $worstDay = null;

        foreach ($records as $record) {
            $recordRevenue = (float)($record['revenue'] ?? 0);
            $recordAdCost = (float)($record['ad_cost'] ?? 0);
            $recordDate = (string)($record['record_date'] ?? '');

            $totalRevenue += $recordRevenue;
            $totalAdCost += $recordAdCost;

            // จัดอันดับด้วย "กำไร" (ติดลบได้เมื่อค่าแอดมากกว่ารายได้)
            $recordProfit = $recordRevenue - $recordAdCost;

            if ($bestDay === null || $recordProfit > (float)$bestDay['profit']) {
                $bestDay = [
                    'record_date' => $recordDate,
                    'profit' => $recordProfit,
                ];
            }

            if ($worstDay === null || $recordProfit < (float)$worstDay['profit']) {
                $worstDay = [
                    'record_date' => $recordDate,
                    'profit' => $recordProfit,
                ];
            }
        }

        // ⭐ ปัดเป็นสตางค์ก่อนใช้ต่อ — ให้ตรงกับ `SUM()` ของฐานข้อมูลที่หน้าอื่นใช้
        $totalRevenue = money_total($totalRevenue);
        $totalAdCost = money_total($totalAdCost);
        $profit = money_total($totalRevenue - $totalAdCost);
        $daysCount = count($records);

        return [
            'total_revenue' => $totalRevenue,
            'total_ad_cost' => $totalAdCost,
            'profit' => $profit,
            'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
            'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
            // คง avg_revenue_per_day ไว้ (ของเดิมอาจมีที่ใช้) — การ์ดใช้ avg_profit_per_day แทน
            'avg_revenue_per_day' => $daysCount > 0 ? round($totalRevenue / $daysCount, 2) : null,
            'avg_profit_per_day' => $daysCount > 0 ? round($profit / $daysCount, 2) : null,
            'days_count' => $daysCount,
            'best_day' => $bestDay,
            'worst_day' => $worstDay,
        ];
    }

    private function buildMonthlyComparison(int $shopId, array $rangeData, ?string $today = null): array
    {
        if (($rangeData['is_monthly'] ?? false) !== true) {
            return [
                'enabled' => false,
                'selected_month' => null,
                'previous_month' => null,
                'change' => [
                    'total_revenue' => null,
                    'total_ad_cost' => null,
                    'profit' => null,
                    'roas' => null,
                ],
            ];
        }

        $selectedMonth = (string)($rangeData['selected_month'] ?? '');
        $previousMonth = (string)($rangeData['previous_month'] ?? '');

        if (!$this->isValidMonth($selectedMonth) || !$this->isValidMonth($previousMonth)) {
            return [
                'enabled' => false,
                'selected_month' => null,
                'previous_month' => null,
                'change' => [
                    'total_revenue' => null,
                    'total_ad_cost' => null,
                    'profit' => null,
                    'roas' => null,
                ],
            ];
        }

        // เทียบ "ช่วงเดียวกัน": ถ้าเดือนที่เลือกคือเดือนปัจจุบันซึ่งยังไม่จบ ให้ตัดเดือนก่อน
        // ที่วันเดียวกันด้วย ไม่งั้นวันที่ 3 ของเดือนจะเทียบ 3 วัน กับ 30 วัน แล้วขึ้น −90%
        // ทุกการ์ดโดยที่ผลงานจริงไม่ได้แย่ลง (หน้ารายปีทำ same-period แบบนี้อยู่แล้ว)
        $cutoffDay = $this->resolveComparisonCutoffDay($selectedMonth, $today);

        $selected = $this->sumMonthUpToDay($shopId, $selectedMonth, $cutoffDay);
        $previous = $this->sumMonthUpToDay($shopId, $previousMonth, $cutoffDay);

        $selectedRevenue = $selected['revenue'];
        $selectedAdCost = $selected['ad_cost'];
        $selectedProfit = $selectedRevenue - $selectedAdCost;
        // เก็บค่าดิบไว้คิด % ด้วย — ปัดก่อนแล้วค่อยหาผลต่างทำให้เปอร์เซ็นต์เพี้ยน
        // (ROAS 2.005 vs 2.004 เปลี่ยนจริง 0.05% แต่ถ้าปัดเป็น 2.01 vs 2.00 จะขึ้น 0.5%)
        $selectedRoasExact = $selectedAdCost > 0 ? $selectedRevenue / $selectedAdCost : null;
        $selectedRoas = $selectedRoasExact === null ? null : round($selectedRoasExact, 2);

        $previousRevenue = $previous['revenue'];
        $previousAdCost = $previous['ad_cost'];
        $previousProfit = $previousRevenue - $previousAdCost;
        $previousRoasExact = $previousAdCost > 0 ? $previousRevenue / $previousAdCost : null;
        $previousRoas = $previousRoasExact === null ? null : round($previousRoasExact, 2);

        return [
            'enabled' => true,
            'selected_month' => $selectedMonth,
            'previous_month' => $previousMonth,
            'compared_up_to_day' => $cutoffDay,
            'change' => [
                'total_revenue' => change_percent($selectedRevenue, $previousRevenue),
                'total_ad_cost' => change_percent($selectedAdCost, $previousAdCost),
                'profit' => change_percent($selectedProfit, $previousProfit),
                'roas' => change_percent($selectedRoasExact, $previousRoasExact),
            ],
        ];
    }

    private function buildDailyChart(array $records): array
    {
        $dates = [];
        $revenues = [];
        $adCosts = [];
        $profits = [];

        foreach ($records as $record) {
            $recordDate = (string)($record['record_date'] ?? '');
            $recordRevenue = (float)($record['revenue'] ?? 0);
            $recordAdCost = (float)($record['ad_cost'] ?? 0);

            $dates[] = $recordDate;
            $revenues[] = $recordRevenue;
            $adCosts[] = $recordAdCost;
            $profits[] = $recordRevenue - $recordAdCost;
        }

        return [
            'dates' => $dates,
            'revenue' => $revenues,
            'ad_cost' => $adCosts,
            'profit' => $profits,
        ];
    }

    private function buildSixMonthChart(int $shopId, string $rangeEndDate, ?string $today = null): array
    {
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $rangeEndDate);
        if (!$endDate) {
            $endDate = new DateTimeImmutable('today');
        }

        // ⚠️⚠️ กราฟนี้มีป้ายของตัวเองว่า "ย้อนหลัง 6 เดือน" จึงต้องไม่ยื่นไปข้างหน้า
        //
        // ช่วง "กำหนดเอง" ตั้งใจไม่ถูกตัดที่วันนี้ (ผู้ใช้เลือกเองย่อมตั้งใจ) แต่ค่านั้น
        // ถูกส่งมาเป็นจุดสิ้นสุดของกราฟด้วย · วัดจริง: วันนี้ 7 ส.ค. เลือกช่วง
        // 1 ก.ค. – 31 ธ.ค. → กราฟออกมาเป็น ก.ค. ฿93,000 · ส.ค. ฿21,000 ·
        // **ก.ย. ต.ค. พ.ย. ธ.ค. = ฿0 ทั้งหมด** 4 ใน 6 แท่งเป็นเดือนที่ยังมาไม่ถึง
        // แต่วาดเป็นเส้นดิ่งลงศูนย์ ซึ่งอ่านแล้วเหมือนธุรกิจกำลังพัง
        $todayObject = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$today);
        if (!$todayObject) {
            $todayObject = new DateTimeImmutable('today');
        }
        if ($endDate > $todayObject) {
            $endDate = $todayObject;
        }

        $endMonthStart = $endDate->modify('first day of this month');
        $startMonthStart = $endMonthStart->modify('-5 months');

        $startMonth = $startMonthStart->format('Y-m');
        $endMonth = $endMonthStart->format('Y-m');

        $rows = $this->recordRepository->getMonthlyTotalsByMonthRange(
            $shopId,
            $startMonth,
            $endMonth,
            // ⚠️⚠️ ตัดที่ **วันนี้** ไม่ใช่ปลายช่วงที่ผู้ใช้เลือก
            //
            // แต่ละแท่งแทน "ทั้งเดือน" มีแต่เดือนปัจจุบันที่ควรถูกตัด (ให้ตรงกับการ์ดสรุป
            // ที่อยู่เหนือกราฟ) · เดิมส่งปลายช่วงที่เลือกมาตัด ซึ่งไปโดนแท่งที่ควรเต็ม:
            //   · เลือก "สัปดาห์ก่อน" (จบ 2 ส.ค.) → แท่ง ส.ค. เหลือ ฿2,000 แทน ฿7,000
            //   · เลือกช่วงเอง 1–10 มี.ค. → แท่ง มี.ค. เหลือ ฿10,000 แทน ฿31,000
            // ร้านที่ทำกำไรวันละเท่ากันเป๊ะทุกวันจึงเห็นเส้นดิ่งลง 2 ใน 3 ในเดือนสุดท้าย
            // และกดสลับตัวเลือกช่วงเวลาเฉย ๆ แท่งเดือนเดียวกันก็เปลี่ยนค่า
            $todayObject->format('Y-m-d')
        );
        $mappedRows = [];

        foreach ($rows as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $mappedRows[$monthKey] = $row;
            }
        }

        $months = [];
        $revenues = [];
        $adCosts = [];
        $profits = [];

        for ($index = 0; $index < 6; $index++) {
            $monthDate = $startMonthStart->modify('+' . $index . ' months');
            $monthKey = $monthDate->format('Y-m');
            $row = $mappedRows[$monthKey] ?? null;

            $months[] = $monthKey;

            // ⚠️⚠️ เดือนที่ยังไม่ได้กรอกเลย = `null` ไม่ใช่ `0`
            // เติม 0 ให้ กราฟจะลากผ่านเหมือนเดือนนั้น "ทำได้เท่าทุนพอดี" ทั้งที่แค่
            // ยังไม่ได้เริ่มบันทึก · ร้านที่เริ่มใช้ระบบกลางปีจึงเห็นเส้นดิ่งลงศูนย์
            // ยาวหลายเดือน (Chart.js เว้นช่วงให้เองเมื่อค่าเป็น null)
            if ($row === null) {
                $revenues[] = null;
                $adCosts[] = null;
                $profits[] = null;
                continue;
            }

            $monthRevenue = (float)($row['total_revenue'] ?? 0);
            $monthAdCost = (float)($row['total_ad_cost'] ?? 0);

            $revenues[] = $monthRevenue;
            $adCosts[] = $monthAdCost;
            $profits[] = $monthRevenue - $monthAdCost;
        }

        return [
            'months' => $months,
            'revenue' => $revenues,
            'ad_cost' => $adCosts,
            'profit' => $profits,
        ];
    }

    /**
     * คำนวณ "pace" ของเป้าหมาย — ต้องได้อีกวันละเท่าไรถึงจะถึงเป้า
     *
     * เป็น pure function (ไม่แตะ repository) รับตัวเลขที่คำนวณมาแล้วเข้ามาล้วน
     *
     * ⚠️⚠️ `days_remaining` = "วันที่ยังหาเงินเพิ่มได้" ซึ่งขึ้นกับว่า **วันนี้กรอกไปแล้วหรือยัง**
     * เพราะยอดของวันนี้ถ้ากรอกแล้วจะอยู่ใน `$actualProfit` เรียบร้อย จะเอามานับเป็น
     * "วันที่เหลือ" อีกไม่ได้ ไม่งั้นนับซ้ำ 1 วัน
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์
     */
    public function calculateGoalPace(
        ?float $targetRevenue,
        float $actualRevenue,
        ?float $targetProfit,
        float $actualProfit,
        string $goalMonth,
        ?string $today = null,
        bool $todayAlreadyRecorded = false
    ): array {
        $default = [
            'pace_applicable' => false,
            'month_status' => 'ended',
            'days_remaining' => null,
            'remaining_revenue' => null,
            'remaining_profit' => null,
            'required_per_day_revenue' => null,
            'required_per_day_profit' => null,
        ];

        if (!$this->isValidMonth($goalMonth)) {
            return $default;
        }

        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }
        $todayObject = $todayObject->setTime(0, 0, 0);

        $currentMonth = $todayObject->format('Y-m');

        if ($goalMonth < $currentMonth) {
            // เดือนจบไปแล้ว — ไม่มี pace ให้ไล่
            return $default;
        }

        $monthStart = DateTimeImmutable::createFromFormat('Y-m-d', $goalMonth . '-01');
        if (!$monthStart) {
            return $default;
        }
        $monthStart = $monthStart->setTime(0, 0, 0);
        $daysInMonth = (int)$monthStart->format('t');

        if ($goalMonth === $currentMonth) {
            $monthStatus = 'current';
            // ⚠️⚠️ วันนี้กรอกแล้ว = ยอดของวันนี้อยู่ใน actual แล้ว ห้ามนับเป็นวันที่เหลืออีก
            //
            // วัดจริงตอนนับซ้ำ: เป้ากำไรเดือน ส.ค. ฿100,000 · กรอกครบ 1–7 ส.ค. ได้ ฿7,000
            // หน้าจอแนะนำ "เหลือ 25 วัน · ต้องได้อีกวันละ ฿3,720"
            // ทำตามเป๊ะ ๆ: 7,000 + (3,720 × 24 วันที่เหลือจริง) = ฿96,280 — ขาดอีกหนึ่งวันพอดี
            // ตัวเลขที่ถูกคือ 93,000 ÷ 24 = ฿3,875
            //
            // ถ้ายังไม่กรอกวันนี้ วันนี้ยังหาเงินได้อยู่ จึงนับรวม (วันสิ้นเดือน → เหลือ 1 วัน)
            $daysRemaining = $daysInMonth - (int)$todayObject->format('j')
                + ($todayAlreadyRecorded ? 0 : 1);
        } else {
            $monthStatus = 'upcoming';
            $daysRemaining = $daysInMonth;
        }

        $daysRemaining = max(0, $daysRemaining);

        $remainingRevenue = $targetRevenue !== null ? max(0.0, $targetRevenue - $actualRevenue) : null;
        $remainingProfit = $targetProfit !== null ? max(0.0, $targetProfit - $actualProfit) : null;

        $perDay = static function (?float $remaining) use ($daysRemaining): ?float {
            if ($remaining === null || $daysRemaining <= 0) {
                return null;
            }

            return round($remaining / $daysRemaining, 2);
        };

        return [
            'pace_applicable' => true,
            'month_status' => $monthStatus,
            'days_remaining' => $daysRemaining,
            'remaining_revenue' => $remainingRevenue,
            'remaining_profit' => $remainingProfit,
            'required_per_day_revenue' => $perDay($remainingRevenue),
            'required_per_day_profit' => $perDay($remainingProfit),
        ];
    }

    private function buildGoalProgress(int $shopId, string $goalMonth, ?string $today = null): array
    {
        if (!$this->isValidMonth($goalMonth)) {
            $goalMonth = date('Y-m');
        }

        $goal = $this->goalRepository->findByShopAndMonth($shopId, $goalMonth);

        $default = [
            'has_goal' => false,
            'goal_month' => $goalMonth,
            'target_revenue' => null,
            'target_profit' => null,
            'actual_revenue' => 0.0,
            'actual_profit' => 0.0,
            'progress_revenue' => null,
            'progress_profit' => null,
            'revenue_reached' => false,
            'profit_reached' => false,
            'is_achieved' => false,
            // ไม่มีเป้า → ไม่มี pace
            'pace_applicable' => false,
            'month_status' => 'ended',
            'days_remaining' => null,
            'remaining_revenue' => null,
            'remaining_profit' => null,
            'required_per_day_revenue' => null,
            'required_per_day_profit' => null,
        ];

        if ($goal === null) {
            return $default;
        }

        $monthStart = $goalMonth . '-01';
        $monthEndObject = DateTimeImmutable::createFromFormat('Y-m-d', $monthStart);
        if (!$monthEndObject) {
            return $default;
        }

        // ⚠️ ต้องตัดที่วันนี้เหมือนการ์ดสรุป — ไม่งั้นการ์ดสรุปขึ้น ฿4,000 ขณะที่การ์ดเป้า
        // ใต้มันบอก "ทำได้ ฿10,000 = 100% ถึงเป้าแล้ว" เพราะนับรายการที่ลงล่วงหน้าไว้ด้วย
        $monthEnd = comparison_range_end(
            $monthEndObject->format('Y-m'),
            resolve_comparison_cutoff_day($monthEndObject->format('Y-m'), $today)
        );
        $monthRecords = $this->fetchRecords($shopId, $monthStart, $monthEnd);
        $monthSummary = $this->buildSummaryFromRecords($monthRecords);

        $targetRevenue = isset($goal['target_revenue']) && $goal['target_revenue'] !== null
            ? (float)$goal['target_revenue']
            : null;
        $targetProfit = isset($goal['target_profit']) && $goal['target_profit'] !== null
            ? (float)$goal['target_profit']
            : null;

        $actualRevenue = (float)($monthSummary['total_revenue'] ?? 0);
        $actualProfit = (float)($monthSummary['profit'] ?? 0);

        $progressRevenue = $this->calculateGoalPercent($actualRevenue, $targetRevenue);
        $progressProfit = $this->calculateGoalPercent($actualProfit, $targetProfit);

        // เทียบค่าจริง ไม่ใช่ progress ที่ปัดทศนิยมแล้ว — ไม่งั้น 9,999.60 จาก 10,000
        // จะกลายเป็น 100.0% แล้วบอกว่า "ถึงเป้า" ขณะที่ AnnualService.php:221-222
        // เทียบค่าจริงจึงบอกว่ายังไม่ถึง (ข้อมูลชุดเดียวกัน สองหน้าตอบต่างกัน)
        $revenueReached = GoalService::isReached($actualRevenue, $targetRevenue) === true;
        $profitReached = GoalService::isReached($actualProfit, $targetProfit) === true;

        $configuredReached = [];
        if ($targetRevenue !== null) {
            $configuredReached[] = $revenueReached;
        }
        if ($targetProfit !== null) {
            $configuredReached[] = $profitReached;
        }

        $isAchieved = !empty($configuredReached) && !in_array(false, $configuredReached, true);

        // pace คำนวณจากตัวเลขที่มีอยู่แล้ว — ไม่ fetch ซ้ำ
        // ⚠️ วันนี้กรอกไปแล้วหรือยัง — ตัวชี้ขาดว่า "วันที่เหลือ" ต้องนับวันนี้ด้วยไหม
        $todayIso = (resolve_comparison_cutoff_day($goalMonth, $today) === null)
            ? ''
            : comparison_range_end($goalMonth, resolve_comparison_cutoff_day($goalMonth, $today));
        $todayAlreadyRecorded = false;
        foreach ($monthRecords as $record) {
            if ((string)($record['record_date'] ?? '') === $todayIso) {
                $todayAlreadyRecorded = true;
                break;
            }
        }

        $pace = $this->calculateGoalPace(
            $targetRevenue,
            $actualRevenue,
            $targetProfit,
            $actualProfit,
            $goalMonth,
            $today,
            $todayAlreadyRecorded
        );

        return array_merge([
            'has_goal' => true,
            'goal_month' => $goalMonth,
            'target_revenue' => $targetRevenue,
            'target_profit' => $targetProfit,
            'actual_revenue' => $actualRevenue,
            'actual_profit' => $actualProfit,
            'progress_revenue' => $progressRevenue,
            'progress_profit' => $progressProfit,
            'revenue_reached' => $revenueReached,
            'profit_reached' => $profitReached,
            'is_achieved' => $isAchieved,
        ], $pace);
    }

    private function resolveGoalMonth(array $rangeData): string
    {
        if (($rangeData['is_monthly'] ?? false) === true && $this->isValidMonth((string)($rangeData['selected_month'] ?? ''))) {
            return (string)$rangeData['selected_month'];
        }

        return date('Y-m');
    }

    /**
     * ปัดลง ไม่ใช่ปัดใกล้สุด — 9,999.60 จากเป้า 10,000 = 99.996% ถ้า round จะกลายเป็น
     * 100.0% แล้วแถบขึ้นเต็มคู่กับป้าย "ยังไม่ถึงเป้า" ในการ์ดเดียวกัน (ป้ายเทียบค่าจริง)
     * ปัดลงทำให้ 100.0% ปรากฏเฉพาะตอนถึงเป้าจริงเท่านั้น
     */
    private function calculateGoalPercent(float $actual, ?float $target): ?float
    {
        // สูตรกลาง — หน้ารายปีและไฟล์ Excel ใช้ตัวเดียวกัน
        return GoalService::progressPercent($actual, $target);
    }

    /**
     * วันสุดท้ายที่ใช้เทียบ — เดือนปัจจุบันตัดที่วันนี้ · เดือนที่จบแล้วใช้ทั้งเดือน (null)
     */
    /**
     * ตัดปลายช่วงไม่ให้เลย "วันนี้"
     *
     * ⚠️ ถ้ามี legacy future rows หลุดมาแล้วไม่ตัด การ์ดสรุปของ "เดือนนี้" จะรวมยอดของวันที่
     * ยังมาไม่ถึงเข้าไปด้วย ขณะที่ป้าย "เทียบเดือนก่อน" ใต้การ์ดเดียวกันตัดที่วันนี้อยู่แล้ว
     * → การ์ดขึ้นกำไร ฿9,000 คู่กับป้าย 0% และ "วันกำไรดีสุด" เป็นวันที่ยังมาไม่ถึง
     * (ตัดสินแล้ว: นับเฉพาะวันที่ถึงแล้ว) · ช่วงที่ผู้ใช้เลือกเองไม่ถูกตัด
     */
    private function clampRangeEndToToday(DateTimeImmutable $rangeEnd, DateTimeImmutable $today): DateTimeImmutable
    {
        return $rangeEnd > $today ? $today : $rangeEnd;
    }

    private function resolveComparisonCutoffDay(string $selectedMonth, ?string $today): ?int
    {
        /* helper กลาง — หน้ารวมร้านใช้ตัวเดียวกัน (เดิมมีแค่ที่นี่ที่ตัดวัน)
           ⚠️⚠️ ต้องเป็น `resolve_month_over_month_cutoff_day()` ไม่ใช่ตัวธรรมดา —
           ค่านี้ถูกใช้ 2 อย่างพร้อมกัน: ตัดช่วงของ **ทั้งสองเดือน** และเป็นตัวเลขที่ป้าย
           "เทียบเดือนก่อน (ถึงวันที่ N)" เอาไปประกาศ · ถ้าไม่หดให้พอดีเดือนก่อน
           วันที่ 31 มี.ค. ป้ายจะบอกว่าเทียบถึงวันที่ 31 ทั้งที่ ก.พ. ไม่มีวันนั้น
           (ดูตัวเลขที่วัดได้ในคำอธิบายของ helper) */
        return resolve_month_over_month_cutoff_day($selectedMonth, $today);
    }

    /**
     * ยอดรวมของเดือน ตั้งแต่วันที่ 1 ถึงวันที่กำหนด (null = ทั้งเดือน)
     *
     * วันตัดที่เกินจำนวนวันของเดือนนั้นถูกหดลงให้พอดี — ตัดวันที่ 31 กับเดือน ก.พ.
     * ต้องได้ทั้งเดือน ไม่ใช่ช่วงว่าง
     *
     * @return array{revenue: float, ad_cost: float}
     */
    private function sumMonthUpToDay(int $shopId, string $month, ?int $cutoffDay): array
    {
        $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$monthStart) {
            return ['revenue' => 0.0, 'ad_cost' => 0.0];
        }

        $lastDayOfMonth = (int)$monthStart->format('t');
        $endDay = $cutoffDay === null ? $lastDayOfMonth : min($cutoffDay, $lastDayOfMonth);

        $records = $this->fetchRecords(
            $shopId,
            $monthStart->format('Y-m-d'),
            $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $endDay)->format('Y-m-d')
        );

        $revenue = 0.0;
        $adCost = 0.0;
        foreach ($records as $record) {
            $revenue += (float)($record['revenue'] ?? 0);
            $adCost += (float)($record['ad_cost'] ?? 0);
        }

        return ['revenue' => $revenue, 'ad_cost' => $adCost];
    }

    private function isValidDate(string $date): bool
    {
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dateObject && $dateObject->format('Y-m-d') === $date;
    }

    private function isValidMonth(string $month): bool
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return false;
        }

        $monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');

        return $monthDate && $monthDate->format('Y-m') === $month;
    }
}
