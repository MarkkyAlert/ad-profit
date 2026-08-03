<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$selectedYearRaw = isset($_GET['year']) ? trim((string)$_GET['year']) : date('Y');
if (preg_match('/^\d{4}$/', $selectedYearRaw) !== 1) {
    $selectedYearRaw = date('Y');
}

$selectedYear = (int)$selectedYearRaw;
if ($selectedYear >= 2400 && $selectedYear <= 2700) {
    $selectedYear -= 543;
}

$currentYear = (int)date('Y');
if ($selectedYear < 2000 || $selectedYear > 2100) {
    $selectedYear = $currentYear;
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$goalRepository = new GoalRepository($pdo);
$annualService = new AnnualService($recordRepository, $shopRepository, $goalRepository);

$annualResult = $annualService->buildYearlySummary($userId, $shopId, $selectedYear);

$zeroMonths = [];
for ($month = 1; $month <= 12; $month++) {
    $zeroMonths[] = [
        'month' => $month,
        'month_key' => sprintf('%04d-%02d', $selectedYear, $month),
        'total_revenue' => 0.0,
        'total_ad_cost' => 0.0,
        'profit' => 0.0,
        'roas' => null,
        'profit_margin' => null,
        'days_count' => 0,
        'profit_per_day' => null,
        'prev_year_profit' => 0.0,
        'yoy_change_percent' => null,
    ];
}

$annualError = null;
$annualData = [
    'year' => $selectedYear,
    'months' => $zeroMonths,
    'goal_progress' => [],
    'summary' => [
        'total_revenue' => 0.0,
        'total_ad_cost' => 0.0,
        'profit' => 0.0,
        'roas' => null,
        'profit_margin' => null,
        'months_with_data' => 0,
        'profit_months' => 0,
        'loss_months' => 0,
        'best_month' => $zeroMonths[0],
        'worst_month' => $zeroMonths[0],
        'prev_year' => $selectedYear - 1,
        'prev_year_profit' => 0.0,
        'yoy_profit_change' => 0.0,
        'yoy_profit_change_percent' => null,
    ],
    'chart' => [
        'months' => array_values(array_map(static fn(array $row): string => (string)$row['month_key'], $zeroMonths)),
        'revenue' => array_fill(0, 12, 0.0),
        'ad_cost' => array_fill(0, 12, 0.0),
        'profit' => array_fill(0, 12, 0.0),
        'prev_profit' => array_fill(0, 12, 0.0),
        'cumulative_profit' => array_fill(0, 12, 0.0),
        'prev_cumulative_profit' => array_fill(0, 12, 0.0),
    ],
];

if (($annualResult['success'] ?? false) === true) {
    // ใช้ array_replace (ไม่ recursive) — service เป็นเจ้าของจำนวนเดือนที่ควรแสดง
    // ถ้า merge แบบ recursive เดือนอนาคตจาก default 12 เดือนจะรอดมาเป็น ฿0
    $annualData = array_replace($annualData, (array)($annualResult['data'] ?? []));
} else {
    $annualError = (string)($annualResult['error'] ?? 'ไม่สามารถโหลดข้อมูลสรุปประจำปีได้');
}

$months = array_values((array)($annualData['months'] ?? []));
$summary = (array)($annualData['summary'] ?? []);

$totalRevenue = (float)($summary['total_revenue'] ?? 0);
$totalAdCost = (float)($summary['total_ad_cost'] ?? 0);
$totalProfit = (float)($summary['profit'] ?? ($totalRevenue - $totalAdCost));
$totalRoas = isset($summary['roas']) && $summary['roas'] !== null ? (float)$summary['roas'] : null;
$totalProfitMargin = isset($summary['profit_margin']) && $summary['profit_margin'] !== null
    ? (float)$summary['profit_margin']
    : null;

$monthsWithData = (int)($summary['months_with_data'] ?? 0);
$profitMonths = (int)($summary['profit_months'] ?? 0);
$lossMonths = (int)($summary['loss_months'] ?? 0);
$breakEvenMonths = max(0, $monthsWithData - $profitMonths - $lossMonths);
$hasAnnualData = abs($totalRevenue) > 0.00001 || abs($totalAdCost) > 0.00001;

$bestMonth = is_array($summary['best_month'] ?? null) ? (array)$summary['best_month'] : null;
$worstMonth = is_array($summary['worst_month'] ?? null) ? (array)$summary['worst_month'] : null;

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

// แสดง "กำไร" ของเดือนดี/แย่สุด (จัดอันดับด้วยกำไรแล้ว ไม่ใช่รายได้)
$bestMonthText = '–';
$bestMonthProfit = null;
if ($bestMonth !== null) {
    $bestMonthProfit = (float)($bestMonth['profit'] ?? 0);
    $bestMonthText = $monthLabel($bestMonth) . ' (' . formatMoney($bestMonthProfit) . ')';
}

$worstMonthText = '–';
$worstMonthProfit = null;
if ($worstMonth !== null) {
    $worstMonthProfit = (float)($worstMonth['profit'] ?? 0);
    $worstMonthText = $monthLabel($worstMonth) . ' (' . formatMoney($worstMonthProfit) . ')';
}

// เป้ารายเดือน — service ใส่มาเฉพาะเดือนที่ตั้งเป้าไว้ และไม่เกิน cutoff
$goalProgress = array_values(array_filter(
    (array)($annualData['goal_progress'] ?? []),
    static fn($row): bool => is_array($row)
));

$totalDaysCount = array_sum(array_map(static fn(array $row): int => (int)($row['days_count'] ?? 0), $months));
$totalProfitPerDay = $totalDaysCount > 0 ? round($totalProfit / $totalDaysCount, 2) : null;

// YoY เทียบช่วงเดียวกันของปีก่อน (service ตัดเดือนอนาคตให้แล้ว)
$prevYear = (int)($summary['prev_year'] ?? ($selectedYear - 1));
$prevYearProfit = (float)($summary['prev_year_profit'] ?? 0);
$yoyChange = (float)($summary['yoy_profit_change'] ?? 0);
$yoyPercent = isset($summary['yoy_profit_change_percent']) && $summary['yoy_profit_change_percent'] !== null
    ? (float)$summary['yoy_profit_change_percent']
    : null;

// แสดง % การเปลี่ยนแปลง — null (ไม่มีฐานให้เทียบ) ต้องไม่กลายเป็น 0%
$formatYoyPercent = static function (?float $percent): string {
    if ($percent === null) {
        return '—';
    }

    $arrow = $percent > 0 ? '↑' : ($percent < 0 ? '↓' : '');
    return $arrow . number_format(abs($percent), 1) . '%';
};

$yoyToneClass = static function (?float $percent): string {
    if ($percent === null || abs($percent) < 0.00001) {
        return 'text-slate-400';
    }

    return $percent > 0 ? 'text-green-400' : 'text-red-400';
};

$chartRaw = (array)($annualData['chart'] ?? []);
$chartLabels = array_map(
    static function (string $monthKey) use ($thaiMonths): string {
        $monthNumber = (int)substr($monthKey, 5, 2);
        return $thaiMonths[$monthNumber] ?? $monthKey;
    },
    array_values((array)($chartRaw['months'] ?? []))
);

$chartPayload = [
    'labels' => $chartLabels,
    'revenue' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['revenue'] ?? []))),
    'ad_cost' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['ad_cost'] ?? []))),
    'profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['profit'] ?? []))),
    'prev_profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['prev_profit'] ?? []))),
    'cumulative_profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['cumulative_profit'] ?? []))),
    'prev_cumulative_profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($chartRaw['prev_cumulative_profit'] ?? []))),
    'prev_year_label' => (string)($prevYear + 543),
];

