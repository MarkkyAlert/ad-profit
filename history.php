<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$selectedMonth = (string)($_GET['month'] ?? date('Y-m'));
if (preg_match('/^\d{4}-\d{2}$/', $selectedMonth) !== 1) {
    $selectedMonth = date('Y-m');
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$historyResult = $recordService->getMonthlyRecords($userId, $shopId, $selectedMonth);

$records = [];
$totals = [
    'total_revenue' => 0.0,
    'total_ad_cost' => 0.0,
    'total_profit' => 0.0,
    'avg_roas' => null,
];

if (($historyResult['success'] ?? false) === true) {
    $records = (array)($historyResult['data']['records'] ?? []);
    $totals = array_merge($totals, (array)($historyResult['data']['totals'] ?? []));
} else {
    set_flash('error', (string)($historyResult['error'] ?? 'ไม่สามารถโหลดข้อมูลประวัติได้'));
}

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'ประวัติรายการ';
$currentPage = 'history';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-100">ประวัติรายการ</h1>
            <p class="mt-2 text-sm text-slate-500">ตารางรายการของเดือนที่เลือก พร้อมแก้ไข/ลบได้ทันที</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <form method="get" action="<?= e(app_url('/history.php')) ?>" class="flex flex-wrap items-center gap-2">
                <label for="month" class="text-sm text-slate-400">เลือกเดือน</label>
                <input
                    id="month"
                    name="month"
                    type="month"
                    value="<?= e($selectedMonth) ?>"
                    class="rounded-xl px-3 py-2 text-sm transition-all">
                <button type="submit" class="btn-ghost px-4 py-2 text-sm">แสดงผล</button>
            </form>

            <a href="<?= e(app_url('/api/export.php?month=' . rawurlencode($selectedMonth))) ?>" data-loading-link="true" class="btn-teal px-4 py-2 text-sm">
                📥 Export CSV
            </a>
        </div>
    </div>

    <div class="mt-5 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-white/10 text-left text-slate-400">
                    <th class="px-3 py-2">วันที่</th>
                    <th class="px-3 py-2">รายได้</th>
                    <th class="px-3 py-2">ค่าแอด</th>
                    <th class="px-3 py-2">กำไร</th>
                    <th class="px-3 py-2">ROAS</th>
                    <th class="px-3 py-2">เทียบเมื่อวาน</th>
                    <th class="px-3 py-2">โน้ต</th>
                    <th class="px-3 py-2 text-center">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" class="px-3 py-4 text-center text-slate-400">ยังไม่มีข้อมูลในเดือนนี้</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $recordId = (int)($record['id'] ?? 0);
                        $recordDate = (string)($record['record_date'] ?? '');
                        $revenue = (float)($record['revenue'] ?? 0);
                        $adCost = (float)($record['ad_cost'] ?? 0);
                        $profit = (float)($record['profit'] ?? ($revenue - $adCost));
                        $roas = isset($record['roas']) ? (is_null($record['roas']) ? null : (float)$record['roas']) : null;
                        $comparePercent = isset($record['compare_revenue_percent'])
                            ? (is_null($record['compare_revenue_percent']) ? null : (float)$record['compare_revenue_percent'])
                            : null;
                        $note = (string)($record['note'] ?? '');
                        ?>
                        <tr class="border-b border-white/[0.06] table-row-hover">
                            <td class="px-3 py-2 text-slate-300 font-medium"><?= e(formatThaiDate($recordDate)) ?></td>
                            <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($revenue)) ?></td>
                            <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($adCost)) ?></td>
                            <td class="px-3 py-2 <?= $profit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($profit)) ?></td>
                            <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($roas)) ?></td>
                            <td class="px-3 py-2 font-medium">
                                <?php if ($comparePercent === null): ?>
                                    <span class="text-slate-400">–</span>
                                <?php else: ?>
                                    <?php
                                    $isPositive = $comparePercent >= 0;
                                    $arrow = $isPositive ? '↑' : '↓';
                                    $compareText = $arrow . ' ' . ($comparePercent > 0 ? '+' : '') . number_format($comparePercent, 1) . '%';
                                    ?>
                                    <span class="<?= $isPositive ? 'text-green-400' : 'text-red-400' ?>"><?= e($compareText) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2 text-slate-400"><?= $note !== '' ? e($note) : '-' ?></td>
                            <td class="px-3 py-2 text-center">
                                <div class="inline-flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn-ghost rounded-lg px-2 py-1 text-xs"
                                        data-edit-button
                                        data-record-id="<?= e((string)$recordId) ?>"
                                        data-record-date="<?= e($recordDate) ?>"
                                        data-revenue="<?= e(number_format($revenue, 2, '.', '')) ?>"
                                        data-ad-cost="<?= e(number_format($adCost, 2, '.', '')) ?>"
                                        data-note="<?= e($note) ?>">
                                        ✏️ แก้ไข
                                    </button>

                                    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" data-delete-form data-confirm="ยืนยันการลบรายการนี้ใช่หรือไม่?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                        <input type="hidden" name="record_id" value="<?= e((string)$recordId) ?>">
                                        <button type="submit" class="btn-danger rounded-lg px-2 py-1 text-xs">
                                            🗑️ ลบ
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

            <tfoot>
                <tr class="border-t border-white/10 bg-white/[0.03] font-semibold">
                    <td class="px-3 py-3 text-slate-200">รวม</td>
                    <td class="px-3 py-3 text-orange-400"><?= e(formatMoney((float)$totals['total_revenue'])) ?></td>
                    <td class="px-3 py-3 text-cyan-400"><?= e(formatMoney((float)$totals['total_ad_cost'])) ?></td>
                    <?php $totalProfit = (float)$totals['total_profit']; ?>
                    <td class="px-3 py-3 <?= $totalProfit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($totalProfit)) ?></td>
                    <td class="px-3 py-3 text-violet-400"><?= e(formatRoas(isset($totals['avg_roas']) ? (is_null($totals['avg_roas']) ? null : (float)$totals['avg_roas']) : null)) ?></td>
                    <td class="px-3 py-3 text-slate-400">–</td>
                    <td class="px-3 py-3 text-slate-400">–</td>
                    <td class="px-3 py-3 text-slate-400">–</td>
                </tr>
            </tfoot>
        </table>
    </div>
