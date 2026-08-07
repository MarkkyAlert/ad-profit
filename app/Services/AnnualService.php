<?php

declare(strict_types=1);

class AnnualService
{
    private RecordRepository $recordRepository;
    private ShopRepository $shopRepository;
    private GoalRepository $goalRepository;

    public function __construct(
        RecordRepository $recordRepository,
        ShopRepository $shopRepository,
        GoalRepository $goalRepository
    ) {
        $this->recordRepository = $recordRepository;
        $this->shopRepository = $shopRepository;
        $this->goalRepository = $goalRepository;
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
        $notAfterDate = $year === $currentYear ? $todayObject->format('Y-m-d') : null;

        try {
            $monthlyTotals = $this->recordRepository->getMonthlyTotalsByMonthRange(
                $shopId,
                $startMonth,
                $endMonth,
                $notAfterDate
            );
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
        //
        // ⚠️⚠️ **ต้องตัดถึง "วันเดียวกัน" ด้วย ไม่ใช่แค่ "เดือนเดียวกัน"**
        // เดิมตัดแค่ระดับเดือน ปีนี้จึงได้ 1 ม.ค.–7 ส.ค. แต่ปีก่อนได้ 1 ม.ค.–31 ส.ค.
        // วัดจริง: ร้านที่ทำกำไรวันละ ฿1,000 เท่ากันเป๊ะทั้งสองปี ถูกรายงานว่า
        // **฿219,000 เทียบ ฿243,000 = ↓9.9%** และตัวเลขนี้ "ดีขึ้นเอง" ทุกวัน
        // จนกลายเป็น 0.0% เฉพาะวันสุดท้ายของเดือนเท่านั้น
        // (`comparison_range_end()` หดวันตัดให้พอดีเดือนสั้น เช่น 31 มี.ค. → ก.พ.)
        $previousTotalsByMonthKey = [];
        if ($lastMonth > 0) {
            $previousYear = $year - 1;
            $previousNotAfterDate = $notAfterDate === null
                ? null
                : comparison_range_end(
                    sprintf('%04d-%02d', $previousYear, $lastMonth),
                    (int)$todayObject->format('j')
                );

            try {
                $previousTotals = $this->recordRepository->getMonthlyTotalsByMonthRange(
                    $shopId,
                    sprintf('%04d-01', $previousYear),
                    sprintf('%04d-%02d', $previousYear, $lastMonth),
                    $previousNotAfterDate
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

        // เป้าทั้งปี — ดึงครั้งเดียวแล้ว map ตาม goal_month (ไม่ query รายเดือนในลูป)
        $goalsByMonthKey = [];
        if ($lastMonth > 0) {
            try {
                $goals = $this->goalRepository->getByShopAndMonthRange(
                    $shopId,
                    sprintf('%04d-01', $year),
                    sprintf('%04d-12', $year)
                );
            } catch (Throwable $exception) {
                error_log('[annual] buildYearlySummary goals failed: ' . $exception->getMessage());
                $goals = [];
            }

            foreach ($goals as $row) {
                $goalMonth = (string)($row['goal_month'] ?? '');
                if ($goalMonth !== '') {
                    $goalsByMonthKey[$goalMonth] = $row;
                }
            }
        }

        $goalProgress = [];
        $previousYearProfit = 0.0;
        $months = [];
        $totalRevenue = 0.0;
        $totalAdCost = 0.0;
        $bestMonth = null;
        $worstMonth = null;

        // ซีรีส์สำหรับกราฟ — index ตรงกับ $months (เดือน 1..lastMonth) ทั้งหมด
        $previousProfitSeries = [];
        $cumulativeSeries = [];
        $previousCumulativeSeries = [];
        $cumulativeProfit = 0.0;

        $monthsWithData = 0;
        $profitMonths = 0;
        $lossMonths = 0;

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

            $monthDaysCount = (int)($totals['days_count'] ?? 0);
            $cumulativeProfit += $monthProfit;

            $previousProfitSeries[] = $previousMonthProfit;
            $cumulativeSeries[] = $cumulativeProfit;
            $previousCumulativeSeries[] = $previousYearProfit;

            $monthRow = [
                'month' => $month,
                'month_key' => $monthKey,
                'total_revenue' => $monthRevenue,
                'total_ad_cost' => $monthAdCost,
                'profit' => $monthProfit,
                'roas' => $monthAdCost > 0 ? round($monthRevenue / $monthAdCost, 2) : null,
                'profit_margin' => $monthRevenue > 0 ? round(($monthProfit / $monthRevenue) * 100, 1) : null,
                // query คืน days_count มาอยู่แล้ว — กันเทียบเดือนที่กรอก 3 วันกับเดือนที่กรอก 30 วัน
                'days_count' => $monthDaysCount,
                // ปรับฐานให้เทียบกันได้จริง — เดือนที่กรอกวันเดียวอาจแรงกว่าเดือนที่กรอกครบแต่ยอดรวมสูงกว่า
                'profit_per_day' => $monthDaysCount > 0 ? round($monthProfit / $monthDaysCount, 2) : null,
                'prev_year_profit' => $previousMonthProfit,
                'yoy_change_percent' => $this->calculateChangePercent($monthProfit, $previousMonthProfit),
            ];

            // จัดอันดับด้วย "กำไร" และเลือกเฉพาะเดือนที่มีข้อมูลจริง
            // (เดือนที่ยังไม่ได้กรอกมีกำไร 0 — ไม่ควรถูกยกเป็นเดือนแย่สุด)
            // $hasRecord ⟺ days_count > 0 (query GROUP BY คืนแถวเฉพาะเดือนที่มี record → COUNT(*) ≥ 1)
            if ($hasRecord) {
                $monthsWithData++;

                // เดือนเท่าทุนพอดี (profit == 0) ไม่นับเป็นทั้งกำไรและขาดทุน
                if ($monthProfit > 0) {
                    $profitMonths++;
                } elseif ($monthProfit < 0) {
                    $lossMonths++;
                }

                // ⚠️⚠️ เดือนปัจจุบันที่ยังไม่จบ **ไม่เข้าแข่ง**
                //
                // การ์ดนี้เทียบด้วยยอดสะสม เดือนที่เพิ่งกรอกไป 3 วันจึงชนะเดือนที่กรอกครบ
                // 31 วันเสมอ · ร้านที่ทำกำไรวันละ ฿1,000 เท่ากันทุกเดือนจะเห็นว่า
                // "เดือนแย่สุด = เดือนนี้" ตลอดต้นเดือน ทั้งที่ตารางในหน้าเดียวกันแสดง
                // กำไรต่อวันของเดือนนี้เท่ากับเดือนอื่นเป๊ะ
                //
                // ยังไม่จบเดือน = ยังตัดสินไม่ได้ ไม่ใช่ "แย่" — หลักเดียวกับที่หน้ารวมร้าน
                // ดันร้านที่ยังไม่มีข้อมูลไปท้ายตาราง แทนที่จะให้ขึ้นอันดับ 1 ด้วยกำไร 0
                $isUnfinishedCurrentMonth = $month === $lastMonth
                    && $todayObject->format('Y-m') === $monthKey
                    && (int)$todayObject->format('j') < (int)$todayObject->format('t');

                if (!$isUnfinishedCurrentMonth) {
                    if ($bestMonth === null || $monthProfit > (float)$bestMonth['profit']) {
                        $bestMonth = $monthRow;
                    }

                    if ($worstMonth === null || $monthProfit < (float)$worstMonth['profit']) {
                        $worstMonth = $monthRow;
                    }
                }
            }

            // เฉพาะเดือนที่ "ตั้งเป้าไว้" เท่านั้น — เดือนไม่มีเป้าไม่ควรโผล่เป็นแถวว่าง
            // (ลูปวิ่งแค่ 1..lastMonth อยู่แล้ว → เป้าเดือนอนาคตถูก cutoff ตัดทิ้งโดยปริยาย)
            $goal = $goalsByMonthKey[$monthKey] ?? null;
            if ($goal !== null) {
                $targetRevenue = $goal['target_revenue'] !== null ? (float)$goal['target_revenue'] : null;
                $targetProfit = $goal['target_profit'] !== null ? (float)$goal['target_profit'] : null;

                // กติกาเดียวกับยอดรวมรายปีด้านบน: ปีปัจจุบันนับถึงวันนี้เท่านั้น
                // เพื่อไม่ให้แถวล่วงหน้าที่หลุดมาจากข้อมูลเก่าทำให้การ์ดเป้าหมายสวนกับแดชบอร์ด
                [$goalRevenue, $goalProfit] = $this->totalsUpTo($monthlyTotals, $shopId, $monthKey, $todayObject);

                $goalProgress[] = [
                    'month' => $month,
                    'month_key' => $monthKey,
                    'target_revenue' => $targetRevenue,
                    'target_profit' => $targetProfit,
                    'actual_revenue' => $goalRevenue,
                    'actual_profit' => $goalProfit,
                    // เป้า 0 หารไม่ได้ → null (ไม่ใช่ 0% ที่จะอ่านว่า "ยังไม่คืบ")
                    // สูตรกลางเดียวกับแดชบอร์ด — เดิมที่นี่ใช้ round ทำให้ 99.996% ขึ้นเป็น
                    // 100.0% คู่กับป้าย "ยังไม่ถึงเป้า" (แดชบอร์ดปัดลงอยู่แล้ว)
                    'revenue_progress' => GoalService::progressPercent($goalRevenue, $targetRevenue),
                    'profit_progress' => GoalService::progressPercent($goalProfit, $targetProfit),
                    'revenue_reached' => GoalService::isReached($goalRevenue, $targetRevenue),
                    'profit_reached' => GoalService::isReached($goalProfit, $targetProfit),
                ];
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
                'goal_progress' => $goalProgress,
                'summary' => [
                    'total_revenue' => $totalRevenue,
                    'total_ad_cost' => $totalAdCost,
                    'profit' => $profit,
                    'roas' => $totalAdCost > 0 ? round($totalRevenue / $totalAdCost, 2) : null,
                    'profit_margin' => $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : null,
                    // นับเฉพาะเดือนที่กรอกแล้ว — เดือนที่ยังไม่กรอกไม่ใช่ "เดือนเท่าทุน"
                    'months_with_data' => $monthsWithData,
                    'profit_months' => $profitMonths,
                    'loss_months' => $lossMonths,
                    'best_month' => $bestMonth,
                    'worst_month' => $worstMonth,
                    // YoY เทียบ "ช่วงเดียวกัน" — ปีนี้ถึงเดือน lastMonth เทียบปีก่อนเดือน 1..lastMonth
                    'prev_year' => $year - 1,
                    'prev_year_profit' => $previousYearProfit,
                    // ⚠️⚠️ "ปีก่อนไม่มีข้อมูล" กับ "ปีก่อนเท่าทุนพอดี" ต้องแยกออกจากกัน
                    //
                    // `yoy_profit_change_percent` เป็น null ทั้งสองกรณี (หารด้วยศูนย์ไม่ได้)
                    // แต่หน้าเว็บแปล null เป็นข้อความ "ไม่มีข้อมูลปีก่อน" ตายตัว
                    // → ปีก่อนบันทึกครบ 365 วันแต่กำไรรวมเป็น ฿0 พอดี ก็ขึ้นว่าไม่มีข้อมูล
                    // ทั้งที่บรรทัดถัดลงมาบนจอเดียวกันพิมพ์ "ปีก่อนช่วงเดียวกัน ฿0"
                    // ซึ่งอ่านว่าเป็นตัวเลขจริง — สองบรรทัดขัดกันเอง
                    'prev_year_has_data' => $previousTotalsByMonthKey !== [],
                    'yoy_profit_change' => $profit - $previousYearProfit,
                    'yoy_profit_change_percent' => $this->calculateChangePercent($profit, $previousYearProfit),
                    'projection' => $this->calculateYearEndProjection(
                        $months,
                        $cumulativeProfit,
                        $lastMonth,
                        $year === $currentYear,
                        $year,
                        $todayObject->format('Y-m-d')
                    ),
                ],
                'chart' => [
                    'months' => array_values(array_map(static fn(array $row): string => (string)$row['month_key'], $months)),
                    'revenue' => array_values(array_map(static fn(array $row): float => (float)$row['total_revenue'], $months)),
                    'ad_cost' => array_values(array_map(static fn(array $row): float => (float)$row['total_ad_cost'], $months)),
                    'profit' => array_values(array_map(static fn(array $row): float => (float)$row['profit'], $months)),
                    // กำไรปีก่อนเดือนเดียวกัน — index ตรงกับแกน x ปีนี้ (same-period ไม่เกิน lastMonth)
                    'prev_profit' => $previousProfitSeries,
                    'cumulative_profit' => $cumulativeSeries,
                    'prev_cumulative_profit' => $previousCumulativeSeries,
                ],
            ],
        ];
    }

    /** จำนวนเดือนล่าสุดที่ใช้เป็นฐานประมาณการ */
    private const PROJECTION_BASIS_MONTHS = 3;

    /**
     * ประมาณการกำไรสิ้นปีแบบ run-rate — pure ไม่แตะ repo
     *
     * คืนเป็น "ช่วง" ไม่ใช่เลขเดียว เพราะ run-rate ไม่รู้จักฤดูกาล/การเปิดรอบขาย
     * เลขเดียวจะถูกอ่านว่าเป็นคำสัญญา ช่วงบอกความไม่แน่นอนตามจริง
     *
     * @param array<int,array<string,mixed>> $months แถวเดือน 1..lastMonth จาก buildYearlySummary
     */
    public function calculateYearEndProjection(
        array $months,
        float $cumulativeProfit,
        int $lastMonth,
        bool $isCurrentYear,
        ?int $year = null,
        ?string $today = null
    ): array {
        // ใช้หาจำนวนวันของแต่ละเดือน (ก.พ. ต่างกันตามปีอธิกสุรทิน) — ไม่ส่งมาก็ใช้ปีปัจจุบัน
        $year = $year ?? (int)date('Y');
        // ปีที่จบไปแล้ว/ยังไม่เริ่ม ไม่ต้องเดา — มีตัวเลขจริงอยู่แล้วหรือยังไม่มีอะไรให้เดา
        if (!$isCurrentYear) {
            return ['available' => false, 'reason' => 'not_current_year'];
        }

        $monthsRemaining = 12 - $lastMonth;

        // เศษของ "เดือนปัจจุบัน" ที่ยังไม่เกิดขึ้น
        //
        // $cumulativeProfit นับเดือนนี้เท่าที่กรอกมา (ต้นเดือนอาจแค่ไม่กี่วัน) แต่
        // $monthsRemaining ถือว่าเดือนนี้ผ่านไปแล้วทั้งเดือน — วันที่เหลือจึงหายไปทั้งสองทาง
        // ทำให้ประมาณการต่ำกว่าจริงเกือบเต็มเดือนเมื่ออยู่ต้นเดือน
        [$currentMonthRemainingRatio, $currentMonthRemainingDays] =
            $this->currentMonthRemainder($year, $lastMonth, $today);

        if ($monthsRemaining <= 0 && $currentMonthRemainingRatio <= 0.0) {
            return ['available' => false, 'reason' => 'year_complete'];
        }

        // ฐาน = เฉพาะเดือนที่กรอก "มากพอจะเป็นตัวแทนของเดือนนั้น"
        //
        // เดือนที่ยังไม่กรอกเลยมีกำไร 0 ซึ่งจะลากค่าเฉลี่ยลงผิด ๆ · เดือนที่กรอกไม่ถึงครึ่ง
        // (มักเป็นเดือนปัจจุบันต้นเดือน) ก็เช่นกัน — กรอก 2 วันจาก 31 แล้วเอามาเป็นตัวคูณ
        // ทำให้ projection_low ต่ำผิดรูปและสรุปว่า "ช่วงคร่อม 0" บ่อยเกินจริง
        $filledProfits = [];
        foreach ($months as $row) {
            $month = (int)($row['month'] ?? 0);
            if ($month > $lastMonth || $month < 1) {
                continue;
            }

            $daysCount = (int)($row['days_count'] ?? 0);
            if ($daysCount <= 0) {
                continue;
            }

            $daysInMonth = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            if ($daysCount * 2 < $daysInMonth) {
                continue;
            }

            $monthProfit = (float)($row['profit'] ?? 0);

            // ⚠️⚠️ เดือนปัจจุบันยังไม่จบ ต้องเทียบเป็น "ถ้าทำแบบนี้ทั้งเดือน" ก่อน
            //
            // เดือนอื่นในฐานคือกำไร **เต็มเดือน** ถ้าเอากำไรครึ่งเดือนของเดือนนี้ไปเฉลี่ย
            // ปนกันตรง ๆ ค่าเฉลี่ยจะถูกลากลงทันทีที่เดือนนี้ผ่านครึ่งเดือน (เกณฑ์ด้านบน)
            //
            // ผลที่วัดได้: ร้านทำกำไรวันละ ฿1,000 เท่ากันทุกวัน กรอกครบทุกวัน
            //   15 ส.ค. → ประมาณการ ฿362,484 – ฿367,000
            //   16 ส.ค. → ประมาณการ ฿299,742 – ฿367,000   ← ขอบล่างหายไป ฿62,742
            // ผู้ใช้แค่บันทึกวันธรรมดาอีก 1 วัน ไม่มีอะไรแย่ลงเลย
            //
            // ⚠️⚠️ ขยายด้วย "วันที่ผ่านไปแล้ว" ไม่ใช่ "วันที่กรอก"
            //
            // สองอย่างนี้ต่างกัน: วันที่ยังมาไม่ถึงคือสิ่งที่ต้องชดเชย ส่วนวันที่ผ่านไปแล้ว
            // แต่ผู้ใช้ไม่ได้กรอกคือ "ไม่ได้กรอก" จริง ๆ — ขยายให้จะเป็นการเดาแทนผู้ใช้
            // (เช่น สิ้นเดือนแล้วกรอกไป 28 จาก 31 วัน ต้องใช้ค่าตามที่กรอกมา ไม่ใช่คูณขึ้น)
            //
            // ⚠️ เฉพาะเดือนปัจจุบัน — เดือนที่จบไปแล้วผ่านครบทุกวันอยู่แล้ว
            $daysElapsed = $daysInMonth - $currentMonthRemainingDays;
            if ($month === $lastMonth && $daysElapsed > 0 && $daysElapsed < $daysInMonth) {
                $monthProfit = $monthProfit / $daysElapsed * $daysInMonth;
            }

            $filledProfits[] = $monthProfit;
        }

        // เดือนเดียวเดาไม่ได้ว่าเป็นแนวโน้มหรือฟลุ๊ค
        $basis = array_slice($filledProfits, -self::PROJECTION_BASIS_MONTHS);
        if (count($basis) < 2) {
            return ['available' => false, 'reason' => 'insufficient_data'];
        }

        $average = array_sum($basis) / count($basis);

        // ตัวคูณจริง = เดือนเต็มที่เหลือ + เศษของเดือนนี้
        $effectiveMonths = $monthsRemaining + $currentMonthRemainingRatio;

        return [
            'available' => true,
            // ยังเป็น "เดือนเต็มที่เหลือ" ตามเดิม เพื่อให้ป้ายในหน้าเว็บอ่านแล้วตรงกับปฏิทิน
            'months_remaining' => $monthsRemaining,
            'current_month_remaining_ratio' => round($currentMonthRemainingRatio, 4),
            // จำนวนวันไว้ให้หน้าเว็บ/xlsx เขียนป้ายได้ตรงกัน ("+ อีก N วันของเดือนนี้")
            'current_month_remaining_days' => $currentMonthRemainingDays,
            'basis_month_count' => count($basis),
            'avg_recent' => round($average, 2),
            'projection_low' => round($cumulativeProfit + ($effectiveMonths * min($basis)), 2),
            'projection_mid' => round($cumulativeProfit + ($effectiveMonths * $average), 2),
            'projection_high' => round($cumulativeProfit + ($effectiveMonths * max($basis)), 2),
        ];
    }

    /**
     * ส่วนของเดือนปัจจุบันที่ยังมาไม่ถึง — คืน [สัดส่วน 0.0–1.0, จำนวนวัน]
     *
     * นับจากปฏิทิน ไม่ใช่จากจำนวนวันที่กรอก — วันที่ผ่านไปแล้วแต่ยังไม่กรอกถือเป็น
     * "ช่องว่างของข้อมูล" ไม่ใช่ "อนาคตที่ต้องเดา" · ถ้า $today ไม่ได้อยู่ในเดือน
     * ที่กำลังคิดอยู่ (เช่นเรียกด้วยปี/เดือนอื่น) คืน 0 เพราะไม่มีเศษให้บวก
     *
     * @return array{0:float,1:int}
     */
    private function currentMonthRemainder(int $year, int $lastMonth, ?string $today): array
    {
        if ($lastMonth < 1 || $lastMonth > 12) {
            return [0.0, 0];
        }

        // ใช้ resolveToday ตัวเดียวกับ buildYearlySummary — อย่าคัดลอกตรรกะแปลงวันซ้ำ
        $todayObject = $this->resolveToday($today);

        if ($todayObject->format('Y-m') !== sprintf('%04d-%02d', $year, $lastMonth)) {
            return [0.0, 0];
        }

        $daysInMonth = (int)$todayObject->format('t');
        $daysRemaining = $daysInMonth - (int)$todayObject->format('j');

        return [$daysRemaining / $daysInMonth, $daysRemaining];
    }

    /**
     * กริดกำไร 12 เดือน × 3 ปี (year-2 → year) สำหรับดูฤดูกาล
     * เทียบปีเดียวยังฟันธงไม่ได้ว่าเดือนไหน "ขายดีประจำ" — ต้องเห็นซ้ำหลายปี
     *
     * @param string|null $today รูปแบบ Y-m-d — seam สำหรับเทสต์ (ไม่ส่ง = วันนี้จริง)
     */
    public function buildMonthlyHeatmap(int $userId, int $shopId, int $year, ?string $today = null): array
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

        $years = [$year - 2, $year - 1, $year];
        $todayObject = $this->resolveToday($today);

        try {
            // ดึงครั้งเดียวคลุมทั้ง 3 ปี — reuse query เดิม ไม่เพิ่ม repo method
            //
            // ⚠️ ตัดที่วันนี้เมื่อช่วงคลุมถึงปีปัจจุบัน — ตารางรายเดือนในหน้าเดียวกันตัดอยู่แล้ว
            // ถ้ากริดไม่ตัด ช่องของเดือนนี้จะรวมแถวเก่าที่เคยลงล่วงหน้าไว้
            // (วัดจริง: ตารางบอก ฿7,000 แต่ช่องกริดเดือนเดียวกันบอก ฿106,000)
            $monthlyTotals = $this->recordRepository->getMonthlyTotalsByMonthRange(
                $shopId,
                sprintf('%04d-01', $years[0]),
                sprintf('%04d-12', $year),
                $year >= (int)$todayObject->format('Y') ? $todayObject->format('Y-m-d') : null
            );
        } catch (Throwable $exception) {
            error_log('[annual] buildMonthlyHeatmap failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'error' => 'ไม่สามารถโหลดข้อมูลฤดูกาลได้',
            ];
        }

        $totalsByMonthKey = [];
        foreach ($monthlyTotals as $row) {
            $monthKey = (string)($row['month_key'] ?? '');
            if ($monthKey !== '') {
                $totalsByMonthKey[$monthKey] = $row;
            }
        }

        // เดือนอนาคตต้องว่างเสมอ — สอดคล้องกับ cutoff ของ buildYearlySummary
        // (กันเรคอร์ดลงวันที่ล่วงหน้าโผล่มาเป็นช่องเขียวในเดือนที่ยังไม่ถึง)
        $todayObject = $this->resolveToday($today);
        $currentYear = (int)$todayObject->format('Y');
        $currentMonth = (int)$todayObject->format('n');

        $grid = [];
        foreach ($years as $gridYear) {
            $row = [];
            for ($month = 1; $month <= 12; $month++) {
                $isFuture = $gridYear > $currentYear || ($gridYear === $currentYear && $month > $currentMonth);
                $totals = $isFuture
                    ? null
                    : ($totalsByMonthKey[sprintf('%04d-%02d', $gridYear, $month)] ?? null);

                // null = ยังไม่ได้กรอก · 0.0 = กรอกแล้วแต่เท่าทุนพอดี — คนละความหมาย ห้ามยุบรวม
                $row[$month] = [
                    'month' => $month,
                    'profit' => $totals !== null
                        ? (float)$totals['total_revenue'] - (float)$totals['total_ad_cost']
                        : null,
                    'has_data' => $totals !== null,
                ];
            }

            $grid[$gridYear] = $row;
        }

        // "ฤดูกาล" คือการเทียบเดือนเดียวกันข้ามปี — มีข้อมูลปีเดียวก็ไม่มีอะไรให้เทียบ
        // ชั้นบนใช้ค่านี้ตัดสินว่าจะสร้างชีต/การ์ดหรือไม่ แทนที่จะแสดงกริดว่างให้ตีความเอง
        $yearsWithData = 0;
        foreach ($grid as $row) {
            foreach ($row as $cell) {
                if (($cell['has_data'] ?? false) === true) {
                    $yearsWithData++;
                    break;
                }
            }
        }

        return [
            'success' => true,
            'data' => [
                'years' => $years,
                'grid' => $grid,
                'years_with_data' => $yearsWithData,
                'comparable' => $yearsWithData >= 2,
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
    /**
     * ยอดของเดือนหนึ่ง "นับถึงวันนี้" — ใช้กับการ์ดเป้าหมายเท่านั้น
     *
     * เดือนที่จบไปแล้วคืนยอดเต็มเดือนตามเดิม · เดือนปัจจุบันยิง query เพิ่ม 1 ครั้ง
     * เพื่อตัดวันที่ยังมาไม่ถึงออก · เดือนอนาคตไม่มีทางเข้ามาถึงตรงนี้ (ลูปหยุดที่เดือนนี้)
     *
     * @param array<int,array<string,mixed>> $monthlyTotals แถวเต็มเดือนที่ดึงมาแล้ว
     * @param int $shopId ร้านที่กำลังดูอยู่
     * @return array{0:float,1:float} [รายได้, กำไร]
     */
    private function totalsUpTo(
        array $monthlyTotals,
        int $shopId,
        string $monthKey,
        DateTimeImmutable $todayObject
    ): array
    {
        $fullMonth = null;
        foreach ($monthlyTotals as $row) {
            if ((string)($row['month_key'] ?? '') === $monthKey) {
                $fullMonth = $row;
            }
        }

        $revenue = (float)($fullMonth['total_revenue'] ?? 0);
        $adCost = (float)($fullMonth['total_ad_cost'] ?? 0);

        if ($monthKey !== $todayObject->format('Y-m')) {
            return [$revenue, $revenue - $adCost];
        }

        try {
            $upToToday = $this->recordRepository->getMonthlyTotalsByMonthRange(
                $shopId,
                $monthKey,
                $monthKey,
                $todayObject->format('Y-m-d')
            );
        } catch (Throwable $exception) {
            error_log('[annual] totalsUpTo failed: ' . $exception->getMessage());
            return [$revenue, $revenue - $adCost];
        }

        $row = $upToToday[0] ?? null;
        $cutRevenue = (float)($row['total_revenue'] ?? 0);
        $cutAdCost = (float)($row['total_ad_cost'] ?? 0);

        return [$cutRevenue, $cutRevenue - $cutAdCost];
    }

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
