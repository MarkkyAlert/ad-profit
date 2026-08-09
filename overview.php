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
// ⚠️ ประกาศก่อนเข้ากิ่ง — ส่วนเรนเดอร์อยู่นอกกิ่งที่กำหนดค่า (กติกาเดียวกับตัวแปรมุมรายปี)
$shopsMissingFromCharts = 0;
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

// ⚠️ ตั้งค่าตั้งต้นของมุมมอง "รายปี" ไว้ก่อนเข้ากิ่ง — ตัวแปรพวกนี้ถูกกำหนดในกิ่ง
// `$view === 'year'` แล้วถูกใช้ในกิ่งเดียวกันตอนเรนเดอร์ ซึ่งถูกต้อง *ในวันนี้*
// แต่ความถูกต้องนั้นอาศัยว่าเงื่อนไขสองที่ตรงกันเป๊ะ ๆ ตลอดไป · ประกาศไว้ก่อน
// ทำให้แก้เงื่อนไขผิดแล้วได้ค่าว่าง แทนที่จะเป็น warning กลางหน้า และทำให้
// เครื่องมือตรวจโค้ดวิเคราะห์หน้านี้ได้ (เดิมหน้าเว็บทั้งชั้นอยู่นอกขอบเขต PHPStan)
$yearlyDaysTotal = 0;
$yearlyShareTotal = null;
$yearlyBestMonth = null;
$yearlyWorstMonth = null;
$yearlyPrevYear = $selectedYear - 1;
$yearlyPrevProfit = null;
$yearlyPrevHasData = false;
$yearlyPrevUnavailable = false;
$yearlyYoyPercent = null;
$yearlyYoyText = static fn(?float $percent): string => format_change_badge($percent, no_value_text())['text'];
$yearlyYoyTone = static fn(?float $percent): string => format_change_badge($percent, no_value_text())['class'];

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
    // ⚠️ นับจากจำนวนวันที่กรอก ไม่ใช่ยอดเงิน (ดูคอมเมนต์ใน annual.php)
    $hasDailyData = (int)($dailySummary['days_count'] ?? 0) > 0;

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
        // ⚠️ วันที่กรอกไม่ครบทุกร้านต้องมีเครื่องหมายบนกราฟ — ไม่งั้นกราฟวาดเทียบตรง ๆ
        // ทั้งที่ข้อความเหนือกราฟบอกว่าเทียบไม่ได้ และการ์ดสรุปตัดวันพวกนั้นออกไปแล้ว
        'is_complete' => array_values(array_map(
            static fn($v): bool => $v === true,
            (array)($chartRaw['is_complete'] ?? [])
        )),
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
    // ⚠️ "วันที่กรอก" ของแถวรวมต้องไม่ใช่ผลบวกข้ามร้าน — 3 ร้านกรอกครบเดือน ม.ค. (31 วัน)
    // จะได้ 93 วัน ในช่วงที่มีแค่ 31 วัน · ตัวเลขที่มีความหมายคือ "วันมากที่สุดที่ร้านใดร้านหนึ่งกรอก"
    $yearlyDaysTotal = 0;
    foreach ($yearlyShopRows as $shopRow) {
        $yearlyDaysTotal = max($yearlyDaysTotal, (int)($shopRow['days_count'] ?? 0));
    }

    // ⚠️ นับจากจำนวนวันที่กรอก ไม่ใช่ยอดเงิน (ดูคอมเมนต์ใน annual.php)
    // ⚠️ ต้องอยู่ **หลัง** ลูปข้างบน ไม่งั้นอ่านค่าก่อนถูกคำนวณแล้วได้ 0 เสมอ
    $hasYearlyData = $yearlyDaysTotal > 0;
    // สัดส่วนของทุกร้านรวมกันคือ 100% ตามนิยาม — ห้ามบวกค่าที่ปัดแล้วทีละแถว
    // (3 ร้านกำไรเท่ากัน → 33.3% ×3 = 99.9% ซึ่งอ่านแล้วเหมือนมีอะไรหาย)
    $yearlyHasShare = false;
    foreach ($yearlyShopRows as $shopRow) {
        if (isset($shopRow['profit_share']) && $shopRow['profit_share'] !== null) {
            $yearlyHasShare = true;
            break;
        }
    }
    $yearlyShareTotal = $yearlyHasShare ? 100.0 : null;

    $yearlyBestMonth = is_array($yearlySummary['best_month'] ?? null) ? (array)$yearlySummary['best_month'] : null;
    $yearlyWorstMonth = is_array($yearlySummary['worst_month'] ?? null) ? (array)$yearlySummary['worst_month'] : null;

    // YoY รวมทุกร้าน — service เทียบช่วงเดียวกันของปีก่อนให้แล้ว
    $yearlyPrevYear = (int)($yearlySummary['prev_year'] ?? ($selectedYear - 1));
    $yearlyPrevProfit = isset($yearlySummary['prev_year_profit']) && $yearlySummary['prev_year_profit'] !== null
        ? (float)$yearlySummary['prev_year_profit']
        : null;
    $yearlyPrevHasData = ($yearlySummary['prev_year_has_data'] ?? false) === true;
    $yearlyPrevUnavailable = ($yearlySummary['prev_year_unavailable'] ?? false) === true;
    $yearlyYoyChange = isset($yearlySummary['yoy_profit_change']) && $yearlySummary['yoy_profit_change'] !== null
        ? (float)$yearlySummary['yoy_profit_change']
        : null;
    $yearlyYoyPercent = isset($yearlySummary['yoy_profit_change_percent']) && $yearlySummary['yoy_profit_change_percent'] !== null
        ? (float)$yearlySummary['yoy_profit_change_percent']
        : null;

    $yearlyYoyText = static fn(?float $percent): string => format_change_badge($percent, no_value_text())['text'];
    $yearlyYoyTone = static fn(?float $percent): string => format_change_badge($percent, no_value_text())['class'];

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
    // ⚠️ นับจากจำนวนวันที่กรอก ไม่ใช่ยอดเงิน (ดูคอมเมนต์ใน annual.php)
    $hasOverviewData = false;
    foreach ($comparisonRows as $comparisonRow) {
        if ((int)($comparisonRow['days_count'] ?? 0) > 0) {
            $hasOverviewData = true;
            break;
        }
    }

    // ⚠️ กราฟทั้งสองตัวข้ามร้านที่ยังไม่มีข้อมูล (กำไร ฿0 ของร้านที่ไม่ได้กรอกจะลอย
    // อยู่เหนือร้านที่ขาดทุนจริง อ่านว่าเป็นร้านที่ดีที่สุด) — ถูกแล้ว **แต่หัวข้อกราฟ
    // เขียนว่า "ทุกร้าน"** ผู้ใช้ที่มี 3 ร้านจึงเห็นตารางด้านบน 3 แถวคู่กับกราฟ 2 เส้น
    // โดยไม่มีอะไรอธิบาย · นับไว้เพื่อบอกใต้หัวข้อ
    foreach ($comparisonRows as $row) {
        if ((int)($row['days_count'] ?? 0) <= 0) {
            $shopsMissingFromCharts++;
        }
    }

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

                /* ⚠️⚠️ ต้องคง `null` ไว้ ห้าม cast เป็น float
                   `(float)null` = 0.0 → เดือนที่ยังไม่ได้กรอกกลับกลายเป็น "เท่าทุนพอดี"
                   อีกครั้ง **ทับตัวแก้ที่ Service ทั้งหมด** (วัดจริง: แก้ที่ Service แล้ว
                   กราฟยังลากผ่านศูนย์เหมือนเดิม เพราะบรรทัดนี้แปลงกลับ)
                   — รูปแบบที่โปรเจกต์นี้เจอซ้ำ ๆ: กติกาถูกบังคับที่หนึ่งแต่ไปไม่ถึงอีกที่ */
                $toChartValue = static fn($value): ?float => $value === null ? null : (float)$value;

                return [
                    'shop_id' => (int)($row['shop_id'] ?? 0),
                    'shop_name' => (string)($row['shop_name'] ?? 'ร้านค้า'),
                    'revenue' => array_values(array_map($toChartValue, (array)($row['revenue'] ?? []))),
                    'profit' => array_values(array_map($toChartValue, (array)($row['profit'] ?? []))),
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
                <p class="mt-2 text-sm text-slate-400">สรุปยอดรวมทุกร้านแบบรายวัน สำหรับเดือนที่เลือก</p>
            <?php elseif ($view === 'year'): ?>
                <p class="mt-2 text-sm text-slate-400">สรุปยอดรวมทุกร้านแบบรายปี พร้อมกราฟและจัดอันดับตามกำไร</p>
            <?php else: ?>
                <p class="mt-2 text-sm text-slate-400">ตารางและกราฟเปรียบเทียบทุกร้านตามเดือนที่เลือก</p>
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
        <p class="mt-4 text-sm text-slate-400">
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
            // ค่าเฉลี่ย/ดีสุด/แย่สุด คิดจาก "วันที่ทุกร้านกรอกครบ" เท่านั้น (ตัดสินไปแล้ว)
            // ป้ายต้องบอกจำนวนวันที่ใช้จริง ไม่งั้นผู้ใช้เข้าใจว่านับทุกวันในเดือน
            $dayCompleteDays = (int)($dailySummary['complete_days_count'] ?? 0);
            $dayTotalShops = (int)($dailySummary['total_shops'] ?? 0);
            $dayIncompleteDays = (int)($dailySummary['incomplete_days'] ?? 0);
            ?>

            <?php if (!$hasDailyData && $activeError === null): ?>
                <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
                    <?php /* ⚠️ ห้ามเขียน "เดือนนี้/ปีนี้" ตายตัว — ผู้ใช้เลือกดูเดือน/ปีอื่นได้
     เดิมเปิด ?month=2025-05 แล้วขึ้นว่า "เดือนนี้ยังไม่มีข้อมูล" ทั้งที่ตัวเลือก
     เดือนบนจอโชว์ 2025-05 อยู่ · แดชบอร์ดเขียนถูกอยู่แล้วว่า "ในช่วงเวลานี้" */ ?>
                    <?= e(formatThaiMonth($selectedMonth)) ?> ยังไม่มีข้อมูลรายวันของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
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

            <?php /* ⚠️ คำเตือน "N วันยังกรอกไม่ครบ" เคยอยู่ในบล็อกนี้ จึงหายไปทั้งอันเมื่อ
                     ยังไม่มีวันไหนกรอกครบเลย (ค่าเฉลี่ย/วันดีสุดเป็น null) — ซึ่งเป็นตอนที่
                     คำเตือนจำเป็นที่สุดพอดี · ย้ายออกมาไว้ข้างนอกแล้ว */ ?>
            <?php if ($dayAvgProfit !== null || $dayBest !== null): ?>
                <section class="section-card mt-4 px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-slate-400">
                        <?php if ($dayAvgProfit !== null): ?>
                            เฉลี่ยกำไร/วัน
                            <span class="font-bold <?= $dayAvgProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                <?= e(formatMoney($dayAvgProfit)) ?>
                            </span>
                            <?php if ($dayAvgRevenue !== null): ?>
                                <span class="text-xs text-slate-400">(รายได้ <?= e(formatMoney($dayAvgRevenue)) ?>)</span>
                            <?php endif; ?>
                            <?php if ($dayCompleteDays > 0): ?>
                                <span class="text-xs text-slate-400">จาก <?= e((string)$dayCompleteDays) ?> วันที่กรอกครบทุกร้านที่เริ่มบันทึกแล้ว</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($dayBest !== null): ?>
                            <?php
                            $bestDayProfit = (float)($dayBest['profit'] ?? 0);
                            $worstDayProfit = $dayWorst !== null ? (float)($dayWorst['profit'] ?? 0) : null;
                            ?>
                            <span class="text-slate-400">·</span>
                            วันกำไรดีสุด<span class="text-xs text-slate-400">(เฉพาะวันที่กรอกครบ)</span>
                            <span class="font-bold <?= $bestDayProfit < 0 ? 'text-red-400' : 'text-green-400' ?>">
                                <?= e(formatThaiDate((string)($dayBest['record_date'] ?? ''))) ?>
                                (<?= e(formatMoney($bestDayProfit)) ?>)
                            </span>
                            <?php
                            // ⚠️ "แย่สุด" ต้องเป็นคนละวันกับ "ดีสุด" ถึงจะมีความหมาย
                            // ไม่งั้นวันเดียวกันตัวเลขเดียวกันจะโผล่สองที่ ที่หนึ่งเขียว ที่หนึ่งแดง
                            // (เกิดตอนกรอกวันเดียว หรือทุกวันกำไรเท่ากันหมด)
                            $dayExtremesComparable = extremes_are_comparable($dayBest, $dayWorst, 'record_date');
                            ?>
                            <?php if ($dayExtremesComparable): ?>
                                <span class="text-slate-400">·</span>
                                แย่สุด
                                <span class="font-bold <?= ($worstDayProfit ?? 0) >= 0 ? 'text-slate-300' : 'text-red-400' ?>">
                                    <?= e(formatThaiDate((string)($dayWorst['record_date'] ?? ''))) ?>
                                    (<?= e(formatMoney($worstDayProfit ?? 0)) ?>)
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                </section>
            <?php endif; ?>

            <?php if ($dayIncompleteDays > 0 || (int)($dailySummary['missing_days'] ?? 0) > 0): ?>
                <section class="section-card mt-4 px-4 py-3">
                    <p class="text-xs text-amber-400">
                        <?php if ($dayIncompleteDays > 0): ?>
                            ⚠️ <?= e((string)$dayIncompleteDays) ?> วันที่ยังกรอกไม่ครบทุกร้านที่เริ่มบันทึกแล้ว —
                            <span class="text-slate-400">ยอดรวมของวันเหล่านั้นเทียบกับวันอื่นตรง ๆ ไม่ได้</span>
                        <?php endif; ?>
                        <?php if ((int)($dailySummary['missing_days'] ?? 0) > 0): ?>
                            <?php if ($dayIncompleteDays > 0): ?><br><?php endif; ?>
                            ⚠️ <?= e((string)(int)$dailySummary['missing_days']) ?> วันที่ผ่านมาแล้วแต่ยังไม่มีร้านไหนกรอกเลย
                        <?php endif; ?>
                    </p>
                </section>
            <?php endif; ?>

            <section class="section-card mt-6 p-4 sm:p-5">
                <h2 class="mb-3 text-base sm:text-lg font-semibold text-slate-100">ตารางรายวัน (รวมทุกร้าน)</h2>
                <div class="overflow-x-auto">
                    <table class="table-cards min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th scope="col" class="px-3 py-2">วันที่</th>
                                <th scope="col" class="px-3 py-2">ยอดขาย</th>
                                <th scope="col" class="px-3 py-2">ค่าแอด</th>
                                <th scope="col" class="px-3 py-2">กำไร</th>
                                <th scope="col" class="px-3 py-2">ROAS</th>
                                <th scope="col" class="px-3 py-2">อัตรากำไร</th>
                                <th scope="col" class="px-3 py-2">ร้านที่กรอก</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyRows as $row): ?>
                                <?php
                                $rowDate = (string)($row['record_date'] ?? '');
                                $rowShopsCount = (int)($row['shops_count'] ?? 0);
                                $rowIsComplete = ($row['is_complete'] ?? true) === true;
                                // ⚠️ ตัวหารต้องเป็น "ร้านที่เริ่มบันทึกแล้ว ณ วันนั้น" ตัวเดียวกับที่
                                // ใช้ตัดสินว่าครบไหม · เดิมใช้จำนวนร้านทั้งหมด ทำให้เห็น "1/3 ร้าน"
                                // โดยไม่มีเครื่องหมายเตือน คู่กับสรุปด้านบนที่เขียนว่า "กรอกครบทุกร้าน"
                                $rowShopsExpected = (int)($row['shops_tracked'] ?? $dayTotalShops);
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
                                        <?= e($rowShopsCount . '/' . $rowShopsExpected) ?> ร้าน<?= $rowIsComplete ? '' : ' ⚠️' ?>
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
                                <?php
                                // ⚠️ "ครบทุกวัน" ต้องนับวันที่ไม่มีใครกรอกเลยด้วย — วันพวกนั้นไม่มีแถว
                                // ในตาราง จึงไม่เคยถูกนับว่าไม่ครบ · เดิมเขียนว่า "ครบทุกวัน"
                                // ขณะที่แดชบอร์ดบนข้อมูลชุดเดียวกันเตือนว่าไม่ได้กรอกมา 4 วันแล้ว
                                $dayMissingDays = (int)($dailySummary['missing_days'] ?? 0);
                                $dayGapParts = [];
                                if ($dayIncompleteDays > 0) {
                                    $dayGapParts[] = $dayIncompleteDays . ' วันไม่ครบ';
                                }
                                if ($dayMissingDays > 0) {
                                    $dayGapParts[] = $dayMissingDays . ' วันยังไม่กรอก';
                                }
                                ?>
                                <td class="px-3 py-3 <?= $dayGapParts !== [] ? 'text-amber-400' : 'text-slate-300' ?>">
                                    <?= e($dayGapParts !== [] ? implode(' · ', $dayGapParts) : 'ครบทุกวัน') ?>
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
                    <span class="text-xs text-slate-400">รวมทั้งเดือน <?= e(formatThaiMonth($selectedMonth)) ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-cards min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th scope="col" class="px-3 py-2">อันดับ</th>
                                <th scope="col" class="px-3 py-2">ร้าน</th>
                                <th scope="col" class="px-3 py-2">ยอดขาย</th>
                                <th scope="col" class="px-3 py-2">ค่าแอด</th>
                                <th scope="col" class="px-3 py-2">กำไร</th>
                                <th scope="col" class="px-3 py-2">ROAS</th>
                                <th scope="col" class="px-3 py-2">อัตรากำไร</th>
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
                                    <td class="px-3 py-2 text-slate-400 font-semibold"><?= e((string)($index + 1)) ?></td>
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

                    // ⚠️ วันที่ยังกรอกไม่ครบทุกร้านวาดจาง ๆ — ยอดของวันนั้นต่ำโดยธรรมชาติ
                    // เทียบกับวันอื่นตรง ๆ ไม่ได้ · การ์ดสรุปตัดวันพวกนี้ออกไปแล้ว และ
                    // ข้อความเหนือกราฟก็เขียนว่าเทียบไม่ได้ เหลือกราฟตัวเดียวที่ยังทำอยู่
                    const completeFlags = chartPayload.is_complete || [];
                    const barColor = (solid) => (ctx) => (
                        completeFlags[ctx.dataIndex] === false ? solid + '55' : solid
                    );

                    new Chart(chartCanvas, {
                        type: 'bar',
                        data: {
                            labels: chartPayload.labels,
                            datasets: [{
                                    label: 'ยอดขาย',
                                    data: chartPayload.revenue,
                                    backgroundColor: barColor('#f97316')
                                },
                                {
                                    label: 'ค่าแอด',
                                    data: chartPayload.ad_cost,
                                    backgroundColor: barColor('#06b6d4')
                                },
                                {
                                    label: 'กำไร',
                                    data: chartPayload.profit,
                                    backgroundColor: barColor('#22c55e')
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

            <?php if (!$hasYearlyData && $activeError === null): ?>
                <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
                    ปี <?= e((string)($selectedYear + 543)) ?> ยังไม่มีข้อมูลยอดขายของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
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
                            <?php /* null = เทียบเป็น % ไม่ได้ ไม่ใช่ "ไม่มีข้อมูล" — ดูคอมเมนต์ใน annual.php */ ?>
                            <span class="text-base font-bold text-slate-400">
                                <?php if ($yearlyPrevUnavailable): ?>
                                    โหลดข้อมูลปีก่อนไม่สำเร็จ — เทียบให้ไม่ได้ตอนนี้
                                <?php else: ?>
                                    <?= $yearlyPrevHasData ? 'ปีก่อนเท่าทุนพอดี เทียบเป็น % ไม่ได้' : 'ไม่มีข้อมูลปีก่อน' ?>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="text-base font-bold <?= e($yearlyYoyTone($yearlyYoyPercent)) ?>">
                                กำไรรวม <?= e($yearlyYoyText($yearlyYoyPercent)) ?>
                            </span>
                            <span class="text-sm <?= e($yearlyYoyTone($yearlyYoyPercent)) ?>">
                                (<?= e((($yearlyYoyChange ?? 0) >= 0 ? '+' : '-') . formatMoney(abs($yearlyYoyChange ?? 0))) ?>)
                            </span>
                            <span class="text-xs text-slate-400">
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
                // ⚠️ ยังไม่มีเดือนที่จบ ≠ ไม่มีข้อมูล — การ์ดนี้ไม่นับเดือนปัจจุบันที่ยังไม่จบ
                // ผู้ใช้ที่เพิ่งเริ่มใช้เดือนนี้จะไม่มีเดือนไหนเข้าเกณฑ์เลย · ขีด (–) อ่านได้ว่า
                // "ข้อมูลหาย" ซึ่งไม่จริงและน่าตกใจ (ข้อความเดียวกับหน้ารายปี)
                $noFinishedMonthText = $selectedYear < (int)date('Y')
                    ? 'ไม่มีข้อมูลในปีนี้'
                    : 'รอให้จบเดือนก่อน';

                // ⚠️⚠️ "ดีสุด" กับ "แย่สุด" ต้องเป็นคนละเดือนจริง ๆ — ร้านที่มีเดือนที่จบแล้ว
                // เดือนเดียวจะเห็นเดือนเดียวกันตัวเลขเดียวกันโผล่สองที่ ที่หนึ่งเขียว ที่หนึ่งเทา
                // (กติกาเดียวกับการ์ดคู่ "วันดีสุด/แย่สุด" ซึ่งมีตัวกันอยู่แล้ว)
                $monthExtremesComparable = extremes_are_comparable($yearlyBestMonth, $yearlyWorstMonth, 'month');

                if (!$monthExtremesComparable) {
                    $bestMonthProfit = null;
                    $worstMonthProfit = null;
                }

                $singleMonthText = extremes_not_comparable_text();
                $bestMonthLabel = $monthExtremesComparable
                    ? ($thaiMonths[(int)($yearlyBestMonth['month'] ?? 0)] ?? $noFinishedMonthText)
                    : ($yearlyBestMonth !== null ? $singleMonthText : $noFinishedMonthText);
                $worstMonthLabel = $monthExtremesComparable
                    ? ($thaiMonths[(int)($yearlyWorstMonth['month'] ?? 0)] ?? $noFinishedMonthText)
                    : ($yearlyWorstMonth !== null ? $singleMonthText : $noFinishedMonthText);
                ?>
                <section class="section-card mt-4 px-4 py-3 sm:px-5">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-slate-400">
                        <span class="text-xs font-medium uppercase tracking-wider text-slate-400">รวมทุกร้าน</span>
                        เดือนกำไรดีสุด
                        <span class="font-bold <?= $bestMonthProfit !== null && $bestMonthProfit < 0 ? 'text-red-400' : 'text-green-400' ?>">
                            <?= e($bestMonthLabel) ?><?= $bestMonthProfit !== null ? ' (' . e(formatMoney($bestMonthProfit)) . ')' : '' ?>
                        </span>
                        <span class="text-slate-400">·</span>
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
                    <table class="table-cards min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th scope="col" class="px-3 py-2">เดือน</th>
                                <th scope="col" class="px-3 py-2">ยอดขาย</th>
                                <th scope="col" class="px-3 py-2">ค่าแอด</th>
                                <th scope="col" class="px-3 py-2">กำไร</th>
                                <th scope="col" class="px-3 py-2">ROAS</th>
                                <th scope="col" class="px-3 py-2">อัตรากำไร</th>
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
                    <table class="table-cards min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-left text-slate-400">
                                <th scope="col" class="px-3 py-2">อันดับ</th>
                                <th scope="col" class="px-3 py-2">ร้าน</th>
                                <th scope="col" class="px-3 py-2">ยอดขาย</th>
                                <th scope="col" class="px-3 py-2">ค่าแอด</th>
                                <th scope="col" class="px-3 py-2">กำไร</th>
                                <th scope="col" class="px-3 py-2">ROAS</th>
                                <th scope="col" class="px-3 py-2">อัตรากำไร</th>
                                <th scope="col" class="px-3 py-2">สัดส่วนกำไร</th>
                                <th scope="col" class="px-3 py-2">วันที่กรอก</th>
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
                                /* ⚠️ กติกาเดียวกับตารางเทียบร้านของมุมรายเดือน — ร้านที่ยังไม่เคยกรอก
                                   ต้องเป็นขีดทั้งแถว ไม่ใช่ ฿0 (ดูคำอธิบายเต็มที่ตารางนั้น) */
                                $rowHasData = $rowDaysCount > 0;
                                ?>
                                <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                                    <td class="px-3 py-2 text-slate-400 font-semibold"><?= e((string)($index + 1)) ?></td>
                                    <td class="px-3 py-2 text-slate-300 font-medium"><?= e((string)($row['shop_name'] ?? 'ร้านค้า')) ?></td>
                                    <td class="px-3 py-2 text-orange-400 font-medium"><?= e($rowHasData ? formatMoney($rowRevenue) : no_value_text()) ?></td>
                                    <td class="px-3 py-2 text-cyan-400 font-medium"><?= e($rowHasData ? formatMoney($rowAdCost) : no_value_text()) ?></td>
                                    <td class="px-3 py-2 <?= !$rowHasData ? 'text-slate-400' : ($rowProfit >= 0 ? 'text-green-400' : 'text-red-400') ?> font-bold"><?= e($rowHasData ? formatMoney($rowProfit) : no_value_text()) ?></td>
                                    <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                    <td class="px-3 py-2 font-medium <?= !$rowHasData || $rowProfitShare === null ? 'text-slate-400' : ($rowProfitShare < 0 ? 'text-red-400' : 'text-slate-300') ?>">
                                        <?= e(format_share_percent($rowHasData ? $rowProfitShare : null)) ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-400 font-medium"><?= e($rowDaysCount > 0 ? $rowDaysCount . ' วัน' : no_value_text()) ?></td>
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
                                <td class="px-3 py-3 text-slate-300"><?= e($yearlyShareTotal === null ? no_value_text() : formatPercent($yearlyShareTotal)) ?></td>
                                <td class="px-3 py-3 text-slate-300"><?= e($yearlyDaysTotal > 0 ? 'สูงสุด ' . $yearlyDaysTotal . ' วัน' : no_value_text()) ?></td>
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
            <?php if (!$hasOverviewData && $activeError === null): ?>
                <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
                    <?= e(formatThaiMonth($selectedMonth)) ?> ยังไม่มีข้อมูลยอดขายของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
                </div>
            <?php endif; ?>

            <div class="mt-5 overflow-x-auto">
                <table class="table-cards min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/10 text-left text-slate-400">
                            <th scope="col" class="px-3 py-1.5">อันดับ</th>
                            <th scope="col" class="px-3 py-1.5">ร้าน</th>
                            <th scope="col" class="px-3 py-1.5">ยอดขาย</th>
                            <th scope="col" class="px-3 py-1.5">ค่าแอด</th>
                            <th scope="col" class="px-3 py-1.5">กำไร</th>
                            <th scope="col" class="px-3 py-1.5">สัดส่วนกำไร</th>
                            <th scope="col" class="px-3 py-1.5">เทียบเดือนก่อน</th>
                            <th scope="col" class="px-3 py-1.5">ROAS</th>
                            <th scope="col" class="px-3 py-1.5">อัตรากำไร</th>
                            <th scope="col" class="px-3 py-1.5">วันที่กรอก</th>
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
                            /* ⚠️⚠️ ร้านที่ยังไม่เคยกรอกเลย = "ยังไม่รู้" ไม่ใช่ "ทำได้ ฿0"
                               กติกานี้ถูกบังคับใช้กับ **การเรียงลำดับ** แล้ว (ร้านที่ไม่มีข้อมูลไปท้ายตาราง)
                               แต่เดิมไปไม่ถึง **ค่าที่พิมพ์ในช่อง** — แถวเดียวกันจึงใช้กติกาสองแบบ:
                               ROAS/อัตรากำไร ตอบ `–` ถูกแล้ว แต่ยอดขาย/ค่าแอด/กำไร/สัดส่วน ตอบเป็นเลข
                               · ตารางนี้คือเครื่องมือตัดสินว่า "ร้านไหนคุ้ม" — คนอ่านเทียบ
                                 "ร้าน C กำไร ฿0" กับ "ร้าน D ขาดทุน ฿-5,000" จะสรุปว่า C ดีกว่า
                                 ทั้งที่ C แค่ยังไม่มีข้อมูล · คอลัมน์ "วันที่กรอก" ที่เขียน 0 วัน
                                 เป็นตัวอธิบายว่าทำไมทั้งแถวเป็นขีด */
                            $rowHasData = (int)($row['days_count'] ?? 0) > 0;
                            ?>
                            <tr class="border-b border-white/[0.06] table-row-hover">
                                <td class="px-3 py-1.5 text-slate-400"><?= e((string)($rowIndex + 1)) ?></td>
                                <td class="px-3 py-1.5 text-slate-300 font-medium"><?= e((string)($row['shop_name'] ?? 'ร้านค้า')) ?></td>
                                <td class="px-3 py-1.5 text-orange-400 font-medium"><?= e($rowHasData ? formatMoney($rowRevenue) : no_value_text()) ?></td>
                                <td class="px-3 py-1.5 text-cyan-400 font-medium"><?= e($rowHasData ? formatMoney($rowAdCost) : no_value_text()) ?></td>
                                <td class="px-3 py-1.5 <?= !$rowHasData ? 'text-slate-400' : ($rowProfit >= 0 ? 'text-green-400' : 'text-red-400') ?> font-bold"><?= e($rowHasData ? formatMoney($rowProfit) : no_value_text()) ?></td>
                                <td class="px-3 py-1.5 font-medium <?= $rowHasData && $rowProfitShare !== null && $rowProfitShare < 0 ? 'text-red-400' : 'text-slate-300' ?>">
                                    <?= e(format_share_percent($rowHasData ? $rowProfitShare : null)) ?>
                                </td>
                                <td class="px-3 py-1.5 font-medium">
                                    <?php
                                    // ⚠️⚠️ ห้ามเขียน "ใหม่" ตายตัว — % เป็น null ได้ 2 กรณี
                                    // วัดจริง: ร้านที่กรอกทุกวันมา 2 ปีครึ่ง แต่เดือนก่อนบังเอิญเท่าทุน
                                    // พอดี ถูกป้ายว่า "ใหม่" พร้อม tooltip "ไม่มีข้อมูลเดือนก่อน"
                                    // ซึ่งไม่จริงทั้งคู่ · ร้านเก่าที่แค่เว้นไม่กรอกเดือนก่อนก็โดนเหมือนกัน
                                    $rowPrevHasData = ($row['prev_has_data'] ?? false) === true;
                                    $rowNoCompareReason = $rowPrevHasData
                                        ? 'เดือนก่อนเท่าทุนพอดี เทียบเป็น % ไม่ได้'
                                        : 'ไม่มีข้อมูลเดือนก่อน';
                                    $rowChangeBadge = format_change_badge($rowChangePercent, '–');
                                    ?>
                                    <?php if ($rowChangePercent === null): ?>
                                        <span class="text-slate-400" title="<?= e($rowNoCompareReason) ?>"><?= e($rowChangeBadge['text']) ?></span>
                                    <?php else: ?>
                                        <span class="<?= e($rowChangeBadge['class']) ?>"><?= e($rowChangeBadge['text']) ?></span>
                                        <span class="text-xs text-slate-400">(<?= e(formatMoney($rowChange)) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-1.5 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                                <td class="px-3 py-1.5 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                                <td class="px-3 py-1.5 text-xs text-slate-400"><?= e((string)(int)($row['days_count'] ?? 0)) ?> วัน</td>
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
                            <td class="px-3 py-2 text-slate-400"><?= e(no_value_text()) ?></td>
                            <td class="px-3 py-2 text-slate-400"><?= e(no_value_text()) ?></td>
                            <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($totalRoas)) ?></td>
                            <td class="px-3 py-2 text-slate-300"><?= e(formatPercent($totalProfitMargin)) ?></td>
                            <td class="px-3 py-2 text-slate-400"><?= e(no_value_text()) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <section class="section-card mt-6 p-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-slate-100">กราฟแท่งเปรียบเทียบระหว่างร้าน</h2>
                    <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร</span>
                </div>
                <?php if ($shopsMissingFromCharts > 0): ?>
                    <p class="mb-3 text-xs text-slate-400">
                        ไม่ได้วาด <?= e((string)$shopsMissingFromCharts) ?> ร้านที่ยังไม่มีข้อมูลในช่วงนี้ (อยู่ท้ายตารางด้านบน)
                    </p>
                <?php endif; ?>
                <div class="h-80 w-full overflow-x-auto">
                    <div style="min-width: <?= max(100, count($barPayload['labels']) * 120) ?>px; height: 100%;">
                        <canvas id="overview-bar-chart" role="img" aria-label="กราฟแท่งเปรียบเทียบกำไรของแต่ละร้าน — ตัวเลขชุดเดียวกันอยู่ในตารางเทียบร้านด้านบน"></canvas>
                    </div>
                </div>
            </section>

            <section class="section-card mt-6 p-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-slate-100">กราฟเส้นแนวโน้มรายร้าน (6 เดือนย้อนหลัง)</h2>
                    <span class="text-xs text-slate-400">เส้นแต่ละร้าน = กำไรรายเดือน</span>
                </div>
                <?php if ($shopsMissingFromCharts > 0): ?>
                    <p class="mb-3 text-xs text-slate-400">
                        ไม่ได้วาด <?= e((string)$shopsMissingFromCharts) ?> ร้านที่ยังไม่มีข้อมูลในช่วงนี้ (อยู่ท้ายตารางด้านบน)
                    </p>
                <?php endif; ?>
                <div class="h-80">
                    <canvas id="overview-trend-chart" role="img" aria-label="กราฟเส้นแนวโน้มกำไรของแต่ละร้าน — ตัวเลขชุดเดียวกันอยู่ในตารางเทียบร้านด้านบน"></canvas>
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
                        // ⚠️ ระบบให้สร้างได้ถึง 20 ร้าน — มีแค่ 6 สีแล้ววนซ้ำ ทำให้ร้านที่ 7
                        // ได้สีเดียวกับร้านที่ 1 เป๊ะ ๆ แยกไม่ออกว่าเส้นไหนของร้านไหน
                        // (คำอธิบายสัญลักษณ์ก็เป็นสี่เหลี่ยมสีเดียวกันสองอัน)
                        const colors = [
                            '#f97316', '#06b6d4', '#22c55e', '#eab308', '#a78bfa', '#f43f5e',
                            '#14b8a6', '#f472b6', '#84cc16', '#38bdf8', '#fb923c', '#c084fc',
                            '#4ade80', '#facc15', '#2dd4bf', '#fb7185', '#60a5fa', '#a3e635',
                            '#e879f9', '#fbbf24',
                        ];
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