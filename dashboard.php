<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);
$selectedRangeType = (string)($_GET['range'] ?? 'month_this');
$customStartDateInput = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : null;
$customEndDateInput = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : null;

if ($customStartDateInput === '') {
    $customStartDateInput = null;
}

if ($customEndDateInput === '') {
    $customEndDateInput = null;
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$goalRepository = new GoalRepository($pdo);
$dashboardService = new DashboardService($recordRepository, $shopRepository, $goalRepository);

$dashboardResult = $dashboardService->buildDashboard(
    $userId,
    $shopId,
    $selectedRangeType,
    $customStartDateInput,
    $customEndDateInput
);

$dashboardError = null;

if (($dashboardResult['success'] ?? false) !== true) {
    $dashboardError = (string)($dashboardResult['error'] ?? 'ไม่สามารถโหลดข้อมูลแดชบอร์ดได้');
    $dashboardResult = $dashboardService->buildDashboard($userId, $shopId, 'month_this', null, null);
}

$dashboardData = [
    'range' => [
        'type' => 'month_this',
        'label' => 'เดือนนี้',
        'start_date' => date('Y-m-01'),
        'end_date' => date('Y-m-t'),
        'is_monthly' => true,
        'selected_month' => date('Y-m'),
        'previous_month' => date('Y-m', strtotime('-1 month')),
        'custom_start_date' => null,
        'custom_end_date' => null,
    ],
    'summary' => [
        'total_revenue' => 0.0,
        'total_ad_cost' => 0.0,
        'profit' => 0.0,
        'roas' => null,
    ],
    'statistics' => [
        'avg_revenue_per_day' => null,
        'profit_margin' => null,
        'best_day' => null,
        'worst_day' => null,
        'days_count' => 0,
    ],
    'comparison' => [
        'enabled' => false,
        'selected_month' => null,
        'previous_month' => null,
        'change' => [
            'total_revenue' => null,
            'total_ad_cost' => null,
            'profit' => null,
            'roas' => null,
        ],
    ],
    'goal' => [
        'has_goal' => false,
        'goal_month' => date('Y-m'),
        'target_revenue' => null,
        'target_profit' => null,
        'actual_revenue' => 0.0,
        'actual_profit' => 0.0,
        'progress_revenue' => null,
        'progress_profit' => null,
        'revenue_reached' => false,
        'profit_reached' => false,
        'is_achieved' => false,
    ],
    'charts' => [
        'daily' => [
            'dates' => [],
            'revenue' => [],
            'ad_cost' => [],
            'profit' => [],
        ],
        'six_months' => [
            'months' => [],
            'revenue' => [],
            'ad_cost' => [],
            'profit' => [],
        ],
    ],
];

if (($dashboardResult['success'] ?? false) === true) {
    $dashboardData = array_replace_recursive($dashboardData, (array)$dashboardResult['data']);
}

$rangeData = (array)($dashboardData['range'] ?? []);
$summary = (array)($dashboardData['summary'] ?? []);
$statistics = (array)($dashboardData['statistics'] ?? []);
$comparison = (array)($dashboardData['comparison'] ?? []);
$goalData = (array)($dashboardData['goal'] ?? []);

$dailyChartRaw = (array)($dashboardData['charts']['daily'] ?? []);
$dailyDates = array_values((array)($dailyChartRaw['dates'] ?? []));
$dailyChartPayload = [
    'labels' => array_map(static fn(string $date): string => formatThaiDate($date), $dailyDates),
    'dates' => $dailyDates,
    'revenue' => array_values(array_map(static fn($value): float => (float)$value, (array)($dailyChartRaw['revenue'] ?? []))),
    'ad_cost' => array_values(array_map(static fn($value): float => (float)$value, (array)($dailyChartRaw['ad_cost'] ?? []))),
    'profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($dailyChartRaw['profit'] ?? []))),
];

$sixMonthRaw = (array)($dashboardData['charts']['six_months'] ?? []);
$sixMonthKeys = array_values((array)($sixMonthRaw['months'] ?? []));
$sixMonthPayload = [
    'labels' => array_map(static fn(string $month): string => formatThaiMonth($month), $sixMonthKeys),
    'months' => $sixMonthKeys,
    'revenue' => array_values(array_map(static fn($value): float => (float)$value, (array)($sixMonthRaw['revenue'] ?? []))),
    'ad_cost' => array_values(array_map(static fn($value): float => (float)$value, (array)($sixMonthRaw['ad_cost'] ?? []))),
    'profit' => array_values(array_map(static fn($value): float => (float)$value, (array)($sixMonthRaw['profit'] ?? []))),
];

$comparisonChanges = (array)($comparison['change'] ?? []);
$comparisonEnabled = (bool)($comparison['enabled'] ?? false);

$comparisonMeta = static function (?float $value): array {
    if ($value === null) {
        return [
            'text' => 'เทียบเดือนก่อน: –',
            'class' => 'text-slate-400',
        ];
    }

    $isUp = $value >= 0;
    $arrow = $isUp ? '↑' : '↓';
    $sign = $value > 0 ? '+' : '';

    return [
        'text' => 'เทียบเดือนก่อน: ' . $arrow . ' ' . $sign . number_format($value, 1) . '%',
        'class' => $isUp ? 'text-green-400' : 'text-red-400',
    ];
};

$comparisonText = [
    'total_revenue' => $comparisonMeta(isset($comparisonChanges['total_revenue']) ? (is_null($comparisonChanges['total_revenue']) ? null : (float)$comparisonChanges['total_revenue']) : null),
    'total_ad_cost' => $comparisonMeta(isset($comparisonChanges['total_ad_cost']) ? (is_null($comparisonChanges['total_ad_cost']) ? null : (float)$comparisonChanges['total_ad_cost']) : null),
    'profit' => $comparisonMeta(isset($comparisonChanges['profit']) ? (is_null($comparisonChanges['profit']) ? null : (float)$comparisonChanges['profit']) : null),
    'roas' => $comparisonMeta(isset($comparisonChanges['roas']) ? (is_null($comparisonChanges['roas']) ? null : (float)$comparisonChanges['roas']) : null),
];

$formatDayMetric = static function ($day): string {
    if (!is_array($day)) {
        return '–';
    }

    $recordDate = (string)($day['record_date'] ?? '');
    if ($recordDate === '') {
        return '–';
    }

    $revenue = (float)($day['revenue'] ?? 0);

    return formatThaiDate($recordDate) . ' (' . formatMoney($revenue) . ')';
};

$selectedRange = (string)($rangeData['type'] ?? 'month_this');
$selectedMonth = is_string($comparison['selected_month'] ?? null) ? (string)$comparison['selected_month'] : '';
$previousMonth = is_string($comparison['previous_month'] ?? null) ? (string)$comparison['previous_month'] : '';

$rangeStart = (string)($rangeData['start_date'] ?? date('Y-m-01'));
$rangeEnd = (string)($rangeData['end_date'] ?? date('Y-m-t'));
$customStart = (string)($rangeData['custom_start_date'] ?? '');
$customEnd = (string)($rangeData['custom_end_date'] ?? '');

$goalMonth = is_string($goalData['goal_month'] ?? null) ? (string)$goalData['goal_month'] : date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', $goalMonth) !== 1) {
    $goalMonth = date('Y-m');
}

