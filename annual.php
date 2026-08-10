<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
// ⚠️ ต้องซ่อม session ก่อนดึงข้อมูล — ร้านอาจถูกลบจากอุปกรณ์อื่นไปแล้ว
// (เดิมการซ่อมอยู่ใน header.php ซึ่ง include ท้ายไฟล์ หน้าจึงขึ้น "ไม่มีสิทธิ์" + ฿0 หนึ่งครั้ง)
$shopId = resolve_current_shop_id($pdo, $userId);

$selectedYear = resolve_calendar_year($_GET['year'] ?? null);
$currentYear = (int)date('Y');

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$goalRepository = new GoalRepository($pdo);
$annualService = new AnnualService($recordRepository, $shopRepository, $goalRepository);
// ใช้ถามอย่างเดียวว่า "ร้านนี้เคยกรอกอะไรไหม" (ดูเหตุผลที่ `$showEmptyShopInvite`)
$recordService = new RecordService($recordRepository, $shopRepository);

$annualResult = $annualService->buildYearlySummary($userId, $shopId, $selectedYear);
$heatmapResult = $annualService->buildMonthlyHeatmap($userId, $shopId, $selectedYear);

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
        'projection' => ['available' => false, 'reason' => 'insufficient_data'],
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

    // ⚠️ โหลดไม่สำเร็จ = "ไม่รู้" ไม่ใช่ "ทุกอย่างเป็นศูนย์"
    // เดิมค่าตั้งต้น 12 เดือนศูนย์ถูกใช้แสดงผลต่อ หน้าจึงขึ้นตารางเทียบรายเดือน 12 แถว
    // การ์ด "เดือนกำไรดีสุด ม.ค. (฿0)" สีเขียว และคำเชิญ "ลองเริ่มบันทึกข้อมูล"
    // พร้อมกับแถบแดงบอกว่าไม่มีสิทธิ์เข้าถึงร้าน — ขัดกันเองทั้งหน้า
    $annualData['months'] = [];
    $annualData['summary'] = [];
    $annualData['goal_progress'] = [];
}

$months = array_values((array)($annualData['months'] ?? []));
$summary = (array)($annualData['summary'] ?? []);

$totalRevenue = (float)($summary['total_revenue'] ?? 0);
$totalAdCost = (float)($summary['total_ad_cost'] ?? 0);
$totalProfit = money_total((float)($summary['profit'] ?? ($totalRevenue - $totalAdCost)));
$totalRoas = isset($summary['roas']) && $summary['roas'] !== null ? (float)$summary['roas'] : null;
$totalProfitMargin = isset($summary['profit_margin']) && $summary['profit_margin'] !== null
    ? (float)$summary['profit_margin']
    : null;

$monthsWithData = (int)($summary['months_with_data'] ?? 0);
$monthOutcomes = annual_month_outcome_counts($summary);
$profitMonths = $monthOutcomes['profit'];
$lossMonths = $monthOutcomes['loss'];
$breakEvenMonths = $monthOutcomes['break_even'];
// ⚠️⚠️ "มีข้อมูลไหม" ต้องนับจาก **จำนวนวัน/เดือนที่กรอก** ไม่ใช่จากยอดเงิน
// วันที่กรอกไว้จริงแต่รายได้และค่าแอดเป็น 0 ทั้งคู่ (วันหยุด/ไม่ยิงแอด — ระบบอนุญาต)
// คือข้อมูลจริง · เดิมเช็กจากยอดเงิน หน้าจึงขึ้นแถบ "ยังไม่มีข้อมูล" คู่กับตาราง
// ที่แสดง "3 วัน" บนจอเดียวกัน (วัดจริงแล้ว)
$hasAnnualData = (int)($summary['months_with_data'] ?? 0) > 0;

