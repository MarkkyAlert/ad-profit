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
        $todayInput = is_string($today) ? trim($today) : '';
        $todayObject = $todayInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $todayInput)
            : false;
        if (!$todayObject || $todayObject->format('Y-m-d') !== $todayInput) {
            $todayObject = new DateTimeImmutable('today');
        }

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

        $previousYearProfit = 0.0;
        $months = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestMonth = null;
        $worstMonth = null;

        $monthsWithData = 0;

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

            $monthRow = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => $monthRevenue,
                'total_ad_cost' => $monthAdCost,
                'profit' => $monthProfit,
                'roas' => $monthAdCost > 0 ? round($monthRevenue / $monthAdCost, 2) : null,
                'profit_margin' => $monthRevenue > 0 ? round(($monthProfit / $monthRevenue) * 100, 1) : null,
                // query คืน days_count มาอยู่แล้ว — กันเทียบเดือนที่กรอก 3 วันกับเดือนที่กรอก 30 วัน
                'days_count' => (int)($totals['days_count'] ?? 0),
                'prev_year_profit' => $previousMonthProfit,
                'yoy_change_percent' => $this->calculateChangePercent($monthProfit, $previousMonthProfit),
            ];

            // จัดอันดับด้วย "กำไร" และเลือกเฉพาะเดือนที่มีข้อมูลจริง
            // (เดือนที่ยังไม่ได้กรอกมีกำไร 0 — ไม่ควรถูกยกเป็นเดือนแย่สุด)
            if ($hasRecord) {
                $monthsWithData++;

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

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'has_data' => $monthsWithData > 0,
                'last_month' => $lastMonth,
                'months' => $months,
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'profit' => $profit,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                    'best_month' => $bestMonth,
                    'worst_month' => $worstMonth,
                    // YoY เทียบ "ช่วงเดียวกัน" — ปีนี้ถึงเดือน lastMonth เทียบปีก่อนเดือน 1..lastMonth
                    'prev_year' => $year - 1,
                    'prev_year_profit' => $previousYearProfit,
                    'yoy_profit_change' => $profit - $previousYearProfit,
                    'yoy_profit_change_percent' => $this->calculateChangePercent($profit, $previousYearProfit),
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

    private function isValidYear(int $year): bool
    {
        return $year >= 2000 && $year <= 2100;
    }
}
