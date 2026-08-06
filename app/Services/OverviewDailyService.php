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

    /**
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     *
     * ⚠️ เดิมคลาสนี้ **ไม่มี seam วันที่เลย** และไล่ทั้งเดือนด้วย `Y-m-t` เสมอ
     * ระบบให้ลงข้อมูลวันล่วงหน้าได้ แท็บ "รายวัน" จึงรวมวันที่ยังมาไม่ถึง
     * ขณะที่แท็บ "เดือน" ของหน้าเดียวกันตัดที่วันนี้ — กดสลับแท็บเฉย ๆ แล้วยอดรวม
     * เปลี่ยนจาก ฿8,000 เป็น ฿14,000 (วัดจริงแล้ว) และรายการวันสุดท้ายเป็นวันที่
     * ยังมาไม่ถึง
     */
    public function buildDailyOverview(int $userId, string $selectedMonth, ?string $today = null): array
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

        // ⚠️ ใช้คู่ helper เดียวกับแท็บ "เดือน" (`OverviewService`) และแดชบอร์ด
        // ไม่ใช่เขียนกติกาตัดวันขึ้นมาใหม่ — ไม่งั้นสองแท็บของหน้าเดียวกันเพี้ยนอีก
        $endDate = comparison_range_end(
            $monthDate->format('Y-m'),
            resolve_comparison_cutoff_day($monthDate->format('Y-m'), $today)
        );

        try {
            $dailyTotals = $this->recordRepository->getDailyTotalsByShopIdsAndDateRange($shopIds, $startDate, $endDate);
            $shopTotals = $this->recordRepository->getTotalsByShopIdsAndDateRange($shopIds, $startDate, $endDate);
            // วันแรกที่แต่ละร้านมีข้อมูล — ใช้ตัดสิน "ครบทุกร้าน" ของแต่ละวัน
            $trackingSince = array_values($this->recordRepository->getFirstRecordDateByShopIds($shopIds));
            sort($trackingSince);
        } catch (Throwable $exception) {
            error_log('[overview-daily] buildDailyOverview query failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลรายวันรวมร้านได้',
            ];
        }

        $dailyRows = $this->buildDailyRows($dailyTotals, $trackingSince);
        $summary = $this->buildSummary($dailyRows, $shopsCount);
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

    /**
     * @param array<int,string> $trackingSince วันแรกที่แต่ละร้านมีข้อมูล (Y-m-d, เรียงแล้ว)
     */
    private function buildDailyRows(array $dailyTotals, array $trackingSince): array
    {
        $rows = [];

        foreach ($dailyTotals as $row) {
            $revenue = (float)($row['total_revenue'] ?? 0);
            $adCost = (float)($row['total_ad_cost'] ?? 0);
            $profit = $revenue - $adCost;
            $shopsCount = (int)($row['shops_count'] ?? 0);
            $recordDate = (string)($row['record_date'] ?? '');

            $rows[] = [
                'record_date' => $recordDate,
                'total_revenue' => $revenue,
                'total_ad_cost' => $adCost,
                'profit' => $profit,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
                'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
                'shops_count' => $shopsCount,
                // ⚠️⚠️ ตัวหารที่หน้าเว็บต้องใช้ = จำนวนร้านที่ "เริ่มบันทึกแล้ว ณ วันนั้น"
                // **ไม่ใช่จำนวนร้านทั้งหมดที่มี** — ต้องเป็นตัวเดียวกับที่ `is_complete` ใช้
                //
                // เดิมคอลัมน์โชว์ `1/3 ร้าน` (ตัวหาร = ร้านทั้งหมด) โดยไม่มีสัญลักษณ์เตือน
                // เพราะ `is_complete` เป็นจริง แล้วสรุปด้านบนเขียนว่า "จาก 7 วันที่กรอกครบ
                // ทุกร้าน" — ผู้ใช้เห็น "1/3" คู่กับคำว่า "ครบ" บนจอเดียวกัน
                //
                // [เจ้าของระบบตัดสิน 2026-08-07] "ครบ" = ทุกร้านที่เริ่มบันทึกแล้ว
                // (ไม่งั้นร้านใหม่ที่เพิ่งเริ่มกรอกจะทำให้ทุกวันก่อนหน้ากลายเป็น "ไม่ครบ"
                //  ย้อนหลังทั้งหมด แล้วสถิติรายวันที่เคยเห็นจะหายไปเฉย ๆ)
                'shops_tracked' => $this->countShopsTrackedOn($trackingSince, $recordDate),
                'is_complete' => $shopsCount >= $this->countShopsTrackedOn($trackingSince, $recordDate),
            ];
        }

        return $rows;
    }

    /**
     * จำนวนร้านที่ "กำลังถูกติดตาม" ณ วันที่กำหนด = ร้านที่มีข้อมูลวันแรกไม่เกินวันนั้น
     *
     * ร้านที่ยังไม่เคยกรอกอะไรเลยจะไม่ถูกนับ — ไม่งั้นการเพิ่มร้านใหม่ทำให้ทุกวันในอดีต
     * กลายเป็น "กรอกไม่ครบ" ย้อนหลังทั้งหมด และสถิติรายวันของประวัติหายพร้อมกัน
     *
     * @param array<int,string> $trackingSince วันแรกที่มีข้อมูล (Y-m-d) เรียงจากน้อยไปมาก
     */
    private function countShopsTrackedOn(array $trackingSince, string $recordDate): int
    {
        if ($recordDate === '') {
            return count($trackingSince);
        }

        $count = 0;
        foreach ($trackingSince as $firstDate) {
            if ($firstDate > $recordDate) {
                break; // เรียงแล้ว — ที่เหลือเริ่มมีข้อมูลทีหลังทั้งหมด
            }

            $count++;
        }

        return $count;
    }

    private function buildSummary(array $dailyRows, int $totalShops): array
    {
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestDay = null;
        $worstDay = null;
        $incompleteDays = 0;
        // ยอดของเฉพาะวันที่กรอกครบ — ใช้คิดค่าเฉลี่ยต่อวันให้ไม่ถูกวันกรอกไม่ครบเจือจาง
        $completeRevenue = 0.0;
        $completeAdCost = 0.0;
        $completeDays = 0;

        foreach ($dailyRows as $row) {
            $totalRevenue += (float)($row['total_revenue'] ?? 0);
            $totalAdCost += (float)($row['total_ad_cost'] ?? 0);

            // จัดอันดับด้วยกำไร — วันรายได้สูงสุดอาจแอดหนักจนกำไรน้อย
            // (dailyRows มีเฉพาะวันที่มี record อยู่แล้ว จึงไม่ต้องกันวันว่าง)
            $dayProfit = (float)($row['profit'] ?? 0);
            $isComplete = ($row['is_complete'] ?? true) === true;

            if (!$isComplete) {
                $incompleteDays++;
                // วันที่บางร้านยังไม่กรอกมียอดรวมต่ำโดยธรรมชาติ ถ้านับด้วยจะชนะ "วันแย่สุด"
                // เกือบทุกครั้งทั้งที่ผลงานจริงอาจดี — จัดอันดับและเฉลี่ยเฉพาะวันที่กรอกครบ
                continue;
            }

            $day = [
                'record_date' => (string)($row['record_date'] ?? ''),
                'profit' => $dayProfit,
            ];

            if ($bestDay === null || $dayProfit > (float)$bestDay['profit']) {
                $bestDay = $day;
            }

            if ($worstDay === null || $dayProfit < (float)$worstDay['profit']) {
                $worstDay = $day;
            }

            $completeRevenue += (float)($row['total_revenue'] ?? 0);
            $completeAdCost += (float)($row['total_ad_cost'] ?? 0);
            $completeDays++;
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
            // เฉลี่ยต่อวันคิดจากวันที่กรอกครบเท่านั้น (ยอดรวมด้านบนยังนับทุกวันตามเดิม)
            'complete_days_count' => $completeDays,
            'avg_revenue_per_day' => $completeDays > 0 ? round($completeRevenue / $completeDays, 2) : null,
            'avg_profit_per_day' => $completeDays > 0
                ? round(($completeRevenue - $completeAdCost) / $completeDays, 2)
                : null,
            'best_day' => $bestDay,
            'worst_day' => $worstDay,
            'total_shops' => $totalShops,
            'incomplete_days' => $incompleteDays,
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
        // กำไรเท่ากันเรียงตามชื่อ — query ไม่การันตีลำดับ อันดับจึงสลับไปมาระหว่างรีเฟรชได้
        // (ที่นี่ไม่ต้องกันร้านที่ไม่มีข้อมูลเหมือน OverviewService เพราะ $shopTotals มาจาก
        //  query ที่คืนเฉพาะร้านที่มีรายการในวันนั้นอยู่แล้ว)
        usort(
            $rows,
            static fn(array $left, array $right): int => ($right['profit'] <=> $left['profit'])
                ?: strcmp((string)$left['shop_name'], (string)$right['shop_name'])
        );

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