/* ⚠️⚠️ "ร้านนี้ไม่เคยกรอกอะไรเลย" ≠ "ปีที่เลือกไม่มีข้อมูล" — สองอย่างนี้ต้องปฏิบัติต่างกัน
   · ปีที่เลือกไม่มีข้อมูล (แต่ร้านมีข้อมูลปีอื่น) → ยังต้องเห็น ฿0 เพราะนั่นคือคำตอบ
     ของคำถามที่ผู้ใช้ถามด้วยการเลือกปีนั้น
   · ร้านที่ยังไม่เคยกรอกอะไรเลย → ฿0 ไม่ใช่คำตอบ มันคือ "ยังไม่ได้เริ่ม"
   วัดจริงก่อนแก้ (ร้านใหม่ 0 แถว): หน้ารายปีกางการ์ด "ยอดขายรวมทั้งปี ฿0 · ค่าแอดรวมทั้งปี ฿0 ·
   กำไรรวมทั้งปี ฿0" อยู่ใต้ข้อความ "ยังไม่มีข้อมูลยอดขาย ลองเริ่มบันทึกข้อมูล" บนจอเดียวกัน
   ขณะที่ ROAS ในชุดการ์ดเดียวกันตอบ `–` ถูกต้องแล้ว — และแดชบอร์ดซ่อนการ์ดทั้งหมดในสถานะเดียวกัน
   (กติกาเดียวกับ `$showFirstRecordInvite` ใน dashboard.php) */
$lastRecordResult = $recordService->getDaysSinceLastRecord($userId, $shopId);
$shopHasEverRecorded = ($lastRecordResult['success'] ?? false) === true
    && (bool)($lastRecordResult['data']['has_records'] ?? false);
$showEmptyShopInvite = ($lastRecordResult['success'] ?? false) === true && !$shopHasEverRecorded;

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
// ⚠️ ยังไม่มีเดือนที่จบ ≠ ไม่มีข้อมูล
//
// การ์ดนี้ไม่นับเดือนปัจจุบันที่ยังไม่จบ (เทียบยอดสะสมกับเดือนที่กรอกครบไม่ได้)
// ผู้ใช้ที่เพิ่งเริ่มใช้เดือนนี้จึงไม่มีเดือนไหนเข้าเกณฑ์เลย · ขีด (–) อ่านได้ว่า
// "ไม่มีข้อมูล" หรือ "ข้อมูลที่กรอกหายไป" ซึ่งทั้งคู่ไม่จริงและน่าตกใจ
// ⚠️ ปีที่จบไปแล้วไม่มี "เดือนที่ยังไม่จบ" ให้รอ — ถ้ายังใช้ข้อความเดิมจะขัดกับ
// แถบ "ปี 2567 ยังไม่มีข้อมูลยอดขาย" ที่อยู่บนจอเดียวกัน
$isFinishedYear = $selectedYear < (int)date('Y');
$noFinishedMonthText = $isFinishedYear ? 'ไม่มีข้อมูลในปีนี้' : 'รอให้จบเดือนก่อน';

// ⚠️⚠️ "ดีสุด" กับ "แย่สุด" ต้องเป็นคนละเดือนจริง ๆ ถึงจะมีความหมาย
//
// วัดจริง: ร้านที่เริ่มกรอกเดือน ก.ค. แล้วเปิดหน้านี้วันที่ 7 ส.ค. (มีเดือนที่จบแล้ว
// เดือนเดียว) → "เดือนกำไรดีสุด ก.ค. (฿31,000)" คู่กับ "เดือนกำไรแย่สุด ก.ค. (฿31,000)"
// เดือนเดียวกัน ตัวเลขเดียวกัน สีตรงข้ามกัน · เกิดกับทุกร้านใน 1–2 เดือนแรกที่ใช้ระบบ
//
// เป็นกติกาเดียวกับการ์ดคู่ "วันดีสุด/แย่สุด" ซึ่งมีตัวกันนี้อยู่แล้ว — ระดับเดือนตกสำรวจ
$monthExtremesComparable = extremes_are_comparable($bestMonth, $worstMonth, 'month');

$singleMonthText = extremes_not_comparable_text();

$bestMonthText = $noFinishedMonthText;
$bestMonthProfit = null;
if ($bestMonth !== null) {
    $bestMonthProfit = $monthExtremesComparable ? (float)($bestMonth['profit'] ?? 0) : null;
    $bestMonthText = $monthExtremesComparable
        ? $monthLabel($bestMonth) . ' (' . formatMoney((float)($bestMonth['profit'] ?? 0)) . ')'
        : $singleMonthText;
}

$worstMonthText = $noFinishedMonthText;
$worstMonthProfit = null;
if ($worstMonth !== null) {
    $worstMonthProfit = $monthExtremesComparable ? (float)($worstMonth['profit'] ?? 0) : null;
    $worstMonthText = $monthExtremesComparable
        ? $monthLabel($worstMonth) . ' (' . formatMoney((float)($worstMonth['profit'] ?? 0)) . ')'
        : $singleMonthText;
}

