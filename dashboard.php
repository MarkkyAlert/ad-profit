<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
// ⚠️ ต้องซ่อม session ก่อนดึงข้อมูล — ร้านอาจถูกลบจากอุปกรณ์อื่นไปแล้ว
// (เดิมการซ่อมอยู่ใน header.php ซึ่ง include ท้ายไฟล์ หน้าจึงขึ้น "ไม่มีสิทธิ์" + ฿0 หนึ่งครั้ง)
$shopId = resolve_current_shop_id($pdo, $userId);
// ⚠️ ต้อง `trim()` ให้เหมือน `api/dashboard-data.php` — ไม่งั้น `?range=%20month_last`
// ทำให้หน้าเว็บกับ endpoint ตอบคนละช่วงเวลาจาก URL เดียวกัน
$selectedRangeType = isset($_GET['range']) ? trim((string)$_GET['range']) : 'month_this';
$customStartDateInput = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : null;
$customEndDateInput = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : null;
$selectedMonthInput = isset($_GET['month']) ? trim((string)$_GET['month']) : null;

if ($customStartDateInput === '') {
    $customStartDateInput = null;
}

if ($customEndDateInput === '') {
    $customEndDateInput = null;
}

if ($selectedMonthInput === '') {
    $selectedMonthInput = null;
}

