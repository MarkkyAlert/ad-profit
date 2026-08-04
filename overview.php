<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);

$viewRaw = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'month';
$view = match ($viewRaw) {
    'day' => 'day',
    'year' => 'year',
    default => 'month',
};

// ไม่รับเดือนอนาคต — helper ดึงกลับมาเป็นเดือนปัจจุบันให้
$selectedMonth = resolve_calendar_month($_GET['month'] ?? null);

// ไม่ระบุ ?year → ใช้ปีของเดือนที่กำลังดูอยู่
$selectedYear = resolve_calendar_year($_GET['year'] ?? null, substr($selectedMonth, 0, 4));
$currentYear = (int)date('Y');

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$shopCount = $shopRepository->countByUserId($userId);

if ($shopCount < 2) {
    $view = 'month';
}

$availableYears = [];
for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
    $availableYears[] = $year;
}

if (!in_array($selectedYear, $availableYears, true)) {
    $availableYears[] = $selectedYear;
    rsort($availableYears);
}

$activeError = null;
$canView = false;

// Monthly view variables
$overviewError = null;
$comparisonRows = [];
$totals = [];
$hasOverviewData = false;
$barPayload = ['labels' => [], 'revenue' => [], 'ad_cost' => [], 'profit' => []];
$trendPayload = ['labels' => [], 'months' => [], 'series' => []];

// Yearly view variables
$yearlyError = null;
$yearlyMonths = [];
$yearlySummary = [];
$yearlyShopRows = [];
$hasYearlyData = false;
$yearChartPayload = ['labels' => [], 'revenue' => [], 'ad_cost' => [], 'profit' => []];

// Daily view variables
$dailyError = null;
$dailyRows = [];
$dailySummary = [];
$dailyShopRows = [];
$hasDailyData = false;
$dailyChartPayload = ['labels' => [], 'revenue' => [], 'ad_cost' => [], 'profit' => []];

$thaiMonths = [
    1 => 'ม.ค.',
    2 => 'ก.พ.',
    3 => 'มี.ค.',
    4 => 'เม.ย.',
    5 => 'พ.ค.',
    6 => 'มิ.ย.',
    7 => 'ก.ค.',
    8 => 'ส.ค.',
    9 => 'ก.ย.',
    10 => 'ต.ค.',
    11 => 'พ.ย.',
    12 => 'ธ.ค.',
];

$monthLabel = static function (array $row) use ($thaiMonths): string {
    $monthNumber = (int)($row['month'] ?? 0);
    return $thaiMonths[$monthNumber] ?? ('เดือน ' . $monthNumber);
};