$goalHasGoal = (bool)($goalData['has_goal'] ?? false);
$goalTargetRevenue = isset($goalData['target_revenue']) && $goalData['target_revenue'] !== null
    ? (float)$goalData['target_revenue']
    : null;
$goalTargetProfit = isset($goalData['target_profit']) && $goalData['target_profit'] !== null
    ? (float)$goalData['target_profit']
    : null;
$goalActualRevenue = (float)($goalData['actual_revenue'] ?? 0);
$goalActualProfit = (float)($goalData['actual_profit'] ?? 0);
$goalProgressRevenue = isset($goalData['progress_revenue']) && $goalData['progress_revenue'] !== null
    ? (float)$goalData['progress_revenue']
    : null;
$goalProgressProfit = isset($goalData['progress_profit']) && $goalData['progress_profit'] !== null
    ? (float)$goalData['progress_profit']
    : null;
$goalRevenueReached = (bool)($goalData['revenue_reached'] ?? false);
$goalProfitReached = (bool)($goalData['profit_reached'] ?? false);
$goalAchieved = (bool)($goalData['is_achieved'] ?? false);
$goalRevenueProgressWidth = $goalProgressRevenue !== null ? max(0.0, min(100.0, $goalProgressRevenue)) : 0.0;
$goalProfitProgressWidth = $goalProgressProfit !== null ? max(0.0, min(100.0, $goalProgressProfit)) : 0.0;
$goalRedirectTo = (string)($_SERVER['REQUEST_URI'] ?? '/dashboard.php');

