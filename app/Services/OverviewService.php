<?php

declare(strict_types=1);

class OverviewService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;

    public function __construct(RecordRepository $recordRepository, ShopRepository $shopRepository)
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
    }

    public function buildOverview(int $userId, string $selectedMonth): array
    {
        if (!$this->isValidMonth($selectedMonth)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        $shops = $this->shopRepository->listByUserId($userId);
        $monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01');

        if (!$monthDate) {
            return [
                'success' => false,
                'error' => 'เดือนที่เลือกไม่ถูกต้อง',
            ];
        }

        $startDate = $monthDate->format('Y-m-01');
        $endDate = $monthDate->format('Y-m-t');

        $comparisonRows = $this->buildShopComparison($shops, $startDate, $endDate);
        $totals = $this->buildTotals($comparisonRows);
        $barChart = $this->buildBarChart($comparisonRows);
        $sixMonthTrend = $this->buildSixMonthTrend($shops, $selectedMonth);

        return [
            'success' => true,
            'data' => [
                'selected_month' => $selectedMonth,
                'shops_count' => count($shops),
                'can_view' => count($shops) >= 2,
                'comparison' => [
                    'rows' => $comparisonRows,
                    'totals' => $totals,
                ],
                'charts' => [
                    'bar' => $barChart,
                    'trend' => $sixMonthTrend,
                ],
            ],
        ];
    }

    public function buildShopComparison(array $shops, string $startDate, string $endDate): array
    {
        $rows = [];

        $shopIds = [];
        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId > 0) {
                $shopIds[] = $shopId;
            }
        }

        $totalsRows = $this->recordRepository->getTotalsByShopIdsAndDateRange($shopIds, $startDate, $endDate);
        $totalsByShopId = [];
        foreach ($totalsRows as $totalRow) {
            $shopId = (int)($totalRow['shop_id'] ?? 0);
            if ($shopId > 0) {
                $totalsByShopId[$shopId] = $totalRow;
            }
        }

        foreach ($shops as $shop) {
            $shopId = (int)$shop['id'];
            $totals = $totalsByShopId[$shopId] ?? null;
            $totalRevenue = (float)($totals['total_revenue'] ?? 0);
            $totalAdCost = (float)($totals['total_ad_cost'] ?? 0);

            $profit = $totalRevenue - $totalAdCost;
            $rows[] = [
                'shop_id' => $shopId,
                'shop_name' => (string)$shop['name'],
                'total_revenue' => $totalRevenue,
                'total_ad_cost' => $totalAdCost,
                'profit' => $profit,
                'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
            ];
        }

        usort($rows, static fn(array $left, array $right): int => $right['total_revenue'] <=> $left['total_revenue']);

        return $rows;
    }

    private function buildTotals(array $rows): array
    {
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;

        foreach ($rows as $row) {
            $totalRevenue += (float)($row['total_revenue'] ?? 0);
            $totalAdCost += (float)($row['total_ad_cost'] ?? 0);
        }

        $profit = $totalRevenue - $totalAdCost;

        return [
            'total_revenue' => $totalRevenue,
            'total_ad_cost' => $totalAdCost,
            'profit' => $profit,
            'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
            'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
        ];
    }

    private function buildBarChart(array $rows): array
    {
        $labels = [];
        $revenues = [];
        $adCosts = [];
        $profits = [];

        foreach ($rows as $row) {
            $revenue = (float)($row['total_revenue'] ?? 0);
            $adCost = (float)($row['total_ad_cost'] ?? 0);

            $labels[] = (string)($row['shop_name'] ?? 'ร้านค้า');
            $revenues[] = $revenue;
            $adCosts[] = $adCost;
            $profits[] = $revenue - $adCost;
        }

        return [
            'labels' => $labels,
            'revenue' => $revenues,
            'ad_cost' => $adCosts,
            'profit' => $profits,
        ];
    }

    private function buildSixMonthTrend(array $shops, string $selectedMonth): array
    {
        $endMonthDate = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01');
        if (!$endMonthDate) {
            $endMonthDate = new DateTimeImmutable('first day of this month');
        }

        $startMonthDate = $endMonthDate->modify('-5 months');
        $startMonth = $startMonthDate->format('Y-m');
        $endMonth = $endMonthDate->format('Y-m');

        $months = [];
        for ($index = 0; $index < 6; $index++) {
            $months[] = $startMonthDate->modify('+' . $index . ' months')->format('Y-m');
        }

        $series = [];

        $shopIds = [];
        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId > 0) {
                $shopIds[] = $shopId;
            }
        }

        $totalsRows = $this->recordRepository->getMonthlyTotalsByShopIdsAndMonthRange($shopIds, $startMonth, $endMonth);
        $rowByShopIdAndMonth = [];
        foreach ($totalsRows as $row) {
            $shopId = (int)($row['shop_id'] ?? 0);
            $monthKey = (string)($row['month_key'] ?? '');
            if ($shopId > 0 && $monthKey !== '') {
                $rowByShopIdAndMonth[$shopId][$monthKey] = $row;
            }
        }

        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }

            $shopName = (string)($shop['name'] ?? 'ร้านค้า');
            $rowByMonth = $rowByShopIdAndMonth[$shopId] ?? [];

            $revenues = [];
            foreach ($months as $month) {
                $revenues[] = (float)($rowByMonth[$month]['total_revenue'] ?? 0);
            }

            $series[] = [
                'shop_id' => $shopId,
                'shop_name' => $shopName,
                'revenue' => $revenues,
            ];
        }

        return [
            'months' => $months,
            'series' => $series,
        ];
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
