<?php

declare(strict_types=1);

class AnnualService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;
    private GoalRepository $goalRepository;

    public function __construct(
        RecordRepository $recordRepository,
        ShopRepository $shopRepository,
        GoalRepository $goalRepository
    ) {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
        $this->goalRepository = $goalRepository;
    }

    /**
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildYearlySummary(int $userId, int $shopId, int $year, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!$this->isValidYear($year)) {
            return [
                'success' => false,
                'error' => 'ปีที่เลือกไม่ถูกต้อง',
            ];
        }

        // เดือนสุดท้ายที่นับ — ปีปัจจุบันตัดที่เดือนนี้ ไม่ให้เดือนอนาคตโผล่มาเป็น ฿0
        // (ทำให้กราฟไม่ดิ่งลง 0 และ worst_month ไม่ไปเลือกเดือนที่ยังมาไม่ถึง)
        $todayObject = $this->resolveToday($today);

        $currentYear = (int)$todayObject->format('Y');
        if ($year < $currentYear) {
            $lastMonth = 12;                              // ปีอดีต — เต็มปี
        } elseif ($year === $currentYear) {
            $lastMonth = (int)$todayObject->format('n');  // ปีปัจจุบัน — ถึงเดือนนี้
        } else {
            $lastMonth = 0;                               // ปีอนาคต — ยังไม่มีข้อมูล
        }

        $startMonth = sprintf('%04d-01', $year);
        $endMonth = sprintf('%04d-%02d', $year, max(1, $lastMonth));

        try {
            $monthlyTotals = $this->recordRepository->getMonthlyTotalsByMonthRange($shopId, $startMonth, $endMonth);
        } catch (Throwable $exception) {
            error_log('[annual] buildYearlySummary failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลรายปีได้',
            ];
        }

        $totalsByMonthKey = [];
        foreach ($monthlyTotals as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $totalsByMonthKey[$monthKey] = $row;
            }
        }

        // ปีก่อน — ขอเฉพาะเดือน 1..lastMonth เพื่อให้เทียบ "ช่วงเดียวกัน"
        // (ปีนี้ถึงแค่ ส.ค. ก็ต้องเทียบ ม.ค.–ส.ค. ของปีก่อน ไม่ใช่ทั้ง 12 เดือน)
        $previousTotalsByMonthKey = [];
        if ($lastMonth > 0) {
            $previousYear = $year - 1;

            try {
                $previousTotals = $this->recordRepository->getMonthlyTotalsByMonthRange(
                    $shopId,
                    sprintf('%04d-01', $previousYear),
                    sprintf('%04d-%02d', $previousYear, $lastMonth)
                );
            } catch (Throwable $exception) {
                error_log('[annual] buildYearlySummary previous year failed: ' . $exception->getMessage());
                $previousTotals = [];
            }

            foreach ($previousTotals as $row) {
                $monthKey = (string)($row['month_key'] ?? '');
                if ($monthKey !== '') {
                    $previousTotalsByMonthKey[$monthKey] = $row;
                }
            }
        }

        // เป้าทั้งปี — ดึงครั้งเดียวแล้ว map ตาม goal_month (ไม่ query รายเดือนในลูป)
        $goalsByMonthKey = [];
        if ($lastMonth > 0) {
            try {
                $goals = $this->goalRepository->getByShopAndMonthRange(
                    $shopId,
                    sprintf('%04d-01', $year),
                    sprintf('%04d-12', $year)
                );
            } catch (Throwable $exception) {
                error_log('[annual] buildYearlySummary goals failed: ' . $exception->getMessage());
                $goals = [];
            }

            foreach ($goals as $row) {
                $goalMonth = (string)($row['goal_month'] ?? '');
                if ($goalMonth !== '') {
                    $goalsByMonthKey[$goalMonth] = $row;
                }
            }
        }

        $goalProgress = [];
        $previousYearProfit = 0.0;
        $months = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestMonth = null;
        $worstMonth = null;

        // ซีรีส์สำหรับกราฟ — index ตรงกับ $months (เดือน 1..lastMonth) ทั้งหมด
        $previousProfitSeries = [];
        $cumulativeSeries = [];
        $previousCumulativeSeries = [];
        $cumulativeProfit = 0.0;

        $monthsWithData = 0;
        $profitMonths = 0;
        $lossMonths = 0;

        for ($month = 1; $month <= $lastMonth; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $totals = $totalsByMonthKey[$monthKey] ?? null;
            $hasRecord = $totals !== null;

            $monthRevenue = (float)($totals['total_revenue'] ?? 0);
            $monthAdCost = (float)($totals['total_ad_cost'] ?? 0);
            $monthProfit = $monthRevenue - $monthAdCost;

            // เดือนเดียวกันของปีก่อน
            $previousMonthKey = sprintf('%04d-%02d', $year - 1, $month);
            $previousMonthTotals = $previousTotalsByMonthKey[$previousMonthKey] ?? null;
            $previousMonthProfit = (float)($previousMonthTotals['total_revenue'] ?? 0)
                - (float)($previousMonthTotals['total_ad_cost'] ?? 0);
            $previousYearProfit += $previousMonthProfit;

            $monthDaysCount = (int)($totals['days_count'] ?? 0);
            $cumulativeProfit += $monthProfit;

            $previousProfitSeries[] = $previousMonthProfit;
            $cumulativeSeries[] = $cumulativeProfit;
            $previousCumulativeSeries[] = $previousYearProfit;

            $monthRow = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => $monthRevenue,
                'total_ad_cost' => $monthAdCost,
                'profit' => $monthProfit,
                'roas' => $monthAdCost > 0 ? round($monthRevenue / $monthAdCost, 2) : null,
                'profit_margin' => $monthRevenue > 0 ? round(($monthProfit / $monthRevenue) * 100, 1) : null,
                // query คืน days_count มาอยู่แล้ว — กันเทียบเดือนที่กรอก 3 วันกับเดือนที่กรอก 30 วัน
                'days_count' => $monthDaysCount,
                // ปรับฐานให้เทียบกันได้จริง — เดือนที่กรอกวันเดียวอาจแรงกว่าเดือนที่กรอกครบแต่ยอดรวมสูงกว่า
                'profit_per_day' => $monthDaysCount > 0 ? round($monthProfit / $monthDaysCount, 2) : null,
                'prev_year_profit' => $previousMonthProfit,
                'yoy_change_percent' => $this->calculateChangePercent($monthProfit, $previousMonthProfit),
            ];

            // จัดอันดับด้วย "กำไร" และเลือกเฉพาะเดือนที่มีข้อมูลจริง
            // (เดือนที่ยังไม่ได้กรอกมีกำไร 0 — ไม่ควรถูกยกเป็นเดือนแย่สุด)
            // $hasRecord ⟺ days_count > 0 (query GROUP BY คืนแถวเฉพาะเดือนที่มี record → COUNT(*) ≥ 1)
            if ($hasRecord) {
                $monthsWithData++;

                // เดือนเท่าทุนพอดี (profit == 0) ไม่นับเป็นทั้งกำไรและขาดทุน
                if ($monthProfit > 0) {
                    $profitMonths++;
                } elseif ($monthProfit < 0) {
                    $lossMonths++;
                }

                if ($bestMonth === null || $monthProfit > (float)$bestMonth['profit']) {
                    $bestMonth = $monthRow;
                }

                if ($worstMonth === null || $monthProfit < (float)$worstMonth['profit']) {
                    $worstMonth = $monthRow;
                }
            }

            // เฉพาะเดือนที่ "ตั้งเป้าไว้" เท่านั้น — เดือนไม่มีเป้าไม่ควรโผล่เป็นแถวว่าง
            // (ลูปวิ่งแค่ 1..lastMonth อยู่แล้ว → เป้าเดือนอนาคตถูก cutoff ตัดทิ้งโดยปริยาย)
            $goal = $goalsByMonthKey[$monthKey] ?? null;
            if ($goal !== null) {
                $targetRevenue = $goal['target_revenue'] !== null ? (float)$goal['target_revenue'] : null;
                $targetProfit = $goal['target_profit'] !== null ? (float)$goal['target_profit'] : null;

                $goalProgress[] = [
                    'month' => $month,
                    'month_key' => $monthKey,
                    'target_revenue' => $targetRevenue,
                    'target_profit' => $targetProfit,
                    'actual_revenue' => $monthRevenue,
                    'actual_profit' => $monthProfit,
                    // เป้า 0 หารไม่ได้ → null (ไม่ใช่ 0% ที่จะอ่านว่า "ยังไม่คืบ")
                    'revenue_progress' => $targetRevenue !== null && $targetRevenue > 0
                        ? round(($monthRevenue / $targetRevenue) * 100, 1)
                        : null,
                    'profit_progress' => $targetProfit !== null && $targetProfit > 0
                        ? round(($monthProfit / $targetProfit) * 100, 1)
                        : null,
                    'revenue_reached' => $targetRevenue !== null ? $monthRevenue >= $targetRevenue : null,
                    'profit_reached' => $targetProfit !== null ? $monthProfit >= $targetProfit : null,
                ];
            }

            $months[] = $monthRow;
            $totalRevenue += $monthRevenue;
            $totalAdCost += $monthAdCost;
        }

        $profit = $totalRevenue - $totalAdCost;

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'has_data' => $monthsWithData > 0,
                'last_month' => $lastMonth,
                'months' => $months,
                'goal_progress' => $goalProgress,
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'profit' => $profit,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                    'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
                    // นับเฉพาะเดือนที่กรอกแล้ว — เดือนที่ยังไม่กรอกไม่ใช่ "เดือนเท่าทุน"
                    'months_with_data' => $monthsWithData,
                    'profit_months' => $profitMonths,
                    'loss_months' => $lossMonths,
                    'best_month' => $bestMonth,
                    'worst_month' => $worstMonth,
                    // YoY เทียบ "ช่วงเดียวกัน" — ปีนี้ถึงเดือน lastMonth เทียบปีก่อนเดือน 1..lastMonth
                    'prev_year' => $year - 1,
                    'prev_year_profit' => $previousYearProfit,
                    'yoy_profit_change' => $profit - $previousYearProfit,
                    'yoy_profit_change_percent' => $this->calculateChangePercent($profit, $previousYearProfit),
                    'projection' => $this->calculateYearEndProjection(
                        $months,
                        $cumulativeProfit,
                        $lastMonth,
                        $year === $currentYear
                    ),
                ],
                'chart' => [
                    'months' => array_values(array_map(static fn(array $row): string => (string)$row['month_key'], $months)),
                    'revenue' => array_values(array_map(static fn(array $row): float => (float)$row['total_revenue'], $months)),
                    'ad_cost' => array_values(array_map(static fn(array $row): float => (float)$row['total_ad_cost'], $months)),
                    'profit' => array_values(array_map(static fn(array $row): float => (float)$row['profit'], $months)),
                    // กำไรปีก่อนเดือนเดียวกัน — index ตรงกับแกน x ปีนี้ (same-period ไม่เกิน lastMonth)
                    'prev_profit' => $previousProfitSeries,
                    'cumulative_profit' => $cumulativeSeries,
                    'prev_cumulative_profit' => $previousCumulativeSeries,
                ],
            ],
        ];
    }

    /** จำนวนเดือนล่าสุดที่ใช้เป็นฐานประมาณการ */
    private const PROJECTION_BASIS_MONTHS = 3;

    /**
     * ประมาณการกำไรสิ้นปีแบบ run-rate — pure ไม่แตะ repo
     *
     * คืนเป็น "ช่วง" ไม่ใช่เลขเดียว เพราะ run-rate ไม่รู้จักฤดูกาล/การเปิดรอบขาย
     * เลขเดียวจะถูกอ่านว่าเป็นคำสัญญา ช่วงบอกความไม่แน่นอนตามจริง
     *
     * @param array<int,array<string,mixed>> $months แถวเดือน 1..lastMonth จาก buildYearlySummary
     */
    public function calculateYearEndProjection(
        array $months,
        float $cumulativeProfit,
        int $lastMonth,
        bool $isCurrentYear
    ): array {
        // ปีที่จบไปแล้ว/ยังไม่เริ่ม ไม่ต้องเดา — มีตัวเลขจริงอยู่แล้วหรือยังไม่มีอะไรให้เดา
        if (!$isCurrentYear) {
            return ['available' => false, 'reason' => 'not_current_year'];
        }

        $monthsRemaining = 12 - $lastMonth;
        if ($monthsRemaining <= 0) {
            return ['available' => false, 'reason' => 'year_complete'];
        }

        // ฐาน = เฉพาะเดือนที่กรอกแล้ว — เดือนที่ยังไม่กรอกมีกำไร 0 ซึ่งจะลากค่าเฉลี่ยลงผิด ๆ
        $filledProfits = [];
        foreach ($months as $row) {
            if ((int)($row['month'] ?? 0) > $lastMonth) {
                continue;
            }

            if ((int)($row['days_count'] ?? 0) > 0) {
                $filledProfits[] = (float)($row['profit'] ?? 0);
            }
        }

        // เดือนเดียวเดาไม่ได้ว่าเป็นแนวโน้มหรือฟลุ๊ค
        $basis = array_slice($filledProfits, -self::PROJECTION_BASIS_MONTHS);
        if (count($basis) < 2) {
            return ['available' => false, 'reason' => 'insufficient_data'];
        }

        $average = array_sum($basis) / count($basis);

        return [
            'available' => true,
            'months_remaining' => $monthsRemaining,
            'basis_month_count' => count($basis),
            'avg_recent' => round($average, 2),
            'projection_low' => round($cumulativeProfit + ($monthsRemaining * min($basis)), 2),
            'projection_mid' => round($cumulativeProfit + ($monthsRemaining * $average), 2),
            'projection_high' => round($cumulativeProfit + ($monthsRemaining * max($basis)), 2),
        ];
    }

    /**
     * กริดกำไร 12 เดือน × 3 ปี (year-2 → year) สำหรับดูฤดูกาล
     * เทียบปีเดียวยังฟันธงไม่ได้ว่าเดือนไหน "ขายดีประจำ" — ต้องเห็นซ้ำหลายปี
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildMonthlyHeatmap(int $userId, int $shopId, int $year, ?string $today = null): array
    {
        if (!$this->shopRepository->userCanAccessShop($shopId, $userId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!$this->isValidYear($year)) {
            return [
                'success' => false,
                'error' => 'ปีที่เลือกไม่ถูกต้อง',
            ];
        }

        $years = [$year - 2, $year - 1, $year];

        try {
            // ดึงครั้งเดียวคลุมทั้ง 3 ปี — reuse query เดิม ไม่เพิ่ม repo method
            $monthlyTotals = $this->recordRepository->getMonthlyTotalsByMonthRange(
                $shopId,
                sprintf('%04d-01', $years[0]),
                sprintf('%04d-12', $year)
            );
        } catch (Throwable $exception) {
            error_log('[annual] buildMonthlyHeatmap failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลฤดูกาลได้',
            ];
        }

        $totalsByMonthKey = [];
        foreach ($monthlyTotals as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $totalsByMonthKey[$monthKey] = $row;
            }
        }

        // เดือนอนาคตต้องว่างเสมอ — สอดคล้องกับ cutoff ของ buildYearlySummary
        // (กันเรคอร์ดลงวันที่ล่วงหน้าโผล่มาเป็นช่องเขียวในเดือนที่ยังไม่ถึง)
        $todayObject = $this->resolveToday($today);
        $currentYear = (int)$todayObject->format('Y');
        $currentMonth = (int)$todayObject->format('n');

        $grid = [];
        foreach ($years as $gridYear) {
            $row = [];
            for ($month = 1; $month <= 12; $month++) {
                $isFuture = $gridYear > $currentYear || ($gridYear === $currentYear && $month > $currentMonth);
                $totals = $isFuture
                    ? null
                    : ($totalsByMonthKey[sprintf('%04d-%02d', $gridYear, $month)] ?? null);

                // null = ยังไม่ได้กรอก · 0.0 = กรอกแล้วแต่เท่าทุนพอดี — คนละความหมาย ห้ามยุบรวม
                $row[$month] = [
                    'month' => $month,
                    'profit' => $totals !== null
                        ? (float)$totals['total_revenue'] - (float)$totals['total_ad_cost']
                        : null,
                    'has_data' => $totals !== null,
                ];
            }

            $grid[$gridYear] = $row;
        }

        return [
            'success' => true,
            'data' => [
                'years' => $years,
                'grid' => $grid,
            ],
        ];
    }

    /**
     * เปอร์เซ็นต์การเปลี่ยนแปลงเทียบฐานปีก่อน
     * ฐาน 0 (ไม่มีข้อมูลปีก่อน/เท่าทุนพอดี) → null เพราะหารไม่ได้
     * ฐานติดลบ → หารด้วย abs เพื่อให้เครื่องหมายสื่อทิศทางจริง (ขาดทุนน้อยลง = บวก)
     */
    private function calculateChangePercent(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.00001) {
            return null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * แปลง seam $today เป็น DateTimeImmutable — รูปแบบผิด/ไม่ส่ง = วันนี้จริง
     */
    private function resolveToday(?string $today): DateTimeImmutable
    {
        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;

        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            return new DateTimeImmutable('today');
        }

        return $todayObject;
    }

    private function isValidYear(int $year): bool
    {
        return $year >= 2000 && $year <= 2100;
    }
}
