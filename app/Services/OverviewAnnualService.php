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

    /**
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildYearlyOverview(int $userId, int $year, ?string $today = null): array
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

        // เดือนสุดท้ายที่นับ — ปีปัจจุบันตัดที่เดือนนี้ ไม่ให้เดือนอนาคตโผล่มาเป็น ฿0
        // (แพตเทิร์นเดียวกับ AnnualService ของร้านเดี่ยว — กราฟจะได้ไม่ดิ่งลง 0)
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
        // ปีปัจจุบันตัดที่วันนี้ — daily_records เป็น actuals แถวเก่าที่ลงล่วงหน้าไว้
        // ต้องไม่โผล่ในยอดรวม (กติกาเดียวกับ `AnnualService`)
        $notAfterDate = $year === $currentYear ? $todayObject->format('Y-m-d') : null;

        try {
            $monthlyTotals = $this->recordRepository->getMonthlyTotalsByShopIdsAndMonthRange(
                $shopIds,
                $startMonth,
                $endMonth,
                $notAfterDate
            );
        } catch (Throwable $exception) {
            error_log('[overview-annual] buildYearlyOverview query failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลรายปีรวมร้านได้',
            ];
        }

        // ปีก่อน — ขอเฉพาะเดือน 1..lastMonth เพื่อให้เทียบ "ช่วงเดียวกัน"
        // (ปีนี้ถึงแค่ ส.ค. ก็ต้องเทียบ ม.ค.–ส.ค. ของปีก่อน ไม่ใช่ทั้ง 12 เดือน)
        $previousYearProfit = null;
        if ($lastMonth > 0) {
            $previousYear = $year - 1;

            // ⚠️⚠️ ต้องตัดถึง "วันเดียวกัน" ไม่ใช่แค่ "เดือนเดียวกัน" — เหตุผลเดียวกับ
            // `AnnualService` เป๊ะ ๆ (ปีนี้ถึง 7 ส.ค. ต้องเทียบกับปีก่อนถึง 7 ส.ค.)
            $previousNotAfterDate = $notAfterDate === null
                ? null
                : comparison_range_end(
                    sprintf('%04d-%02d', $previousYear, $lastMonth),
                    (int)$todayObject->format('j')
                );

            try {
                $previousTotals = $this->recordRepository->getMonthlyTotalsByShopIdsAndMonthRange(
                    $shopIds,
                    sprintf('%04d-01', $previousYear),
                    sprintf('%04d-%02d', $previousYear, $lastMonth),
                    $previousNotAfterDate
                );
            } catch (Throwable $exception) {
                error_log('[overview-annual] buildYearlyOverview previous year failed: ' . $exception->getMessage());
                $previousTotals = [];
            }

            $previousYearProfit = 0.0;
            foreach ($previousTotals as $row) {
                $previousYearProfit += (float)($row['total_revenue'] ?? 0) - (float)($row['total_ad_cost'] ?? 0);
            }
        }

        $monthTotalsByKey = [];
        for ($month = 1; $month <= $lastMonth; $month++) {
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
                'days_count' => 0,
            ];
        }

        // เดือนที่มี record จริง — ใช้กันไม่ให้ best/worst ไปเลือกเดือนที่ยังไม่ได้กรอก
        $monthsWithRecord = [];

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
            $daysCount = (int)($row['days_count'] ?? 0);

            $monthTotalsByKey[$monthKey]['total_revenue'] += $revenue;
            $monthTotalsByKey[$monthKey]['total_ad_cost'] += $adCost;

            $shopTotalsById[$shopId]['total_revenue'] += $revenue;
            $shopTotalsById[$shopId]['total_ad_cost'] += $adCost;
            $shopTotalsById[$shopId]['days_count'] += $daysCount;

            $monthsWithRecord[$monthKey] = true;
        }

        $months = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestMonth = null;
        $worstMonth = null;

        for ($month = 1; $month <= $lastMonth; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $monthTotals = $monthTotalsByKey[$monthKey];

            $monthRevenue = (float)($monthTotals['total_revenue'] ?? 0);
            $monthAdCost = (float)($monthTotals['total_ad_cost'] ?? 0);
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

            // จัดอันดับด้วยกำไร และเลือกเฉพาะเดือนที่มีข้อมูลจริง
            // (เดือนที่ยังไม่ได้กรอกมีกำไร 0 — ไม่ควรถูกยกเป็นเดือนแย่สุด)
            // ⚠️ เดือนปัจจุบันที่ยังไม่จบไม่เข้าแข่ง — เทียบด้วยยอดสะสม เดือนที่เพิ่งกรอก
            // ไม่กี่วันจึงชนะเดือนที่กรอกครบเสมอ (กติกาเดียวกับ `AnnualService`)
            $isUnfinishedCurrentMonth = $todayObject->format('Y-m') === $monthKey
                && (int)$todayObject->format('j') < (int)$todayObject->format('t');

            if (isset($monthsWithRecord[$monthKey]) && !$isUnfinishedCurrentMonth) {
                if ($bestMonth === null || $monthProfit > (float)$bestMonth['profit']) {
                    $bestMonth = $monthRow;
                }

                if ($worstMonth === null || $monthProfit < (float)$worstMonth['profit']) {
                    $worstMonth = $monthRow;
                }
            }

            $months[] = $monthRow;
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
                // กันเทียบร้านที่กรอก 3 วันกับร้านที่กรอกทั้งปีตรง ๆ
                'days_count' => (int)($shopTotal['days_count'] ?? 0),
                // กำไรรวมติดลบ/เท่าทุน → สัดส่วนไม่มีความหมาย (เทียบกับฐานที่ไม่ใช่ "ของทั้งหมด")
                'profit_share' => $profit > 0 ? round(($shopProfit / $profit) * 100, 1) : null,
            ];
        }

        usort(
            $shopsRows,
            // จัดอันดับด้วยกำไร ให้สอดคล้องกับมุมเดือน/แดชบอร์ด (เสมอกันค่อยใช้ shop_id)
            static function (array $left, array $right): int {
                $profitCompare = $right['profit'] <=> $left['profit'];
                if ($profitCompare !== 0) {
                    return $profitCompare;
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
                'last_month' => $lastMonth,
                'months' => $months,
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'profit' => $profit,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                    'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
                    'best_month' => $bestMonth,
                    'worst_month' => $worstMonth,
                    // YoY รวมทุกร้าน เทียบ "ช่วงเดียวกัน" ของปีก่อน (1..lastMonth)
                    'prev_year' => $year - 1,
                    'prev_year_profit' => $previousYearProfit,
                    'yoy_profit_change' => $previousYearProfit !== null ? $profit - $previousYearProfit : null,
                    'yoy_profit_change_percent' => $previousYearProfit !== null
                        ? $this->calculateChangePercent($profit, $previousYearProfit)
                        : null,
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
