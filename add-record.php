<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
// ⚠️ ต้องซ่อม session ก่อนดึงข้อมูล — ร้านอาจถูกลบจากอุปกรณ์อื่นไปแล้ว
// (เดิมการซ่อมอยู่ใน header.php ซึ่ง include ท้ายไฟล์ หน้าจึงขึ้น "ไม่มีสิทธิ์" + ฿0 หนึ่งครั้ง)
$shopId = resolve_current_shop_id($pdo, $userId);

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$recentRecords = $recordService->getRecentRecords($userId, $shopId, 7);

// เติมค่าของวันที่ฟอร์มตั้งต้นไว้ (วันนี้) — การบันทึกเป็นการเขียนทับทุกช่อง
// ถ้าไม่เติมโน้ตเดิมกลับมา การแก้แค่ยอดขายจะลบโน้ตของวันนั้นทิ้งไปด้วย
$todayDate = date('Y-m-d');
$existingResult = $recordService->getRecordForDate($userId, $shopId, $todayDate);
$existingRecord = ($existingResult['success'] ?? false) === true
    ? ($existingResult['data'] ?? null)
    : null;

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
        <?= shop_context_field($shopId) ?>
        <input type="hidden" name="action" value="upsert">

        <div>
            <label for="record-date" class="mb-1 block text-sm text-slate-300">วันที่</label>
            <input
                id="record-date"
                name="record_date"
                type="date"
                data-date="<?= e(date('d/m/Y')) ?>"
                value="<?= e($todayDate) ?>"
                required
                class="w-full rounded-xl px-4 py-2.5 transition-all date-input-formatted"
                style="position: relative;"
                onchange="this.setAttribute('data-date', this.value ? this.value.split('-').reverse().join('/') : '')">
            <?php // ลงวันที่ล่วงหน้าทำได้ (บางคนตั้งใจ) แต่ต้องเห็นว่ากำลังทำอยู่ — กันพิมพ์ปีผิด ?>
            <p id="future-date-warning" class="mt-1 hidden text-xs text-amber-300">
                ⚠️ วันที่นี้อยู่ในอนาคต — บันทึกได้ แต่ตรวจสอบอีกครั้งว่าพิมพ์ปีถูกไหม
            </p>
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
                value="<?= $existingRecord !== null ? e((string)$existingRecord['revenue']) : '' ?>"
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
                value="<?= $existingRecord !== null ? e((string)$existingRecord['ad_cost']) : '' ?>"
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
                placeholder="เช่น แอดชุดใหม่เริ่มวิ่ง"><?= $existingRecord !== null ? e((string)$existingRecord['note']) : '' ?></textarea>
        </div>

        <div class="md:col-span-2">
            <?php // บอกให้รู้ตัวว่ากำลังแก้ของเดิม ไม่ใช่เพิ่มใหม่ — JS อัปเดตข้อความนี้ตอนเปลี่ยนวัน ?>
            <p id="existing-record-hint" class="mb-2 text-xs text-amber-300 <?= $existingRecord !== null ? '' : 'hidden' ?>">
                ✎ วันนี้มีข้อมูลอยู่แล้ว — ระบบเติมค่าเดิมไว้ให้ กดบันทึกจะเป็นการแก้ไขทับ
            </p>
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
        <?= shop_context_field($shopId) ?>
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
            <div class="flex items-center gap-2">
                <label for="bulk-month" class="text-sm text-slate-400">โหลดเดือน</label>
                <?php // ไม่มี name= โดยตั้งใจ — ใช้ฝั่ง JS อย่างเดียว ไม่ส่งไปกับฟอร์ม ?>
                <input
                    type="month"
                    id="bulk-month"
                    max="<?= e(date('Y-m')) ?>"
                    value="<?= e(date('Y-m')) ?>"
                    class="rounded-xl px-3 py-2 text-sm">
            </div>

            <button type="button" id="bulk-add-row" class="btn-ghost px-4 py-2 text-sm">+ เพิ่มแถว</button>
            <button type="submit" class="btn-orange px-6 py-2.5 text-base shadow-sm">✓ บันทึกทั้งหมด</button>
        </div>
    </form>
</section>

