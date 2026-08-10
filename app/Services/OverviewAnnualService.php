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
        $previousTotals = [];
        $previousYearReadFailed = false;
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
                // ⚠️ อ่านปีก่อนไม่สำเร็จ ≠ ปีก่อนไม่มีข้อมูล (เหตุผลเต็มอยู่ใน AnnualService)
                error_log('[overview-annual] buildYearlyOverview previous year failed: ' . $exception->getMessage());
                $previousTotals = [];
                $previousYearReadFailed = true;
            }

            $previousYearProfit = 0.0;
            foreach ($previousTotals as $row) {
                $previousYearProfit += (float)($row['total_revenue'] ?? 0) - (float)($row['total_ad_cost'] ?? 0);
            }
            $previousYearProfit = money_total($previousYearProfit);
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
            $monthProfit = money_total($monthRevenue - $monthAdCost);

            $monthRow = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => $monthRevenue,
                'total_ad_cost' => $monthAdCost,
                'profit' => $monthProfit,
                'roas' => $monthAdCost > 0 ? round($monthRevenue / $monthAdCost, 2) : null,
                'profit_margin' => $monthRevenue > 0 ? round(($monthProfit / $monthRevenue) * 100, 1) : null,
                /* ⚠️⚠️ "เดือนนี้มีร้านไหนกรอกไหม" — แถวเดือนถูกสร้างขึ้นมาครบทุกเดือนด้วยศูนย์
                   ตั้งแต่ต้น ตัวเลขจึงแยก "ไม่มีใครกรอก" ออกจาก "กรอกแล้วเท่าทุน" ไม่ได้เลย
                   · หน้าเว็บและกราฟใช้คีย์นี้ตัดสินว่าจะพิมพ์ ฿0 หรือเว้นขีด */
                'has_record' => isset($monthsWithRecord[$monthKey]),
            ];

            // จัดอันดับด้วยกำไร และเลือกเฉพาะเดือนที่มีข้อมูลจริง
            // (เดือนที่ยังไม่ได้กรอกมีกำไร 0 — ไม่ควรถูกยกเป็นเดือนแย่สุด)
            // ⚠️ เดือนปัจจุบันที่ยังไม่จบไม่เข้าแข่ง — เทียบด้วยยอดสะสม เดือนที่เพิ่งกรอก
            // ไม่กี่วันจึงชนะเดือนที่กรอกครบเสมอ (กติกาเดียวกับ `AnnualService`)
            $isUnfinishedCurrentMonth = month_is_unfinished($monthKey, $todayObject);

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

        // ⭐ ปัดเป็นสตางค์ก่อนใช้ต่อ — ให้ตรงกับ `SUM()` ของฐานข้อมูลที่หน้าอื่นใช้
        $totalRevenue = money_total($totalRevenue);
        $totalAdCost = money_total($totalAdCost);
        $profit = money_total($totalRevenue - $totalAdCost);

        $shopsRows = [];
        foreach ($shopTotalsById as $shopTotal) {
            $shopRevenue = (float)($shopTotal['total_revenue'] ?? 0);
            $shopAdCost = (float)($shopTotal['total_ad_cost'] ?? 0);
            $shopProfit = money_total($shopRevenue - $shopAdCost);

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
                // สัดส่วนถูกเติมทีหลังด้วย `distribute_profit_share()` เพื่อให้รวมได้ 100.00 พอดี
                'profit_share' => null,
            ];
        }

        /* ⚠️ กติกาเดียวกับมุมเดือน — ปัดทีละร้านแล้วปล่อยไว้ไม่มีวันรวมได้ 100
           (วัดจริง: 3 ร้านกำไรเท่ากันได้ 99.99) · ร้านที่ยังไม่เคยกรอกได้ null ไม่ใช่ 0 */
        $annualShares = distribute_profit_share(
            array_map(
                /* ⚠️ ไม่กรองร้านที่ยังไม่เคยกรอกออกตรงนี้โดยตั้งใจ — ชั้นแสดงผลเป็นเจ้าของ
                   กติกานั้นอยู่แล้ว (`overview.php` และชีตเทียบร้านของ xlsx เว้นทั้งแถว
                   เมื่อ `days_count = 0`) · ร้านที่ยังไม่กรอกมีกำไร 0 จึงกินส่วนแบ่ง 0%
                   ผลรวมของแถวที่แสดงจริงยังเป็น 100.00 พอดี
                   ⚠️ เคยลองกรองที่ชั้นนี้แล้วพัง — service เติมคีย์ `days_count` ให้ทุกแถว
                      เสมอ (ค่าปริยาย 0) การกรองจึงกลืนแถวที่มีข้อมูลจริงของผู้เรียกที่ stub มา */
                static fn(array $row): ?float => (float)($row['profit'] ?? 0),
                $shopsRows
            ),
            $profit
        );
        foreach ($shopsRows as $position => $row) {
            $shopsRows[$position]['profit_share'] = $annualShares[$position] ?? null;
        }

        // ⚠️⚠️ ร้านที่ยังไม่มีข้อมูลต้องไปท้ายตารางเสมอ — กติกาเดียวกับมุมเดือน
        // (`OverviewService::buildShopComparison`) แต่เดิมบังคับใช้แค่ที่นั่น
        //
        // กำไร `0.00` ของร้านที่ไม่ได้กรอก "มากกว่า" ร้านที่ขาดทุนจริง มันจึงขึ้นอันดับ 1
        // วัดจริง (ร้าน A/B กรอกครบทั้งปีแต่ขาดทุน · ร้าน C ไม่เคยกรอกเลย):
        //   อันดับ 1  ร้าน C (ไม่เคยกรอก)  ฿0        กรอก — วัน
        //   อันดับ 2  ร้าน A               ฿-21,900  กรอก 219 วัน
        //   อันดับ 3  ร้าน B               ฿-65,700  กรอก 219 วัน
        // ขณะที่แท็บ "รายเดือน" ของหน้าเดียวกัน ข้อมูลชุดเดียวกัน ตอบตรงกันข้าม
        // และไฟล์ Excel ชีต "เทียบร้าน" ใช้ลำดับนี้จึงผิดตามไปด้วย
        //
        // ร้านที่ไม่มีข้อมูลแปลว่า "ยังไม่รู้" ไม่ใช่ "ดีที่สุด"
        usort($shopsRows, 'compare_shop_rows_for_ranking');

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
                    // ⚠️ "ปีก่อนไม่มีข้อมูล" ≠ "ปีก่อนเท่าทุนพอดี" — เหตุผลเต็มอยู่ใน AnnualService
                    'prev_year_has_data' => $previousTotals !== [],
                    'prev_year_unavailable' => $previousYearReadFailed,
                    'yoy_profit_change' => $previousYearProfit !== null ? $profit - $previousYearProfit : null,
                    /* ⚠️⚠️⚠️ **ปีนี้ไม่มี record เลย = เทียบไม่ได้ ไม่ใช่ "ตก 100%"**
                       · `change_percent(0, ปีก่อน)` ให้ −100 ซึ่งอ่านว่า "ยอดหายไปหมด"
                         ทั้งที่ความจริงคือ "ยังไม่ได้เริ่มบันทึก"
                       · วัดจริง: ปีก่อนกำไร ฿4,000 ปีนี้ยังไม่กรอก → หน้าจอขึ้น
                         "กำไรรวม ↓ 100.0% (-฿4,000)" คู่กับแถบ "ปีนี้ยังไม่มีข้อมูล" บนจอเดียวกัน
                       ⚠️ **ปีที่กรอกแล้วและเท่าทุนจริง ยังต้องได้ −100% ตามปกติ** —
                          เกณฑ์คือ "มี record ไหม" ไม่ใช่ "กำไรเป็นศูนย์ไหม" */
                    'yoy_profit_change_percent' => ($previousYearProfit !== null && $monthsWithRecord !== [])
                        ? change_percent($profit, $previousYearProfit)
                        : null,
                    // ⚠️ เหตุผลของ null — ดูคำอธิบายเต็มใน `AnnualService`
                    'current_year_has_data' => $monthsWithRecord !== [],
                ],
                'chart' => [
                    'months' => array_values(array_map(static fn(array $row): string => (string)($row['month_key'] ?? ''), $months)),
                    /* ⚠️⚠️⚠️ เดือนที่ยังไม่มีร้านไหนกรอกเลยต้องส่ง **null** ไม่ใช่ 0
                       กติกานี้ลงให้กราฟของแดชบอร์ด · หน้ารวมร้านมุมเดือน · หน้ารายปีไปแล้ว
                       — **มุมรายปีของหน้ารวมร้านเป็นที่สุดท้ายที่ตกสำรวจ**
                       · ส่ง 0 = กราฟดิ่งลงศูนย์ตั้งแต่เดือนที่ยังไม่ถึง อ่านว่า "ยอดตก"
                       ⚠️ เดือนที่กรอกแล้วเท่าทุนพอดีต้องยังเป็น 0.0 (เกณฑ์คือมี record ไหม
                          ไม่ใช่ยอดเงินเป็นเท่าไหร่) */
                    'revenue' => array_values(array_map(
                        static fn(array $row): ?float => ($row['has_record'] ?? true)
                            ? (float)($row['total_revenue'] ?? 0)
                            : null,
                        $months
                    )),
                    'ad_cost' => array_values(array_map(
                        static fn(array $row): ?float => ($row['has_record'] ?? true)
                            ? (float)($row['total_ad_cost'] ?? 0)
                            : null,
                        $months
                    )),
                    'profit' => array_values(array_map(
                        static fn(array $row): ?float => ($row['has_record'] ?? true)
                            ? (float)($row['profit'] ?? 0)
                            : null,
                        $months
                    )),
                ],
                'shops' => $shopsRows,
            ],
        ];
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