</section>

<div id="edit-modal" class="modal-bg fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="section-card w-full max-w-lg p-6 shadow-2xl shadow-indigo-900/10">
        <div class="mb-5 flex items-center justify-between">
            <h2 class="text-lg font-bold text-slate-100">✏️ แก้ไขรายการ</h2>
            <button type="button" id="close-edit-modal" class="btn-ghost rounded-lg px-3 py-1 text-sm">ปิด ✕</button>
        </div>

        <form action="<?= e(app_url('/api/records.php')) ?>" method="post" class="grid gap-4 md:grid-cols-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
            <input type="hidden" name="record_id" id="edit-record-id" value="">

            <div>
                <label for="edit-record-date" class="mb-1 block text-sm text-slate-300">วันที่</label>
                <input id="edit-record-date" name="record_date" type="date" required class="w-full rounded-xl px-4 py-2.5 transition-all">
            </div>

            <div>
                <label for="edit-revenue" class="mb-1 block text-sm text-slate-300">รายได้ (฿)</label>
                <input id="edit-revenue" name="revenue" type="number" min="0" step="0.01" required class="w-full rounded-xl px-4 py-2.5 transition-all">
            </div>

            <div>
                <label for="edit-ad-cost" class="mb-1 block text-sm text-slate-300">ค่าแอด (฿)</label>
                <input id="edit-ad-cost" name="ad_cost" type="number" min="0" step="0.01" required class="w-full rounded-xl px-4 py-2.5 transition-all">
            </div>

            <div class="md:col-span-2">
                <label for="edit-note" class="mb-1 block text-sm text-slate-300">โน้ต</label>
                <textarea id="edit-note" name="note" rows="3" maxlength="255" class="w-full rounded-xl px-4 py-2.5 transition-all"></textarea>
            </div>

            <div class="md:col-span-2 flex items-center justify-end gap-2">
                <button type="button" id="cancel-edit-modal" class="btn-ghost px-4 py-2 text-sm">ยกเลิก</button>
                <button type="submit" class="btn-primary px-4 py-2 text-sm">💾 บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function() {
        const modal = document.getElementById('edit-modal');
        const closeButton = document.getElementById('close-edit-modal');
        const cancelButton = document.getElementById('cancel-edit-modal');
        const editButtons = document.querySelectorAll('[data-edit-button]');
        const deleteForms = document.querySelectorAll('[data-delete-form]');

        const fields = {
            recordId: document.getElementById('edit-record-id'),
            recordDate: document.getElementById('edit-record-date'),
            revenue: document.getElementById('edit-revenue'),
            adCost: document.getElementById('edit-ad-cost'),
            note: document.getElementById('edit-note')
        };

        const openModal = () => {
            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        };

        const closeModal = () => {
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };

        editButtons.forEach((button) => {
            button.addEventListener('click', () => {
                fields.recordId.value = button.dataset.recordId || '';
                fields.recordDate.value = button.dataset.recordDate || '';
                fields.revenue.value = button.dataset.revenue || '';
                fields.adCost.value = button.dataset.adCost || '';
                fields.note.value = button.dataset.note || '';
                openModal();
            });
        });

        [closeButton, cancelButton].forEach((button) => {
            if (!button) {
                return;
            }

            button.addEventListener('click', closeModal);
        });

        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });
        }


    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>