// ประมาณการสิ้นปี — "ไม่ใช่ตัวเลขจริง" ต้องแยกออกจากการ์ดสรุปให้ชัด
$projection = (array)($summary['projection'] ?? []);
$hasProjection = ($projection['available'] ?? false) === true;
$projectionReason = (string)($projection['reason'] ?? '');

// heat map ฤดูกาล 3 ปี — ล้มเหลวก็แค่ไม่แสดง section (ไม่กระทบสรุปประจำปี)
$heatmapYears = [];
$heatmapGrid = [];
$heatmapPeak = 0.0;
// "ฤดูกาล" ต้องมีอย่างน้อย 2 ปีถึงจะเทียบได้ — ปีเดียวคือกริดที่ว่าง 2 ใน 3 แถว
// เกณฑ์เดียวกับที่ api/export-xlsx.php ใช้ตัดสินว่าจะสร้างชีต "ฤดูกาล" หรือไม่
$heatmapComparable = ($heatmapResult['data']['comparable'] ?? false) === true;
if (($heatmapResult['success'] ?? false) === true) {
    $heatmapYears = array_values((array)($heatmapResult['data']['years'] ?? []));
    $heatmapGrid = (array)($heatmapResult['data']['grid'] ?? []);

    // normalize ความเข้มด้วยค่าสูงสุดของ |กำไร| ทั้งกริด — เทียบข้ามปีได้ในสเกลเดียว
    foreach ($heatmapGrid as $gridRow) {
        foreach ((array)$gridRow as $cell) {
            // ⚠️ เดือนที่ยังไม่จบต้องไม่เข้าฐานคำนวณความเข้มด้วย — ยอดบางส่วนจะดึงสเกลให้เพี้ยน
            if (
                ($cell['has_data'] ?? false) === true
                && $cell['profit'] !== null
                && ($cell['is_unfinished'] ?? false) !== true
            ) {
                $heatmapPeak = max($heatmapPeak, abs((float)$cell['profit']));
            }
        }
    }
}

// เข้มตามสัดส่วน แต่ไม่จางจนมองไม่เห็น (ขั้นต่ำ 0.12)
$heatCellStyle = static function (array $cell) use ($heatmapPeak): string {
    if (($cell['has_data'] ?? false) !== true || $cell['profit'] === null) {
        return '';
    }

    // ⚠️ เดือนที่ยังไม่จบมียอดแค่บางส่วน ระบายสีเทียบกับเดือนที่จบแล้วไม่ได้
    // ไม่งั้นกริด "ฤดูกาล" จะบอกว่าเดือนปัจจุบันแย่สุดเสมอ ทั้งที่ยังตัดสินไม่ได้
    if (($cell['is_unfinished'] ?? false) === true) {
        return 'background-color: rgba(148, 163, 184, 0.10);';
    }

    $profit = (float)$cell['profit'];
    if (abs($profit) < 0.00001) {
        return 'background-color: rgba(148, 163, 184, 0.18);';   // เท่าทุน — เทา ไม่ใช่ว่าง
    }

    $ratio = $heatmapPeak > 0 ? min(1.0, abs($profit) / $heatmapPeak) : 1.0;
    // floor คุม alpha สุดท้าย (ไม่ใช่ ratio) — ไม่งั้นเดือนที่กำไรน้อยจะจางจนดูเหมือนไม่มีข้อมูล
    $alpha = max(0.12, $ratio * 0.55);
    $rgb = $profit > 0 ? '34, 197, 94' : '239, 68, 68';

    return sprintf('background-color: rgba(%s, %.2f);', $rgb, $alpha);
};

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

