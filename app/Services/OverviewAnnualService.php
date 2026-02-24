<?php

declare(strict_types=1);

class OverviewAnnualService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;

    public function __construct(RecordRepository $recordRepository, ShopRepository $shopRepository)
    {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
    }

    public function buildYearlyOverview(int $userId, int $year): array
    {
        if (!$this->isValidYear($year)) {
            return [
                'success' => false,
                'error' => 'ปีที่เลือกไม่ถูกต้อง',
            ];
        }

        try {
            $shops = $this->shopRepository->listByUserId($userId);
        } catch (Throwable $exception) {
            error_log('[overview-annual] buildYearlyOverview shop list failed: ' . $exception->getMessage());
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
                    'year' => $year,
                    'shops_count' => $shopsCount,
                    'can_view' => false,
                ],
            ];
        }

        $startMonth = sprintf('%04d-01', $year);
        $endMonth = sprintf('%04d-12', $year);

        try {
            $monthlyTotals = $this->recordRepository->getMonthlyTotalsByShopIdsAndMonthRange($shopIds, $startMonth, $endMonth);
        } catch (Throwable $exception) {
            error_log('[overview-annual] buildYearlyOverview query failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลรายปีรวมร้านได้',
            ];
        }

        $monthTotalsByKey = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $monthTotalsByKey[$monthKey] = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => 0.0,
                'total_ad_cost' => 0.0,
            ];
        }

        $shopTotalsById = [];
        foreach ($shopIds as $shopId) {
            $shopTotalsById[$shopId] = [
                'shop_id' => $shopId,
                'shop_name' => $shopNameById[$shopId] ?? 'ร้านค้า',
                'total_revenue' => 0.0,
                'total_ad_cost' => 0.0,
            ];
        }

        foreach ($monthlyTotals as $row) {
            $shopId = (int)($row['shop_id'] ?? 0);
            $monthKey = (string)($row['month_key'] ?? '');

            if ($shopId <= 0 || $monthKey === '') {
                continue;
            }

            if (!isset($monthTotalsByKey[$monthKey], $shopTotalsById[$shopId])) {
                continue;
            }

            $revenue = (float)($row['total_revenue'] ?? 0);
            $adCost = (float)($row['total_ad_cost'] ?? 0);

            $monthTotalsByKey[$monthKey]['total_revenue'] += $revenue;
            $monthTotalsByKey[$monthKey]['total_ad_cost'] += $adCost;

            $shopTotalsById[$shopId]['total_revenue'] += $revenue;
            $shopTotalsById[$shopId]['total_ad_cost'] += $adCost;
        }

        $months = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $monthTotals = $monthTotalsByKey[$monthKey];

            $monthRevenue = (float)($monthTotals['total_revenue'] ?? 0);
            $monthAdCost = (float)($monthTotals['total_ad_cost'] ?? 0);
            $monthProfit = $monthRevenue - $monthAdCost;

            $months[] = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => $monthRevenue,
                'total_ad_cost' => $monthAdCost,
                'profit' => $monthProfit,
                'roas' => $monthAdCost > 0 ? round($monthRevenue / $monthAdCost, 2) : null,
                'profit_margin' => $monthRevenue > 0 ? round(($monthProfit / $monthRevenue) * 100, 1) : null,
            ];

            $totalRevenue += $monthRevenue;
            $totalAdCost += $monthAdCost;
        }

        $profit = $totalRevenue - $totalAdCost;

        $shopsRows = [];
        foreach ($shopTotalsById as $shopTotal) {
            $shopRevenue = (float)($shopTotal['total_revenue'] ?? 0);
            $shopAdCost = (float)($shopTotal['total_ad_cost'] ?? 0);
            $shopProfit = $shopRevenue - $shopAdCost;

            $shopsRows[] = [
                'shop_id' => (int)($shopTotal['shop_id'] ?? 0),
                'shop_name' => (string)($shopTotal['shop_name'] ?? 'ร้านค้า'),
                'total_revenue' => $shopRevenue,
                'total_ad_cost' => $shopAdCost,
                'profit' => $shopProfit,
                'roas' => $shopAdCost > 0 ? round($shopRevenue / $shopAdCost, 2) : null,
                'profit_margin' => $shopRevenue > 0 ? round(($shopProfit / $shopRevenue) * 100, 1) : null,
            ];
        }

        usort(
            $shopsRows,
            static function (array $left, array $right): int {
                $revenueCompare = $right['total_revenue'] <=> $left['total_revenue'];
                if ($revenueCompare !== 0) {
                    return $revenueCompare;
                }

                return ($left['shop_id'] ?? 0) <=> ($right['shop_id'] ?? 0);
            }
        );

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'shops_count' => $shopsCount,
                'can_view' => true,
                'months' => $months,
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'profit' => $profit,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                    'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
                ],
                'chart' => [
                    'months' => array_values(array_map(static fn(array $row): string => (string)($row['month_key'] ?? ''), $months)),
                    'revenue' => array_values(array_map(static fn(array $row): float => (float)($row['total_revenue'] ?? 0), $months)),
                    'ad_cost' => array_values(array_map(static fn(array $row): float => (float)($row['total_ad_cost'] ?? 0), $months)),
                    'profit' => array_values(array_map(static fn(array $row): float => (float)($row['profit'] ?? 0), $months)),
                ],
                'shops' => $shopsRows,
            ],
        ];
    }

    private function isValidYear(int $year): bool
    {
        return $year >= 2000 && $year <= 2100;
    }
}
