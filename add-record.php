<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$recentRecords = $recordService->getRecentRecords($userId, $shopId, 7);

// วันที่ยังไม่ได้กรอกของเดือนปัจจุบัน (ใช้โชว์ banner + เติมลงตาราง bulk)
$currentMonth = date('Y-m');
$unfilledResult = $recordService->getUnfilledDatesForMonth($userId, $shopId, $currentMonth);
$missingDates = ($unfilledResult['success'] ?? false) === true
    ? array_values((array)($unfilledResult['data']['missing_dates'] ?? []))
    : [];
$missingCount = count($missingDates);

// แสดงรายการวันแบบไทยไม่เกิน 10 วัน ที่เหลือสรุปเป็น "และอีก N วัน"
$missingPreviewLimit = 10;
$missingPreviewText = implode(', ', array_map(
    static fn(string $date): string => formatThaiDate($date),
    array_slice($missingDates, 0, $missingPreviewLimit)
));
if ($missingCount > $missingPreviewLimit) {
    $missingPreviewText .= ' และอีก ' . ($missingCount - $missingPreviewLimit) . ' วัน';
}

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'บันทึกข้อมูลรายวัน';
$currentPage = 'add-record';

require __DIR__ . '/includes/header.php';
?>
<style>
    .date-input-formatted {
        position: relative;
        color: transparent !important;
    }

    .date-input-formatted::before {
        content: attr(data-date);
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #e2e8f0;
        pointer-events: none;
    }

    .date-input-formatted::-webkit-calendar-picker-indicator {
        opacity: 1;
        cursor: pointer;
    }
</style>
<section class="section-card p-5">
    <h1 class="text-xl font-semibold text-slate-100">บันทึกข้อมูลรายวัน</h1>
    <p class="mt-2 text-sm text-slate-500">กรอกวันซ้ำจะอัปเดตทับอัตโนมัติ</p>

    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" class="mt-5 grid gap-4 md:grid-cols-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upsert">

        <div>
            <label for="record-date" class="mb-1 block text-sm text-slate-300">วันที่</label>
            <input
                id="record-date"
                name="record_date"
                type="date"
                data-date="<?= e(date('d/m/Y')) ?>"
                value="<?= e(date('Y-m-d')) ?>"
                required
                class="w-full rounded-xl px-4 py-2.5 transition-all date-input-formatted"
                style="position: relative;"
                onchange="this.setAttribute('data-date', this.value ? this.value.split('-').reverse().join('/') : '')">
        </div>

        <div>
            <label for="revenue" class="mb-1 block text-sm text-slate-300">รายได้ (฿)</label>
            <input
                id="revenue"
                name="revenue"
                type="number"
                min="0"
                step="0.01"
                required
                class="w-full rounded-xl px-4 py-2.5 transition-all">
        </div>

        <div>
            <label for="ad-cost" class="mb-1 block text-sm text-slate-300">ค่าแอด (฿)</label>
            <input
                id="ad-cost"
                name="ad_cost"
                type="number"
                min="0"
                step="0.01"
                required
                class="w-full rounded-xl px-4 py-2.5 transition-all">
        </div>

        <div class="md:col-span-2">
            <label for="note" class="mb-1 block text-sm text-slate-300">โน้ต (ไม่บังคับ)</label>
            <textarea
                id="note"
                name="note"
                rows="3"
                maxlength="255"
                class="w-full rounded-xl px-4 py-2.5 transition-all"
                placeholder="เช่น แอดชุดใหม่เริ่มวิ่ง"></textarea>
        </div>

        <div class="md:col-span-2">
            <button type="submit" class="btn-orange px-6 py-2.5 text-base shadow-sm">
                ✓ บันทึกข้อมูล
            </button>
        </div>
    </form>
</section>