// ⚠️⚠️ null แปลว่า "เทียบเป็น % ไม่ได้" ไม่ใช่ "ปีก่อนไม่มีข้อมูล"
// ปีก่อนที่บันทึกครบทั้งปีแต่กำไรรวมเป็น ฿0 พอดี ก็ได้ null เหมือนกัน
// เดิมเขียนว่า "ไม่มีข้อมูลปีก่อน" ตายตัว ซึ่งขัดกับบรรทัดถัดลงมาบนจอเดียวกัน
// ที่พิมพ์ "ปีก่อนช่วงเดียวกัน ฿0" (อ่านว่าเป็นตัวเลขจริง)
$prevYearHasData = ($summary['prev_year_has_data'] ?? false) === true;
// อ่านข้อมูลปีก่อนไม่สำเร็จ — ต้องบอกตามจริง ไม่ใช่บอกว่า "ไม่มีข้อมูล" พร้อม ฿0
$prevYearUnavailable = ($summary['prev_year_unavailable'] ?? false) === true;

// แสดง % การเปลี่ยนแปลง — helper กลาง (null ต้องไม่กลายเป็น 0%)
$formatYoyPercent = static fn(?float $percent): string => format_change_badge($percent, no_value_text())['text'];
$yoyToneClass = static fn(?float $percent): string => format_change_badge($percent, no_value_text())['class'];

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
            <p class="mt-1 text-xs sm:text-sm text-slate-400">ภาพรวมรายปีของร้านที่เลือก พร้อมตารางรายเดือน เทียบปีก่อน และกราฟเปรียบเทียบ</p>
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

        <?php // ไม่มีข้อมูลให้ดาวน์โหลด = ไม่ต้องมีปุ่ม (กดไปก็ล้มด้วยเหตุผลเดียวกัน) ?>
        <?php if ($annualError === null): ?>
            <a href="<?= e(app_url('/api/export-xlsx.php?year=' . rawurlencode((string)$selectedYear))) ?>"
                data-loading-link="true" class="btn-teal px-4 py-2 text-sm">
                📊 ดาวน์โหลดรายงานประจำปี (Excel)
            </a>
        <?php endif; ?>
    </div>

    <?php if ($annualError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-sm text-red-400">
            <?= e($annualError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasAnnualData && $annualError === null): ?>
        <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-400">
            <?php /* ⚠️ ห้ามเขียน "ปีนี้" ตายตัว — ผู้ใช้เลือกดูปีอื่นได้ */ ?>
            ปี <?= e((string)($selectedYear + 543)) ?> ยังไม่มีข้อมูลยอดขาย ลองเริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
        </div>
    <?php endif; ?>
</section>

<?php if ($annualError !== null): ?>
    <?php // โหลดไม่สำเร็จ = ไม่รู้ตัวเลข → ไม่แสดงการ์ด/ตาราง/กราฟใด ๆ เลย
          // เดิมทุกอย่างยังเรนเดอร์ด้วยค่าตั้งต้น ฿0 หน้าจึงบอกทั้ง "ไม่มีสิทธิ์"
          // และ "ทั้งปีทำได้ ฿0 · เดือนกำไรดีสุด ม.ค." พร้อมกันในหน้าเดียว ?>
<?php else: ?>

<?php /* ⚠️⚠️ ต้องเป็น `if` ของตัวเอง ห้ามต่อเป็น `elseif` ของ `$annualError` ข้างบน —
         `else:` ของบล็อกนั้นครอบยาวไปจนจบหน้า (ตาราง กราฟ ประมาณการ กริดฤดูกาล)
         ต่อเป็น elseif แล้วทุกอย่างหายทั้งก้อน (เคยพลาดแบบนี้มาแล้วที่ dashboard.php
         และจับได้ตอนวัดหน้าจริง ไม่ใช่ตอนอ่านโค้ด) */ ?>
<?php if (!$showEmptyShopInvite): ?>
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
<?php endif; ?>

<section class="section-card mt-4 px-4 py-3 sm:px-5">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span class="text-xs font-medium uppercase tracking-wider text-slate-400">
            เทียบ <?= e((string)($prevYear + 543)) ?>
            <?php if (count($months) > 0 && count($months) < 12): ?>
                (ม.ค.–<?= e($thaiMonths[count($months)] ?? '') ?>)
            <?php endif; ?>
        </span>
        <?php if ($yoyPercent === null): ?>
            <span class="text-base font-bold text-slate-400">
                <?php if ($prevYearUnavailable): ?>
                    โหลดข้อมูลปีก่อนไม่สำเร็จ — เทียบให้ไม่ได้ตอนนี้
                <?php else: ?>
                    <?= $prevYearHasData ? 'ปีก่อนเท่าทุนพอดี เทียบเป็น % ไม่ได้' : 'ไม่มีข้อมูลปีก่อน' ?>
                <?php endif; ?>
            </span>
        <?php else: ?>
            <span class="text-base font-bold <?= e($yoyToneClass($yoyPercent)) ?>">
                กำไร <?= e($formatYoyPercent($yoyPercent)) ?>
            </span>
            <span class="text-sm <?= e($yoyToneClass($yoyPercent)) ?>">
                (<?= e(($yoyChange >= 0 ? '+' : '-') . formatMoney(abs($yoyChange))) ?>)
            </span>
            <span class="text-xs text-slate-400">
                ปีก่อน <?= e(formatMoney($prevYearProfit)) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php /* ⚠️ ร้านที่ยังไม่เคยกรอกเลย ไม่มี "กำไรสะสม" ให้พูดถึง — เดิมพิมพ์ ฿0
             ทั้งที่ยังไม่ได้เริ่ม (กติกาเดียวกับการ์ดตัวเลขด้านบน) */ ?>
    <?php if (count($months) > 0 && !$showEmptyShopInvite): ?>
        <div class="mt-2 border-t border-white/[0.06] pt-2 text-sm text-slate-400">
            กำไรสะสม ณ <?= e($thaiMonths[count($months)] ?? '') ?>
            <span class="font-bold <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></span>
            <span class="text-slate-400">·</span>
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
            <span class="text-slate-400">·</span>
            กำไร <span class="font-medium text-green-400"><?= e((string)$profitMonths) ?></span>
            <span class="text-slate-400">/</span>
            ขาดทุน <span class="font-medium <?= $lossMonths > 0 ? 'text-red-400' : 'text-slate-300' ?>"><?= e((string)$lossMonths) ?></span>
            <?php if ($breakEvenMonths > 0): ?>
                <span class="text-slate-400">·</span>
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
                            <span class="text-slate-400">/ <?= e(formatMoney($goalTargetRevenue)) ?></span>
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
                            <span class="text-slate-400">/ <?= e(formatMoney($goalTargetProfit)) ?></span>
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
        <table class="table-cards min-w-full text-sm">
            <thead>
                <tr class="border-b border-white/10 text-left text-slate-400">
                    <th scope="col" class="px-3 py-2">เดือน</th>
                    <th scope="col" class="px-3 py-2">ยอดขาย</th>
                    <th scope="col" class="px-3 py-2">ค่าแอด</th>
                    <th scope="col" class="px-3 py-2">กำไร</th>
                    <th scope="col" class="px-3 py-2">ROAS</th>
                    <th scope="col" class="px-3 py-2">อัตรากำไร</th>
                    <th scope="col" class="px-3 py-2">วันที่กรอก</th>
                    <th scope="col" class="px-3 py-2">กำไร/วัน</th>
                    <th scope="col" class="px-3 py-2">เทียบ <?= e((string)($prevYear + 543)) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($months as $row): ?>
                    <?php
                    $rowRevenue = (float)($row['total_revenue'] ?? 0);
                    $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                    $rowProfit = money_total((float)($row['profit'] ?? ($rowRevenue - $rowAdCost)));
                    $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                    $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                    $rowDaysCount = (int)($row['days_count'] ?? 0);
                    $rowProfitPerDay = isset($row['profit_per_day']) && $row['profit_per_day'] !== null
                        ? (float)$row['profit_per_day']
                        : null;
                    $rowYoyPercent = isset($row['yoy_change_percent']) && $row['yoy_change_percent'] !== null
                        ? (float)$row['yoy_change_percent']
                        : null;

                    /* ⚠️⚠️ เดือนที่ "ยังไม่ได้กรอกเลยสักวัน" ≠ เดือนที่ "ทำได้ ฿0"
                       เดิมช่องเงินพิมพ์ ฿0 และช่องเทียบปีก่อนพิมพ์ ↓ 100.0% ขณะที่ช่อง
                       ROAS/อัตรากำไร/วันที่กรอก ในแถวเดียวกันพิมพ์ "—" ว่าไม่มีข้อมูล
                       → แถวเดียวกันพูดสองอย่าง และร้านที่เพิ่งเริ่มใช้กลางปีจะเห็นครึ่งปีแรก
                       เป็น "ตก 100%" ทั้งที่แค่ยังไม่ได้เริ่มบันทึก
                       (หลักเดียวกับทั้งระบบ: หน้าเว็บห้ามเดาแทนข้อมูล) */
                    $rowHasData = $rowDaysCount > 0;
                    $blank = no_value_text();
                    ?>
                    <?php /* ⚠️ `row-no-data` = บนจอแคบให้ยุบเหลือบรรทัดเดียว
                             การ์ดบนมือถือแสดง 1 แถว/1 ใบ เดือนที่ไม่มีข้อมูลจึงกลายเป็นการ์ดเต็มใบ
                             9 บรรทัดที่มีแต่ขีด · วัดจริงบนจอ 375: 6 จาก 8 การ์ดเป็นแบบนั้น
                             กินพื้นที่ 1,884 จาก 2,512px (75%) = ต้องปัดผ่านความว่างเปล่าราว 2 จอครึ่ง
                             กว่าจะถึงเดือนที่มีข้อมูลจริง (CSS อยู่ใน `includes/header.php`) */ ?>
                    <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap<?= $rowHasData ? '' : ' row-no-data text-slate-400' ?>">
                        <td class="px-3 py-2 text-slate-300 font-medium" data-empty-note="ยังไม่มีข้อมูล"><?= e($monthLabel($row)) ?></td>
                        <td class="px-3 py-2 font-medium <?= $rowHasData ? 'text-orange-400' : 'text-slate-400' ?>"><?= e($rowHasData ? formatMoney($rowRevenue) : $blank) ?></td>
                        <td class="px-3 py-2 font-medium <?= $rowHasData ? 'text-cyan-400' : 'text-slate-400' ?>"><?= e($rowHasData ? formatMoney($rowAdCost) : $blank) ?></td>
                        <td class="px-3 py-2 font-bold <?= !$rowHasData ? 'text-slate-400' : ($rowProfit >= 0 ? 'text-green-400' : 'text-red-400') ?>"><?= e($rowHasData ? formatMoney($rowProfit) : $blank) ?></td>
                        <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($rowRoas)) ?></td>
                        <td class="px-3 py-2 text-slate-400 font-medium"><?= e(formatPercent($rowProfitMargin)) ?></td>
                        <td class="px-3 py-2 text-slate-400 font-medium"><?= e($rowHasData ? $rowDaysCount . ' วัน' : $blank) ?></td>
                        <td class="px-3 py-2 font-medium <?= $rowProfitPerDay === null ? 'text-slate-400' : ($rowProfitPerDay >= 0 ? 'text-green-400' : 'text-red-400') ?>">
                            <?= e($rowProfitPerDay === null ? $blank : formatMoney($rowProfitPerDay)) ?>
                        </td>
                        <?php // ไม่มีข้อมูลของเดือนนี้ = เทียบกับปีก่อนไม่ได้ ไม่ใช่ "ตก 100%" ?>
                        <td class="px-3 py-2 font-medium <?= e($rowHasData ? $yoyToneClass($rowYoyPercent) : 'text-slate-400') ?>"><?= e($rowHasData ? $formatYoyPercent($rowYoyPercent) : $blank) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t border-white/10 bg-white/[0.03] font-semibold">
                    <td class="px-3 py-3 text-slate-200">รวมทั้งปี</td>
                    <?php /* ⚠️ ทุกแถวเดือนเหนือแถวนี้เป็นขีดเมื่อไม่มีข้อมูล — แถวรวมต้องพูดภาษาเดียวกัน
                             ไม่งั้นตารางที่ว่างทั้งตารางลงท้ายด้วย "รวมทั้งปี ฿0" ซึ่งอ่านว่า
                             "ทำมาทั้งปีได้ศูนย์" (กติกาเดียวกับที่ ROAS/อัตรากำไร ในแถวนี้ทำอยู่แล้ว) */ ?>
                    <td class="px-3 py-3 text-orange-400"><?= e($totalDaysCount > 0 ? formatMoney($totalRevenue) : no_value_text()) ?></td>
                    <td class="px-3 py-3 text-cyan-400"><?= e($totalDaysCount > 0 ? formatMoney($totalAdCost) : no_value_text()) ?></td>
                    <td class="px-3 py-3 <?= $totalDaysCount <= 0 ? 'text-slate-400' : ($totalProfit >= 0 ? 'text-green-400' : 'text-red-400') ?>"><?= e($totalDaysCount > 0 ? formatMoney($totalProfit) : no_value_text()) ?></td>
                    <td class="px-3 py-3 text-violet-400"><?= e(formatRoas($totalRoas)) ?></td>
                    <td class="px-3 py-3 text-slate-300"><?= e(formatPercent($totalProfitMargin)) ?></td>
                    <td class="px-3 py-3 text-slate-300"><?= e($totalDaysCount > 0 ? $totalDaysCount . ' วัน' : no_value_text()) ?></td>
                    <td class="px-3 py-3 <?= $totalProfitPerDay === null ? 'text-slate-400' : ($totalProfitPerDay >= 0 ? 'text-green-400' : 'text-red-400') ?>">
                        <?= e($totalProfitPerDay === null ? no_value_text() : formatMoney($totalProfitPerDay)) ?>
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
            <canvas id="annual-bar-chart" role="img" aria-label="กราฟแท่งรายได้ ค่าแอด และกำไรของแต่ละเดือน — ตัวเลขชุดเดียวกันอยู่ในตารางรายเดือนด้านบน"></canvas>
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
            <canvas id="annual-cumulative-chart" role="img" aria-label="กราฟเส้นกำไรสะสมตลอดปี — คำนวณจากตัวเลขในตารางรายเดือนด้านบน"></canvas>
        </div>
    </div>
