<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
$selectedMonth = (string)($_GET['month'] ?? date('Y-m'));
if (preg_match('/^\d{4}-\d{2}$/', $selectedMonth) !== 1) {
    $selectedMonth = date('Y-m');
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$overviewService = new OverviewService($recordRepository, $shopRepository);

$overviewResult = $overviewService->buildOverview($userId, $selectedMonth);

$overviewError = null;
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
            ];
        },
        $trendSeriesRaw
    )),
];

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'รวมทุกร้าน';
$currentPage = 'overview';

require __DIR__ . '/includes/header.php';
?>
<section class="rounded-xl border border-slate-700 bg-slate-800 p-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">หน้ารวมทุกร้าน</h1>
            <p class="mt-2 text-sm text-slate-300">ตารางและกราฟเปรียบเทียบทุกร้านตามเดือนที่เลือก</p>
        </div>

        <form method="get" action="<?= e(app_url('/overview.php')) ?>" class="flex flex-wrap items-center gap-2">
            <label for="overview-month" class="text-sm text-slate-300">เลือกเดือน</label>
            <input
                id="overview-month"
                name="month"
                type="month"
                value="<?= e($selectedMonth) ?>"
                class="rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-sm focus:border-cyan-400 focus:outline-none"
            >
            <button type="submit" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-600">แสดงผล</button>
        </form>
    </div>

    <?php if ($overviewError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">
            <?= e($overviewError) ?>
        </div>
    <?php endif; ?>

    <?php if (!$canView): ?>
        <p class="mt-4 text-sm text-slate-300">ต้องมีอย่างน้อย 2 ร้านถึงจะเห็นข้อมูลเปรียบเทียบ</p>
    <?php else: ?>
        <?php if (!$hasOverviewData): ?>
            <div class="mt-4 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-3 py-2 text-sm text-cyan-100">
                เดือนนี้ยังไม่มีข้อมูลยอดขายของทุกร้าน แนะนำให้เริ่มบันทึกข้อมูลที่หน้า "➕ บันทึก"
            </div>
        <?php endif; ?>

        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="border-b border-slate-700 text-left text-slate-400">
                    <th class="px-3 py-2">ร้าน</th>
                    <th class="px-3 py-2">ยอดขาย</th>
                    <th class="px-3 py-2">ค่าแอด</th>
                    <th class="px-3 py-2">กำไร</th>
                    <th class="px-3 py-2">ROAS</th>
                    <th class="px-3 py-2">อัตรากำไร</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($comparisonRows as $row): ?>
                    <?php
                    $rowRevenue = (float)($row['total_revenue'] ?? 0);
                    $rowAdCost = (float)($row['total_ad_cost'] ?? 0);
                    $rowProfit = (float)($row['profit'] ?? ($rowRevenue - $rowAdCost));
                    $rowRoas = isset($row['roas']) && $row['roas'] !== null ? (float)$row['roas'] : null;
                    $rowProfitMargin = isset($row['profit_margin']) && $row['profit_margin'] !== null ? (float)$row['profit_margin'] : null;
                    ?>
                    <tr class="border-b border-slate-700/70">
                        <td class="px-3 py-2 text-slate-100"><?= e((string)($row['shop_name'] ?? 'ร้านค้า')) ?></td>
                        <td class="px-3 py-2 text-orange-400"><?= e(formatMoney($rowRevenue)) ?></td>
                        <td class="px-3 py-2 text-cyan-400"><?= e(formatMoney($rowAdCost)) ?></td>
                        <td class="px-3 py-2 <?= $rowProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($rowProfit)) ?></td>
                        <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($rowRoas)) ?></td>
                        <td class="px-3 py-2 text-slate-200"><?= e(formatPercent($rowProfitMargin)) ?></td>
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
                <tr class="border-t border-slate-600 bg-slate-900/60 font-semibold">
                    <td class="px-3 py-2 text-slate-100">รวมทุกร้าน</td>
                    <td class="px-3 py-2 text-orange-400"><?= e(formatMoney($totalRevenue)) ?></td>
                    <td class="px-3 py-2 text-cyan-400"><?= e(formatMoney($totalAdCost)) ?></td>
                    <td class="px-3 py-2 <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></td>
                    <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($totalRoas)) ?></td>
                    <td class="px-3 py-2 text-slate-100"><?= e(formatPercent($totalProfitMargin)) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <section class="mt-6 rounded-xl border border-slate-700 bg-slate-900/40 p-5">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">กราฟแท่งเปรียบเทียบระหว่างร้าน</h2>
                <span class="text-xs text-slate-400">ยอดขาย / ค่าแอด / กำไร</span>
            </div>
            <div class="h-80">
                <canvas id="overview-bar-chart"></canvas>
            </div>
        </section>

        <section class="mt-6 rounded-xl border border-slate-700 bg-slate-900/40 p-5">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">กราฟเส้นแนวโน้มทุกร้าน (6 เดือนย้อนหลัง)</h2>
                <span class="text-xs text-slate-400">เส้นแต่ละร้าน = ยอดขายรายเดือน</span>
            </div>
            <div class="h-80">
                <canvas id="overview-trend-chart"></canvas>
            </div>
        </section>

        <script>
            (function () {
                const barPayload = <?= json_encode($barPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                const trendPayload = <?= json_encode($trendPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

                const barCanvas = document.getElementById('overview-bar-chart');
                if (barCanvas) {
                    new Chart(barCanvas, {
                        type: 'bar',
                        data: {
                            labels: barPayload.labels,
                            datasets: [
                                {
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

                const trendCanvas = document.getElementById('overview-trend-chart');
                if (trendCanvas) {
                    const colors = ['#f97316', '#06b6d4', '#22c55e', '#eab308', '#a78bfa', '#f43f5e'];
                    const datasets = (trendPayload.series || []).map((series, index) => {
                        const color = colors[index % colors.length];

                        return {
                            label: series.shop_name || 'ร้านค้า',
                            data: series.revenue || [],
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
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
