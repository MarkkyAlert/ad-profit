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
$annualService = new AnnualService($recordRepository, $shopRepository);

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
    ];
}

$annualError = null;
$annualData = [
    'year' => $selectedYear,
    'months' => $zeroMonths,
    'summary' => [
        'total_revenue' => 0.0,
        'total_ad_cost' => 0.0,
        'profit' => 0.0,
        'roas' => null,
        'best_month' => $zeroMonths[0],
        'worst_month' => $zeroMonths[0],
    ],
    'chart' => [
        'months' => array_values(array_map(static fn(array $row): string => (string)$row['month_key'], $zeroMonths)),
        'revenue' => array_fill(0, 12, 0.0),
        'ad_cost' => array_fill(0, 12, 0.0),
        'profit' => array_fill(0, 12, 0.0),
    ],
];

if (($annualResult['success'] ?? false) === true) {
    $annualData = array_replace_recursive($annualData, (array)($annualResult['data'] ?? []));
} else {
    $annualError = (string)($annualResult['error'] ?? 'ไม่สามารถโหลดข้อมูลสรุปประจำปีได้');
}

$months = array_values((array)($annualData['months'] ?? []));
$summary = (array)($annualData['summary'] ?? []);

$totalRevenue = (float)($summary['total_revenue'] ?? 0);
$totalAdCost = (float)($summary['total_ad_cost'] ?? 0);
$totalProfit = (float)($summary['profit'] ?? ($totalRevenue - $totalAdCost));
$totalRoas = isset($summary['roas']) && $summary['roas'] !== null ? (float)$summary['roas'] : null;
$totalProfitMargin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : null;
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

$bestMonthText = '–';
if ($bestMonth !== null) {
    $bestMonthText = $monthLabel($bestMonth) . ' (' . formatMoney((float)($bestMonth['total_revenue'] ?? 0)) . ')';
}

$worstMonthText = '–';
if ($worstMonth !== null) {
    $worstMonthText = $monthLabel($worstMonth) . ' (' . formatMoney((float)($worstMonth['total_revenue'] ?? 0)) . ')';
}

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
<section class="section-card p-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">สรุปประจำปี</h1>
            <p class="mt-2 text-sm text-slate-300">ภาพรวมรายปีของร้านที่เลือก พร้อมตาราง 12 เดือนและกราฟเปรียบเทียบ</p>
        </div>

        <form method="get" action="<?= e(app_url('/annual.php')) ?>" class="flex flex-wrap items-center gap-2">
            <label for="annual-year" class="text-sm text-slate-300">เลือกปี (พ.ศ.)</label>
            <select id="annual-year" name="year" class="rounded-xl border border-white/10 bg-white/[0.06] px-3 py-2 text-sm">
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
        <div class="mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">
            <?= e($annualError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$hasAnnualData): ?>
        <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-100">
            ปีนี้ยังไม่มีข้อมูลยอดขาย ลองเริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
        </div>
    <?php endif; ?>
</section>

<section class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
    <article class="stat-card s-revenue">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ยอดขายรวมทั้งปี</p>
        <p class="mt-2 text-xl font-bold text-orange-400"><?= e(formatMoney($totalRevenue)) ?></p>
    </article>
    <article class="stat-card s-adcost">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ค่าแอดรวมทั้งปี</p>
        <p class="mt-2 text-xl font-bold text-cyan-400"><?= e(formatMoney($totalAdCost)) ?></p>
    </article>
    <article class="stat-card s-profit">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">กำไรรวมทั้งปี</p>
        <p class="mt-2 text-xl font-bold <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></p>
    </article>
    <article class="stat-card s-roas">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">ROAS เฉลี่ยทั้งปี</p>
        <p class="mt-2 text-xl font-bold text-violet-400"><?= e(formatRoas($totalRoas)) ?></p>
    </article>
    <article class="stat-card s-best">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">เดือนขายดีสุด</p>
        <p class="mt-2 text-base font-bold text-green-400"><?= e($bestMonthText) ?></p>
    </article>
    <article class="stat-card s-worst">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">เดือนขายแย่สุด</p>
        <p class="mt-2 text-base font-bold text-red-400"><?= e($worstMonthText) ?></p>
    </article>
</section>

<section class="section-card mt-6 p-5">
    <h2 class="mb-3 text-lg font-semibold">ตารางเทียบรายเดือน (12 เดือน)</h2>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="border-b border-white/[0.08] text-left text-slate-400">
                <th class="px-3 py-2">เดือน</th>
                <th class="px-3 py-2">ยอดขาย</th>
                <th class="px-3 py-2">ค่าแอด</th>
                <th class="px-3 py-2">กำไร</th>
                <th class="px-3 py-2">ROAS</th>
                <th class="px-3 py-2">อัตรากำไร</th>
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
                ?>
                <tr class="border-b border-white/[0.06]">
                    <td class="px-3 py-2 text-slate-100"><?= e($monthLabel($row)) ?></td>
                    <td class="px-3 py-2 text-orange-400"><?= e(formatMoney($rowRevenue)) ?></td>
                    <td class="px-3 py-2 text-cyan-400"><?= e(formatMoney($rowAdCost)) ?></td>
                    <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($rowProfit)) ?></td>
                    <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($rowRoas)) ?></td>
                    <td class="px-3 py-2 text-slate-200"><?= e(formatPercent($rowProfitMargin)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr class="border-t border-white/[0.10] bg-white/[0.03] font-semibold">
                <td class="px-3 py-2 text-slate-100">รวมทั้งปี</td>
                <td class="px-3 py-2 text-orange-400"><?= e(formatMoney($totalRevenue)) ?></td>
                <td class="px-3 py-2 text-cyan-400"><?= e(formatMoney($totalAdCost)) ?></td>
                <td class="px-3 py-2 <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></td>
                <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($totalRoas)) ?></td>
                <td class="px-3 py-2 text-slate-100"><?= e(formatPercent($totalProfitMargin)) ?></td>
            </tr>
            </tfoot>
        </table>
    </div>
</section>

<section class="section-card mt-6 p-5">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="text-lg font-semibold">กราฟแท่งรายเดือน (12 เดือน)</h2>
        <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร</span>
    </div>
    <div class="h-80">
        <canvas id="annual-bar-chart"></canvas>
    </div>
</section>

<script>
    (function () {
        const chartPayload = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartCanvas = document.getElementById('annual-bar-chart');

        if (!chartCanvas) {
            return;
        }

        new Chart(chartCanvas, {
            type: 'bar',
            data: {
                labels: chartPayload.labels,
                datasets: [
                    {
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
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(255, 255, 255, 0.06)' }
                    },
                    y: {
                        ticks: {
                            color: '#94a3b8',
                            callback: (value) => '฿' + Number(value).toLocaleString('th-TH')
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.06)' }
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
    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
