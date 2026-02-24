<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$selectedDate = (string)($_GET['date'] ?? date('Y-m-d'));
$selectedDateObject = DateTime::createFromFormat('Y-m-d', $selectedDate);
if (!$selectedDateObject || $selectedDateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
    $selectedDateObject = DateTime::createFromFormat('Y-m-d', $selectedDate);
}
$selectedDateForDisplay = $selectedDateObject ? $selectedDateObject->format('d/m/Y') : $selectedDate;

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$recentRecords = $recordService->getRecentRecords($userId, $shopId, 7);

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'บันทึกข้อมูลรายวัน';
$currentPage = 'add-record';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-5">
    <h1 class="text-xl font-semibold text-slate-100">บันทึกข้อมูลรายวัน</h1>
    <p class="mt-2 text-sm text-slate-500">กรอกวันซ้ำจะอัปเดตทับอัตโนมัติ</p>

    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" class="mt-5 grid gap-4 md:grid-cols-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upsert">

        <div>
            <label for="record-date" class="mb-1 block text-sm text-slate-300">วันที่</label>
            <div class="relative">
                <input
                    id="record-date"
                    name="record_date_display"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    spellcheck="false"
                    required
                    maxlength="10"
                    placeholder="วัน/เดือน/ปี เช่น 24/02/2026"
                    value="<?= e($selectedDateForDisplay) ?>"
                    class="w-full rounded-xl border border-white/10 bg-[#070c18] px-4 py-3 pr-12 text-sm text-slate-200">
                <input id="record-date-iso" name="record_date" type="hidden" value="<?= e($selectedDate) ?>">

                <div class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-400">📅</div>
                <input
                    id="record-date-picker"
                    type="date"
                    value="<?= e($selectedDate) ?>"
                    aria-label="เลือกวันที่"
                    class="absolute right-1 top-1/2 h-9 w-11 -translate-y-1/2 cursor-pointer opacity-0">
            </div>
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

<script>
    (function() {
        const displayInput = document.getElementById('record-date');
        const isoInput = document.getElementById('record-date-iso');
        const pickerInput = document.getElementById('record-date-picker');
        if (!displayInput || !isoInput) return;

        const pad2 = (value) => String(value).padStart(2, '0');

        const isoToDisplay = (iso) => {
            const match = String(iso || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!match) return '';
            return `${match[3]}/${match[2]}/${match[1]}`;
        };

        const displayToIso = (value) => {
            const match = String(value || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!match) return '';

            const dd = parseInt(match[1], 10);
            const mm = parseInt(match[2], 10);
            const yyyy = parseInt(match[3], 10);
            if (!yyyy || mm < 1 || mm > 12 || dd < 1 || dd > 31) return '';

            const date = new Date(yyyy, mm - 1, dd);
            if (date.getFullYear() !== yyyy || date.getMonth() !== (mm - 1) || date.getDate() !== dd) return '';

            return `${yyyy}-${pad2(mm)}-${pad2(dd)}`;
        };

        const setError = () => {
            displayInput.setCustomValidity('กรุณากรอกวันที่เป็นรูปแบบ วัน/เดือน/ปี เช่น 24/02/2026');
            displayInput.reportValidity();
        };

        const clearError = () => {
            displayInput.setCustomValidity('');
        };

        const syncFromDisplay = () => {
            const iso = displayToIso(displayInput.value);
            isoInput.value = iso;
            if (pickerInput && iso) {
                pickerInput.value = iso;
            }
            clearError();
        };

        displayInput.addEventListener('input', syncFromDisplay);

        displayInput.addEventListener('blur', () => {
            const raw = String(displayInput.value || '').trim();
            if (raw === '') {
                isoInput.value = '';
                setError();
                return;
            }

            const iso = displayToIso(raw);
            if (!iso) {
                isoInput.value = '';
                setError();
                return;
            }

            isoInput.value = iso;
            if (pickerInput) {
                pickerInput.value = iso;
            }
            displayInput.value = isoToDisplay(iso);
            clearError();
        });

        if (pickerInput) {
            pickerInput.addEventListener('change', () => {
                const iso = String(pickerInput.value || '').trim();
                if (!iso) return;
                isoInput.value = iso;
                displayInput.value = isoToDisplay(iso);
                clearError();
            });
        }

        const form = displayInput.form;
        if (form) {
            form.addEventListener('submit', (event) => {
                const iso = displayToIso(displayInput.value);
                if (!iso) {
                    setError();
                    event.preventDefault();
                    return;
                }

                isoInput.value = iso;
                if (pickerInput) {
                    pickerInput.value = iso;
                }
                clearError();
            });
        }
    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>