// ตัวเลือก "เลือกเดือน (ย้อนหลัง)" ต้องย้อนหลังจริง — เดือนอนาคตถูกดึงกลับเป็นเดือนปัจจุบัน
if ($selectedMonthInput !== null) {
    $selectedMonthInput = resolve_calendar_month($selectedMonthInput);
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$goalRepository = new GoalRepository($pdo);
$dashboardService = new DashboardService($recordRepository, $shopRepository, $goalRepository);

/** เตือนเมื่อไม่ได้กรอกข้อมูลติดต่อกันตั้งแต่กี่วัน (ปรับได้) */
const REMINDER_DAYS_THRESHOLD = 3;

$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

// เทียบวันล่าสุดกับวันเดียวกันในสัปดาห์ของเดือนนั้น
$weekdayResult = $recordService->getWeekdayContext($userId, $shopId);
$weekdayData = ($weekdayResult['success'] ?? false) === true
    ? (array)($weekdayResult['data'] ?? [])
    : [];
$weekdayHasData = (bool)($weekdayData['has_data'] ?? false);
$weekdayComparable = (bool)($weekdayData['comparable'] ?? false);
// ฟันธง "สูงกว่า/ต่ำกว่าปกติ" ได้เมื่อมีตัวอย่างพอเท่านั้น — เกณฑ์เดียวกับตารางแยกตามวัน
$weekdayTrendReliable = (bool)($weekdayData['trend_reliable'] ?? false);
$weekdayName = isset($weekdayData['weekday']) && $weekdayData['weekday'] !== null
    ? formatThaiWeekday((int)$weekdayData['weekday'])
    : '';
$weekdayTargetDate = (string)($weekdayData['target_date'] ?? '');
// ⚠️⚠️ ห้ามเขียนว่า "เดือนนี้" ตายตัว — การ์ดนี้เทียบกับเดือนของ **รายการล่าสุด**
// ซึ่งอาจเป็นเดือนก่อนถ้ายังไม่ได้กรอกอะไรในเดือนนี้ · วัดจริง: เดือน ส.ค. ไม่มีข้อมูลเลย
// แต่การ์ดขึ้น "วันอังคารเดือนนี้เฉลี่ย ฿8,000 (จาก 3 วัน)" ซึ่งเป็นข้อมูลของ ก.ค.
// ขณะที่ตารางใต้การ์ดบนจอเดียวกันว่างเปล่าเพราะเดือนนี้ยังไม่มีข้อมูล
$weekdayMonthLabel = ($weekdayData['window_is_current_month'] ?? true) === true
    ? 'เดือนนี้'
    : 'เดือน ' . formatThaiMonth((string)($weekdayData['window_month'] ?? ''));
$weekdayTargetRevenue = (float)($weekdayData['target_revenue'] ?? 0);
$weekdayAvgRevenue = isset($weekdayData['avg_revenue']) && $weekdayData['avg_revenue'] !== null
    ? (float)$weekdayData['avg_revenue']
    : null;
$weekdayTargetRoas = isset($weekdayData['target_roas']) && $weekdayData['target_roas'] !== null
    ? (float)$weekdayData['target_roas']
    : null;
$weekdayAvgRoas = isset($weekdayData['avg_roas']) && $weekdayData['avg_roas'] !== null
    ? (float)$weekdayData['avg_roas']
    : null;
$weekdaySampleCount = (int)($weekdayData['sample_count'] ?? 0);
$weekdayTargetProfit = (float)($weekdayData['target_profit'] ?? 0);
$weekdayAvgProfit = isset($weekdayData['avg_profit']) && $weekdayData['avg_profit'] !== null
    ? (float)$weekdayData['avg_profit']
    : null;

// เทียบเชิงคุณภาพจาก "กำไร" — ใช้ผลต่างเทียบกับ |ค่าเฉลี่ย| (ทนกำไรติดลบ/ศูนย์)
// หมายเหตุ: ใช้ ratio ไม่ได้ เพราะกำไร -50 เทียบเฉลี่ย -100 คือ "ดีขึ้น" แต่ ratio จะได้ 0.5
$weekdayHint = null;
$weekdayHintClass = 'text-slate-400';
if ($weekdayTrendReliable && $weekdayAvgProfit !== null) {
    $weekdayProfitDiff = $weekdayTargetProfit - $weekdayAvgProfit;
    $weekdayTolerance = abs($weekdayAvgProfit) * 0.1;

    if ($weekdayProfitDiff > $weekdayTolerance) {
        $weekdayHint = 'สูงกว่า' . $weekdayName . 'ปกติของ' . $weekdayMonthLabel;
        $weekdayHintClass = 'text-green-400';
    } elseif ($weekdayProfitDiff < -$weekdayTolerance) {
        $weekdayHint = 'ต่ำกว่า' . $weekdayName . 'ปกติของ' . $weekdayMonthLabel;
        $weekdayHintClass = 'text-amber-300';
    } else {
        $weekdayHint = 'ใกล้เคียง' . $weekdayName . 'ปกติของ' . $weekdayMonthLabel;
        $weekdayHintClass = 'text-slate-300';
    }
}

// กำไรเฉลี่ยแยกตามวันในสัปดาห์ — เลือก window ได้ (8 สัปดาห์ / เดือนนี้)
$breakdownModeInput = isset($_GET['wd']) ? strtolower(trim((string)$_GET['wd'])) : '8w';
$breakdownMode = in_array($breakdownModeInput, ['8w', 'month'], true) ? $breakdownModeInput : '8w';
$breakdownWindow = $recordService->resolveWeekdayWindow($breakdownMode);
$breakdownMode = (string)$breakdownWindow['mode'];
$breakdownLabel = $breakdownMode === 'month' ? 'เดือนนี้' : 'ย้อนหลัง 8 สัปดาห์';

$breakdownResult = $recordService->getWeekdayBreakdown(
    $userId,
    $shopId,
    (string)$breakdownWindow['start_date'],
    (string)$breakdownWindow['end_date']
);
$breakdownData = ($breakdownResult['success'] ?? false) === true
    ? (array)($breakdownResult['data'] ?? [])
    : [];
$breakdownHasData = (bool)($breakdownData['has_data'] ?? false);
$breakdownRows = array_values((array)($breakdownData['weekdays'] ?? []));

// ไฮไลต์ดี/เงียบ เฉพาะวันที่มีข้อมูลพอ และต้องมีอย่างน้อย 2 วันให้เทียบ
// เกณฑ์มาจาก service ตัวเดียวกับ trend_reliable ของการ์ดด้านบน — อย่าฮาร์ดโค้ดซ้ำ
$breakdownMinSample = RecordService::WEEKDAY_MIN_SAMPLE;
$breakdownBestWeekday = null;
$breakdownWorstWeekday = null;
$breakdownEligible = array_values(array_filter(
    $breakdownRows,
    static fn(array $row): bool => (int)($row['sample_count'] ?? 0) >= $breakdownMinSample
        && ($row['avg_profit'] ?? null) !== null
));

if (count($breakdownEligible) >= 2) {
    $breakdownBest = $breakdownEligible[0];
    $breakdownWorst = $breakdownEligible[0];
    foreach ($breakdownEligible as $row) {
        if ((float)$row['avg_profit'] > (float)$breakdownBest['avg_profit']) {
            $breakdownBest = $row;
        }
        if ((float)$row['avg_profit'] < (float)$breakdownWorst['avg_profit']) {
            $breakdownWorst = $row;
        }
    }

    // ถ้าทุกวันกำไรเท่ากันหมด ก็ไม่ต้องไฮไลต์
    if ((float)$breakdownBest['avg_profit'] !== (float)$breakdownWorst['avg_profit']) {
        $breakdownBestWeekday = (int)$breakdownBest['weekday'];
        $breakdownWorstWeekday = (int)$breakdownWorst['weekday'];
    }
}

$lastRecordResult = $recordService->getDaysSinceLastRecord($userId, $shopId);
$lastRecordData = ($lastRecordResult['success'] ?? false) === true
    ? (array)($lastRecordResult['data'] ?? [])
    : [];

$hasAnyRecord = (bool)($lastRecordData['has_records'] ?? false);
$daysSinceLastRecord = isset($lastRecordData['days_since']) && $lastRecordData['days_since'] !== null
    ? (int)$lastRecordData['days_since']
    : null;
$showInactiveReminder = $hasAnyRecord
    && $daysSinceLastRecord !== null
    && $daysSinceLastRecord >= REMINDER_DAYS_THRESHOLD;
$showFirstRecordInvite = ($lastRecordResult['success'] ?? false) === true && !$hasAnyRecord;

$dashboardResult = $dashboardService->buildDashboard(
    $userId,
    $shopId,
    $selectedRangeType,
    $customStartDateInput,
    $customEndDateInput,
    $selectedMonthInput
);

$dashboardError = null;

if (($dashboardResult['success'] ?? false) !== true) {
    $dashboardError = (string)($dashboardResult['error'] ?? 'ไม่สามารถโหลดข้อมูลแดชบอร์ดได้');

    // ลองใหม่ด้วยช่วงมาตรฐาน เผื่อปัญหาอยู่ที่ช่วงวันที่ที่เลือก
    // ⚠️ ถ้าเป็นเรื่องสิทธิ์ การลองใหม่ก็ล้มเหมือนเดิม — อย่าเอาค่าตั้งต้น ฿0 มาแสดง
    // เป็นข้อมูลจริง (เคยขึ้นการ์ด ฿0 + "ยังไม่มีข้อมูลในช่วงนี้" คู่กับแถบแดง)
    $dashboardResult = $dashboardService->buildDashboard($userId, $shopId, 'month_this', null, null, null);
}

$dashboardFailed = ($dashboardResult['success'] ?? false) !== true;

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
        'avg_profit_per_day' => null,
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
        'pace_applicable' => false,
        'month_status' => 'ended',
        'days_remaining' => null,
        'remaining_revenue' => null,
        'remaining_profit' => null,
        'required_per_day_revenue' => null,
        'required_per_day_profit' => null,
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
/* ⚠️⚠️ ต้องคง `null` ไว้ ห้าม cast เป็น float — `(float)null` = 0.0 จะทำให้เดือนที่
   ยังไม่ได้กรอกกลับกลายเป็น "เท่าทุนพอดี" อีกครั้ง ทับตัวแก้ที่ `DashboardService`
   (Chart.js เว้นช่วงให้เองเมื่อค่าเป็น null = เส้นขาดตอน แปลว่ายังไม่มีข้อมูล) */
$toChartValue = static fn($value): ?float => $value === null ? null : (float)$value;

$sixMonthPayload = [
    'labels' => array_map(static fn(string $month): string => formatThaiMonth($month), $sixMonthKeys),
    'months' => $sixMonthKeys,
    'revenue' => array_values(array_map($toChartValue, (array)($sixMonthRaw['revenue'] ?? []))),
    'ad_cost' => array_values(array_map($toChartValue, (array)($sixMonthRaw['ad_cost'] ?? []))),
    'profit' => array_values(array_map($toChartValue, (array)($sixMonthRaw['profit'] ?? []))),
];

$comparisonChanges = (array)($comparison['change'] ?? []);
$comparisonEnabled = (bool)($comparison['enabled'] ?? false);

// เดือนปัจจุบันเทียบกับ "เดือนก่อนถึงวันเดียวกัน" ไม่ใช่ทั้งเดือน — ป้ายต้องบอกให้ชัด
// ไม่งั้นผู้ใช้อ่านว่ากำลังเทียบกับยอดเต็มเดือนก่อน แล้วสรุปว่าเดือนนี้แย่ลงทั้งที่เพิ่งต้นเดือน
$comparisonCutoffDay = isset($comparison['compared_up_to_day']) && $comparison['compared_up_to_day'] !== null
    ? (int)$comparison['compared_up_to_day']
    : null;
$comparisonLabel = $comparisonCutoffDay !== null
    ? 'เทียบเดือนก่อน (ถึงวันที่ ' . $comparisonCutoffDay . '): '
    : 'เทียบเดือนก่อน: ';

$comparisonMeta = static function (?float $value) use ($comparisonLabel): array {
    $badge = format_change_badge($value);

    return [
        'text' => $comparisonLabel . $badge['text'],
        'class' => $badge['class'],
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

    $profit = (float)($day['profit'] ?? 0);

    return formatThaiDate($recordDate) . ' (' . formatMoney($profit) . ')';
};

$selectedRange = (string)($rangeData['type'] ?? 'month_this');
$selectedMonth = is_string($comparison['selected_month'] ?? null) ? (string)$comparison['selected_month'] : '';
$previousMonth = is_string($comparison['previous_month'] ?? null) ? (string)$comparison['previous_month'] : '';

$rangeStart = (string)($rangeData['start_date'] ?? date('Y-m-01'));
$rangeEnd = (string)($rangeData['end_date'] ?? date('Y-m-t'));
$customStart = (string)($rangeData['custom_start_date'] ?? '');
$customEnd = (string)($rangeData['custom_end_date'] ?? '');
$monthPickerValue = is_string($rangeData['selected_month'] ?? null) ? (string)$rangeData['selected_month'] : date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', $monthPickerValue) !== 1) {
    $monthPickerValue = date('Y-m');
}

$goalMonth = is_string($goalData['goal_month'] ?? null) ? (string)$goalData['goal_month'] : date('Y-m');
if (preg_match('/^\d{4}-\d{2}$/', $goalMonth) !== 1) {
    $goalMonth = date('Y-m');
}

$goalHasGoal = (bool)($goalData['has_goal'] ?? false);
$goalMonthLabel = formatThaiMonth($goalMonth);
$goalActionText = $goalHasGoal
    ? 'แก้ไขเป้าหมายเดือน ' . $goalMonthLabel
    : '🎯 ตั้งเป้าเดือน ' . $goalMonthLabel;
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

// pace — ต้องได้อีกวันละเท่าไรถึงถึงเป้า (โชว์เฉพาะเดือนที่ยังไล่ทัน)
$goalPaceApplicable = (bool)($goalData['pace_applicable'] ?? false);
$goalDaysRemaining = isset($goalData['days_remaining']) && $goalData['days_remaining'] !== null
    ? (int)$goalData['days_remaining']
    : null;
$goalPerDayRevenue = isset($goalData['required_per_day_revenue']) && $goalData['required_per_day_revenue'] !== null
    ? (float)$goalData['required_per_day_revenue']
    : null;
$goalPerDayProfit = isset($goalData['required_per_day_profit']) && $goalData['required_per_day_profit'] !== null
    ? (float)$goalData['required_per_day_profit']
    : null;
$goalRemainingRevenue = isset($goalData['remaining_revenue']) && $goalData['remaining_revenue'] !== null
    ? (float)$goalData['remaining_revenue']
    : null;
$goalRemainingProfit = isset($goalData['remaining_profit']) && $goalData['remaining_profit'] !== null
    ? (float)$goalData['remaining_profit']
    : null;

$showRevenuePace = $goalPaceApplicable && !$goalRevenueReached
    && $goalRemainingRevenue !== null && $goalRemainingRevenue > 0
    && $goalPerDayRevenue !== null && $goalDaysRemaining !== null;
$showProfitPace = $goalPaceApplicable && !$goalProfitReached
    && $goalRemainingProfit !== null && $goalRemainingProfit > 0
    && $goalPerDayProfit !== null && $goalDaysRemaining !== null;
$goalRevenueProgressWidth = $goalProgressRevenue !== null ? max(0.0, min(100.0, $goalProgressRevenue)) : 0.0;
$goalProfitProgressWidth = $goalProgressProfit !== null ? max(0.0, min(100.0, $goalProgressProfit)) : 0.0;
$goalRedirectTo = (string)($_SERVER['REQUEST_URI'] ?? '/dashboard.php');

$daysCount = (int)($statistics['days_count'] ?? 0);
$hasDailyData = !empty($dailyChartPayload['dates']);
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
<?php // แบนเนอร์เชิญชวน/เตือนต้องไม่ขึ้นคู่กับแถบแดง — สองข้อความขัดกันเอง ?>
<?php if ($showInactiveReminder && !$dashboardFailed): ?>
    <div class="mb-6 rounded-xl border border-amber-500/25 bg-amber-500/10 px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-amber-200">
                    คุณไม่ได้กรอกข้อมูลมา <?= e((string)$daysSinceLastRecord) ?> วันแล้ว
                </p>
                <p class="mt-1 text-xs text-amber-100/70">
                    บันทึกล่าสุด: <?= e(formatThaiDate((string)($lastRecordData['last_record_date'] ?? ''))) ?>
                </p>
            </div>
            <a href="<?= e(app_url('/add-record.php')) ?>" class="btn-orange shrink-0 px-4 py-2 text-sm" data-loading-link="true">
                ✎ กรอกข้อมูลตอนนี้
            </a>
        </div>
    </div>
<?php // ⚠️ กิ่งนี้ก็ต้องปิดด้วย — มันมาจากคนละ service call กับการ์ดสรุป
      // ถ้าอันหนึ่งสำเร็จอีกอันล้ม หน้าจะขึ้น "ร้านนี้ยังไม่มีข้อมูล" คู่กับแถบแดง ?>
<?php elseif ($showFirstRecordInvite && !$dashboardFailed): ?>
    <div class="mb-6 rounded-xl border border-indigo-500/25 bg-indigo-500/10 px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-indigo-200">เริ่มบันทึกวันแรกของคุณ</p>
                <p class="mt-1 text-xs text-indigo-100/70">
                    ร้านนี้ยังไม่มีข้อมูล กรอกรายได้และค่าแอดวันแรกเพื่อเริ่มดูสรุปผล
                </p>
            </div>
            <a href="<?= e(app_url('/add-record.php')) ?>" class="btn-primary shrink-0 px-4 py-2 text-sm" data-loading-link="true">
                + เริ่มบันทึก
            </a>
        </div>
    </div>
<?php endif; ?>
<section class="section-card mb-6 p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between sm:gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-100">แดชบอร์ด</h1>
            <?php // ⚠️ โหลดไม่สำเร็จ = ไม่มีช่วงข้อมูลจริง — ค่าตั้งต้นคือ "ทั้งเดือนนี้"
                  // ซึ่งเลยวันนี้ไปด้วย ขัดกับกฎของหน้าเองที่ห้ามนับวันอนาคต ?>
            <?php if (!$dashboardFailed): ?>
                <p class="mt-1 text-xs sm:text-sm text-slate-400">ช่วงข้อมูล: <?= e(formatThaiDate($rangeStart)) ?> - <?= e(formatThaiDate($rangeEnd)) ?></p>
            <?php endif; ?>
        </div>

        <form id="dashboard-range-form" method="get" action="<?= e(app_url('/dashboard.php')) ?>" class="flex flex-wrap items-end gap-2 sm:gap-3">
            <div>
                <label for="range-type" class="mb-1 block text-xs text-slate-400">ช่วงเวลา</label>
                <select id="range-type" name="range" class="rounded-xl px-3 py-2 text-sm transition-all">
                    <option value="week_this" <?= $selectedRange === 'week_this' ? 'selected' : '' ?>>สัปดาห์นี้</option>
                    <option value="week_last" <?= $selectedRange === 'week_last' ? 'selected' : '' ?>>สัปดาห์ก่อน</option>
                    <option value="month_this" <?= $selectedRange === 'month_this' ? 'selected' : '' ?>>เดือนนี้</option>
                    <option value="month_last" <?= $selectedRange === 'month_last' ? 'selected' : '' ?>>เดือนก่อน</option>
                    <option value="month_pick" <?= $selectedRange === 'month_pick' ? 'selected' : '' ?>>เลือกเดือน (ย้อนหลัง)</option>
                    <option value="custom" <?= $selectedRange === 'custom' ? 'selected' : '' ?>>กำหนดเอง</option>
                </select>
            </div>

            <div id="custom-range-fields" class="flex flex-wrap items-end gap-3 <?= $selectedRange === 'custom' ? '' : 'hidden' ?>">
                <div>
                    <label for="custom-start-date" class="mb-1 block text-xs text-slate-400">เริ่มต้น</label>
                    <input id="custom-start-date" name="start_date" type="date" value="<?= e($customStart) ?>" class="rounded-xl px-3 py-2 text-sm transition-all">
                </div>
                <div>
                    <label for="custom-end-date" class="mb-1 block text-xs text-slate-400">สิ้นสุด</label>
                    <input id="custom-end-date" name="end_date" type="date" value="<?= e($customEnd) ?>" class="rounded-xl px-3 py-2 text-sm transition-all">
                </div>
            </div>

            <div id="month-pick-field" class="flex flex-wrap items-end gap-3 <?= $selectedRange === 'month_pick' ? '' : 'hidden' ?>">
                <div>
                    <label for="month-pick-input" class="mb-1 block text-xs text-slate-400">เดือน</label>
                    <input id="month-pick-input" name="month" type="month" max="<?= e(date('Y-m')) ?>" value="<?= e($monthPickerValue) ?>" class="rounded-xl px-3 py-2 text-sm transition-all">
                </div>
            </div>

            <button type="submit" class="btn-ghost px-4 py-2 text-sm">อัปเดต</button>
        </form>
    </div>

    <?php if ($dashboardError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-sm text-red-400">
            <?= e($dashboardError) ?>
        </div>
    <?php endif; ?>

    <?php if ($daysCount === 0 && !$dashboardFailed): ?>
        <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
            ยังไม่มีข้อมูลในช่วงเวลานี้ ลองไปที่หน้า "➕ บันทึก" เพื่อเริ่มบันทึกยอดขายและค่าแอด
        </div>
    <?php endif; ?>
</section>

<?php if ($dashboardFailed): ?>
    <?php // โหลดไม่สำเร็จ = ไม่รู้ตัวเลข → ไม่แสดงการ์ด/กราฟ/ตารางใด ๆ
          // เดิมทุกอย่างยังเรนเดอร์ด้วยค่าตั้งต้น ฿0 หน้าจึงบอกทั้ง "ไม่มีสิทธิ์"
          // และ "เดือนนี้ทำได้ ฿0 · ยังไม่มีข้อมูลในช่วงนี้" พร้อมกันในหน้าเดียว ?>
<?php else: ?>

<section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <article class="stat-card s-revenue">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ยอดขายรวม</p>
        <p class="mt-2 text-xl sm:text-2xl font-bold text-orange-400"><?= e(formatMoney((float)$summary['total_revenue'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['total_revenue']['class']) ?>"><?= e($comparisonText['total_revenue']['text']) ?></p>
        <?php endif; ?>
    </article>
    <article class="stat-card s-adcost">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ค่าแอดรวม</p>
        <p class="mt-2 text-xl sm:text-2xl font-bold text-cyan-400"><?= e(formatMoney((float)$summary['total_ad_cost'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['total_ad_cost']['class']) ?>"><?= e($comparisonText['total_ad_cost']['text']) ?></p>
        <?php endif; ?>
    </article>
    <?php /* ⚠️ กำไรคือเหตุผลที่แอปนี้มีอยู่ แต่การ์ด 4 ใบเคยขนาดเท่ากันหมด และเรียง
             ยอดขาย → ค่าแอด → **กำไร** → ROAS · บนมือถือที่การ์ดเรียงลงมาทีละใบ ผู้ใช้
             ต้องเลื่อนผ่าน 2 ใบกว่าจะเจอตัวเลขที่เปิดแอปมาเพื่อดู
             → บนจอเล็กดันขึ้นเป็นใบแรก และตัวเลขใหญ่กว่าใบอื่น ส่วนบนจอใหญ่ที่เห็นครบ
               ทั้ง 4 ใบพร้อมกันอยู่แล้ว คงลำดับเดิมไว้ */ ?>
    <article class="stat-card s-profit order-first sm:order-none">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">กำไร</p>
        <p class="mt-2 text-2xl sm:text-3xl font-bold <?= (float)$summary['profit'] >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney((float)$summary['profit'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['profit']['class']) ?>"><?= e($comparisonText['profit']['text']) ?></p>
        <?php endif; ?>
    </article>
    <article class="stat-card s-roas">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ROAS</p>
        <p class="mt-2 text-xl sm:text-2xl font-bold text-violet-400"><?= e(formatRoas($summary['roas'])) ?></p>
        <?php if ($comparisonEnabled): ?>
            <p class="mt-1 text-xs <?= e($comparisonText['roas']['class']) ?>"><?= e($comparisonText['roas']['text']) ?></p>
        <?php endif; ?>
    </article>
</section>

<?php if ($comparisonEnabled): ?>
    <section class="section-card mt-4 p-4 text-sm text-slate-400">
        เทียบผลของ <span class="font-semibold text-slate-200"><?= e(formatThaiMonth($selectedMonth)) ?></span>
        กับ <span class="font-semibold text-slate-200"><?= e(formatThaiMonth($previousMonth)) ?></span>
    </section>
<?php endif; ?>

<section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <?php
    $avgProfitPerDay = isset($statistics['avg_profit_per_day']) && $statistics['avg_profit_per_day'] !== null
        ? (float)$statistics['avg_profit_per_day']
        : null;
    ?>
    <article class="stat-card s-neutral">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">เฉลี่ยกำไรต่อวัน</p>
        <p class="mt-1 text-lg font-semibold <?= $avgProfitPerDay === null || $avgProfitPerDay >= 0 ? 'text-slate-100' : 'text-red-400' ?>">
            <?= $avgProfitPerDay !== null ? e(formatMoney($avgProfitPerDay) . '/วัน') : '–' ?>
        </p>
    </article>

    <article class="stat-card s-neutral">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">อัตรากำไร</p>
        <p class="mt-1 text-lg font-semibold text-slate-100"><?= e(formatPercent(isset($statistics['profit_margin']) ? (is_null($statistics['profit_margin']) ? null : (float)$statistics['profit_margin']) : null)) ?></p>
    </article>

    <?php
    // ⚠️⚠️ "ดีสุด" กับ "แย่สุด" ต้องเป็นคนละวันจริง ๆ ถึงจะมีความหมาย
    //
    // วัดจริงก่อนแก้ — ร้านใหม่กรอกวันแรกวันเดียว:
    //   วันกำไรดีสุด   7 ส.ค. 2569 (฿1,000)   ← เขียว
    //   วันกำไรแย่สุด  7 ส.ค. 2569 (฿1,000)   ← แดง
    // วันเดียวกัน ตัวเลขเดียวกัน สีตรงข้ามกัน — และเป็นสิ่งแรกที่ผู้ใช้ใหม่เห็น
    // เกิดเหมือนกันเมื่อทุกวันในช่วงกำไรเท่ากันหมด
    //
    // ตัวเลขจาก service ถูกต้องตามคณิตศาสตร์ (วันเดียวย่อมเป็นทั้งมากสุดและน้อยสุด)
    // สิ่งที่ผิดคือการเอามาโชว์คู่กันเป็นขั้วตรงข้าม — ตารางกำไรตามวันในหน้าเดียวกัน
    // มีตัวกันแบบนี้อยู่แล้ว (ต้องมี ≥ 2 ตัวอย่าง และไม่ไฮไลต์ถ้าดีสุด = แย่สุด)
    $bestDayRow = isset($statistics['best_day']) && is_array($statistics['best_day']) ? $statistics['best_day'] : null;
    $worstDayRow = isset($statistics['worst_day']) && is_array($statistics['worst_day']) ? $statistics['worst_day'] : null;
    $dayExtremesComparable = extremes_are_comparable($bestDayRow, $worstDayRow, 'record_date');
    ?>
    <article class="stat-card s-best">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">วันกำไรดีสุด</p>
        <?php if ($dayExtremesComparable): ?>
            <p class="mt-1 text-sm font-semibold text-green-400"><?= e($formatDayMetric($bestDayRow)) ?></p>
        <?php else: ?>
            <p class="mt-1 text-sm font-semibold text-slate-400">ยังเทียบไม่ได้</p>
            <p class="text-xs text-slate-400">ต้องมีข้อมูลตั้งแต่ 2 วันขึ้นไปที่กำไรต่างกัน</p>
        <?php endif; ?>
    </article>

    <article class="stat-card s-worst">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">วันกำไรแย่สุด</p>
        <?php if ($dayExtremesComparable): ?>
            <p class="mt-1 text-sm font-semibold text-red-400"><?= e($formatDayMetric($worstDayRow)) ?></p>
        <?php else: ?>
            <p class="mt-1 text-sm font-semibold text-slate-400">ยังเทียบไม่ได้</p>
        <?php endif; ?>
    </article>
</section>

<section class="section-card mt-6 p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-100">🎯 เป้าหมายรายเดือน</h2>
            <p class="mt-1 text-sm text-slate-400">เดือนเป้าหมาย: <?= e(formatThaiMonth($goalMonth)) ?></p>
        </div>

        <button
            type="button"
            data-open-goal-modal
            class="btn-teal px-4 py-2 text-sm">
            <?= e($goalActionText) ?>
        </button>
    </div>

    <?php if (!$goalHasGoal): ?>
        <div class="mt-4 rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-400">
            ยังไม่ได้ตั้งเป้าหมายสำหรับเดือน <?= e($goalMonthLabel) ?>
        </div>
    <?php else: ?>
        <?php if ($goalAchieved): ?>
            <div class="mt-4 rounded-lg border border-green-500/30 bg-green-950/40 px-3 py-2 text-sm font-medium text-green-400">
                🎉 ถึงเป้าแล้ว!
            </div>
        <?php endif; ?>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <?php if ($goalTargetRevenue !== null): ?>
                <article class="stat-card s-revenue">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-slate-400">เป้ารายได้</p>
                        <p class="text-sm font-semibold text-orange-400"><?= e(formatPercent($goalProgressRevenue)) ?></p>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">ทำได้ <?= e(formatMoney($goalActualRevenue)) ?> / เป้า <?= e(formatMoney($goalTargetRevenue)) ?></p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/10">
                        <div class="progress-orange h-full rounded-full transition-all duration-500" style="width: <?= e(number_format($goalRevenueProgressWidth, 1, '.', '')) ?>%"></div>
                    </div>
                    <?php if ($goalRevenueReached): ?>
                        <p class="mt-2 text-xs font-medium text-green-400">🎉 เป้ารายได้สำเร็จแล้ว</p>
                    <?php elseif ($showRevenuePace): ?>
                        <p class="mt-2 text-xs text-slate-300">
                            เหลือ <?= e((string)$goalDaysRemaining) ?> วัน · ต้องได้อีกวันละ
                            <span class="font-semibold text-orange-400"><?= e(formatMoney($goalPerDayRevenue)) ?></span>
                            ถึงเป้ารายได้
                        </p>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($goalTargetProfit !== null): ?>
                <article class="stat-card s-profit">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm text-slate-400">เป้ากำไร</p>
                        <p class="text-sm font-semibold text-green-400"><?= e(formatPercent($goalProgressProfit)) ?></p>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">ทำได้ <?= e(formatMoney($goalActualProfit)) ?> / เป้า <?= e(formatMoney($goalTargetProfit)) ?></p>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/10">
                        <div class="progress-green h-full rounded-full transition-all duration-500" style="width: <?= e(number_format($goalProfitProgressWidth, 1, '.', '')) ?>%"></div>
                    </div>
                    <?php if ($goalProfitReached): ?>
                        <p class="mt-2 text-xs font-medium text-green-400">🎉 เป้ากำไรสำเร็จแล้ว</p>
                    <?php elseif ($showProfitPace): ?>
                        <p class="mt-2 text-xs text-slate-300">
                            เหลือ <?= e((string)$goalDaysRemaining) ?> วัน · ต้องได้อีกวันละ
                            <span class="font-semibold text-green-400"><?= e(formatMoney($goalPerDayProfit)) ?></span>
                            ถึงเป้ากำไร
                        </p>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
        </div>

        <form id="delete-goal-form" action="<?= e(app_url('/api/goals.php')) ?>" method="post" class="mt-4" data-confirm="ยืนยันการลบเป้าหมายของเดือน <?= e($goalMonthLabel) ?> ใช่หรือไม่?">
            <?= csrf_field() ?>
        <?= shop_context_field($shopId) ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="goal_month" value="<?= e($goalMonth) ?>">
            <input type="hidden" name="redirect_to" value="<?= e($goalRedirectTo) ?>">

            <button type="submit" class="btn-danger px-4 py-2 text-sm">
                🗑️ ลบเป้าหมายเดือน <?= e($goalMonthLabel) ?>
            </button>
        </form>
    <?php endif; ?>
</section>

<div id="goal-modal" class="modal-bg fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="section-card w-full max-w-lg p-6 shadow-2xl shadow-black/50">
        <div class="mb-5 flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-100"><?= e($goalHasGoal ? '✏️ แก้ไขเป้าหมายเดือน ' . $goalMonthLabel : '🎯 ตั้งเป้าหมายเดือน ' . $goalMonthLabel) ?></h2>
            <button type="button" id="close-goal-modal" class="btn-ghost rounded-lg px-3 py-1 text-sm">ปิด ✕</button>
        </div>

        <form action="<?= e(app_url('/api/goals.php')) ?>" method="post" class="grid gap-4 md:grid-cols-2">
            <?= csrf_field() ?>
        <?= shop_context_field($shopId) ?>
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
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="เว้นว่าง = คงค่าเดิม">
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
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="เว้นว่าง = คงค่าเดิม">
            </div>

            <div class="md:col-span-2 flex items-center justify-end gap-2">
                <button type="button" id="cancel-goal-modal" class="btn-ghost px-4 py-2 text-sm">ยกเลิก</button>
                <button type="submit" class="btn-primary px-4 py-2 text-sm">💾 บันทึกเป้าหมาย</button>
            </div>
        </form>
    </div>
</div>

<?php if ($weekdayHasData): ?>
    <section class="section-card mt-6 p-4 sm:p-5">
        <h2 class="text-base sm:text-lg font-semibold text-slate-100">📅 เทียบวันในสัปดาห์</h2>
        <p class="mt-1 text-xs text-slate-400">
            วันล่าสุด: <?= e($weekdayName) ?> <?= e(formatThaiDate($weekdayTargetDate)) ?>
        </p>

        <?php $weekdayProfitClass = $weekdayTargetProfit >= 0 ? 'text-green-400' : 'text-red-400'; ?>

        <?php if (!$weekdayComparable): ?>
            <p class="mt-3 text-sm text-slate-300">
                กำไร <span class="font-semibold <?= e($weekdayProfitClass) ?>"><?= e(formatMoney($weekdayTargetProfit)) ?></span>
                · ยังไม่มีวัน<?= e($weekdayName) ?>อื่นใน<?= e($weekdayMonthLabel) ?> ให้เทียบ
            </p>
            <p class="mt-1 text-xs text-slate-400">
                รายได้ <?= e(formatMoney($weekdayTargetRevenue)) ?>
            </p>
        <?php else: ?>
            <div class="mt-3 space-y-1.5 text-sm text-slate-300">
                <p>
                    กำไร <span class="font-semibold <?= e($weekdayProfitClass) ?>"><?= e(formatMoney($weekdayTargetProfit)) ?></span>
                    · วัน<?= e($weekdayName) ?><?= e($weekdayMonthLabel) ?> เฉลี่ย
                    <span class="font-semibold <?= e(((float)$weekdayAvgProfit) >= 0 ? 'text-slate-100' : 'text-red-400') ?>"><?= e(formatMoney((float)$weekdayAvgProfit)) ?></span>
                    <span class="text-xs text-slate-400">(จาก <?= e((string)$weekdaySampleCount) ?> วัน)</span>
                </p>
                <?php if ($weekdayTargetRoas !== null || $weekdayAvgRoas !== null): ?>
                    <p>
                        ROAS วันล่าสุด <span class="font-semibold text-violet-400"><?= e(formatRoas($weekdayTargetRoas)) ?></span>
                        · เฉลี่ย <span class="font-semibold text-slate-100"><?= e(formatRoas($weekdayAvgRoas)) ?></span>
                    </p>
                <?php endif; ?>
                <?php if ($weekdayHint !== null): ?>
                    <p class="text-xs <?= e($weekdayHintClass) ?>"><?= e($weekdayHint) ?></p>
                <?php elseif ($weekdayComparable): ?>
                    <?php // มีค่าเฉลี่ยให้ดู แต่ยังน้อยเกินจะฟันธงว่าสูง/ต่ำกว่าปกติ ?>
                    <p class="text-xs text-slate-400">
                        ยังมีวัน<?= e($weekdayName) ?>ไม่ถึง <?= e((string)RecordService::WEEKDAY_MIN_SAMPLE) ?> วันใน<?= e($weekdayMonthLabel) ?> — ยังสรุปแนวโน้มไม่ได้
                    </p>
                <?php endif; ?>
                <p class="text-xs text-slate-400">
                    รายได้ <?= e(formatMoney($weekdayTargetRevenue)) ?>
                    · เฉลี่ย <?= e($weekdayAvgRevenue !== null ? formatMoney($weekdayAvgRevenue) : '–') ?>
                </p>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($breakdownHasData): ?>
    <section class="section-card mt-6 p-4 sm:p-5">
        <?php
        // คง filter ช่วงเวลาเดิมของ dashboard ไว้ตอนสลับ window (whitelist กันสะท้อน input มั่ว)
        $breakdownKeptQuery = [];
        foreach (['range', 'start_date', 'end_date', 'month'] as $keptKey) {
            if (isset($_GET[$keptKey]) && is_string($_GET[$keptKey])) {
                $breakdownKeptQuery[$keptKey] = (string)$_GET[$keptKey];
            }
        }
        $breakdownModeUrl = static function (string $mode) use ($breakdownKeptQuery): string {
            return app_url('/dashboard.php') . '?' . http_build_query(array_merge($breakdownKeptQuery, ['wd' => $mode]));
        };
        ?>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-base sm:text-lg font-semibold text-slate-100">
                กำไรเฉลี่ยตามวัน · <?= e($breakdownLabel) ?>
            </h2>
            <div class="flex shrink-0 gap-2">
                <a href="<?= e($breakdownModeUrl('8w')) ?>"
                    class="<?= $breakdownMode === '8w' ? 'btn-primary' : 'btn-ghost' ?> px-3 py-1.5 text-xs"
                    data-loading-link="true">8 สัปดาห์</a>
                <a href="<?= e($breakdownModeUrl('month')) ?>"
                    class="<?= $breakdownMode === 'month' ? 'btn-primary' : 'btn-ghost' ?> px-3 py-1.5 text-xs"
                    data-loading-link="true">เดือนนี้</a>
            </div>
        </div>

        <p class="mt-1 text-xs text-slate-400">
            <?= e(formatThaiDate((string)$breakdownWindow['start_date'])) ?> –
            <?= e(formatThaiDate((string)$breakdownWindow['end_date'])) ?> ·
            ยิ่ง "จาก N วัน" มาก ยิ่งเชื่อได้ · ไฮไลต์เฉพาะวันที่มีข้อมูลตั้งแต่
            <?= e((string)$breakdownMinSample) ?> วันขึ้นไป
        </p>

        <div class="mt-3 overflow-x-auto">
            <table class="table-cards min-w-full text-sm">
                <thead>
                    <tr class="border-b border-white/10 text-left text-slate-400">
                        <th class="px-3 py-2">วัน</th>
                        <th class="px-3 py-2">กำไรเฉลี่ย</th>
                        <th class="px-3 py-2">ROAS เฉลี่ย</th>
                        <th class="px-3 py-2">จาก</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdownRows as $breakdownRow): ?>
                        <?php
                        $rowWeekday = (int)($breakdownRow['weekday'] ?? 0);
                        $rowSampleCount = (int)($breakdownRow['sample_count'] ?? 0);
                        $rowAvgProfit = $breakdownRow['avg_profit'] ?? null;
                        $rowAvgRoas = $breakdownRow['avg_roas'] ?? null;

                        $rowClass = '';
                        if ($breakdownBestWeekday !== null && $rowWeekday === $breakdownBestWeekday) {
                            $rowClass = 'bg-green-500/10';
                        } elseif ($breakdownWorstWeekday !== null && $rowWeekday === $breakdownWorstWeekday) {
                            $rowClass = 'bg-amber-500/10';
                        }
                        ?>
                        <tr class="border-b border-white/[0.06] <?= e($rowClass) ?>">
                            <td class="px-3 py-2 font-medium text-slate-300"><?= e(formatThaiWeekday($rowWeekday)) ?></td>
                            <?php if ($rowSampleCount === 0 || $rowAvgProfit === null): ?>
                                <td class="px-3 py-2 text-slate-400">—</td>
                                <td class="px-3 py-2 text-slate-400">—</td>
                                <td class="px-3 py-2 text-slate-400">—</td>
                            <?php else: ?>
                                <td class="px-3 py-2 font-semibold <?= ((float)$rowAvgProfit) >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                                    <?= e(formatMoney((float)$rowAvgProfit)) ?>
                                </td>
                                <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($rowAvgRoas !== null ? (float)$rowAvgRoas : null)) ?></td>
                                <td class="px-3 py-2 text-xs text-slate-400"><?= e((string)$rowSampleCount) ?> วัน</td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="section-card mt-6 p-4 sm:p-5">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
        <h2 class="text-base sm:text-lg font-semibold text-slate-100">กราฟแท่งรายวัน (รายได้ vs ค่าแอด)</h2>
        <span class="text-xs text-slate-400">เฉพาะวันที่มีข้อมูล</span>
    </div>
    <?php /* ⚠️ ไม่มีข้อมูล = ไม่ต้องวาดกรอบกราฟเปล่าไว้ · เดิมยังวาดตารางกริดพร้อมแกน
             ฿0–฿1 ที่ระบบสร้างขึ้นเอง กินพื้นที่เกือบครึ่งจอในหน้าจอแรกที่ผู้ใช้ใหม่เห็น
             (หลักเดียวกับทั้งระบบ: ไม่มีข้อมูลก็ไม่ต้องแสดงตัวเลขที่ไม่มีอยู่จริง) */ ?>
    <?php if (!$hasDailyData): ?>
        <p class="text-sm text-slate-400">ยังไม่มีข้อมูลรายวันในช่วงเวลาที่เลือก</p>
    <?php else: ?>
    <div class="h-52 sm:h-64 lg:h-80 w-full overflow-x-auto">
        <div style="min-width: <?= max(100, count((array)($dailyChartPayload['dates'] ?? [])) * 60) ?>px; height: 100%;">
            <canvas id="daily-bar-chart"></canvas>
        </div>
    </div>
    <?php endif; ?>
</section>

<section class="section-card mt-6 p-4 sm:p-5">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
        <h2 class="text-base sm:text-lg font-semibold text-slate-100">แนวโน้มย้อนหลัง 6 เดือน</h2>
        <span class="text-xs text-slate-400">แสดงรายเดือนเสมอ</span>
    </div>
    <?php if (!$hasSixMonthData): ?>
        <p class="text-sm text-slate-400">ยังไม่มีข้อมูลย้อนหลัง 6 เดือน</p>
    <?php else: ?>
    <div class="h-52 sm:h-64 lg:h-80">
        <canvas id="six-month-line-chart"></canvas>
    </div>
    <?php endif; ?>
</section>
<?php endif; // $dashboardFailed ?>

<?php // ⚠️ สคริปต์ต้องอยู่ "นอก" บล็อกข้างบน — ตัวเลือกช่วงเวลาอยู่ในส่วนหัวซึ่งแสดงเสมอ
      // ถ้าสคริปต์หายไปพร้อมกับเนื้อหา ผู้ใช้จะเลือก "กำหนดเอง"/"เลือกเดือน" แล้วไม่มี
      // ช่องวันที่โผล่มาให้กรอกเลย ทั้งที่นั่นคือทางเดียวที่จะออกจากหน้าที่โหลดไม่สำเร็จ
      // (กราฟเช็ก canvas ก่อนวาดอยู่แล้ว ไม่มี canvas ก็ข้ามไปเงียบ ๆ) ?>
<script>
    (function() {
        const rangeForm = document.getElementById('dashboard-range-form');
        const rangeSelect = document.getElementById('range-type');
        const customFields = document.getElementById('custom-range-fields');
        const monthPickField = document.getElementById('month-pick-field');
        const startInput = document.getElementById('custom-start-date');
        const endInput = document.getElementById('custom-end-date');
        const monthInput = document.getElementById('month-pick-input');
        const goalModal = document.getElementById('goal-modal');
        const openGoalModalButtons = document.querySelectorAll('[data-open-goal-modal]');
        const closeGoalModalButton = document.getElementById('close-goal-modal');
        const cancelGoalModalButton = document.getElementById('cancel-goal-modal');
        const deleteGoalForm = document.getElementById('delete-goal-form');

        const dailyPayload = <?= json_encode($dailyChartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const sixMonthPayload = <?= json_encode($sixMonthPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const toggleRangeFields = () => {
            if (!rangeSelect) {
                return;
            }

            if (customFields) {
                if (rangeSelect.value === 'custom') {
                    customFields.classList.remove('hidden');
                } else {
                    customFields.classList.add('hidden');
                }
            }

            if (monthPickField) {
                if (rangeSelect.value === 'month_pick') {
                    monthPickField.classList.remove('hidden');
                } else {
                    monthPickField.classList.add('hidden');
                }
            }
        };

        const autoSubmitIfReady = () => {
            if (!rangeForm || !rangeSelect) {
                return;
            }

            if (rangeSelect.value === 'custom') {
                if (startInput && endInput && startInput.value !== '' && endInput.value !== '') {
                    rangeForm.submit();
                }
                return;
            }

            if (rangeSelect.value === 'month_pick') {
                if (monthInput && monthInput.value !== '') {
                    rangeForm.submit();
                }
                return;
            }

            if (rangeSelect.value !== 'custom') {
                rangeForm.submit();
                return;
            }
        };

        if (rangeSelect) {
            rangeSelect.addEventListener('change', () => {
                toggleRangeFields();
                autoSubmitIfReady();
            });
        }

        if (startInput) {
            startInput.addEventListener('change', autoSubmitIfReady);
        }

        if (endInput) {
            endInput.addEventListener('change', autoSubmitIfReady);
        }

        if (monthInput) {
            monthInput.addEventListener('change', autoSubmitIfReady);
        }

        toggleRangeFields();

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



        const dailyCanvas = document.getElementById('daily-bar-chart');
        if (dailyCanvas) {
            new Chart(dailyCanvas, {
                type: 'bar',
                data: {
                    labels: dailyPayload.labels,
                    datasets: [{
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
                                color: '#e2e8f0'
                            }
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
                    datasets: [{
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
                                color: '#e2e8f0'
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
<?php require __DIR__ . '/includes/footer.php'; ?>