$availableYears = [];
for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
    $availableYears[] = $year;
}

if (!in_array($selectedYear, $availableYears, true)) {
    $availableYears[] = $selectedYear;
    rsort($availableYears);
}

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'สรุปประจำปี';
$currentPage = 'annual';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between sm:gap-4">
        <div>
            <h1 class="text-lg sm:text-xl font-semibold text-slate-100">สรุปประจำปี</h1>
            <p class="mt-1 text-xs sm:text-sm text-slate-500">ภาพรวมรายปีของร้านที่เลือก พร้อมตารางรายเดือน เทียบปีก่อน และกราฟเปรียบเทียบ</p>
        </div>

        <form method="get" action="<?= e(app_url('/annual.php')) ?>" class="flex flex-wrap items-center gap-2">
            <label for="annual-year" class="text-sm text-slate-400">เลือกปี (พ.ศ.)</label>
            <select id="annual-year" name="year" class="rounded-xl px-3 py-2 text-sm transition-all">
                <?php foreach ($availableYears as $year): ?>
                    <option value="<?= e((string)$year) ?>" <?= $year === $selectedYear ? 'selected' : '' ?>>
                        <?= e((string)($year + 543)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-ghost px-4 py-2 text-sm">แสดงผล</button>
        </form>
    </div>

    <?php if ($annualError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-sm text-red-400">
            <?= e($annualError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasAnnualData): ?>
        <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
            ปีนี้ยังไม่มีข้อมูลยอดขาย ลองเริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
        </div>
    <?php endif; ?>
</section>

<section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <article class="stat-card s-revenue">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ยอดขายรวมทั้งปี</p>
        <p class="mt-2 text-lg sm:text-xl font-bold text-orange-400"><?= e(formatMoney($totalRevenue)) ?></p>
    </article>
    <article class="stat-card s-adcost">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ค่าแอดรวมทั้งปี</p>
        <p class="mt-2 text-lg sm:text-xl font-bold text-cyan-400"><?= e(formatMoney($totalAdCost)) ?></p>
    </article>
    <article class="stat-card s-profit">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">กำไรรวมทั้งปี</p>
        <p class="mt-2 text-lg sm:text-xl font-bold <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></p>
    </article>
    <article class="stat-card s-roas">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ROAS เฉลี่ยทั้งปี</p>
        <p class="mt-2 text-lg sm:text-xl font-bold text-violet-400"><?= e(formatRoas($totalRoas)) ?></p>
    </article>
    <article class="stat-card s-best">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">เดือนกำไรดีสุด</p>
        <p class="mt-2 text-base font-bold <?= $bestMonthProfit !== null && $bestMonthProfit < 0 ? 'text-red-400' : 'text-green-400' ?>">
            <?= e($bestMonthText) ?>
        </p>
    </article>
    <article class="stat-card s-worst">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">เดือนกำไรแย่สุด</p>
        <p class="mt-2 text-base font-bold <?= $worstMonthProfit !== null && $worstMonthProfit >= 0 ? 'text-slate-300' : 'text-red-400' ?>">
            <?= e($worstMonthText) ?>
        </p>
    </article>
</section>

<section class="section-card mt-4 px-4 py-3 sm:px-5">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
            เทียบ <?= e((string)($prevYear + 543)) ?>
            <?php if (count($months) > 0 && count($months) < 12): ?>
                (ม.ค.–<?= e($thaiMonths[count($months)] ?? '') ?>)
            <?php endif; ?>
        </span>
        <?php if ($yoyPercent === null): ?>
            <span class="text-base font-bold text-slate-400">ไม่มีข้อมูลปีก่อน</span>
        <?php else: ?>
            <span class="text-base font-bold <?= e($yoyToneClass($yoyPercent)) ?>">
                กำไร <?= e($formatYoyPercent($yoyPercent)) ?>
            </span>
            <span class="text-sm <?= e($yoyToneClass($yoyPercent)) ?>">
                (<?= e(($yoyChange >= 0 ? '+' : '-') . formatMoney(abs($yoyChange))) ?>)
            </span>
            <span class="text-xs text-slate-500">
                ปีก่อน <?= e(formatMoney($prevYearProfit)) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (count($months) > 0): ?>
        <div class="mt-2 border-t border-white/[0.06] pt-2 text-sm text-slate-400">
            กำไรสะสม ณ <?= e($thaiMonths[count($months)] ?? '') ?>
            <span class="font-bold <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></span>
            <span class="text-slate-600">·</span>
            ปีก่อนช่วงเดียวกัน <span class="font-medium text-slate-300"><?= e(formatMoney($prevYearProfit)) ?></span>
            <?php if ($yoyPercent !== null): ?>
                <span class="font-medium <?= e($yoyToneClass($yoyPercent)) ?>">
                    (<?= e($yoyChange >= 0 ? 'นำอยู่ ' : 'ตามอยู่ ') ?><?= e(formatMoney(abs($yoyChange))) ?>)
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($monthsWithData > 0): ?>
        <div class="mt-2 border-t border-white/[0.06] pt-2 text-sm text-slate-400">
            <span class="font-medium text-slate-300"><?= e((string)$monthsWithData) ?></span> เดือนมีข้อมูล
            <span class="text-slate-600">·</span>
            กำไร <span class="font-medium text-green-400"><?= e((string)$profitMonths) ?></span>
            <span class="text-slate-600">/</span>
            ขาดทุน <span class="font-medium <?= $lossMonths > 0 ? 'text-red-400' : 'text-slate-300' ?>"><?= e((string)$lossMonths) ?></span>
            <?php if ($breakEvenMonths > 0): ?>
                <span class="text-slate-600">·</span>
                เท่าทุน <span class="font-medium text-slate-300"><?= e((string)$breakEvenMonths) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (count($goalProgress) > 0): ?>
    <section class="section-card mt-6 p-4 sm:p-5">
        <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
            <h2 class="text-base sm:text-lg font-semibold text-slate-100">🎯 เป้าหมายรายเดือน</h2>
            <span class="text-xs text-slate-400">เฉพาะเดือนที่ตั้งเป้าไว้ (<?= e((string)count($goalProgress)) ?> เดือน)</span>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($goalProgress as $goalRow): ?>
                <?php
                $goalMonthLabel = $monthLabel($goalRow);
                $goalTargetRevenue = isset($goalRow['target_revenue']) && $goalRow['target_revenue'] !== null
                    ? (float)$goalRow['target_revenue']
                    : null;
                $goalTargetProfit = isset($goalRow['target_profit']) && $goalRow['target_profit'] !== null
                    ? (float)$goalRow['target_profit']
                    : null;
                $goalActualRevenue = (float)($goalRow['actual_revenue'] ?? 0);
                $goalActualProfit = (float)($goalRow['actual_profit'] ?? 0);
                ?>
                <article class="rounded-xl border border-white/10 bg-white/[0.02] px-3 py-3">
                    <p class="text-sm font-semibold text-slate-200"><?= e($goalMonthLabel) ?></p>

                    <?php if ($goalTargetRevenue !== null): ?>
                        <?php
                        $revenueReached = ($goalRow['revenue_reached'] ?? false) === true;
                        $revenueProgress = isset($goalRow['revenue_progress']) && $goalRow['revenue_progress'] !== null
                            ? (float)$goalRow['revenue_progress']
                            : null;
                        ?>
                        <p class="mt-2 text-xs text-slate-400">รายได้</p>
                        <p class="text-sm font-medium <?= $revenueReached ? 'text-green-400' : 'text-amber-400' ?>">
                            <?= e($revenueReached ? '✓ ' : '') ?><?= e(formatMoney($goalActualRevenue)) ?>
                            <span class="text-slate-500">/ <?= e(formatMoney($goalTargetRevenue)) ?></span>
                            <?php if ($revenueProgress !== null): ?>
                                (<?= e(number_format($revenueProgress, 1)) ?>%)
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($goalTargetProfit !== null): ?>
                        <?php
                        $profitReached = ($goalRow['profit_reached'] ?? false) === true;
                        $profitProgress = isset($goalRow['profit_progress']) && $goalRow['profit_progress'] !== null
                            ? (float)$goalRow['profit_progress']
                            : null;
                        // กำไรติดลบต้องเด่นเป็นแดง แม้จะยังไม่ถึงเป้าเหมือนกัน
                        $profitToneClass = $goalActualProfit < 0
                            ? 'text-red-400'
                            : ($profitReached ? 'text-green-400' : 'text-amber-400');
                        ?>
                        <p class="mt-2 text-xs text-slate-400">กำไร</p>
                        <p class="text-sm font-medium <?= e($profitToneClass) ?>">
                            <?= e($profitReached ? '✓ ' : '') ?><?= e(formatMoney($goalActualProfit)) ?>
                            <span class="text-slate-500">/ <?= e(formatMoney($goalTargetProfit)) ?></span>
                            <?php if ($profitProgress !== null): ?>
                                (<?= e(number_format($profitProgress, 1)) ?>%)
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="section-card mt-6 p-4 sm:p-5">
    <h2 class="mb-3 text-base sm:text-lg font-semibold text-slate-100">ตารางเทียบรายเดือน (<?= e((string)count($months)) ?> เดือน)</h2>
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
                    <th class="px-3 py-2">วันที่กรอก</th>
                    <th class="px-3 py-2">กำไร/วัน</th>
                    <th class="px-3 py-2">เทียบ <?= e((string)($prevYear + 543)) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($months as $row): ?>
                    <?php
                    $rowRevenue = (float)($row['total_revenue'] ?? 0);
                    $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                    $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                    $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                    $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                    $rowDaysCount = (int)($row['days_count'] ?? 0);
                    $rowProfitPerDay = isset($row['profit_per_day']) && $row['profit_per_day'] !== null
                        ? (float)$row['profit_per_day']
                        : null;
                    $rowYoyPercent = isset($row['yoy_change_percent']) && $row['yoy_change_percent'] !== null
                        ? (float)$row['yoy_change_percent']
                        : null;
                    ?>
                    <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                        <td class="px-3 py-2 text-slate-300 font-medium"><?= e($monthLabel($row)) ?></td>
                        <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($rowRevenue)) ?></td>
                        <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($rowAdCost)) ?></td>
                        <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($rowProfit)) ?></td>
                        <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                        <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                        <td class="px-3 py-2 text-slate-400 font-medium"><?= e($rowDaysCount > 0 ? $rowDaysCount . ' วัน' : '—') ?></td>
                        <td class="px-3 py-2 font-medium <?= $rowProfitPerDay === null ? 'text-slate-500' : ($rowProfitPerDay >= 0 ? 'text-green-400' : 'text-red-400') ?>">
                            <?= e($rowProfitPerDay === null ? '—' : formatMoney($rowProfitPerDay)) ?>
                        </td>
                        <td class="px-3 py-2 font-medium <?= e($yoyToneClass($rowYoyPercent)) ?>"><?= e($formatYoyPercent($rowYoyPercent)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t border-white/10 bg-white/[0.03] font-semibold">
                    <td class="px-3 py-3 text-slate-200">รวมทั้งปี</td>
                    <td class="px-3 py-3 text-orange-400"><?= e(formatMoney($totalRevenue)) ?></td>
                    <td class="px-3 py-3 text-cyan-400"><?= e(formatMoney($totalAdCost)) ?></td>
                    <td class="px-3 py-3 <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></td>
                    <td class="px-3 py-3 text-violet-400"><?= e(formatRoas($totalRoas)) ?></td>
                    <td class="px-3 py-3 text-slate-300"><?= e(formatPercent($totalProfitMargin)) ?></td>
                    <td class="px-3 py-3 text-slate-300"><?= e($totalDaysCount > 0 ? $totalDaysCount . ' วัน' : '—') ?></td>
                    <td class="px-3 py-3 <?= $totalProfitPerDay === null ? 'text-slate-500' : ($totalProfitPerDay >= 0 ? 'text-green-400' : 'text-red-400') ?>">
                        <?= e($totalProfitPerDay === null ? '—' : formatMoney($totalProfitPerDay)) ?>
                    </td>
                    <td class="px-3 py-3 <?= e($yoyToneClass($yoyPercent)) ?>"><?= e($formatYoyPercent($yoyPercent)) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

<section class="section-card mt-6 p-4 sm:p-5">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
        <h2 class="text-base sm:text-lg font-semibold text-slate-100">กราฟแท่งรายเดือน (<?= e((string)count($months)) ?> เดือน)</h2>
        <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร · เส้นประ = กำไรปีก่อน</span>
    </div>
    <div class="h-52 sm:h-64 lg:h-80 w-full overflow-x-auto">
        <div style="min-width: 600px; height: 100%;">
            <canvas id="annual-bar-chart"></canvas>
        </div>
    </div>
</section>

<section class="section-card mt-6 p-4 sm:p-5">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
        <h2 class="text-base sm:text-lg font-semibold text-slate-100">กำไรสะสม ปีนี้ vs ปีก่อน</h2>
        <span class="text-xs text-slate-400">ช่วงเดียวกัน · เส้นห่างกันมาก = ทิ้งห่าง/ตามหลัง</span>
    </div>
    <div class="h-52 sm:h-64 w-full overflow-x-auto">
        <div style="min-width: 600px; height: 100%;">
            <canvas id="annual-cumulative-chart"></canvas>
        </div>
    </div>
</section>

<script>
    (function() {
        const chartPayload = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const chartCanvas = document.getElementById('annual-bar-chart');

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
                    },
                    {
                        // เส้นประกำไรปีก่อน — เทียบรูปทรงฤดูกาล ไม่ใช่แค่ตัวเลขรวม
                        type: 'line',
                        label: 'กำไรปีก่อน (' + chartPayload.prev_year_label + ')',
                        data: chartPayload.prev_profit,
                        borderColor: '#94a3b8',
                        backgroundColor: '#94a3b8',
                        borderDash: [6, 4],
                        borderWidth: 2,
                        pointRadius: 3,
                        tension: 0.25,
                        fill: false
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

        const cumulativeCanvas = document.getElementById('annual-cumulative-chart');

        if (!cumulativeCanvas) {
            return;
        }

        new Chart(cumulativeCanvas, {
            type: 'line',
            data: {
                labels: chartPayload.labels,
                datasets: [{
                        label: 'กำไรสะสมปีนี้',
                        data: chartPayload.cumulative_profit,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.12)',
                        borderWidth: 2,
                        pointRadius: 3,
                        tension: 0.25,
                        fill: true
                    },
                    {
                        label: 'กำไรสะสมปีก่อน (' + chartPayload.prev_year_label + ')',
                        data: chartPayload.prev_cumulative_profit,
                        borderColor: '#94a3b8',
                        backgroundColor: '#94a3b8',
                        borderDash: [6, 4],
                        borderWidth: 2,
                        pointRadius: 3,
                        tension: 0.25,
                        fill: false
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
<?php require __DIR__ . '/includes/footer.php'; ?>