$daysCount = (int)($statistics['days_count'] ?? 0);
$hasDailyData = !empty($dailyPayload['dates']);
$hasSixMonthData = false;
foreach (['revenue', 'ad_cost', 'profit'] as $seriesKey) {
    foreach ((array)($sixMonthPayload[$seriesKey] ?? []) as $value) {
        if (abs((float)$value) > 0.00001) {
            $hasSixMonthData = true;
            break 2;
        }
    }
}

$shopCount = $shopRepository->countByUserId($userId);
$pageTitle = 'แดชบอร์ด';
$currentPage = 'dashboard';

require __DIR__ . '/includes/header.php';
?>
<section class="mb-6 rounded-xl border border-slate-700 bg-slate-800 p-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-100">แดชบอร์ด</h1>
            <p class="mt-1 text-sm text-slate-400">ช่วงข้อมูล: <?= e(formatThaiDate($rangeStart)) ?> - <?= e(formatThaiDate($rangeEnd)) ?></p>
        </div>

        <form id="dashboard-range-form" method="get" action="<?= e(app_url('/dashboard.php')) ?>" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="range-type" class="mb-1 block text-xs text-slate-300">ช่วงเวลา</label>
                <select id="range-type" name="range" class="rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-sm focus:border-cyan-400 focus:outline-none">
                    <option value="week_this" <?= $selectedRange === 'week_this' ? 'selected' : '' ?>>สัปดาห์นี้</option>
                    <option value="week_last" <?= $selectedRange === 'week_last' ? 'selected' : '' ?>>สัปดาห์ก่อน</option>
                    <option value="month_this" <?= $selectedRange === 'month_this' ? 'selected' : '' ?>>เดือนนี้</option>
                    <option value="month_last" <?= $selectedRange === 'month_last' ? 'selected' : '' ?>>เดือนก่อน</option>
                    <option value="custom" <?= $selectedRange === 'custom' ? 'selected' : '' ?>>กำหนดเอง</option>
                </select>
            </div>

            <div id="custom-range-fields" class="flex flex-wrap items-end gap-3 <?= $selectedRange === 'custom' ? '' : 'hidden' ?>">
                <div>
                    <label for="custom-start-date" class="mb-1 block text-xs text-slate-300">เริ่มต้น</label>
                    <input id="custom-start-date" name="start_date" type="date" value="<?= e($customStart) ?>" class="rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-sm focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label for="custom-end-date" class="mb-1 block text-xs text-slate-300">สิ้นสุด</label>
                    <input id="custom-end-date" name="end_date" type="date" value="<?= e($customEnd) ?>" class="rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-sm focus:border-cyan-400 focus:outline-none">
                </div>
            </div>

            <button type="submit" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-600">อัปเดต</button>
        </form>
    </div>

    <?php if ($dashboardError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">
            <?= e($dashboardError) ?>
        </div>
    <?php endif; ?>

    <?php if ($daysCount === 0): ?>
        <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-100">
            ยังไม่มีข้อมูลในช่วงเวลานี้ ลองไปที่หน้า "➕ บันทึก" เพื่อเริ่มบันทึกยอดขายและค่าแอด
        </div>
    <?php endif; ?>
</section>

<section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">ยอดขายรวม</p>
        <p class="mt-1 text-xl font-semibold text-orange-400"><?= e(formatMoney((float)$summary['total_revenue'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['total_revenue']['class']) ?>"><?= e($comparisonText['total_revenue']['text']) ?></p>
        <?php endif; ?>
    </article>
    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">ค่าแอดรวม</p>
        <p class="mt-1 text-xl font-semibold text-cyan-400"><?= e(formatMoney((float)$summary['total_ad_cost'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['total_ad_cost']['class']) ?>"><?= e($comparisonText['total_ad_cost']['text']) ?></p>
        <?php endif; ?>
    </article>
    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">กำไร</p>
        <p class="mt-1 text-xl font-semibold <?= (float)$summary['profit'] >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney((float)$summary['profit'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['profit']['class']) ?>"><?= e($comparisonText['profit']['text']) ?></p>
        <?php endif; ?>
    </article>
    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">ROAS</p>
        <p class="mt-1 text-xl font-semibold text-violet-400"><?= e(formatRoas($summary['roas'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['roas']['class']) ?>"><?= e($comparisonText['roas']['text']) ?></p>
        <?php endif; ?>
    </article>
</section>

<?php if ($comparisonEnabled): ?>
    <section class="mt-4 rounded-xl border border-slate-700 bg-slate-800 p-4 text-sm text-slate-300">
        เทียบผลของ <span class="font-semibold text-slate-100"><?= e(formatThaiMonth($selectedMonth)) ?></span>
        กับ <span class="font-semibold text-slate-100"><?= e(formatThaiMonth($previousMonth)) ?></span>
    </section>
<?php endif; ?>

<section class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">เฉลี่ยรายได้ต่อวัน</p>
        <p class="mt-1 text-lg font-semibold text-slate-100"><?= $statistics['avg_revenue_per_day'] !== null ? e(formatMoney((float)$statistics['avg_revenue_per_day']) . '/วัน') : '–' ?></p>
    </article>

    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">อัตรากำไร</p>
        <p class="mt-1 text-lg font-semibold text-slate-100"><?= e(formatPercent(isset($statistics['profit_margin']) ? (is_null($statistics['profit_margin']) ? null : (float)$statistics['profit_margin']) : null)) ?></p>
    </article>

    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">วันขายดีสุด</p>
        <p class="mt-1 text-sm font-semibold text-green-400"><?= e($formatDayMetric(isset($statistics['best_day']) && is_array($statistics['best_day']) ? $statistics['best_day'] : null)) ?></p>
    </article>

    <article class="rounded-xl border border-slate-700 bg-slate-800 p-4">
        <p class="text-sm text-slate-400">วันขายแย่สุด</p>
        <p class="mt-1 text-sm font-semibold text-red-400"><?= e($formatDayMetric(isset($statistics['worst_day']) && is_array($statistics['worst_day']) ? $statistics['worst_day'] : null)) ?></p>
    </article>
</section>

<section class="mt-6 rounded-xl border border-slate-700 bg-slate-800 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold">🎯 เป้าหมายรายเดือน</h2>
            <p class="mt-1 text-sm text-slate-400">เดือนเป้าหมาย: <?= e(formatThaiMonth($goalMonth)) ?></p>
        </div>

        <button
            type="button"
            data-open-goal-modal
            class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400"
        >
            <?= $goalHasGoal ? 'แก้ไขเป้าหมาย' : '🎯 ตั้งเป้าเดือนนี้' ?>
        </button>
    </div>

    <?php if (!$goalHasGoal): ?>
        <div class="mt-4 rounded-lg border border-slate-700 bg-slate-900/50 p-4 text-sm text-slate-300">
            ยังไม่ได้ตั้งเป้าหมายสำหรับเดือนนี้
        </div>
    <?php else: ?>
        <?php if ($goalAchieved): ?>
            <div class="mt-4 rounded-lg border border-green-500/40 bg-green-500/10 px-3 py-2 text-sm font-medium text-green-300">
                🎉 ถึงเป้าแล้ว!
            </div>
        <?php endif; ?>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <?php if ($goalTargetRevenue !== null): ?>
                <article class="rounded-lg border border-slate-700 bg-slate-900/40 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-slate-300">เป้ารายได้</p>
                        <p class="text-sm font-semibold text-orange-400"><?= e(formatPercent($goalProgressRevenue)) ?></p>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">ทำได้ <?= e(formatMoney($goalActualRevenue)) ?> / เป้า <?= e(formatMoney($goalTargetRevenue)) ?></p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-700">
                        <div class="h-full bg-orange-400" style="width: <?= e(number_format($goalRevenueProgressWidth, 1, '.', '')) ?>%"></div>
                    </div>
                    <?php if ($goalRevenueReached): ?>
                        <p class="mt-2 text-xs font-medium text-green-300">🎉 เป้ารายได้สำเร็จแล้ว</p>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($goalTargetProfit !== null): ?>
                <article class="rounded-lg border border-slate-700 bg-slate-900/40 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-slate-300">เป้ากำไร</p>
                        <p class="text-sm font-semibold text-green-400"><?= e(formatPercent($goalProgressProfit)) ?></p>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">ทำได้ <?= e(formatMoney($goalActualProfit)) ?> / เป้า <?= e(formatMoney($goalTargetProfit)) ?></p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-700">
                        <div class="h-full bg-green-400" style="width: <?= e(number_format($goalProfitProgressWidth, 1, '.', '')) ?>%"></div>
                    </div>
                    <?php if ($goalProfitReached): ?>
                        <p class="mt-2 text-xs font-medium text-green-300">🎉 เป้ากำไรสำเร็จแล้ว</p>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
        </div>

        <form id="delete-goal-form" action="<?= e(app_url('/api/goals.php')) ?>" method="post" class="mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="goal_month" value="<?= e($goalMonth) ?>">
            <input type="hidden" name="redirect_to" value="<?= e($goalRedirectTo) ?>">

            <button type="submit" class="rounded-lg bg-red-500/90 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">
                ลบเป้าหมายเดือนนี้
            </button>
        </form>
    <?php endif; ?>
</section>

<div id="goal-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4">
    <div class="w-full max-w-lg rounded-xl border border-slate-700 bg-slate-800 p-5 shadow-xl">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold"><?= $goalHasGoal ? 'แก้ไขเป้าหมายรายเดือน' : 'ตั้งเป้าหมายรายเดือน' ?></h2>
            <button type="button" id="close-goal-modal" class="rounded-md px-2 py-1 text-sm text-slate-300 hover:bg-slate-700">ปิด</button>
        </div>

        <form action="<?= e(app_url('/api/goals.php')) ?>" method="post" class="grid gap-4 md:grid-cols-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upsert">
            <input type="hidden" name="goal_month" value="<?= e($goalMonth) ?>">
            <input type="hidden" name="redirect_to" value="<?= e($goalRedirectTo) ?>">

            <div>
                <label for="goal-target-revenue" class="mb-1 block text-sm text-slate-300">เป้ารายได้ (฿)</label>
                <input
                    id="goal-target-revenue"
                    name="target_revenue"
                    type="number"
                    min="0"
                    step="0.01"
                    value="<?= e($goalTargetRevenue !== null ? number_format($goalTargetRevenue, 2, '.', '') : '') ?>"
                    class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none"
                    placeholder="เว้นว่างได้"
                >
            </div>

            <div>
                <label for="goal-target-profit" class="mb-1 block text-sm text-slate-300">เป้ากำไร (฿)</label>
                <input
                    id="goal-target-profit"
                    name="target_profit"
                    type="number"
                    min="0"
                    step="0.01"
                    value="<?= e($goalTargetProfit !== null ? number_format($goalTargetProfit, 2, '.', '') : '') ?>"
                    class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none"
                    placeholder="เว้นว่างได้"
                >
            </div>

            <div class="md:col-span-2 flex items-center justify-end gap-2">
                <button type="button" id="cancel-goal-modal" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-600">ยกเลิก</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400">บันทึกเป้าหมาย</button>
            </div>
        </form>
    </div>
</div>

<section class="mt-6 rounded-xl border border-slate-700 bg-slate-800 p-5">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">กราฟแท่งรายวัน (รายได้ vs ค่าแอด)</h2>
        <span class="text-xs text-slate-400">เฉพาะวันที่มีข้อมูล</span>
    </div>
    <?php if (!$hasDailyData): ?>
        <p class="mb-3 text-sm text-slate-400">ยังไม่มีข้อมูลรายวันในช่วงเวลาที่เลือก</p>
    <?php endif; ?>
    <div class="h-80">
        <canvas id="daily-bar-chart"></canvas>
    </div>
</section>

<section class="mt-6 rounded-xl border border-slate-700 bg-slate-800 p-5">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">แนวโน้มย้อนหลัง 6 เดือน</h2>
        <span class="text-xs text-slate-400">แสดงรายเดือนเสมอ</span>
    </div>
    <?php if (!$hasSixMonthData): ?>
        <p class="mb-3 text-sm text-slate-400">ยังไม่มีข้อมูลย้อนหลัง 6 เดือน</p>
    <?php endif; ?>
    <div class="h-80">
        <canvas id="six-month-line-chart"></canvas>
    </div>
</section>

<script>
    (function () {
        const rangeForm = document.getElementById('dashboard-range-form');
        const rangeSelect = document.getElementById('range-type');
        const customFields = document.getElementById('custom-range-fields');
        const startInput = document.getElementById('custom-start-date');
        const endInput = document.getElementById('custom-end-date');
        const goalModal = document.getElementById('goal-modal');
        const openGoalModalButtons = document.querySelectorAll('[data-open-goal-modal]');
        const closeGoalModalButton = document.getElementById('close-goal-modal');
        const cancelGoalModalButton = document.getElementById('cancel-goal-modal');
        const deleteGoalForm = document.getElementById('delete-goal-form');

        const dailyPayload = <?= json_encode($dailyChartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const sixMonthPayload = <?= json_encode($sixMonthPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const toggleCustomFields = () => {
            if (!customFields || !rangeSelect) {
                return;
            }

            if (rangeSelect.value === 'custom') {
                customFields.classList.remove('hidden');
            } else {
                customFields.classList.add('hidden');
            }
        };

        const autoSubmitIfReady = () => {
            if (!rangeForm || !rangeSelect) {
                return;
            }

            if (rangeSelect.value !== 'custom') {
                rangeForm.submit();
                return;
            }

            if (startInput && endInput && startInput.value !== '' && endInput.value !== '') {
                rangeForm.submit();
            }
        };

        if (rangeSelect) {
            rangeSelect.addEventListener('change', () => {
                toggleCustomFields();
                autoSubmitIfReady();
            });
        }

        if (startInput) {
            startInput.addEventListener('change', autoSubmitIfReady);
        }

        if (endInput) {
            endInput.addEventListener('change', autoSubmitIfReady);
        }

        toggleCustomFields();

        if (goalModal) {
            const openGoalModal = () => {
                goalModal.classList.remove('hidden');
                goalModal.classList.add('flex');
            };

            const closeGoalModal = () => {
                goalModal.classList.add('hidden');
                goalModal.classList.remove('flex');
            };

            openGoalModalButtons.forEach((button) => {
                button.addEventListener('click', openGoalModal);
            });

            [closeGoalModalButton, cancelGoalModalButton].forEach((button) => {
                if (!button) {
                    return;
                }

                button.addEventListener('click', closeGoalModal);
            });

            goalModal.addEventListener('click', (event) => {
                if (event.target === goalModal) {
                    closeGoalModal();
                }
            });
        }

        if (deleteGoalForm) {
            deleteGoalForm.addEventListener('submit', (event) => {
                const accepted = window.confirm('ยืนยันการลบเป้าหมายของเดือนนี้ใช่หรือไม่?');
                if (!accepted) {
                    event.preventDefault();
                }
            });
        }

        const dailyCanvas = document.getElementById('daily-bar-chart');
        if (dailyCanvas) {
            new Chart(dailyCanvas, {
                type: 'bar',
                data: {
                    labels: dailyPayload.labels,
                    datasets: [
                        {
                            label: 'รายได้',
                            data: dailyPayload.revenue,
                            backgroundColor: '#f97316',
                        },
                        {
                            label: 'ค่าแอด',
                            data: dailyPayload.ad_cost,
                            backgroundColor: '#06b6d4',
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
                            ticks: { color: '#94a3b8' },
                            grid: { color: 'rgba(51, 65, 85, 0.4)' }
                        },
                        y: {
                            ticks: {
                                color: '#94a3b8',
                                callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                            },
                            grid: { color: 'rgba(51, 65, 85, 0.4)' }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: { color: '#e2e8f0' }
                        },
                        tooltip: {
                            callbacks: {
                                title: (items) => {
                                    if (!items.length) {
                                        return '';
                                    }

                                    return String(items[0].label || '');
                                },
                                label: (context) => context.dataset.label + ': ฿' + Number(context.raw || 0).toLocaleString('th-TH'),
                                afterBody: (items) => {
                                    if (!items.length) {
                                        return '';
                                    }

                                    const index = items[0].dataIndex;
                                    return 'กำไร: ฿' + Number(dailyPayload.profit[index] || 0).toLocaleString('th-TH');
                                }
                            }
                        }
                    }
                }
            });
        }

        const sixMonthCanvas = document.getElementById('six-month-line-chart');
        if (sixMonthCanvas) {
            new Chart(sixMonthCanvas, {
                type: 'line',
                data: {
                    labels: sixMonthPayload.labels,
                    datasets: [
                        {
                            label: 'ยอดขาย',
                            data: sixMonthPayload.revenue,
                            borderColor: '#f97316',
                            backgroundColor: 'rgba(249, 115, 22, 0.2)',
                            tension: 0.3,
                            fill: false
                        },
                        {
                            label: 'ค่าแอด',
                            data: sixMonthPayload.ad_cost,
                            borderColor: '#06b6d4',
                            backgroundColor: 'rgba(6, 182, 212, 0.2)',
                            tension: 0.3,
                            fill: false
                        },
                        {
                            label: 'กำไร',
                            data: sixMonthPayload.profit,
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34, 197, 94, 0.2)',
                            tension: 0.3,
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
                            ticks: { color: '#94a3b8' },
                            grid: { color: 'rgba(51, 65, 85, 0.4)' }
                        },
                        y: {
                            ticks: {
                                color: '#94a3b8',
                                callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                            },
                            grid: { color: 'rgba(51, 65, 85, 0.4)' }
                        }
                    },
                    plugins: {
                        legend: {
                            labels: { color: '#e2e8f0' }
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
<?php require __DIR__ . '/includes/footer.php'; ?>