</section>

<?php if ($heatmapComparable): ?>
    <section class="section-card mt-6 p-4 sm:p-5">
        <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
            <h2 class="text-base sm:text-lg font-semibold text-slate-100">ฤดูกาลกำไร (3 ปี)</h2>
            <span class="text-xs text-slate-400">เดือนเดียวกันเขียวหลายปีติด = ฤดูกาลขายจริง ไม่ใช่ฟลุ๊ค</span>
        </div>
        <div class="overflow-x-auto">
            <?php /* ⚠️⚠️ ตารางนี้ **ห้ามแปลงเป็นการ์ด** ต่างจากตารางอื่นทั้งระบบ
                     มันคือตารางความร้อนที่ต้องอ่านเป็น 2 มิติ — จุดประสงค์ทั้งหมดคือ
                     "เดือนเดียวกันเทียบข้ามหลายปีติด ๆ" (เขียนไว้ใต้หัวข้อนี้เอง)
                     · วัดจริงตอนเป็นการ์ด (จอ 375): แต่ละปีกลายเป็นการ์ดสูง 393px เรียง 12 เดือน
                       ลงมา รวม 3 ปี = 1,209px → ม.ค. ของปีหนึ่งอยู่ห่างจาก ม.ค. ของอีกปีราว 400px
                       **เทียบข้ามปีไม่ได้เลย** ซึ่งเป็นเหตุผลเดียวที่ส่วนนี้มีอยู่
                     · ใช้ `sticky-first-col` ตรึงคอลัมน์ปีไว้แล้วเลื่อนแนวนอนแทน — ปัดทีละ 3–4 เดือน
                       แต่ยังเห็นทั้ง 3 ปีเรียงกันในแนวตั้ง ซึ่งคือการเปรียบเทียบที่ต้องการ
                       (หลักเดียวกับตารางกรอกหลายวันในหน้าบันทึก) */ ?>
            <table class="sticky-first-col min-w-[46rem] text-xs">
                <thead>
                    <tr class="border-b border-white/10 text-left text-slate-400">
                        <th scope="col" class="px-2 py-2">ปี</th>
                        <?php for ($heatMonth = 1; $heatMonth <= 12; $heatMonth++): ?>
                            <th scope="col" class="px-2 py-2 text-center"><?= e($thaiMonths[$heatMonth] ?? '') ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($heatmapYears as $heatYear): ?>
                        <tr class="border-b border-white/[0.06] whitespace-nowrap">
                            <td class="px-2 py-2 font-medium text-slate-300"><?= e((string)((int)$heatYear + 543)) ?></td>
                            <?php for ($heatMonth = 1; $heatMonth <= 12; $heatMonth++): ?>
                                <?php
                                $heatCell = (array)($heatmapGrid[$heatYear][$heatMonth] ?? []);
                                $heatHasData = ($heatCell['has_data'] ?? false) === true;
                                $heatProfit = $heatHasData && $heatCell['profit'] !== null ? (float)$heatCell['profit'] : null;
                                ?>
                                <td class="px-2 py-2 text-center font-medium <?= $heatProfit === null ? 'text-slate-400' : 'text-slate-100' ?>"
                                    style="<?= e($heatCellStyle($heatCell)) ?>"
                                    title="<?= e($thaiMonths[$heatMonth] . ' ' . ((int)$heatYear + 543) . ': ' . ($heatProfit === null ? 'ไม่มีข้อมูล' : formatMoney($heatProfit))) ?>">
                                    <?= e($heatProfit === null ? '–' : formatMoney($heatProfit)) ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-400">
            <span class="flex items-center gap-1">
                <span class="inline-block h-3 w-5 rounded" style="background-color: rgba(34, 197, 94, 0.55);"></span> กำไร
            </span>
            <span class="flex items-center gap-1">
                <span class="inline-block h-3 w-5 rounded" style="background-color: rgba(239, 68, 68, 0.55);"></span> ขาดทุน
            </span>
            <span class="flex items-center gap-1">
                <span class="inline-block h-3 w-5 rounded" style="background-color: rgba(148, 163, 184, 0.18);"></span> เท่าทุน
            </span>
            <span class="flex items-center gap-1">
                <span class="inline-block h-3 w-5 rounded border border-white/10"></span> ไม่มีข้อมูล
            </span>
            <span class="text-slate-400">· ยิ่งเข้มยิ่งกำไร/ขาดทุนมาก (เทียบกับเดือนที่สุดในกริด)</span>
        </div>
    </section>
