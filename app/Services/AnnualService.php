<?php

declare(strict_types=1);

class AnnualService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;

    public function __construct(RecordRepository $recordRepository, ShopRepository $shopRepository)
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
    }

    public function buildYearlySummary(int $userId, int $shopId, int $year): array
    {
        if (!$this->canAccessShop($userId, $shopId)) {
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

        $startMonth = sprintf('%04d-01', $year);
        $endMonth = sprintf('%04d-12', $year);
        $monthlyTotals = $this->recordRepository->getMonthlyTotalsByMonthRange($shopId, $startMonth, $endMonth);

        $totalsByMonthKey = [];
        foreach ($monthlyTotals as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $totalsByMonthKey[$monthKey] = $row;
            }
        }

        $months = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestMonth = null;
        $worstMonth = null;

        for ($month = 1; $month <= 12; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $totals = $totalsByMonthKey[$monthKey] ?? null;

            $monthRevenue = (float)($totals['total_revenue'] ?? 0);
            $monthAdCost = (float)($totals['total_ad_cost'] ?? 0);
            $monthProfit = $monthRevenue - $monthAdCost;

            $monthRow = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => $monthRevenue,
                'total_ad_cost' => $monthAdCost,
                'profit' => $monthProfit,
                'roas' => $monthAdCost > 0 ? round($monthRevenue / $monthAdCost, 2) : null,
                'profit_margin' => $monthRevenue > 0 ? round(($monthProfit / $monthRevenue) * 100, 1) : null,
            ];

            if ($bestMonth === null || $monthRevenue > (float)$bestMonth['total_revenue']) {
                $bestMonth = $monthRow;
            }

            if ($worstMonth === null || $monthRevenue < (float)$worstMonth['total_revenue']) {
                $worstMonth = $monthRow;
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
                'months' => $months,
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'profit' => $profit,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                    'best_month' => $bestMonth,
                    'worst_month' => $worstMonth,
                ],
                'chart' => [
                    'months' => array_values(array_map(static fn(array $row): string => (string)$row['month_key'], $months)),
                    'revenue' => array_values(array_map(static fn(array $row): float => (float)$row['total_revenue'], $months)),
                    'ad_cost' => array_values(array_map(static fn(array $row): float => (float)$row['total_ad_cost'], $months)),
                    'profit' => array_values(array_map(static fn(array $row): float => (float)$row['profit'], $months)),
                ],
            ],
        ];
    }

    private function canAccessShop(int $userId, int $shopId): bool
    {
        return $this->shopRepository->findByIdAndUserId($shopId, $userId) !== null;
    }

    private function isValidYear(int $year): bool
    {
        return $year >= 2000 && $year <= 2100;
    }
}
