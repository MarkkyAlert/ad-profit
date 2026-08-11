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
        // ⚠️⚠️ วันที่ **ไม่มีใครกรอกเลย** ไม่โผล่เป็นแถวในตาราง (query คืนเฉพาะวันที่มี record)
        // จึงไม่เคยถูกนับว่า "ไม่ครบ" → แถวรวมเขียนว่า "ครบทุกวัน"
        //
        // วัดจริง: 2 ร้านกรอกครบทั้งคู่แค่ 1–3 ส.ค. แล้วหยุด (4–7 ส.ค. ไม่มีข้อมูลของใครเลย)
        // แถวรวมบอก "ครบทุกวัน" ขณะที่แดชบอร์ดของร้านเดียวกันบนข้อมูลชุดเดียวกัน
        // ขึ้นแถบเหลืองว่า "คุณไม่ได้กรอกข้อมูลมา 4 วันแล้ว"
        //
        // ⚠️⚠️ แต่ต้องนับเฉพาะวันที่ "อยู่ในความรับผิดชอบ" จริง — คือตั้งแต่วันแรกที่มี
        // ร้านไหนเริ่มบันทึกเป็นต้นไป · เดิมนับทุกวันของเดือนที่ผ่านไปแล้วตรง ๆ
        //
        // วัดจริง: สมัครวันที่ 20 ส.ค. แล้วกรอกครบทุกวันตั้งแต่ 20–31 → ยังขึ้นคำเตือน
        // "⚠️ 19 วันที่ผ่านมาแล้วแต่ยังไม่มีร้านไหนกรอกเลย" ทั้งที่ทำถูกทุกอย่าง
        // — เป็นคำเตือนแรกที่ผู้ใช้ใหม่เห็น และมันกล่าวหาผิด
        //
        // คอลัมน์ "ร้านที่กรอก" ในตารางเดียวกันใช้กติกานี้อยู่แล้ว (`countShopsTrackedOn()`)
        // ตัวนับนี้แค่ไม่เคยเอาข้อมูลชุดเดียวกันมาใช้
        $elapsedDays = $this->daysUnderTracking($trackingSince, $startDate, $endDate);
        $summary = $this->buildSummary($dailyRows, $shopsCount, $elapsedDays);
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
     * จำนวนวันในช่วงที่ "มีคนเริ่มบันทึกแล้ว" — ใช้เป็นตัวตั้งของ `missing_days`
     *
     * ⚠️ วันก่อนที่ร้านแรกจะเริ่มบันทึก ไม่ใช่วันที่ผู้ใช้ "ลืมกรอก" — มันคือวันที่
     * เขายังไม่ได้ใช้ระบบนี้ · การนับรวมเข้าไปทำให้ผู้ใช้ใหม่ถูกเตือนตั้งแต่วันแรก
     *
     * @param array<int,string> $trackingSince วันแรกที่แต่ละร้านมีข้อมูล (Y-m-d, เรียงจากน้อยไปมาก)
     */
    private function daysUnderTracking(array $trackingSince, string $startDate, string $endDate): int
    {
        $firstTrackedDate = $trackingSince[0] ?? null;
        if ($firstTrackedDate === null) {
            return 0; // ยังไม่มีร้านไหนเริ่มบันทึกเลย — ไม่มีวันไหนให้ทวงถาม
        }

        $from = $firstTrackedDate > $startDate ? $firstTrackedDate : $startDate;
        if ($from > $endDate) {
            return 0; // ช่วงที่ดูอยู่เกิดก่อนที่ใครจะเริ่มใช้ระบบ
        }

        return (new DateTimeImmutable($from))->diff(new DateTimeImmutable($endDate))->days + 1;
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
            $profit = money_total($revenue - $adCost);
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

    private function buildSummary(array $dailyRows, int $totalShops, int $elapsedDays = 0): array
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

        // ⭐ ปัดเป็นสตางค์ก่อนใช้ต่อ — ให้ตรงกับ `SUM()` ของฐานข้อมูลที่หน้าอื่นใช้
        $totalRevenue = money_total($totalRevenue);
        $totalAdCost = money_total($totalAdCost);
        $profit = money_total($totalRevenue - $totalAdCost);
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
            'avg_revenue_per_day' => $completeDays > 0
                ? round(money_total($completeRevenue) / $completeDays, 2)
                : null,
            'avg_profit_per_day' => $completeDays > 0
                ? round(money_total($completeRevenue - $completeAdCost) / $completeDays, 2)
                : null,
            'best_day' => $bestDay,
            'worst_day' => $worstDay,
            'total_shops' => $totalShops,
            'incomplete_days' => $incompleteDays,
            // วันที่ผ่านมาแล้วในเดือนนี้ แต่ไม่มีใครกรอกเลยสักร้าน (ไม่มีแถวในตาราง)
            'missing_days' => max(0, $elapsedDays - $daysCount),
        ];
    }

    private function buildChart(array $dailyRows): array
    {
        $dates = [];
        $revenues = [];
        $adCosts = [];
        $profits = [];
        $complete = [];

        foreach ($dailyRows as $row) {
            $dates[] = (string)($row['record_date'] ?? '');
            $revenues[] = (float)($row['total_revenue'] ?? 0);
            $adCosts[] = (float)($row['total_ad_cost'] ?? 0);
            $profits[] = (float)($row['profit'] ?? 0);
            // ⚠️ วันที่บางร้านยังไม่กรอกมียอดต่ำโดยธรรมชาติ — การ์ดสรุปตัดวันพวกนี้
            // ออกจากค่าเฉลี่ย/วันดีสุด-แย่สุดแล้ว และหน้าเว็บก็เขียนกำกับว่า
            // "ยอดรวมของวันเหล่านั้นเทียบกับวันอื่นตรง ๆ ไม่ได้" แต่กราฟยังวาดเทียบ
            // ตรง ๆ โดยไม่มีเครื่องหมายอะไรเลย → ส่งธงไปให้กราฟทำเครื่องหมายได้
            $complete[] = ($row['is_complete'] ?? true) === true;
        }

        return [
            'dates' => $dates,
            'revenue' => $revenues,
            'ad_cost' => $adCosts,
            'profit' => $profits,
            'is_complete' => $complete,
        ];
    }

    private function buildShopRows(array $shopTotals, array $shopNameById): array
    {
        $rows = [];

        $totalsByShopId = [];
        foreach ($shopTotals as $row) {
            $totalsByShopId[(int)($row['shop_id'] ?? 0)] = $row;
        }

        // ⚠️⚠️ ต้องมีทุกร้าน รวมร้านที่ยังไม่กรอก — query คืนเฉพาะร้านที่มีรายการ
        //
        // วัดจริง: 3 ร้าน กรอกจริง 2 · กดสลับแท็บของหน้าเดียวกันเฉย ๆ ตารางหัวข้อ
        // "เปรียบเทียบระหว่างร้าน" แท็บรายเดือน/รายปีมี 3 แถว แต่แท็บรายวันเหลือ 2 แถว
        // ร้านที่ 3 หายไปทั้งแถว ทั้งที่เมนูเลือกร้านด้านบนยังลิสต์ครบ 3 ร้าน
        foreach ($shopNameById as $shopId => $shopName) {
            $row = $totalsByShopId[(int)$shopId] ?? null;
            $revenue = (float)($row['total_revenue'] ?? 0);
            $adCost = (float)($row['total_ad_cost'] ?? 0);
            $profit = money_total($revenue - $adCost);

            $rows[] = [
                'shop_id' => (int)$shopId,
                'shop_name' => (string)$shopName,
                'total_revenue' => $revenue,
                'total_ad_cost' => $adCost,
                'profit' => $profit,
                'roas' => $adCost > 0 ? round($revenue / $adCost, 2) : null,
                'profit_margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : null,
                // ร้านที่ไม่มีแถวใน query = ยังไม่กรอกอะไรในเดือนนี้
                'days_count' => (int)($row['days_count'] ?? 0),
            ];
        }

        // ⚠️ ใช้ตัวเทียบกลางตัวเดียวกับอีก 2 แท็บ — ร้านที่ยังไม่มีข้อมูลไปท้ายเสมอ
        usort($rows, 'compare_shop_rows_for_ranking');

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
