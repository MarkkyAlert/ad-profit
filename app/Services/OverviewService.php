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

        try {
            $shops = $this->shopRepository->listByUserId($userId);
        } catch (Throwable $exception) {
            error_log('[overview] buildOverview shop list failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดรายการร้านค้าได้',
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
            $comparisonRows = $this->buildShopComparison($shops, $startDate, $endDate);
            $totals = $this->buildTotals($comparisonRows);
            // วิเคราะห์เพิ่ม (เฉพาะมุมเดือน): สัดส่วนกำไร + เทียบเดือนก่อน
            $comparisonRows = $this->attachProfitShare($comparisonRows, $totals);
            $comparisonRows = $this->attachProfitMomentum($comparisonRows, $shops, $monthDate);
            $barChart = $this->buildBarChart($comparisonRows);
            $sixMonthTrend = $this->buildSixMonthTrend($shops, $selectedMonth);
        } catch (Throwable $exception) {
            error_log('[overview] buildOverview failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลภาพรวมได้',
            ];
        }

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
                // จำนวนวันที่มีข้อมูลจริง — กันตีความผิดเวลาแต่ละร้านกรอกไม่เท่ากัน
                // (query คืน days_count มาให้อยู่แล้ว)
                'days_count' => (int)($totals['days_count'] ?? 0),
            ];
        }

        // จัดอันดับด้วย "กำไร" ไม่ใช่รายได้ — ร้านรายได้สูงแต่ค่าแอดหนักไม่ควรอยู่บนสุด
        usort($rows, static fn(array $left, array $right): int => $right['profit'] <=> $left['profit']);

        return $rows;
    }

    /**
     * สัดส่วนกำไรของแต่ละร้านเทียบกำไรรวม
     *
     * กำไรรวม <= 0 → คืน null ทุกแถว (เปอร์เซ็นต์ของฐานติดลบ/ศูนย์ ไม่มีความหมาย)
     * ร้านที่ขาดทุนขณะที่รวมเป็นบวก → share ติดลบได้ (เป็นตัวถ่วง) และร้านอื่นอาจเกิน 100%
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $totals
     * @return array<int,array<string,mixed>>
     */
    private function attachProfitShare(array $rows, array $totals): array
    {
        $totalProfit = (float)($totals['profit'] ?? 0);
        $shareAvailable = $totalProfit > 0;

        foreach ($rows as $index => $row) {
            $rows[$index]['profit_share'] = $shareAvailable
                ? round(((float)($row['profit'] ?? 0) / $totalProfit) * 100, 1)
                : null;
        }

        return $rows;
    }

    /**
     * เทียบกำไรกับเดือนก่อนหน้า (momentum) — ทำเฉพาะมุมเดือน
     *
     * หารด้วย abs(prev) เพื่อให้เครื่องหมายสะท้อน "ดีขึ้น/แย่ลง" จริง
     * แม้เดือนก่อนจะขาดทุน (prev -100 → now -50 = ดีขึ้น +50%)
     * prev = 0 (ร้านใหม่/ไม่มีข้อมูลเดือนก่อน) → percent = null
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $shops
     * @return array<int,array<string,mixed>>
     */
    private function attachProfitMomentum(array $rows, array $shops, DateTimeImmutable $monthDate): array
    {
        $previousMonth = $monthDate->modify('-1 month');
        $previousStart = $previousMonth->format('Y-m-01');
        $previousEnd = $previousMonth->format('Y-m-t');

        $shopIds = [];
        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId > 0) {
                $shopIds[] = $shopId;
            }
        }

        $previousRows = $this->recordRepository->getTotalsByShopIdsAndDateRange($shopIds, $previousStart, $previousEnd);

        $previousProfitByShopId = [];
        foreach ($previousRows as $previousRow) {
            $shopId = (int)($previousRow['shop_id'] ?? 0);
            if ($shopId > 0) {
                $previousProfitByShopId[$shopId] = (float)($previousRow['total_revenue'] ?? 0)
                    - (float)($previousRow['total_ad_cost'] ?? 0);
            }
        }

        foreach ($rows as $index => $row) {
            $shopId = (int)($row['shop_id'] ?? 0);
            $previousProfit = $previousProfitByShopId[$shopId] ?? 0.0;
            $profit = (float)($row['profit'] ?? 0);
            $change = $profit - $previousProfit;

            $rows[$index]['prev_profit'] = $previousProfit;
            $rows[$index]['profit_change'] = $change;
            $rows[$index]['profit_change_percent'] = abs($previousProfit) > 0.00001
                ? round(($change / abs($previousProfit)) * 100, 1)
                : null;
        }

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
            $profits = [];
            foreach ($months as $month) {
                $monthRevenue = (float)($rowByMonth[$month]['total_revenue'] ?? 0);
                $monthAdCost = (float)($rowByMonth[$month]['total_ad_cost'] ?? 0);

                $revenues[] = $monthRevenue;
                // query คืน total_ad_cost มาอยู่แล้ว → คิดกำไรได้โดยไม่ต้องยิง query เพิ่ม
                $profits[] = $monthRevenue - $monthAdCost;
            }

            $series[] = [
                'shop_id' => $shopId,
                'shop_name' => $shopName,
                'revenue' => $revenues,   // คงไว้เผื่อทำ toggle ทีหลัง
                'profit' => $profits,
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
