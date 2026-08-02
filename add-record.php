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

        <p class="mb-2 text-xs text-slate-400">
            💡 วางจาก Excel / Google Sheets ได้ — คัดลอกหลายแถว (วันที่ · รายได้ · ค่าแอด · โน้ต)
            แล้วกด <kbd class="rounded bg-white/10 px-1">Ctrl</kbd>+<kbd class="rounded bg-white/10 px-1">V</kbd>
            ในช่องที่ต้องการเริ่มวาง
        </p>
        <p id="bulk-paste-notice" class="mb-3 hidden text-xs text-amber-200"></p>

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

        // ── วางจาก Excel / Google Sheets (TSV) ──────────────────────────
        const COLUMN_NAMES = ['record_date[]', 'revenue[]', 'ad_cost[]', 'note[]'];
        const pasteNotice = document.getElementById('bulk-paste-notice');

        const getRows = () => Array.from(tbody.querySelectorAll('tr'));
        const pad2 = (value) => String(value).padStart(2, '0');

        // รองรับ YYYY-MM-DD และ D/M/YYYY (+ แปลง พ.ศ. 2400–2700) — นอกเหนือจากนี้คืน null
        const parseDateCell = (raw) => {
            const value = String(raw).trim();
            if (value === '') {
                return null;
            }

            let year;
            let month;
            let day;

            let matched = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
            if (matched) {
                year = Number(matched[1]);
                month = Number(matched[2]);
                day = Number(matched[3]);
            } else {
                matched = value.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
                if (!matched) {
                    return null;
                }
                day = Number(matched[1]);
                month = Number(matched[2]);
                year = Number(matched[3]);
            }

            if (year >= 2400 && year <= 2700) {
                year -= 543; // พ.ศ. → ค.ศ.
            }

            if (month < 1 || month > 12 || day < 1 || day > 31) {
                return null;
            }

            return year + '-' + pad2(month) + '-' + pad2(day);
        };

        const normalizeCell = (columnIndex, raw) => {
            if (columnIndex === 0) {
                // parse ไม่ได้ → คืนค่าดิบ ให้ฝั่ง server เป็นคน reject
                return parseDateCell(raw) || String(raw).trim();
            }

            if (columnIndex === 1 || columnIndex === 2) {
                return String(raw).replace(/[฿,\s]/g, '').trim();
            }

            return String(raw).trim();
        };

        tbody.addEventListener('paste', (event) => {
            const target = event.target;
            if (!target || target.tagName !== 'INPUT') {
                return;
            }

            const clipboard = event.clipboardData || window.clipboardData;
            const text = clipboard ? clipboard.getData('text') : '';
            if (text === '') {
                return;
            }

            // ค่าเดี่ยว (ไม่มี tab/newline) → ปล่อยให้เบราว์เซอร์วางตามปกติ
            if (!text.includes('\t') && !text.includes('\n') && !text.includes('\r')) {
                return;
            }

            event.preventDefault();

            const lines = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
            while (lines.length > 0 && lines[lines.length - 1].trim() === '') {
                lines.pop();
            }
            if (lines.length === 0) {
                return;
            }

            const grid = lines.map((line) => line.split('\t'));

            const startRow = getRows().indexOf(target.closest('tr'));
            const startCol = COLUMN_NAMES.indexOf(target.getAttribute('name'));
            if (startRow < 0 || startCol < 0) {
                return;
            }

            // ข้าม header — เช็กเฉพาะตอนวางเริ่มที่คอลัมน์วันที่ (กันตัดข้อมูลทิ้งผิด)
            if (startCol === 0 && grid.length > 1 && parseDateCell(grid[0][0]) === null) {
                grid.shift();
            }

            let placedRows = 0;
            let truncatedRows = 0;

            grid.forEach((cells, rowOffset) => {
                const rowIndex = startRow + rowOffset;

                while (getRows().length <= rowIndex && getRows().length < MAX_ROWS) {
                    addRow();
                }

                const rows = getRows();
                if (rowIndex >= rows.length) {
                    truncatedRows++;
                    return;
                }

                const row = rows[rowIndex];
                cells.forEach((cell, cellOffset) => {
                    const columnIndex = startCol + cellOffset;
                    if (columnIndex > 3) {
                        return; // เกินคอลัมน์สุดท้าย (โน้ต)
                    }

                    const input = row.querySelector('input[name="' + COLUMN_NAMES[columnIndex] + '"]');
                    if (input) {
                        input.value = normalizeCell(columnIndex, cell);
                    }
                });

                placedRows++;
            });

            refresh();

            if (pasteNotice) {
                if (truncatedRows > 0) {
                    pasteNotice.textContent = 'วางได้ ' + placedRows + ' แถว · ส่วนที่เกิน '
                        + MAX_ROWS + ' แถว (' + truncatedRows + ' แถว) ถูกตัด';
                    pasteNotice.classList.remove('hidden');
                } else {
                    pasteNotice.classList.add('hidden');
                }
            }
        });
    })();
</script>

<section class="section-card mt-6 p-5">
    <h2 class="text-lg font-semibold text-slate-100">นำเข้าไฟล์ CSV</h2>
    <p class="mt-1 text-sm text-slate-500">
        รองรับคอลัมน์ <span class="text-slate-300">วันที่ · รายได้ · ค่าแอด · โน้ต</span>
        (หัวภาษาอังกฤษ date/revenue/ad_cost/note ก็ได้) ·
        คอลัมน์ที่คำนวณเอง เช่น กำไร/ROAS และแถว "รวม" จะถูกข้ามให้อัตโนมัติ
    </p>
    <p class="mt-1 text-xs text-slate-500">
        ไฟล์ที่ดาวน์โหลดจากหน้าประวัติ นำกลับเข้ามาได้เลย · วันเดียวกันจะอัปเดตทับ ·
        ถ้ามีแถวใดผิด ระบบจะไม่บันทึกทั้งไฟล์ · สูงสุด <?= e((string)RecordService::IMPORT_MAX_ROWS) ?> แถว / 2MB
    </p>

    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" enctype="multipart/form-data"
        class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_csv">

        <input
            type="file"
            name="csv"
            accept=".csv,text/csv"
            required
            class="w-full rounded-xl border border-white/10 bg-[#070c18] px-3 py-2 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-sm file:text-slate-200">

        <button type="submit" class="btn-teal shrink-0 px-6 py-2.5 text-sm">↑ นำเข้า</button>
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
<?php require __DIR__ . '/includes/footer.php'; ?>