<?php endif; ?>

<?php if ($hasProjection): ?>
    <?php
    $projectionLow = (float)($projection['projection_low'] ?? 0);
    $projectionMid = (float)($projection['projection_mid'] ?? 0);
    $projectionHigh = (float)($projection['projection_high'] ?? 0);
    // ป้ายต้องบอกทั้งเดือนเต็มและเศษของเดือนนี้ ไม่งั้นตัวเลขที่โชว์อธิบายผลลัพธ์ไม่ได้
    // ข้อความมาจาก helper ตัวเดียวกับที่ไฟล์ Excel ใช้ — เดิมคัดลอกไว้ 2 ที่แล้วเพี้ยนกัน
    $projectionRemainingText = projection_remaining_label($projection);
    // ช่วงคร่อม 0 = ยังบอกไม่ได้ว่าจะจบปีบวกหรือลบ — ไม่ควรระบายเขียว
    $projectionTone = $projectionHigh < 0
        ? 'text-red-400'
        : ($projectionLow >= 0 ? 'text-slate-200' : 'text-amber-400');
    ?>
    <section class="mt-6 rounded-2xl border border-dashed border-white/10 bg-white/[0.015] p-4 sm:p-5">
        <div class="mb-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-2">
            <h2 class="text-sm font-semibold text-slate-400">🔮 ประมาณการสิ้นปี (ไม่ใช่ตัวเลขจริง)</h2>
            <span class="text-xs text-slate-400">เหลืออีก <?= e($projectionRemainingText) ?></span>
        </div>

        <p class="text-xl sm:text-2xl font-bold <?= e($projectionTone) ?>">
            <?= e(formatMoney($projectionLow)) ?> – <?= e(formatMoney($projectionHigh)) ?>
        </p>
        <p class="mt-1 text-sm text-slate-400">
            กลาง <span class="font-medium text-slate-400"><?= e(formatMoney($projectionMid)) ?></span>
        </p>

        <p class="mt-3 border-t border-white/[0.06] pt-2 text-xs leading-relaxed text-slate-400">
            <?= e(projection_footnote_text($projection)) ?>
        </p>
    </section>
<?php elseif ($projectionReason === 'insufficient_data' && $hasAnnualData): ?>
    <section class="mt-6 rounded-2xl border border-dashed border-white/10 bg-white/[0.015] px-4 py-3 sm:px-5">
        <p class="text-xs text-slate-400">
            🔮 ข้อมูลยังไม่พอประมาณการสิ้นปี — ต้องมีอย่างน้อย 2 เดือนที่กรอกไปแล้วเกินครึ่งเดือน
        </p>
    </section>
<?php endif; ?>

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
<?php endif; // $annualError ?>
<?php require __DIR__ . '/includes/footer.php'; ?>