if ($view === 'day') {
    $overviewDailyService = new OverviewDailyService($recordRepository, $shopRepository);
    $dailyResult = $overviewDailyService->buildDailyOverview($userId, $selectedMonth);

    $dailyData = [
        'selected_month' => $selectedMonth,
        'shops_count' => 0,
        'can_view' => false,
        'days' => [],
        'summary' => [
            'total_revenue' => 0.0,
            'total_ad_cost' => 0.0,
            'profit' => 0.0,
            'roas' => null,
            'profit_margin' => null,
            'days_count' => 0,
            'avg_revenue_per_day' => null,
            'avg_profit_per_day' => null,
            'best_day' => null,
            'worst_day' => null,
            'total_shops' => 0,
            'incomplete_days' => 0,
        ],
        'chart' => [
            'dates' => [],
            'revenue' => [],
            'ad_cost' => [],
            'profit' => [],
        ],
        'shops' => [],
    ];

    if (($dailyResult['success'] ?? false) === true) {
        $dailyData = array_replace_recursive($dailyData, (array)($dailyResult['data'] ?? []));
    } else {
        $dailyError = (string)($dailyResult['error'] ?? 'ไม่สามารถโหลดข้อมูลรายวันรวมร้านได้');
    }

    $activeError = $dailyError;
    $canView = (bool)($dailyData['can_view'] ?? false);
    $dailyRows = array_values((array)($dailyData['days'] ?? []));
    $dailySummary = (array)($dailyData['summary'] ?? []);
    $dailyShopRows = array_values((array)($dailyData['shops'] ?? []));

    $dailyTotalRevenue = (float)($dailySummary['total_revenue'] ?? 0);
    $dailyTotalAdCost = (float)($dailySummary['total_ad_cost'] ?? 0);
    $hasDailyData = abs($dailyTotalRevenue) > 0.00001 || abs($dailyTotalAdCost) > 0.00001;

    $chartRaw = (array)($dailyData['chart'] ?? []);
    $chartDates = array_values((array)($chartRaw['dates'] ?? []));
    $dailyChartPayload = [
        'labels' => array_map(static function (string $date): string {
            $day = (int)substr($date, 8, 2);
            return (string)$day;
        }, $chartDates),
        'revenue' => array_values(array_map(static fn($v): float => (float)$v, (array)($chartRaw['revenue'] ?? []))),
        'ad_cost' => array_values(array_map(static fn($v): float => (float)$v, (array)($chartRaw['ad_cost'] ?? []))),
        'profit' => array_values(array_map(static fn($v): float => (float)$v, (array)($chartRaw['profit'] ?? []))),
    ];
} elseif ($view === 'year') {
    $overviewAnnualService = new OverviewAnnualService($recordRepository, $shopRepository);
    $yearlyResult = $overviewAnnualService->buildYearlyOverview($userId, $selectedYear);

    $yearlyData = [
        'year' => $selectedYear,
        'shops_count' => 0,
        'can_view' => false,
        'months' => [],
        'summary' => [
            'total_revenue' => 0.0,
            'total_ad_cost' => 0.0,
            'profit' => 0.0,
            'roas' => null,
            'profit_margin' => null,
        ],
        'chart' => [
            'months' => [],
            'revenue' => [],
            'ad_cost' => [],
            'profit' => [],
        ],
        'shops' => [],
    ];

    if (($yearlyResult['success'] ?? false) === true) {
        $yearlyData = array_replace_recursive($yearlyData, (array)($yearlyResult['data'] ?? []));
    } else {
        $yearlyError = (string)($yearlyResult['error'] ?? 'ไม่สามารถโหลดข้อมูลรายปีรวมร้านได้');
    }

    $activeError = $yearlyError;
    $canView = (bool)($yearlyData['can_view'] ?? false);
    $yearlyMonths = array_values((array)($yearlyData['months'] ?? []));
    $yearlySummary = (array)($yearlyData['summary'] ?? []);
    $yearlyShopRows = array_values((array)($yearlyData['shops'] ?? []));

    $yearTotalRevenue = (float)($yearlySummary['total_revenue'] ?? 0);
    $yearTotalAdCost = (float)($yearlySummary['total_ad_cost'] ?? 0);
    $hasYearlyData = abs($yearTotalRevenue) > 0.00001 || abs($yearTotalAdCost) > 0.00001;

    // แถวรวม: สัดส่วนบวกกันได้เพราะฐานเดียวกัน (null ทุกแถวเมื่อกำไรรวม ≤ 0)
    $yearlyDaysTotal = array_sum(array_map(
        static fn(array $row): int => (int)($row['days_count'] ?? 0),
        $yearlyShopRows
    ));
    $yearlyShareTotal = null;
    foreach ($yearlyShopRows as $shopRow) {
        if (isset($shopRow['profit_share']) && $shopRow['profit_share'] !== null) {
            $yearlyShareTotal = round((float)($yearlyShareTotal ?? 0) + (float)$shopRow['profit_share'], 1);
        }
    }

    $yearlyBestMonth = is_array($yearlySummary['best_month'] ?? null) ? (array)$yearlySummary['best_month'] : null;
    $yearlyWorstMonth = is_array($yearlySummary['worst_month'] ?? null) ? (array)$yearlySummary['worst_month'] : null;

    // YoY รวมทุกร้าน — service เทียบช่วงเดียวกันของปีก่อนให้แล้ว
    $yearlyPrevYear = (int)($yearlySummary['prev_year'] ?? ($selectedYear - 1));
    $yearlyPrevProfit = isset($yearlySummary['prev_year_profit']) && $yearlySummary['prev_year_profit'] !== null
        ? (float)$yearlySummary['prev_year_profit']
        : null;
    $yearlyYoyChange = isset($yearlySummary['yoy_profit_change']) && $yearlySummary['yoy_profit_change'] !== null
        ? (float)$yearlySummary['yoy_profit_change']
        : null;
    $yearlyYoyPercent = isset($yearlySummary['yoy_profit_change_percent']) && $yearlySummary['yoy_profit_change_percent'] !== null
        ? (float)$yearlySummary['yoy_profit_change_percent']
        : null;

    $yearlyYoyText = static function (?float $percent): string {
        if ($percent === null) {
            return '—';
        }

        $arrow = $percent > 0 ? '↑' : ($percent < 0 ? '↓' : '');
        return $arrow . number_format(abs($percent), 1) . '%';
    };

    $yearlyYoyTone = static function (?float $percent): string {
        if ($percent === null || abs($percent) < 0.00001) {
            return 'text-slate-400';
        }

        return $percent > 0 ? 'text-green-400' : 'text-red-400';
    };

    $chartRaw = (array)($yearlyData['chart'] ?? []);
    $chartLabels = array_map(
        static function (string $monthKey) use ($thaiMonths): string {
            $monthNumber = (int)substr($monthKey, 5, 2);
            return $thaiMonths[$monthNumber] ?? $monthKey;
        },
        array_values((array)($chartRaw['months'] ?? []))
    );

    $yearChartPayload = [
        'labels' => $chartLabels,
        'revenue' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['revenue'] ?? []))),
        'ad_cost' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['ad_cost'] ?? []))),
        'profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['profit'] ?? []))),
    ];
} else {
    $overviewService = new OverviewService($recordRepository, $shopRepository);
    $overviewResult = $overviewService->buildOverview($userId, $selectedMonth);

    $overviewData = [
        'selected_month' => $selectedMonth,
        'shops_count' => 0,
        'can_view' => false,
        'comparison' => [
            'rows' => [],
            'totals' => [
                'total_revenue' => 0.0,
                'total_ad_cost' => 0.0,
                'profit' => 0.0,
                'roas' => null,
                'profit_margin' => null,
            ],
        ],
        'charts' => [
            'bar' => [
                'labels' => [],
                'revenue' => [],
                'ad_cost' => [],
                'profit' => [],
            ],
            'trend' => [
                'months' => [],
                'series' => [],
            ],
        ],
    ];

    if (($overviewResult['success'] ?? false) === true) {
        $overviewData = array_replace_recursive($overviewData, (array)($overviewResult['data'] ?? []));
    } else {
        $overviewError = (string)($overviewResult['error'] ?? 'ไม่สามารถโหลดข้อมูลภาพรวมทุกร้านได้');
    }

    $activeError = $overviewError;
    $comparisonRows = (array)($overviewData['comparison']['rows'] ?? []);
    $totals = (array)($overviewData['comparison']['totals'] ?? []);
    $canView = (bool)($overviewData['can_view'] ?? false);
    $overviewTotalRevenue = (float)($totals['total_revenue'] ?? 0);
    $overviewTotalAdCost = (float)($totals['total_ad_cost'] ?? 0);
    $hasOverviewData = abs($overviewTotalRevenue) > 0.00001 || abs($overviewTotalAdCost) > 0.00001;

    $barRaw = (array)($overviewData['charts']['bar'] ?? []);
    $barPayload = [
        'labels' => array_values((array)($barRaw['labels'] ?? [])),
        'revenue' => array_values(array_map(static fn($value): float => (float)$value, (array)($barRaw['revenue'] ?? []))),
        'ad_cost' => array_values(array_map(static fn($value): float => (float)$value, (array)($barRaw['ad_cost'] ?? []))),
        'profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($barRaw['profit'] ?? []))),
    ];

    $trendRaw = (array)($overviewData['charts']['trend'] ?? []);
    $trendMonthKeys = array_values((array)($trendRaw['months'] ?? []));
    $trendSeriesRaw = array_values((array)($trendRaw['series'] ?? []));
    $trendPayload = [
        'labels' => array_map(static fn(string $month): string => formatThaiMonth($month), $trendMonthKeys),
        'months' => $trendMonthKeys,
        'series' => array_values(array_map(
            static function ($series): array {
                $row = is_array($series) ? $series : [];

                return [
                    'shop_id' => (int)($row['shop_id'] ?? 0),
                    'shop_name' => (string)($row['shop_name'] ?? 'ร้านค้า'),
                    'revenue' => array_values(array_map(static fn($value): float => (float)$value, (array)($row['revenue'] ?? []))),
                    'profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($row['profit'] ?? []))),
                ];
            },
            $trendSeriesRaw
        )),
    ];
}