<template id="bulk-row-template">
    <tr class="border-b border-white/[0.06]">
        <td class="px-2 py-2 text-slate-500 bulk-row-number"></td>
        <td class="px-2 py-2">
            <input type="hidden" name="row_number[]" value="">
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
    // ── โหลดข้อมูลทั้งเดือนมาแก้ (AJAX read-only จุดเดียวของแอป) ──────────────
    // ⚠️ แอปนี้เป็น server-render + form POST เป็นหลัก — นี่เป็นข้อยกเว้นที่ตั้งใจ
    //    GET api/month-grid.php อ่านอย่างเดียว ไม่เปลี่ยน state จึงไม่ต้องมี CSRF
    //    (auth ผ่าน session cookie) · การบันทึกยังเป็น form POST + CSRF เหมือนเดิม
    // ประกาศนอก IIFE เพราะใช้ 2 ที่: ตารางกรอกหลายวัน และฟอร์มหลัก (เติมค่าเดิม)
    const MONTH_GRID_URL = <?= json_encode(
        app_url('/api/month-grid.php'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
</script>

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

                // ส่งเลขแถวที่ผู้ใช้เห็นไปด้วย — แถวที่ไม่ได้กรอกจะไม่ถูกส่ง ทำให้แถวถัดไป
                // เลื่อนขึ้น ถ้าเซิร์ฟเวอร์นับเองจะรายงาน "แถวที่ 2" ทั้งที่ผู้ใช้เห็นเป็นแถวที่ 3
                const numberInput = row.querySelector('input[name="row_number[]"]');
                if (numberInput) {
                    numberInput.value = String(index + 1);
                }
            });

            if (counter) {
                counter.textContent = rows.length + ' / ' + MAX_ROWS + ' แถว';
            }

            // หมายเหตุ: ปุ่ม "เติมทั้งเดือน" ไม่ถูก disable ตาม MAX_ROWS
            // เพราะมันล้างตารางแล้วเติมใหม่เสมอ (ต่างจากปุ่มเพิ่มแถวที่ต่อท้าย)
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

        // "หน้าตาเหมือนวันที่ไหม" — ใช้แยกแถวหัวตารางออกจากข้อมูล ไม่สนว่าจะ parse ได้จริงหรือไม่
        const looksLikeDateCell = (raw) =>
            /^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{4})$/.test(String(raw).trim());

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

                // วันกำกวม: 05/03/2026 เป็นได้ทั้ง 5 มี.ค. และ 3 พ.ค. แล้วแต่ว่าไฟล์ต้นทาง
                // เป็น D/M หรือ M/D — คืน null ให้ค่าดิบไปถึง server แล้วถูกปฏิเสธด้วยข้อความ
                // เดียวกับ RecordService::isAmbiguousSlashDate() แทนที่จะเดาแล้วบันทึกผิดเงียบ ๆ
                if (day <= 12 && month <= 12) {
                    return null;
                }
            }

            if (year >= 2400 && year <= 2700) {
                year -= 543; // พ.ศ. → ค.ศ.
            }

            if (month < 1 || month > 12 || day < 1 || day > 31) {
                return null;
            }

            return year + '-' + pad2(month) + '-' + pad2(day);
        };

        // ตัวคั่นทศนิยมอาจเป็นจุดหรือจุลภาค แล้วแต่ภาษาของ Excel ที่สร้างไฟล์
        // ⚠️ ต้องใช้กติกาเดียวกับ RecordService::cleanImportNumber() ฝั่ง PHP
        // ลบจุลภาคทิ้งเสมอทำให้ 1234,56 กลายเป็น 123456 (100 เท่า) แล้วบันทึกสำเร็จ
        const cleanAmountCell = (raw) => {
            const value = String(raw).replace(/[฿\s\u00a0]/g, '').trim();
            const lastDot = value.lastIndexOf('.');
            const lastComma = value.lastIndexOf(',');

            if (lastDot === -1 && lastComma === -1) {
                return value;
            }

            const decimalPosition = Math.max(lastDot, lastComma);
            const fractionDigits = value.slice(decimalPosition + 1);

            // ตามหลังด้วยตัวเลข 1–2 หลักเท่านั้น จึงเป็นทศนิยมของค่าเงิน
            if (!/^\d{1,2}$/.test(fractionDigits)) {
                return value.replace(/[.,]/g, '');
            }

            return value.slice(0, decimalPosition).replace(/[.,]/g, '') + '.' + fractionDigits;
        };

        const normalizeCell = (columnIndex, raw) => {
            if (columnIndex === 0) {
                // parse ไม่ได้ → คืนค่าดิบ ให้ฝั่ง server เป็นคน reject
                return parseDateCell(raw) || String(raw).trim();
            }

            if (columnIndex === 1 || columnIndex === 2) {
                return cleanAmountCell(raw);
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
            // ใช้ looksLikeDateCell ไม่ใช่ parseDateCell เพราะวันกำกวมคืน null แต่เป็นข้อมูล ไม่ใช่หัวตาราง
            if (startCol === 0 && grid.length > 1 && !looksLikeDateCell(grid[0][0])) {
                grid.shift();
            }

            let placedRows = 0;
            let truncatedRows = 0;
            let unreadableDates = 0;

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
                    if (!input) {
                        return;
                    }

                    // ช่องวันที่: เดาไม่ได้ก็อย่าเดา — เว้นว่างแล้วบอกผู้ใช้ ดีกว่าใส่ค่าที่อาจผิด
                    // แล้วให้ผ่านไปทั้งชุด (05/03/2026 อ่านได้ทั้ง 5 มี.ค. และ 3 พ.ค.)
                    if (columnIndex === 0 && parseDateCell(cell) === null && String(cell).trim() !== '') {
                        unreadableDates++;
                        input.value = '';
                        return;
                    }

                    input.value = normalizeCell(columnIndex, cell);
                });

                placedRows++;
            });

            refresh();

            if (pasteNotice) {
                const messages = [];

                if (truncatedRows > 0) {
                    messages.push('วางได้ ' + placedRows + ' แถว · ส่วนที่เกิน '
                        + MAX_ROWS + ' แถว (' + truncatedRows + ' แถว) ถูกตัด');
                }

                if (unreadableDates > 0) {
                    messages.push('มี ' + unreadableDates + ' ช่องวันที่ที่อ่านไม่ได้หรือกำกวม '
                        + '(เช่น 05/03/2026 อ่านได้ทั้งวัน/เดือน และเดือน/วัน) — เว้นว่างไว้ '
                        + 'กรุณาเลือกวันที่เอง หรือใช้รูปแบบ ปี-เดือน-วัน');
                }

                pasteNotice.textContent = messages.join(' · ');
                pasteNotice.classList.toggle('hidden', messages.length === 0);
            }
        });

        const monthInput = document.getElementById('bulk-month');
        let loadedMonth = monthInput ? monthInput.value : '';

        const showBulkNotice = (message) => {
            if (!pasteNotice) {
                return;
            }

            if (message === '') {
                pasteNotice.classList.add('hidden');
                return;
            }

            pasteNotice.textContent = message;
            pasteNotice.classList.remove('hidden');
        };

        // "มีข้อมูลผู้ใช้กรอกค้าง" = มีค่าในช่องรายได้/ค่าแอด/โน้ต
        // (ไม่นับ record_date เพราะการโหลดเดือนเป็นคนเติมวันที่ให้เอง ไม่ใช่งานที่ผู้ใช้พิมพ์)
        // ใช้นิยาม "แตะแล้ว" เดียวกับตอน submit
        const tableHasInput = () => Array.from(tbody.querySelectorAll('tr')).some((row) =>
            ['revenue[]', 'ad_cost[]', 'note[]'].some((name) => {
                const input = row.querySelector('input[name="' + name + '"]');
                return input !== null && input.value.trim() !== '';
            })
        );

        const populateFromDays = (days) => {
            tbody.innerHTML = '';

            days.slice(0, MAX_ROWS).forEach((day) => {
                addRow();

                const rows = getRows();
                const row = rows[rows.length - 1];
                const setValue = (name, value) => {
                    const input = row.querySelector('input[name="' + name + '"]');
                    if (input) {
                        input.value = value;
                    }
                };

                setValue('record_date[]', day.date);

                // วันที่เคยบันทึกไว้ → เติมค่าเดิมมาให้แก้ · วันที่ยังไม่มี → เว้นว่าง
                if (day.has_record) {
                    setValue('revenue[]', day.revenue === null ? '' : String(day.revenue));
                    setValue('ad_cost[]', day.ad_cost === null ? '' : String(day.ad_cost));
                    setValue('note[]', day.note || '');
                }
            });

            refresh();
        };

        if (monthInput) {
            monthInput.addEventListener('change', async () => {
                const selectedMonth = monthInput.value;
                if (!/^\d{4}-\d{2}$/.test(selectedMonth)) {
                    showBulkNotice('กรุณาเลือกเดือนให้ถูกต้อง');
                    return;
                }

                // เตือนก่อนทับ เฉพาะตอนมีข้อมูลค้างในตาราง
                if (tableHasInput()
                    && !window.confirm('โหลดเดือนใหม่ ข้อมูลที่ยังไม่บันทึกจะหาย?')) {
                    monthInput.value = loadedMonth;   // คืนค่าเดิมให้ picker
                    return;
                }

                showBulkNotice('กำลังโหลดข้อมูลเดือนนี้...');

                try {
                    const response = await fetch(
                        MONTH_GRID_URL + '?month=' + encodeURIComponent(selectedMonth),
                        { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }
                    );

                    const payload = await response.json();

                    // ผิดพลาด → แจ้งข้อความ แต่ไม่ล้างตารางที่ผู้ใช้กรอกไว้
                    if (!payload || payload.success !== true) {
                        showBulkNotice((payload && payload.error) ? payload.error : 'โหลดข้อมูลเดือนนี้ไม่สำเร็จ');
                        return;
                    }

                    const days = (payload.data && payload.data.days) ? payload.data.days : [];
                    populateFromDays(days);
                    loadedMonth = selectedMonth;

                    showBulkNotice(days.length === 0 ? 'เดือนนี้ยังไม่ถึงกำหนดกรอก' : '');
                } catch (error) {
                    showBulkNotice('โหลดข้อมูลเดือนนี้ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
                }
            });
        }

        // ── ไม่ส่งแถวที่ "เติมวันที่ให้ล่วงหน้า แต่ผู้ใช้ยังไม่ได้กรอก" ────────
        // ปุ่มเติมทั้งเดือน/เติมวันที่ขาด ใส่ record_date ให้ทุกแถว ทำให้แถวนั้นไม่ "ว่างสนิท"
        // ฝั่ง server จึงมองว่ากรอกไม่ครบแล้ว reject ทั้งชุด → ตัดออกตั้งแต่ฝั่ง client
        // เกณฑ์: มี record_date แต่ revenue/ad_cost/note ว่างทั้งหมด = ยังไม่ได้แตะ
        // (แถวที่แตะแล้วแม้กรอกไม่ครบ ยังส่งไปให้ server validate ตามกฎเดิม)
        const bulkForm = tbody.closest('form');

        if (bulkForm) {
            bulkForm.addEventListener('submit', (event) => {
                const untouchedRows = [];
                let touchedRowCount = 0;

                getRows().forEach((row) => {
                    const dateInput = row.querySelector('input[name="record_date[]"]');
                    const hasDate = dateInput !== null && dateInput.value.trim() !== '';
                    const hasOtherValue = ['revenue[]', 'ad_cost[]', 'note[]'].some((name) => {
                        const input = row.querySelector('input[name="' + name + '"]');
                        return input !== null && input.value.trim() !== '';
                    });

                    if (hasOtherValue) {
                        touchedRowCount++;
                        return;
                    }

                    if (hasDate) {
                        untouchedRows.push(row);
                    }
                    // แถวว่างสนิท → ปล่อยไป server ข้ามเองตามเดิม
                });

                if (touchedRowCount === 0) {
                    // มีแต่แถววันที่ล่วงหน้า → ถ้าตัดออกจะเหลือ 0 แถว ไม่ต้องส่งฟอร์มเปล่า
                    if (untouchedRows.length > 0) {
                        event.preventDefault();
                        showBulkNotice('ยังไม่ได้กรอกข้อมูลวันไหนเลย');
                    }

                    // ตารางว่างสนิท → ปล่อยให้ server ตอบเหมือนเดิม
                    return;
                }

                // disable ทั้งแถว (ครบทุกคอลัมน์) เพื่อให้ index ของ name[] ยังตรงกัน
                untouchedRows.forEach((row) => {
                    row.querySelectorAll('input').forEach((input) => {
                        input.disabled = true;
                    });
                });
            });
        }
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
        <?= shop_context_field($shopId) ?>
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
<script>
    // เตือนเมื่อวันที่อยู่ในอนาคต — ไม่บล็อกการบันทึก (ลงล่วงหน้าเป็นเรื่องปกติ)
    // แต่ทำให้การพิมพ์ปีผิด เช่น 2027 ไม่ผ่านไปเงียบ ๆ
    (() => {
        const dateInput = document.getElementById('record-date');
        const warning = document.getElementById('future-date-warning');
        if (!dateInput || !warning) {
            return;
        }

        const today = new Date();
        const todayIso = [
            today.getFullYear(),
            String(today.getMonth() + 1).padStart(2, '0'),
            String(today.getDate()).padStart(2, '0'),
        ].join('-');

        const sync = () => {
            warning.classList.toggle('hidden', !(dateInput.value && dateInput.value > todayIso));
        };

        dateInput.addEventListener('change', sync);
        dateInput.addEventListener('input', sync);
        sync();
    })();

    // ── เปลี่ยนวันที่ → ดึงค่าเดิมของวันนั้นมาเติมให้ ────────────────────────────
    // การบันทึกเป็นการเขียนทับทุกช่อง ถ้าไม่เติมโน้ตเดิมกลับมา การแก้แค่ยอดขาย
    // จะลบโน้ตของวันนั้นทิ้ง · ใช้ endpoint เดิม (api/month-grid.php) ไม่เพิ่มจุดใหม่
    (() => {
        const dateInput = document.getElementById('record-date');
        const revenueInput = document.getElementById('revenue');
        const adCostInput = document.getElementById('ad-cost');
        const noteInput = document.getElementById('note');
        const hint = document.getElementById('existing-record-hint');

        if (!dateInput || !revenueInput || !adCostInput || !noteInput || !hint) {
            return;
        }

        const monthCache = new Map();          // 'YYYY-MM' → Map(วันที่ → ข้อมูล)
        let lastAppliedDate = dateInput.value; // ค่าที่เซิร์ฟเวอร์เติมมาให้ตอนโหลดหน้า

        const loadMonth = async (month) => {
            if (monthCache.has(month)) {
                return monthCache.get(month);
            }

            const response = await fetch(
                MONTH_GRID_URL + '?month=' + encodeURIComponent(month),
                { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }
            );
            const payload = await response.json();

            if (!payload || payload.success !== true) {
                throw new Error('load failed');
            }

            const byDate = new Map();
            const days = (payload.data && payload.data.days) ? payload.data.days : [];
            days.forEach((day) => { byDate.set(day.date, day); });
            monthCache.set(month, byDate);

            return byDate;
        };

        const applyDay = (day) => {
            if (day && (day.revenue !== null || day.ad_cost !== null || day.note)) {
                revenueInput.value = day.revenue === null ? '' : String(day.revenue);
                adCostInput.value = day.ad_cost === null ? '' : String(day.ad_cost);
                noteInput.value = day.note || '';
                hint.classList.remove('hidden');
                return;
            }

            revenueInput.value = '';
            adCostInput.value = '';
            noteInput.value = '';
            hint.classList.add('hidden');
        };

        dateInput.addEventListener('change', async () => {
            const value = dateInput.value;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value === lastAppliedDate) {
                return;
            }

            try {
                const byDate = await loadMonth(value.slice(0, 7));
                applyDay(byDate.get(value) || null);
                lastAppliedDate = value;
            } catch (error) {
                // โหลดไม่ได้ → ล้างช่องแล้วเตือน ดีกว่าปล่อยค่าของวันก่อนหน้าค้างไว้
                // แล้วผู้ใช้กดบันทึกทับวันใหม่ด้วยตัวเลขของวันเก่า
                applyDay(null);
                lastAppliedDate = value;
                hint.textContent = '⚠️ โหลดข้อมูลเดิมของวันนี้ไม่สำเร็จ — ตรวจสอบก่อนกดบันทึก';
                hint.classList.remove('hidden');
            }
        });
    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>