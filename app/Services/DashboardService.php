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

    public function buildDashboard(
        int $userId,
        int $shopId,
        string $rangeType,
        ?string $customStartDate,
        ?string $customEndDate
    ): array {
        if (!$this->canAccessShop($userId, $shopId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        $range = $this->resolveRange($rangeType, $customStartDate, $customEndDate);
        if (($range['success'] ?? false) !== true) {
            return [
                'success' => false,
                'error' => (string)($range['error'] ?? 'รูปแบบช่วงเวลาไม่ถูกต้อง'),
            ];
        }

        $rangeData = (array)$range['data'];
        $records = $this->recordRepository->getByDateRange(
            $shopId,
            (string)$rangeData['start_date'],
            (string)$rangeData['end_date']
        );

        $summary = $this->buildSummaryFromRecords($records);
        $dailyChart = $this->buildDailyChart($records);
        $sixMonthChart = $this->buildSixMonthChart($shopId, (string)$rangeData['end_date']);
        $comparison = $this->buildMonthlyComparison($shopId, $rangeData);
        $goalMonth = $this->resolveGoalMonth($rangeData);
        $goalProgress = $this->buildGoalProgress($shopId, $goalMonth);

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
        if (!$this->canAccessShop($userId, $shopId)) {
            return [
                'success' => false,
                'error' => 'คุณไม่มีสิทธิ์เข้าถึงร้านค้านี้',
            ];
        }

        if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate) || $startDate > $endDate) {
            return [
                'success' => false,
                'error' => 'ช่วงวันที่ไม่ถูกต้อง',
            ];
        }

        $records = $this->recordRepository->getByDateRange($shopId, $startDate, $endDate);

        $summary = $this->buildSummaryFromRecords($records);

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

    private function resolveRange(string $rangeType, ?string $customStartDate, ?string $customEndDate): array
    {
        $today = new DateTimeImmutable('today');
        $normalizedRangeType = in_array($rangeType, ['week_this', 'week_last', 'month_this', 'month_last', 'custom'], true)
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

            $weekEnd = $weekStart->modify('+6 days');

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

        $monthStart = $normalizedRangeType === 'month_last'
            ? $today->modify('first day of last month')
            : $today->modify('first day of this month');

        $monthEnd = $monthStart->modify('last day of this month');
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

            if ($bestDay === null || $recordRevenue > (float)$bestDay['revenue']) {
                $bestDay = [
                    'record_date' => $recordDate,
                    'revenue' => $recordRevenue,
                ];
            }

            if ($worstDay === null || $recordRevenue < (float)$worstDay['revenue']) {
                $worstDay = [
                    'record_date' => $recordDate,
                    'revenue' => $recordRevenue,
                ];
            }
        }

        $profit = $totalRevenue - $totalAdCost;
        $daysCount = count($records);

        return [
            'total_revenue' => $totalRevenue,
            'total_ad_cost' => $totalAdCost,
            'profit' => $profit,
            'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
            'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
            'avg_revenue_per_day' => $daysCount > 0 ? round($totalRevenue / $daysCount, 2) : null,
            'days_count' => $daysCount,
            'best_day' => $bestDay,
            'worst_day' => $worstDay,
        ];
    }

    private function buildMonthlyComparison(int $shopId, array $rangeData): array
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

        $rows = $this->recordRepository->getMonthlyTotalsByMonthRange($shopId, $previousMonth, $selectedMonth);
        $rowByMonth = [];
        foreach ($rows as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $rowByMonth[$monthKey] = $row;
            }
        }

        $selectedRevenue = (float)($rowByMonth[$selectedMonth]['total_revenue'] ?? 0);
        $selectedAdCost = (float)($rowByMonth[$selectedMonth]['total_ad_cost'] ?? 0);
        $selectedProfit = $selectedRevenue - $selectedAdCost;
        $selectedRoas = $selectedAdCost > 0 ? round($selectedRevenue / $selectedAdCost, 2) : null;

        $previousRevenue = (float)($rowByMonth[$previousMonth]['total_revenue'] ?? 0);
        $previousAdCost = (float)($rowByMonth[$previousMonth]['total_ad_cost'] ?? 0);
        $previousProfit = $previousRevenue - $previousAdCost;
        $previousRoas = $previousAdCost > 0 ? round($previousRevenue / $previousAdCost, 2) : null;

        return [
            'enabled' => true,
            'selected_month' => $selectedMonth,
            'previous_month' => $previousMonth,
            'change' => [
                'total_revenue' => $this->calculateChangePercent($selectedRevenue, $previousRevenue),
                'total_ad_cost' => $this->calculateChangePercent($selectedAdCost, $previousAdCost),
                'profit' => $this->calculateChangePercent($selectedProfit, $previousProfit),
                'roas' => $this->calculateChangePercent($selectedRoas, $previousRoas),
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

    private function buildSixMonthChart(int $shopId, string $rangeEndDate): array
    {
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $rangeEndDate);
        if (!$endDate) {
            $endDate = new DateTimeImmutable('today');
        }

        $endMonthStart = $endDate->modify('first day of this month');
        $startMonthStart = $endMonthStart->modify('-5 months');

        $startMonth = $startMonthStart->format('Y-m');
        $endMonth = $endMonthStart->format('Y-m');

        $rows = $this->recordRepository->getMonthlyTotalsByMonthRange($shopId, $startMonth, $endMonth);
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

            $monthRevenue = (float)($row['total_revenue'] ?? 0);
            $monthAdCost = (float)($row['total_ad_cost'] ?? 0);

            $months[] = $monthKey;
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

    private function buildGoalProgress(int $shopId, string $goalMonth): array
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
        ];

        if ($goal === null) {
            return $default;
        }

        $monthStart = $goalMonth . '-01';
        $monthEndObject = DateTimeImmutable::createFromFormat('Y-m-d', $monthStart);
        if (!$monthEndObject) {
            return $default;
        }

        $monthEnd = $monthEndObject->format('Y-m-t');
        $monthRecords = $this->recordRepository->getByDateRange($shopId, $monthStart, $monthEnd);
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

        $revenueReached = $progressRevenue !== null && $progressRevenue >= 100;
        $profitReached = $progressProfit !== null && $progressProfit >= 100;

        $configuredReached = [];
        if ($targetRevenue !== null) {
            $configuredReached[] = $revenueReached;
        }
        if ($targetProfit !== null) {
            $configuredReached[] = $profitReached;
        }

        $isAchieved = !empty($configuredReached) && !in_array(false, $configuredReached, true);

        return [
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
        ];
    }

    private function resolveGoalMonth(array $rangeData): string
    {
        if (($rangeData['is_monthly'] ?? false) === true && $this->isValidMonth((string)($rangeData['selected_month'] ?? ''))) {
            return (string)$rangeData['selected_month'];
        }

        return date('Y-m');
    }

    private function calculateGoalPercent(float $actual, ?float $target): ?float
    {
        if ($target === null || $target <= 0) {
            return null;
        }

        return round(($actual / $target) * 100, 1);
    }

    private function calculateChangePercent(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || abs($previous) < 0.00001) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function canAccessShop(int $userId, int $shopId): bool
    {
        return $this->shopRepository->findByIdAndUserId($shopId, $userId) !== null;
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