<section class="section-card mt-6 p-5">
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-semibold text-slate-100">กรอกหลายวัน</h2>
        <span id="bulk-row-count" class="text-xs text-slate-400"></span>
    </div>
    <p class="mb-4 text-sm text-slate-500">
        กรอกได้สูงสุด <?= e((string)RecordService::BULK_MAX_ROWS) ?> แถวต่อครั้ง · แถวที่เว้นว่างไว้ทั้งแถวจะถูกข้าม ·
        ถ้ามีแถวใดกรอกผิด ระบบจะไม่บันทึกทั้งชุด
    </p>

    <?php if ($missingCount > 0): ?>
        <div class="mb-4 rounded-xl border border-amber-500/25 bg-amber-500/10 px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-amber-200">
                        เดือนนี้ยังไม่ได้กรอก <?= e((string)$missingCount) ?> วัน
                    </p>
                    <p class="mt-1 break-words text-xs text-amber-100/70"><?= e($missingPreviewText) ?></p>
                </div>
                <button type="button" id="bulk-fill-missing" class="btn-ghost shrink-0 px-4 py-2 text-sm">
                    ↓ เติมวันที่ขาดลงตาราง
                </button>
            </div>
            <p id="bulk-fill-notice" class="mt-2 hidden text-xs text-amber-100/70"></p>
        </div>
    <?php else: ?>
        <div class="mb-4 rounded-xl border border-green-500/25 bg-green-500/10 px-4 py-3">
            <p class="text-sm font-medium text-green-200">กรอกครบทุกวันแล้ว 🎉</p>
        </div>
    <?php endif; ?>

    <form action="<?= e(app_url('/api/records.php')) ?>" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_upsert">

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-white/10 text-left text-slate-400">
                        <th class="px-2 py-2 w-10">#</th>
                        <th class="px-2 py-2">วันที่</th>
                        <th class="px-2 py-2">รายได้ (฿)</th>
                        <th class="px-2 py-2">ค่าแอด (฿)</th>
                        <th class="px-2 py-2">โน้ต</th>
                        <th class="px-2 py-2 w-12"></th>
                    </tr>
                </thead>
                <tbody id="bulk-rows"></tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button type="button" id="bulk-add-row" class="btn-ghost px-4 py-2 text-sm">+ เพิ่มแถว</button>
            <button type="submit" class="btn-orange px-6 py-2.5 text-base shadow-sm">✓ บันทึกทั้งหมด</button>
        </div>
    </form>
</section>

<template id="bulk-row-template">
    <tr class="border-b border-white/[0.06]">
        <td class="px-2 py-2 text-slate-500 bulk-row-number"></td>
        <td class="px-2 py-2">
            <input name="record_date[]" type="date" class="w-full rounded-lg px-2 py-1.5 text-sm">
        </td>
        <td class="px-2 py-2">
            <input name="revenue[]" type="number" min="0" step="0.01" class="w-full rounded-lg px-2 py-1.5 text-sm" placeholder="0.00">
        </td>
        <td class="px-2 py-2">
            <input name="ad_cost[]" type="number" min="0" step="0.01" class="w-full rounded-lg px-2 py-1.5 text-sm" placeholder="0.00">
        </td>
        <td class="px-2 py-2">
            <input name="note[]" type="text" maxlength="255" class="w-full rounded-lg px-2 py-1.5 text-sm" placeholder="ไม่บังคับ">
        </td>
        <td class="px-2 py-2 text-center">
            <button type="button" class="bulk-remove-row text-red-400 hover:text-red-300 text-lg leading-none" title="ลบแถว">×</button>
        </td>
    </tr>
</template>

