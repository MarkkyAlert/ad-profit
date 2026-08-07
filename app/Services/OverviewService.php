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

    /**
     * @param string|null $today seam วันนี้ (Y-m-d) — จำเป็นเพราะการเทียบเดือนก่อนต้องตัดวัน
     */
    public function buildOverview(int $userId, string $selectedMonth, ?string $today = null): array
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

        // ⚠️ ตัดที่วันนี้เหมือนแดชบอร์ด — เดิมคอลัมน์กำไรนับทั้งเดือน (รวมรายการที่ลง
        // ล่วงหน้า) ขณะที่ป้าย "เทียบเดือนก่อน" ในแถวเดียวกันตัดที่วันนี้
        // ตัวเลขในแถวเดียวกันจึงบวกกันไม่ลง (กำไร ฿9,000 แต่ป้ายคิดจาก ฿4,000)
        $endDate = comparison_range_end(
            $monthDate->format('Y-m'),
            resolve_comparison_cutoff_day($monthDate->format('Y-m'), $today)
        );

        try {
            $comparisonRows = $this->buildShopComparison($shops, $startDate, $endDate);
            $totals = $this->buildTotals($comparisonRows);
            // วิเคราะห์เพิ่ม (เฉพาะมุมเดือน): สัดส่วนกำไร + เทียบเดือนก่อน
            $comparisonRows = $this->attachProfitShare($comparisonRows, $totals);
            $comparisonRows = $this->attachProfitMomentum($comparisonRows, $shops, $monthDate, $today);
            $barChart = $this->buildBarChart($comparisonRows);
            // ⚠️ ส่ง `$comparisonRows` (เรียงตามกำไรแล้ว) ไม่ใช่ `$shops` (เรียงตาม id)
            // ไม่งั้นลำดับเส้นในกราฟกับลำดับแถวในตารางบนจอเดียวกันไม่ตรงกัน
            // และสีที่แต่ละร้านได้ก็ผูกกับลำดับนี้
            $sixMonthTrend = $this->buildSixMonthTrend($comparisonRows, $selectedMonth, $today);
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
        //
        // ⚠️ ร้านที่ยังไม่มีข้อมูลเลย (days_count = 0) ต้องตกไปท้ายตารางเสมอ ไม่ว่ากำไรจะเป็น
        // เท่าไหร่ — กำไร 0.0 ของร้านที่ไม่ได้กรอกอะไร "มากกว่า" ร้านที่ขาดทุนจริงเชิงตัวเลข
        // ทำให้เดือนที่ทุกร้านขาดทุน ร้านที่ไม่ได้กรอกขึ้นอันดับ 1 พร้อมเลข ฿0 ทั้งแถว
        // ร้านที่ไม่มีข้อมูลคือ "ยังไม่รู้" ไม่ใช่ "ดีที่สุด"
        //
        // เกณฑ์รองไว้กันอันดับสลับไปมาเวลากำไรเท่ากันเป๊ะ (query ไม่การันตีลำดับ):
        // กรอกครบกว่าอยู่บน → เท่ากันอีกเรียงตามชื่อ
        usort($rows, static function (array $left, array $right): int {
            $leftHasData = ((int)$left['days_count']) > 0;
            $rightHasData = ((int)$right['days_count']) > 0;

            return [$rightHasData, $right['profit'], $right['days_count']]
                <=> [$leftHasData, $left['profit'], $left['days_count']]
                ?: strcmp((string)$left['shop_name'], (string)$right['shop_name']);
        });

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
    private function attachProfitMomentum(
        array $rows,
        array $shops,
        DateTimeImmutable $monthDate,
        ?string $today = null
    ): array {
        $selectedMonth = $monthDate->format('Y-m');
        $previousMonth = $monthDate->modify('-1 month');
        $previousStart = $previousMonth->format('Y-m-01');

        // ⭐ เดือนปัจจุบันต้องเทียบกับ "เดือนก่อนถึงวันเดียวกัน" ไม่ใช่ทั้งเดือน
        // เดิมเทียบทั้งเดือนเสมอ วันที่ 4 ของเดือนจึงขึ้น −87.1% ขณะที่แดชบอร์ดบอก 0%
        // สำหรับข้อมูลชุดเดียวกัน — helper ตัวเดียวกับที่แดชบอร์ดใช้
        $cutoffDay = resolve_comparison_cutoff_day($selectedMonth, $today);
        $previousEnd = comparison_range_end($previousMonth->format('Y-m'), $cutoffDay);

        $shopIds = [];
        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? 0);
            if ($shopId > 0) {
                $shopIds[] = $shopId;
            }
        }

        $previousProfitByShopId = $this->sumProfitByShopId($shopIds, $previousStart, $previousEnd);

        // ตารางถูกสร้างจากช่วงที่ตัดวันแล้ว จึงใช้กำไรของแถวได้ตรง ๆ
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

    /**
     * กำไรรวมของแต่ละร้านในช่วงวันที่กำหนด
     *
     * @param array<int,int> $shopIds
     * @return array<int,float>
     */
    private function sumProfitByShopId(array $shopIds, string $startDate, string $endDate): array
    {
        $totals = $this->recordRepository->getTotalsByShopIdsAndDateRange($shopIds, $startDate, $endDate);

        $profitByShopId = [];
        foreach ($totals as $row) {
            $shopId = (int)($row['shop_id'] ?? 0);
            if ($shopId > 0) {
                $profitByShopId[$shopId] = (float)($row['total_revenue'] ?? 0)
                    - (float)($row['total_ad_cost'] ?? 0);
            }
        }

        return $profitByShopId;
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
            // ⚠️⚠️ ร้านที่ยังไม่เคยกรอกต้องไม่อยู่ในกราฟ — แท่ง/เส้นที่ ฿0 อ่านว่า
            // "เท่าทุน" ซึ่งดูดีกว่าร้านที่ขาดทุนจริง · ตารางข้าง ๆ ดันร้านพวกนี้ไปท้าย
            // อยู่แล้ว แต่กราฟใต้ตารางเดียวกันยังวาดให้อยู่บนสุด (วัดจริง: เส้นร้านที่
            // ไม่เคยกรอกอยู่เหนือร้านที่ขาดทุนทั้ง 6 เดือน) · ร้านนั้นยังอยู่ในตารางอยู่
            // ผู้ใช้จึงไม่ได้ขาดข้อมูลว่ามีร้านนี้อยู่
            if ((int)($row['days_count'] ?? 0) <= 0) {
                continue;
            }

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

    /**
     * ⚠️ ต้องรับ `$today` ต่อไปด้วย — ตารางเปรียบเทียบที่อยู่บนจอเดียวกันตัดที่วันนี้
     * (`comparison_range_end()` ด้านบน) ถ้ากราฟไม่ตัด แท่งของเดือนนี้จะรวมแถวเก่าที่
     * เคยลงล่วงหน้าไว้ ตัวเลขสองอันบนจอเดียวกันจึงไม่ตรงกัน (วัดจริง ฿7,000 กับ ฿106,000)
     */
    private function buildSixMonthTrend(array $shops, string $selectedMonth, ?string $today = null): array
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
            // ⚠️ รับได้ทั้งแถวร้านดิบ (`id`) และแถวตารางเปรียบเทียบ (`shop_id`)
            // ตัวเรียกส่งแบบหลังมาเพื่อให้ลำดับเส้นตรงกับตาราง
            $shopId = (int)($shop['id'] ?? $shop['shop_id'] ?? 0);
            if ($shopId > 0) {
                $shopIds[] = $shopId;
            }
        }

        $notAfterDate = comparison_range_end(
            $endMonth,
            resolve_comparison_cutoff_day($endMonth, $today)
        );

        $totalsRows = $this->recordRepository->getMonthlyTotalsByShopIdsAndMonthRange(
            $shopIds,
            $startMonth,
            $endMonth,
            $notAfterDate
        );
        $rowByShopIdAndMonth = [];
        foreach ($totalsRows as $row) {
            $shopId = (int)($row['shop_id'] ?? 0);
            $monthKey = (string)($row['month_key'] ?? '');
            if ($shopId > 0 && $monthKey !== '') {
                $rowByShopIdAndMonth[$shopId][$monthKey] = $row;
            }
        }

        foreach ($shops as $shop) {
            $shopId = (int)($shop['id'] ?? $shop['shop_id'] ?? 0);
            if ($shopId <= 0) {
                continue;
            }

            $rowByMonth = $rowByShopIdAndMonth[$shopId] ?? [];

            // ⚠️ ร้านที่ไม่มีข้อมูลเลยตลอด 6 เดือนต้องไม่มีเส้นในกราฟ — เส้น ฿0 แบน ๆ
            // จะอยู่เหนือร้านที่ขาดทุนจริงตลอดทั้งกราฟ ซึ่งอ่านว่าเป็นร้านที่ดีที่สุด
            if ($rowByMonth === []) {
                continue;
            }

            $shopName = (string)($shop['name'] ?? $shop['shop_name'] ?? 'ร้านค้า');

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
