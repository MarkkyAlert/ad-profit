<?php

declare(strict_types=1);

class OverviewDailyService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;

    public function __construct(RecordRepository $recordRepository, ShopRepository $shopRepository)
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
    }

    public function buildDailyOverview(int $userId, string $selectedMonth): array
    {
        if (!$this->isValidMonth($selectedMonth)) {
            return [
                'success' => false,
                'error' => 'รูปแบบเดือนต้องเป็น YYYY-MM',
            ];
        }

        try {
            $shops = $this->shopRepository->listByUserId($userId);
        } catch (Throwable $exception) {
            error_log('[overview-daily] buildDailyOverview shop list failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดรายการร้านค้าได้',
            ];
        }

        $shopIds = [];
        $shopNameById = [];
        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }

            $shopIds[] = $shopId;
            $shopNameById[$shopId] = (string)($shop['name'] ?? 'ร้านค้า');
        }

        $shopsCount = count($shopIds);
        if ($shopsCount < 2) {
            return [
                'success' => true,
                'data' => [
                    'selected_month' => $selectedMonth,
                    'shops_count' => $shopsCount,
                    'can_view' => false,
                ],
            ];
        }

        $monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $selectedMonth . '-01');
        if (!$monthDate) {
            return [
                'success' => false,
                'error' => 'เดือนที่เลือกไม่ถูกต้อง',
            ];
        }

        $startDate = $monthDate->format('Y-m-01');
        $endDate = $monthDate->format('Y-m-t');

        try {
            $dailyTotals = $this->recordRepository->getDailyTotalsByShopIdsAndDateRange($shopIds, $startDate, $endDate);
            $shopTotals = $this->recordRepository->getTotalsByShopIdsAndDateRange($shopIds, $startDate, $endDate);
        } catch (Throwable $exception) {
            error_log('[overview-daily] buildDailyOverview query failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลรายวันรวมร้านได้',
            ];
        }

        $dailyRows = $this->buildDailyRows($dailyTotals);
        $summary = $this->buildSummary($dailyRows);
        $chart = $this->buildChart($dailyRows);
        $shopRows = $this->buildShopRows($shopTotals, $shopNameById);

        return [
            'success' => true,
            'data' => [
                'selected_month' => $selectedMonth,
                'shops_count' => $shopsCount,
                'can_view' => true,
                'days' => $dailyRows,
                'summary' => $summary,
                'chart' => $chart,
                'shops' => $shopRows,
            ],
        ];
    }

    private function buildDailyRows(array $dailyTotals): array
    {
        $rows = [];

        foreach ($dailyTotals as $row) {
            $revenue = (float)($row['total_revenue'] ?? 0);
            $adCost = (float)($row['total_ad_cost'] ?? 0);
            $profit = $revenue - $adCost;

            $rows[] = [
                'record_date' => (string)($row['record_date'] ?? ''),
                'total_revenue' => $revenue,
                'total_ad_cost' => $adCost,
                'profit' => $profit,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
                'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
                'shops_count' => (int)($row['shops_count'] ?? 0),
            ];
        }

        return $rows;
    }

    private function buildSummary(array $dailyRows): array
    {
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;

        foreach ($dailyRows as $row) {
            $totalRevenue += (float)($row['total_revenue'] ?? 0);
            $totalAdCost += (float)($row['total_ad_cost'] ?? 0);
        }

        $profit = $totalRevenue - $totalAdCost;
        $daysCount = count($dailyRows);

        return [
            'total_revenue' => $totalRevenue,
            'total_ad_cost' => $totalAdCost,
            'profit' => $profit,
            'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
            'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
            'days_count' => $daysCount,
            'avg_revenue_per_day' => $daysCount > 0 ? round($totalRevenue / $daysCount, 2) : null,
        ];
    }

    private function buildChart(array $dailyRows): array
    {
        $dates = [];
        $revenues = [];
        $adCosts = [];
        $profits = [];

        foreach ($dailyRows as $row) {
            $dates[] = (string)($row['record_date'] ?? '');
            $revenues[] = (float)($row['total_revenue'] ?? 0);
            $adCosts[] = (float)($row['total_ad_cost'] ?? 0);
            $profits[] = (float)($row['profit'] ?? 0);
        }

        return [
            'dates' => $dates,
            'revenue' => $revenues,
            'ad_cost' => $adCosts,
            'profit' => $profits,
        ];
    }

    private function buildShopRows(array $shopTotals, array $shopNameById): array
    {
        $rows = [];

        foreach ($shopTotals as $row) {
            $shopId = (int)($row['shop_id'] ?? 0);
            $revenue = (float)($row['total_revenue'] ?? 0);
            $adCost = (float)($row['total_ad_cost'] ?? 0);
            $profit = $revenue - $adCost;

            $rows[] = [
                'shop_id' => $shopId,
                'shop_name' => $shopNameById[$shopId] ?? 'ร้านค้า',
                'total_revenue' => $revenue,
                'total_ad_cost' => $adCost,
                'profit' => $profit,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
                'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
            ];
        }

        // จัดอันดับด้วยกำไร ให้สอดคล้องกับมุมเดือน/แดชบอร์ด
        usort($rows, static fn(array $left, array $right): int => $right['profit'] <=> $left['profit']);

        return $rows;
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