<script>
    (function() {
        const MAX_ROWS = <?= (int)RecordService::BULK_MAX_ROWS ?>;
        const INITIAL_ROWS = 5;

        const tbody = document.getElementById('bulk-rows');
        const template = document.getElementById('bulk-row-template');
        const addButton = document.getElementById('bulk-add-row');
        const counter = document.getElementById('bulk-row-count');

        if (!tbody || !template || !addButton) {
            return;
        }

        const refresh = () => {
            const rows = tbody.querySelectorAll('tr');
            rows.forEach((row, index) => {
                const numberCell = row.querySelector('.bulk-row-number');
                if (numberCell) {
                    numberCell.textContent = String(index + 1);
                }
            });

            if (counter) {
                counter.textContent = rows.length + ' / ' + MAX_ROWS + ' แถว';
            }

            addButton.disabled = rows.length >= MAX_ROWS;
            addButton.classList.toggle('opacity-50', addButton.disabled);
            addButton.classList.toggle('cursor-not-allowed', addButton.disabled);
        };

        const addRow = () => {
            if (tbody.querySelectorAll('tr').length >= MAX_ROWS) {
                return;
            }

            tbody.appendChild(template.content.cloneNode(true));
            refresh();
        };

        // ลบแถว: เหลือแถวสุดท้ายให้ล้างค่าแทนการลบ (กันตารางว่างเปล่า)
        tbody.addEventListener('click', (event) => {
            const button = event.target.closest('.bulk-remove-row');
            if (!button) {
                return;
            }

            const row = button.closest('tr');
            if (!row) {
                return;
            }

            if (tbody.querySelectorAll('tr').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach((input) => {
                    input.value = '';
                });
            }

            refresh();
        });

        addButton.addEventListener('click', addRow);

        for (let index = 0; index < INITIAL_ROWS; index++) {
            addRow();
        }

        // ── เติมวันที่ยังไม่ได้กรอกลงตาราง ──────────────────────────────
        const MISSING_DATES = <?= json_encode(
            $missingDates,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        ) ?>;

        const fillButton = document.getElementById('bulk-fill-missing');
        const fillNotice = document.getElementById('bulk-fill-notice');

        if (fillButton && MISSING_DATES.length > 0) {
            fillButton.addEventListener('click', () => {
                const dates = MISSING_DATES.slice(0, MAX_ROWS);

                tbody.innerHTML = '';
                dates.forEach((date) => {
                    addRow();
                    const rows = tbody.querySelectorAll('tr');
                    const dateInput = rows[rows.length - 1].querySelector('input[name="record_date[]"]');
                    if (dateInput) {
                        dateInput.value = date;
                    }
                });

                refresh();

                if (fillNotice) {
                    if (MISSING_DATES.length > MAX_ROWS) {
                        fillNotice.textContent = 'เติมให้ ' + MAX_ROWS + ' วันแรก (ขาดทั้งหมด '
                            + MISSING_DATES.length + ' วัน) — บันทึกชุดนี้ก่อนแล้วกดเติมอีกครั้ง';
                        fillNotice.classList.remove('hidden');
                    } else {
                        fillNotice.classList.add('hidden');
                    }
                }

                tbody.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        }
    })();
</script>

<section class="section-card mt-6 p-5">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-100">รายการล่าสุด 7 วัน</h2>
        <a href="<?= e(app_url('/history.php')) ?>" class="text-sm text-indigo-400 hover:text-indigo-300 font-medium">ดูประวัติทั้งหมด</a>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-white/10 text-left text-slate-400">
                    <th class="px-3 py-2">วันที่</th>
                    <th class="px-3 py-2">รายได้</th>
                    <th class="px-3 py-2">ค่าแอด</th>
                    <th class="px-3 py-2">กำไร</th>
                    <th class="px-3 py-2">ROAS</th>
                    <th class="px-3 py-2">โน้ต</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentRecords)): ?>
                    <tr>
                        <td colspan="6" class="px-3 py-4 text-center text-slate-400">ยังไม่มีข้อมูลในร้านนี้</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentRecords as $record): ?>
                        <?php
                        $revenue = (float)($record['revenue'] ?? 0);
                        $adCost = (float)($record['ad_cost'] ?? 0);
                        $profit = $revenue - $adCost;
                        $roas = $adCost > 0 ? round($revenue / $adCost, 2) : null;
                        $note = (string)($record['note'] ?? '');
                        ?>
                        <tr class="border-b border-white/[0.06]">
                            <td class="px-3 py-2 text-slate-300 font-medium"><?= e(formatThaiDate((string)($record['record_date'] ?? ''))) ?></td>
                            <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($revenue)) ?></td>
                            <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($adCost)) ?></td>
                            <td class="px-3 py-2 <?= $profit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($profit)) ?></td>
                            <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($roas)) ?></td>
                            <td class="px-3 py-2 text-slate-400"><?= $note !== '' ? e($note) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>