$pageTitle = 'รวมทุกร้าน';
$currentPage = 'overview';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between sm:gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-100">หน้ารวมทุกร้าน</h1>
            <?php if ($view === 'day'): ?>
                <p class="mt-2 text-sm text-slate-500">สรุปยอดรวมทุกร้านแบบรายวัน สำหรับเดือนที่เลือก</p>
            <?php elseif ($view === 'year'): ?>
                <p class="mt-2 text-sm text-slate-500">สรุปยอดรวมทุกร้านแบบรายปี พร้อมกราฟและจัดอันดับตามกำไร</p>
            <?php else: ?>
                <p class="mt-2 text-sm text-slate-500">ตารางและกราฟเปรียบเทียบทุกร้านตามเดือนที่เลือก</p>
            <?php endif; ?>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-end sm:gap-3">
            <?php if ($shopCount >= 2): ?>
                <div class="inline-flex rounded-xl border border-white/10 bg-white/5 p-1 text-xs">
                    <a href="<?= e(app_url('/overview.php?view=day&month=' . $selectedMonth)) ?>"
                        class="rounded-lg px-3 py-1.5 font-semibold transition-colors <?= $view === 'day' ? 'bg-white/10 text-slate-100' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        รายวัน
                    </a>
                    <a href="<?= e(app_url('/overview.php?view=month&month=' . $selectedMonth)) ?>"
                        class="rounded-lg px-3 py-1.5 font-semibold transition-colors <?= $view === 'month' ? 'bg-white/10 text-slate-100' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        รายเดือน
                    </a>
                    <a href="<?= e(app_url('/overview.php?view=year&year=' . (string)$selectedYear)) ?>"
                        class="rounded-lg px-3 py-1.5 font-semibold transition-colors <?= $view === 'year' ? 'bg-white/10 text-slate-100' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        รายปี
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($view === 'year'): ?>
                <form method="get" action="<?= e(app_url('/overview.php')) ?>" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="view" value="year">
                    <label for="overview-year" class="text-sm text-slate-400">เลือกปี (พ.ศ.)</label>
                    <select id="overview-year" name="year" class="rounded-xl px-3 py-2 text-sm transition-all">
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?= e((string)$year) ?>" <?= $year === $selectedYear ? 'selected' : '' ?>>
                                <?= e((string)($year + 543)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-ghost px-4 py-2 text-sm">แสดงผล</button>
                </form>
            <?php else: ?>
                <form method="get" action="<?= e(app_url('/overview.php')) ?>" class="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="view" value="<?= e($view) ?>">
                    <label for="overview-month" class="text-sm text-slate-400">เลือกเดือน</label>
                    <input
                        id="overview-month"
                        name="month"
                        type="month"
                        max="<?= e(date('Y-m')) ?>"
                        value="<?= e($selectedMonth) ?>"
                        class="rounded-xl px-3 py-2 text-sm transition-all">
                    <button type="submit" class="btn-ghost px-4 py-2 text-sm">แสดงผล</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($activeError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-sm text-red-400">
            <?= e($activeError) ?>
        </div>
    <?php endif; ?>


    <?php if (!$canView): ?>
        <p class="mt-4 text-sm text-slate-500">
            <?php if ($shopCount < 2): ?>
                ต้องมีอย่างน้อย 2 ร้านถึงจะเห็นข้อมูลเปรียบเทียบ
            <?php else: ?>
                ไม่สามารถแสดงข้อมูลได้ในขณะนี้
            <?php endif; ?>
        </p>
    <?php else: ?>
        <?php if ($view === 'day'): ?>
            <?php
            $dayTotalRevenue = (float)($dailySummary['total_revenue'] ?? 0);
            $dayTotalAdCost = (float)($dailySummary['total_ad_cost'] ?? 0);
            $dayProfit = (float)($dailySummary['profit'] ?? ($dayTotalRevenue - $dayTotalAdCost));
            $dayRoas = isset($dailySummary['roas']) && $dailySummary['roas'] !== null ? (float)$dailySummary['roas'] : null;
            $dayProfitMargin = isset($dailySummary['profit_margin']) && $dailySummary['profit_margin'] !== null
                ? (float)$dailySummary['profit_margin']
                : ($dayTotalRevenue > 0 ? round(($dayProfit / $dayTotalRevenue) * 100, 1) : null);
            $dayAvgRevenue = isset($dailySummary['avg_revenue_per_day']) && $dailySummary['avg_revenue_per_day'] !== null
                ? (float)$dailySummary['avg_revenue_per_day']
                : null;
            $dayAvgProfit = isset($dailySummary['avg_profit_per_day']) && $dailySummary['avg_profit_per_day'] !== null
                ? (float)$dailySummary['avg_profit_per_day']
                : null;
            $dayBest = is_array($dailySummary['best_day'] ?? null) ? (array)$dailySummary['best_day'] : null;
            $dayWorst = is_array($dailySummary['worst_day'] ?? null) ? (array)$dailySummary['worst_day'] : null;
            $dayTotalShops = (int)($dailySummary['total_shops'] ?? 0);
            $dayIncompleteDays = (int)($dailySummary['incomplete_days'] ?? 0);
            ?>

            <?php if (!$hasDailyData): ?>
                <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
                    เดือนนี้ยังไม่มีข้อมูลรายวันของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
                </div>
            <?php endif; ?>

            <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <article class="stat-card s-revenue">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ยอดขายรวม</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-orange-400"><?= e(formatMoney($dayTotalRevenue)) ?></p>
                </article>
                <article class="stat-card s-adcost">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ค่าแอดรวม</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-cyan-400"><?= e(formatMoney($dayTotalAdCost)) ?></p>
                </article>
                <article class="stat-card s-profit">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">กำไรรวม</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold <?= $dayProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($dayProfit)) ?></p>
                </article>
                <article class="stat-card s-roas">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ROAS เฉลี่ย</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-violet-400"><?= e(formatRoas($dayRoas)) ?></p>
                </article>
                <article class="stat-card s-neutral">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">อัตรากำไร</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-slate-100"><?= e(formatPercent($dayProfitMargin)) ?></p>
                </article>
            </section>

            <?php if ($dayAvgProfit !== null || $dayBest !== null): ?>
                <section class="section-card mt-4 px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-slate-400">
                        <?php if ($dayAvgProfit !== null): ?>
                            เฉลี่ยกำไร/วัน
                            <span class="font-bold <?= $dayAvgProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                <?= e(formatMoney($dayAvgProfit)) ?>
                            </span>
                            <?php if ($dayAvgRevenue !== null): ?>
                                <span class="text-xs text-slate-500">(รายได้ <?= e(formatMoney($dayAvgRevenue)) ?>)</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($dayBest !== null): ?>
                            <?php
                            $bestDayProfit = (float)($dayBest['profit'] ?? 0);
                            $worstDayProfit = $dayWorst !== null ? (float)($dayWorst['profit'] ?? 0) : null;
                            ?>
                            <span class="text-slate-600">·</span>
                            วันกำไรดีสุด
                            <span class="font-bold <?= $bestDayProfit < 0 ? 'text-red-400' : 'text-green-400' ?>">
                                <?= e(formatThaiDate((string)($dayBest['record_date'] ?? ''))) ?>
                                (<?= e(formatMoney($bestDayProfit)) ?>)
                            </span>
                            <?php if ($dayWorst !== null): ?>
                                <span class="text-slate-600">·</span>
                                แย่สุด
                                <span class="font-bold <?= ($worstDayProfit ?? 0) >= 0 ? 'text-slate-300' : 'text-red-400' ?>">
                                    <?= e(formatThaiDate((string)($dayWorst['record_date'] ?? ''))) ?>
                                    (<?= e(formatMoney($worstDayProfit ?? 0)) ?>)
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($dayIncompleteDays > 0): ?>
                        <p class="mt-2 border-t border-white/[0.06] pt-2 text-xs text-amber-400">
                            ⚠️ <?= e((string)$dayIncompleteDays) ?> วันที่ยังกรอกไม่ครบทุกร้าน —
                            <span class="text-slate-500">ยอดรวมของวันเหล่านั้นเทียบกับวันอื่นตรง ๆ ไม่ได้</span>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="section-card mt-6 p-4 sm:p-5">
                <h2 class="mb-3 text-base sm:text-lg font-semibold text-slate-100">ตารางรายวัน (รวมทุกร้าน)</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th class="px-3 py-2">วันที่</th>
                                <th class="px-3 py-2">ยอดขาย</th>
                                <th class="px-3 py-2">ค่าแอด</th>
                                <th class="px-3 py-2">กำไร</th>
                                <th class="px-3 py-2">ROAS</th>
                                <th class="px-3 py-2">อัตรากำไร</th>
                                <th class="px-3 py-2">ร้านที่กรอก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyRows as $row): ?>
                                <?php
                                $rowDate = (string)($row['record_date'] ?? '');
                                $rowShopsCount = (int)($row['shops_count'] ?? 0);
                                $rowIsComplete = ($row['is_complete'] ?? true) === true;
                                $rowRevenue = (float)($row['total_revenue'] ?? 0);
                                $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                                $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                                $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                                $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                                $rowDay = (int)substr($rowDate, 8, 2);
                                ?>
                                <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                                    <td class="px-3 py-2 text-slate-300 font-medium"><?= e((string)$rowDay) ?></td>
                                    <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($rowRevenue)) ?></td>
                                    <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($rowAdCost)) ?></td>
                                    <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($rowProfit)) ?></td>
                                    <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                    <td class="px-3 py-2 font-medium <?= $rowIsComplete ? 'text-slate-400' : 'text-amber-400' ?>">
                                        <?= e($rowShopsCount . '/' . $dayTotalShops) ?> ร้าน<?= $rowIsComplete ? '' : ' ⚠️' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/10 bg-white/[0.03] font-semibold whitespace-nowrap">
                                <td class="px-3 py-3 text-slate-200">รวมทั้งเดือน</td>
                                <td class="px-3 py-3 text-orange-400"><?= e(formatMoney($dayTotalRevenue)) ?></td>
                                <td class="px-3 py-3 text-cyan-400"><?= e(formatMoney($dayTotalAdCost)) ?></td>
                                <td class="px-3 py-3 <?= $dayProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($dayProfit)) ?></td>
                                <td class="px-3 py-3 text-violet-400"><?= e(formatRoas($dayRoas)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e(formatPercent($dayProfitMargin)) ?></td>
                                <td class="px-3 py-3 <?= $dayIncompleteDays > 0 ? 'text-amber-400' : 'text-slate-300' ?>">
                                    <?= e($dayIncompleteDays > 0 ? $dayIncompleteDays . ' วันไม่ครบ' : 'ครบทุกวัน') ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="section-card mt-6 p-4 sm:p-5">
                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
                    <h2 class="text-base sm:text-lg font-semibold text-slate-100">กราฟแท่งรายวัน (รวมทุกร้าน)</h2>
                    <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร</span>
                </div>
                <div class="h-52 sm:h-64 lg:h-80 w-full overflow-x-auto">
                    <div style="min-width: <?= max(400, count($dailyChartPayload['labels']) * 28) ?>px; height: 100%;">
                        <canvas id="overview-daily-bar-chart"></canvas>
                    </div>
                </div>
            </section>

            <section class="section-card mt-6 p-4 sm:p-5">
                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
                    <h2 class="text-base sm:text-lg font-semibold text-slate-100">เปรียบเทียบระหว่างร้าน (จัดอันดับตามกำไร)</h2>
                    <span class="text-xs text-slate-400">รวมทั้งเดือน <?= e($selectedMonth) ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th class="px-3 py-2">อันดับ</th>
                                <th class="px-3 py-2">ร้าน</th>
                                <th class="px-3 py-2">ยอดขาย</th>
                                <th class="px-3 py-2">ค่าแอด</th>
                                <th class="px-3 py-2">กำไร</th>
                                <th class="px-3 py-2">ROAS</th>
                                <th class="px-3 py-2">อัตรากำไร</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyShopRows as $index => $row): ?>
                                <?php
                                $rowRevenue = (float)($row['total_revenue'] ?? 0);
                                $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                                $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                                $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                                $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                                ?>
                                <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                                    <td class="px-3 py-2 text-slate-500 font-semibold"><?= e((string)($index + 1)) ?></td>
                                    <td class="px-3 py-2 text-slate-300 font-medium"><?= e((string)($row['shop_name'] ?? 'ร้านค้า')) ?></td>
                                    <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($rowRevenue)) ?></td>
                                    <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($rowAdCost)) ?></td>
                                    <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($rowProfit)) ?></td>
                                    <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/10 bg-white/[0.03] font-semibold whitespace-nowrap">
                                <td class="px-3 py-3 text-slate-200" colspan="2">รวมทุกร้าน</td>
                                <td class="px-3 py-3 text-orange-400"><?= e(formatMoney($dayTotalRevenue)) ?></td>
                                <td class="px-3 py-3 text-cyan-400"><?= e(formatMoney($dayTotalAdCost)) ?></td>
                                <td class="px-3 py-3 <?= $dayProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($dayProfit)) ?></td>
                                <td class="px-3 py-3 text-violet-400"><?= e(formatRoas($dayRoas)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e(formatPercent($dayProfitMargin)) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <script>
                (function() {
                    const chartPayload = <?= json_encode($dailyChartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    const chartCanvas = document.getElementById('overview-daily-bar-chart');

                    if (!chartCanvas) {
                        return;
                    }

                    new Chart(chartCanvas, {
                        type: 'bar',
                        data: {
                            labels: chartPayload.labels,
                            datasets: [{
                                    label: 'ยอดขาย',
                                    data: chartPayload.revenue,
                                    backgroundColor: '#f97316'
                                },
                                {
                                    label: 'ค่าแอด',
                                    data: chartPayload.ad_cost,
                                    backgroundColor: '#06b6d4'
                                },
                                {
                                    label: 'กำไร',
                                    data: chartPayload.profit,
                                    backgroundColor: '#22c55e'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: '#94a3b8'
                                    },
                                    grid: {
                                        color: 'rgba(255, 255, 255, 0.06)'
                                    }
                                },
                                y: {
                                    ticks: {
                                        color: '#94a3b8',
                                        callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                                    },
                                    grid: {
                                        color: 'rgba(255, 255, 255, 0.06)'
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    labels: {
                                        color: '#cbd5e1'
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => context.dataset.label + ': ฿' + Number(context.raw || 0).toLocaleString('th-TH')
                                    }
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php elseif ($view === 'year'): ?>
            <?php
            $yearTotalRevenue = (float)($yearlySummary['total_revenue'] ?? 0);
            $yearTotalAdCost = (float)($yearlySummary['total_ad_cost'] ?? 0);
            $yearProfit = (float)($yearlySummary['profit'] ?? ($yearTotalRevenue - $yearTotalAdCost));
            $yearRoas = isset($yearlySummary['roas']) && $yearlySummary['roas'] !== null ? (float)$yearlySummary['roas'] : null;
            $yearProfitMargin = isset($yearlySummary['profit_margin']) && $yearlySummary['profit_margin'] !== null
                ? (float)$yearlySummary['profit_margin']
                : ($yearTotalRevenue > 0 ? round(($yearProfit / $yearTotalRevenue) * 100, 1) : null);
            ?>

            <?php if (!$hasYearlyData): ?>
                <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
                    ปีนี้ยังไม่มีข้อมูลยอดขายของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
                </div>
            <?php endif; ?>

            <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <article class="stat-card s-revenue">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ยอดขายรวมทั้งปี</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-orange-400"><?= e(formatMoney($yearTotalRevenue)) ?></p>
                </article>
                <article class="stat-card s-adcost">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ค่าแอดรวมทั้งปี</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-cyan-400"><?= e(formatMoney($yearTotalAdCost)) ?></p>
                </article>
                <article class="stat-card s-profit">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">กำไรรวมทั้งปี</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold <?= $yearProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($yearProfit)) ?></p>
                </article>
                <article class="stat-card s-roas">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ROAS เฉลี่ยทั้งปี</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-violet-400"><?= e(formatRoas($yearRoas)) ?></p>
                </article>
                <article class="stat-card s-neutral">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-400">อัตรากำไรทั้งปี</p>
                    <p class="mt-2 text-lg sm:text-xl font-bold text-slate-100"><?= e(formatPercent($yearProfitMargin)) ?></p>
                </article>
            </section>

            <?php if ($yearlyPrevProfit !== null): ?>
                <section class="section-card mt-4 px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
                            เทียบ <?= e((string)($yearlyPrevYear + 543)) ?>
                            <?php if (count($yearlyMonths) > 0 && count($yearlyMonths) < 12): ?>
                                (ม.ค.–<?= e($thaiMonths[count($yearlyMonths)] ?? '') ?>)
                            <?php endif; ?>
                        </span>
                        <?php if ($yearlyYoyPercent === null): ?>
                            <span class="text-base font-bold text-slate-400">ไม่มีข้อมูลปีก่อน</span>
                        <?php else: ?>
                            <span class="text-base font-bold <?= e($yearlyYoyTone($yearlyYoyPercent)) ?>">
                                กำไรรวม <?= e($yearlyYoyText($yearlyYoyPercent)) ?>
                            </span>
                            <span class="text-sm <?= e($yearlyYoyTone($yearlyYoyPercent)) ?>">
                                (<?= e((($yearlyYoyChange ?? 0) >= 0 ? '+' : '-') . formatMoney(abs($yearlyYoyChange ?? 0))) ?>)
                            </span>
                            <span class="text-xs text-slate-500">
                                ปีก่อน <?= e(formatMoney($yearlyPrevProfit)) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($yearlyBestMonth !== null || $yearlyWorstMonth !== null): ?>
                <?php
                // แสดง "กำไร" ของเดือนดี/แย่สุด (จัดอันดับด้วยกำไร ไม่ใช่รายได้)
                $bestMonthProfit = $yearlyBestMonth !== null ? (float)($yearlyBestMonth['profit'] ?? 0) : null;
                $worstMonthProfit = $yearlyWorstMonth !== null ? (float)($yearlyWorstMonth['profit'] ?? 0) : null;
                $bestMonthLabel = $yearlyBestMonth !== null
                    ? ($thaiMonths[(int)($yearlyBestMonth['month'] ?? 0)] ?? '–')
                    : '–';
                $worstMonthLabel = $yearlyWorstMonth !== null
                    ? ($thaiMonths[(int)($yearlyWorstMonth['month'] ?? 0)] ?? '–')
                    : '–';
                ?>
                <section class="section-card mt-4 px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-slate-400">
                        <span class="text-xs font-medium uppercase tracking-wider text-slate-500">รวมทุกร้าน</span>
                        เดือนกำไรดีสุด
                        <span class="font-bold <?= $bestMonthProfit !== null && $bestMonthProfit < 0 ? 'text-red-400' : 'text-green-400' ?>">
                            <?= e($bestMonthLabel) ?><?= $bestMonthProfit !== null ? ' (' . e(formatMoney($bestMonthProfit)) . ')' : '' ?>
                        </span>
                        <span class="text-slate-600">·</span>
                        แย่สุด
                        <span class="font-bold <?= $worstMonthProfit !== null && $worstMonthProfit >= 0 ? 'text-slate-300' : 'text-red-400' ?>">
                            <?= e($worstMonthLabel) ?><?= $worstMonthProfit !== null ? ' (' . e(formatMoney($worstMonthProfit)) . ')' : '' ?>
                        </span>
                    </div>
                </section>
            <?php endif; ?>

            <section class="section-card mt-6 p-4 sm:p-5">
                <h2 class="mb-3 text-base sm:text-lg font-semibold text-slate-100">ตารางรวมรายเดือน (<?= e((string)count($yearlyMonths)) ?> เดือน)</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th class="px-3 py-2">เดือน</th>
                                <th class="px-3 py-2">ยอดขาย</th>
                                <th class="px-3 py-2">ค่าแอด</th>
                                <th class="px-3 py-2">กำไร</th>
                                <th class="px-3 py-2">ROAS</th>
                                <th class="px-3 py-2">อัตรากำไร</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yearlyMonths as $row): ?>
                                <?php
                                $rowRevenue = (float)($row['total_revenue'] ?? 0);
                                $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                                $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                                $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                                $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                                ?>
                                <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                                    <td class="px-3 py-2 text-slate-300 font-medium"><?= e($monthLabel($row)) ?></td>
                                    <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($rowRevenue)) ?></td>
                                    <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($rowAdCost)) ?></td>
                                    <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($rowProfit)) ?></td>
                                    <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/10 bg-white/[0.03] font-semibold whitespace-nowrap">
                                <td class="px-3 py-3 text-slate-200">รวมทั้งปี</td>
                                <td class="px-3 py-3 text-orange-400"><?= e(formatMoney($yearTotalRevenue)) ?></td>
                                <td class="px-3 py-3 text-cyan-400"><?= e(formatMoney($yearTotalAdCost)) ?></td>
                                <td class="px-3 py-3 <?= $yearProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($yearProfit)) ?></td>
                                <td class="px-3 py-3 text-violet-400"><?= e(formatRoas($yearRoas)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e(formatPercent($yearProfitMargin)) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="section-card mt-6 p-4 sm:p-5">
                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
                    <h2 class="text-base sm:text-lg font-semibold text-slate-100">กราฟแท่งรายเดือน (<?= e((string)count($yearlyMonths)) ?> เดือน)</h2>
                    <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร</span>
                </div>
                <div class="h-52 sm:h-64 lg:h-80 w-full overflow-x-auto">
                    <div style="min-width: 600px; height: 100%;">
                        <canvas id="overview-year-bar-chart"></canvas>
                    </div>
                </div>
            </section>

            <section class="section-card mt-6 p-4 sm:p-5">
                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
                    <h2 class="text-base sm:text-lg font-semibold text-slate-100">ตารางเปรียบเทียบรายปี (จัดอันดับตามกำไร)</h2>
                    <span class="text-xs text-slate-400">รวมปี <?= e((string)($selectedYear + 543)) ?> (พ.ศ.)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th class="px-3 py-2">อันดับ</th>
                                <th class="px-3 py-2">ร้าน</th>
                                <th class="px-3 py-2">ยอดขาย</th>
                                <th class="px-3 py-2">ค่าแอด</th>
                                <th class="px-3 py-2">กำไร</th>
                                <th class="px-3 py-2">ROAS</th>
                                <th class="px-3 py-2">อัตรากำไร</th>
                                <th class="px-3 py-2">สัดส่วนกำไร</th>
                                <th class="px-3 py-2">วันที่กรอก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yearlyShopRows as $index => $row): ?>
                                <?php
                                $rowRevenue = (float)($row['total_revenue'] ?? 0);
                                $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                                $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                                $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                                $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                                $rowProfitShare = isset($row['profit_share']) && $row['profit_share'] !== null ? (float)$row['profit_share'] : null;
                                $rowDaysCount = (int)($row['days_count'] ?? 0);
                                ?>
                                <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                                    <td class="px-3 py-2 text-slate-500 font-semibold"><?= e((string)($index + 1)) ?></td>
                                    <td class="px-3 py-2 text-slate-300 font-medium"><?= e((string)($row['shop_name'] ?? 'ร้านค้า')) ?></td>
                                    <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($rowRevenue)) ?></td>
                                    <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($rowAdCost)) ?></td>
                                    <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($rowProfit)) ?></td>
                                    <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                    <td class="px-3 py-2 font-medium <?= $rowProfitShare === null ? 'text-slate-500' : ($rowProfitShare < 0 ? 'text-red-400' : 'text-slate-300') ?>">
                                        <?= e($rowProfitShare === null ? '—' : formatPercent($rowProfitShare)) ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e($rowDaysCount > 0 ? $rowDaysCount . ' วัน' : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/10 bg-white/[0.03] font-semibold whitespace-nowrap">
                                <td class="px-3 py-3 text-slate-200" colspan="2">รวมทุกร้าน</td>
                                <td class="px-3 py-3 text-orange-400"><?= e(formatMoney($yearTotalRevenue)) ?></td>
                                <td class="px-3 py-3 text-cyan-400"><?= e(formatMoney($yearTotalAdCost)) ?></td>
                                <td class="px-3 py-3 <?= $yearProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($yearProfit)) ?></td>
                                <td class="px-3 py-3 text-violet-400"><?= e(formatRoas($yearRoas)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e(formatPercent($yearProfitMargin)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e($yearlyShareTotal === null ? '—' : formatPercent($yearlyShareTotal)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e($yearlyDaysTotal > 0 ? $yearlyDaysTotal . ' วัน' : '—') ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <script>
                (function() {
                    const chartPayload = <?= json_encode($yearChartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    const chartCanvas = document.getElementById('overview-year-bar-chart');

                    if (!chartCanvas) {
                        return;
                    }

                    new Chart(chartCanvas, {
                        type: 'bar',
                        data: {
                            labels: chartPayload.labels,
                            datasets: [{
                                    label: 'ยอดขาย',
                                    data: chartPayload.revenue,
                                    backgroundColor: '#f97316'
                                },
                                {
                                    label: 'ค่าแอด',
                                    data: chartPayload.ad_cost,
                                    backgroundColor: '#06b6d4'
                                },
                                {
                                    label: 'กำไร',
                                    data: chartPayload.profit,
                                    backgroundColor: '#22c55e'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        color: '#94a3b8'
                                    },
                                    grid: {
                                        color: 'rgba(255, 255, 255, 0.06)'
                                    }
                                },
                                y: {
                                    ticks: {
                                        color: '#94a3b8',
                                        callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                                    },
                                    grid: {
                                        color: 'rgba(255, 255, 255, 0.06)'
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    labels: {
                                        color: '#cbd5e1'
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => context.dataset.label + ': ฿' + Number(context.raw || 0).toLocaleString('th-TH')
                                    }
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php else: ?>
            <?php if (!$hasOverviewData): ?>
                <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
                    เดือนนี้ยังไม่มีข้อมูลยอดขายของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
                </div>
            <?php endif; ?>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/10 text-left text-slate-400">
                            <th class="px-3 py-1.5">อันดับ</th>
                            <th class="px-3 py-1.5">ร้าน</th>
                            <th class="px-3 py-1.5">ยอดขาย</th>
                            <th class="px-3 py-1.5">ค่าแอด</th>
                            <th class="px-3 py-1.5">กำไร</th>
                            <th class="px-3 py-1.5">สัดส่วนกำไร</th>
                            <th class="px-3 py-1.5">เทียบเดือนก่อน</th>
                            <th class="px-3 py-1.5">ROAS</th>
                            <th class="px-3 py-1.5">อัตรากำไร</th>
                            <th class="px-3 py-1.5">วันที่กรอก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comparisonRows as $rowIndex => $row): ?>
                            <?php
                            $rowRevenue = (float)($row['total_revenue'] ?? 0);
                            $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                            $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                            $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                            $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                            $rowProfitShare = isset($row['profit_share']) && $row['profit_share'] !== null ? (float)$row['profit_share'] : null;
                            $rowChangePercent = isset($row['profit_change_percent']) && $row['profit_change_percent'] !== null
                                ? (float)$row['profit_change_percent']
                                : null;
                            $rowChange = (float)($row['profit_change'] ?? 0);
                            ?>
                            <tr class="border-b border-white/[0.06] table-row-hover">
                                <td class="px-3 py-1.5 text-slate-500"><?= e((string)($rowIndex + 1)) ?></td>
                                <td class="px-3 py-1.5 text-slate-300 font-medium"><?= e((string)($row['shop_name'] ?? 'ร้านค้า')) ?></td>
                                <td class="px-3 py-1.5 text-orange-400 font-medium"><?= e(formatMoney($rowRevenue)) ?></td>
                                <td class="px-3 py-1.5 text-cyan-400 font-medium"><?= e(formatMoney($rowAdCost)) ?></td>
                                <td class="px-3 py-1.5 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($rowProfit)) ?></td>
                                <td class="px-3 py-1.5 font-medium <?= $rowProfitShare !== null && $rowProfitShare < 0 ? 'text-red-400' : 'text-slate-300' ?>">
                                    <?= $rowProfitShare !== null ? e(formatPercent($rowProfitShare)) : '—' ?>
                                </td>
                                <td class="px-3 py-1.5 font-medium">
                                    <?php if ($rowChangePercent === null): ?>
                                        <span class="text-slate-500" title="ไม่มีข้อมูลเดือนก่อน">ใหม่</span>
                                    <?php else: ?>
                                        <span class="<?= $rowChangePercent >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                            <?= $rowChangePercent >= 0 ? '↑' : '↓' ?>
                                            <?= e(formatPercent(abs($rowChangePercent))) ?>
                                        </span>
                                        <span class="text-xs text-slate-500">(<?= e(formatMoney($rowChange)) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-1.5 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                <td class="px-3 py-1.5 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                <td class="px-3 py-1.5 text-xs text-slate-500"><?= e((string)(int)($row['days_count'] ?? 0)) ?> วัน</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php
                        $totalRevenue = (float)($totals['total_revenue'] ?? 0);
                        $totalAdCost = (float)($totals['total_ad_cost'] ?? 0);
                        $totalProfit = (float)($totals['profit'] ?? ($totalRevenue - $totalAdCost));
                        $totalRoas = isset($totals['roas']) && $totals['roas'] !== null ? (float)$totals['roas'] : null;
                        $totalProfitMargin = isset($totals['profit_margin']) && $totals['profit_margin'] !== null ? (float)$totals['profit_margin'] : null;
                        ?>
                        <tr class="border-t border-white/10 bg-white/[0.03] font-semibold">
                            <td class="px-3 py-2 text-slate-200" colspan="2">รวมทุกร้าน</td>
                            <td class="px-3 py-2 text-orange-400"><?= e(formatMoney($totalRevenue)) ?></td>
                            <td class="px-3 py-2 text-cyan-400"><?= e(formatMoney($totalAdCost)) ?></td>
                            <td class="px-3 py-2 <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></td>
                            <td class="px-3 py-2 text-slate-500">—</td>
                            <td class="px-3 py-2 text-slate-500">—</td>
                            <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($totalRoas)) ?></td>
                            <td class="px-3 py-2 text-slate-300"><?= e(formatPercent($totalProfitMargin)) ?></td>
                            <td class="px-3 py-2 text-slate-500">—</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <section class="section-card mt-6 p-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-slate-100">กราฟแท่งเปรียบเทียบระหว่างร้าน</h2>
                    <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร</span>
                </div>
                <div class="h-80 w-full overflow-x-auto">
                    <div style="min-width: <?= max(100, count($barPayload['labels']) * 120) ?>px; height: 100%;">
                        <canvas id="overview-bar-chart"></canvas>
                    </div>
                </div>
            </section>

            <section class="section-card mt-6 p-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-slate-100">กราฟเส้นแนวโน้มทุกร้าน (6 เดือนย้อนหลัง)</h2>
                    <span class="text-xs text-slate-400">เส้นแต่ละร้าน = กำไรรายเดือน</span>
                </div>
                <div class="h-80">
                    <canvas id="overview-trend-chart"></canvas>
                </div>
            </section>

            <script>
                (function() {
                    const barPayload = <?= json_encode($barPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    const trendPayload = <?= json_encode($trendPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                    const barCanvas = document.getElementById('overview-bar-chart');
                    if (barCanvas) {
                        new Chart(barCanvas, {
                            type: 'bar',
                            data: {
                                labels: barPayload.labels,
                                datasets: [{
                                        label: 'ยอดขาย',
                                        data: barPayload.revenue,
                                        backgroundColor: '#f97316'
                                    },
                                    {
                                        label: 'ค่าแอด',
                                        data: barPayload.ad_cost,
                                        backgroundColor: '#06b6d4'
                                    },
                                    {
                                        label: 'กำไร',
                                        data: barPayload.profit,
                                        backgroundColor: '#22c55e'
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: {
                                    mode: 'index',
                                    intersect: false
                                },
                                scales: {
                                    x: {
                                        ticks: {
                                            color: '#94a3b8'
                                        },
                                        grid: {
                                            color: 'rgba(255, 255, 255, 0.06)'
                                        }
                                    },
                                    y: {
                                        ticks: {
                                            color: '#94a3b8',
                                            callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                                        },
                                        grid: {
                                            color: 'rgba(255, 255, 255, 0.06)'
                                        }
                                    }
                                },
                                plugins: {
                                    legend: {
                                        labels: {
                                            color: '#cbd5e1'
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => context.dataset.label + ': ฿' + Number(context.raw || 0).toLocaleString('th-TH')
                                        }
                                    }
                                }
                            }
                        });
                    }

                    const trendCanvas = document.getElementById('overview-trend-chart');
                    if (trendCanvas) {
                        const colors = ['#f97316', '#06b6d4', '#22c55e', '#eab308', '#a78bfa', '#f43f5e'];
                        const datasets = (trendPayload.series || []).map((series, index) => {
                            const color = colors[index % colors.length];

                            return {
                                label: series.shop_name || 'ร้านค้า',
                                // plot กำไร ให้สอดคล้องกับทั้งแอปที่วัดด้วยกำไร (ค่าติดลบ plot ได้)
                                data: series.profit || [],
                                borderColor: color,
                                backgroundColor: color,
                                tension: 0.3,
                                fill: false
                            };
                        });

                        new Chart(trendCanvas, {
                            type: 'line',
                            data: {
                                labels: trendPayload.labels,
                                datasets: datasets
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: {
                                    mode: 'index',
                                    intersect: false
                                },
                                scales: {
                                    x: {
                                        ticks: {
                                            color: '#94a3b8'
                                        },
                                        grid: {
                                            color: 'rgba(255, 255, 255, 0.06)'
                                        }
                                    },
                                    y: {
                                        ticks: {
                                            color: '#94a3b8',
                                            callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                                        },
                                        grid: {
                                            color: 'rgba(255, 255, 255, 0.06)'
                                        }
                                    }
                                },
                                plugins: {
                                    legend: {
                                        labels: {
                                            color: '#cbd5e1'
                                        }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => context.dataset.label + ': ฿' + Number(context.raw || 0).toLocaleString('th-TH')
                                        }
                                    }
                                }
                            }
                        });
                    }
                })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>