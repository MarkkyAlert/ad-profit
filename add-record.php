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
    <p class="mt-2 text-sm text-slate-400">กรอกวันซ้ำจะอัปเดตทับอัตโนมัติ</p>

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
                max="<?= e($todayDate) ?>"
                value="<?= e($todayDate) ?>"
                required
                class="w-full rounded-xl px-4 py-2.5 transition-all date-input-formatted"
                style="position: relative;"
                onchange="this.setAttribute('data-date', this.value ? this.value.split('-').reverse().join('/') : '')">
            <p id="future-date-warning" class="mt-1 hidden text-xs text-amber-300">
                วันที่นี้อยู่ในอนาคต ระบบจะไม่บันทึกยอดที่ยังไม่เกิดขึ้น
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
                <?php // ⚠️ ห้ามเขียน "วันนี้" ตายตัว — ผู้ใช้เปลี่ยนวันที่ได้ แล้วป้ายจะพูดถึงวันผิด ?>
                ✎ วันที่เลือกไว้มีข้อมูลอยู่แล้ว — ระบบเติมค่าเดิมไว้ให้ กดบันทึกจะเป็นการแก้ไขทับ
            </p>
            <button type="submit" class="btn-orange px-6 py-2.5 text-base shadow-sm">
                ✓ บันทึกข้อมูล
            </button>
        </div>
    </form>
</section>

<?php /* ⚠️ ยุบเก็บไว้โดยตั้งใจ — หน้านี้มี 3 วิธีกรอกซ้อนกัน (วันเดียว · หลายวัน · ไฟล์ CSV)
         เดิมกางออกพร้อมกันหมด ผู้ใช้ใหม่จึงเจอสามทางเลือกโดยไม่รู้ว่าต่างกันยังไง และบนมือถือ
         ต้องเลื่อนผ่านตาราง 31 แถวกว่าจะถึงส่วนอื่น
         → งานที่ทำทุกวัน (กรอกวันนี้) กางไว้ · งานที่ทำนาน ๆ ครั้ง (ย้อนหลัง/นำเข้าไฟล์) ยุบไว้
         ⚠️ ใช้ <details> ของ HTML ล้วน ไม่ใช่ JS — เนื้อหาข้างในยังอยู่ใน DOM ตลอด
         สคริปต์ของตาราง (วางจาก Excel · เติมค่าเดิม) จึงทำงานเหมือนเดิมทุกอย่าง */ ?>
<details class="section-card mt-6 p-5">
    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 list-none">
        <span class="text-lg font-semibold text-slate-100">กรอกหลายวัน <span class="text-sm font-normal text-slate-400">— ย้อนหลังทีละหลายวัน</span></span>
        <span id="bulk-row-count" class="text-xs text-slate-400"></span>
    </summary>
    <div class="mt-4">
    <p class="mb-4 text-sm text-slate-400">
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

        <?php
        // ⚠️⚠️ ต้องมี `min-w-[…]` ไม่ใช่แค่ `min-w-full`
        //
        // `min-w-full` = "อย่างน้อยเท่าความกว้างจอ" ตารางจึงบีบตัวเองให้พอดีจอมือถือ
        // แทนที่จะกว้างตามเนื้อหาแล้วให้กล่องเลื่อนเอา · ช่องวันที่เป็น input ของระบบ
        // ปฏิบัติการซึ่งย่อไม่ได้ (~150px) ช่องที่เหลือจึงถูกบีบจนหมด
        //
        // วัดจริงบนจอ 375px: รายได้ = 22px · ค่าแอด = 26px · โน้ต = 24px
        // พิมพ์เลข 4 หลักแล้วเห็นทีละตัว — และนี่คือหน้าที่ใช้กรอกข้อมูลเป็นหลัก
        ?>
        <div class="overflow-x-auto">
            <?php /* ⚠️ ตารางนี้ **ไม่แปลงเป็นการ์ด** เหมือนตารางอื่น — มันเป็นฟอร์มที่
                     JS ผูกกับโครง `tbody tr` + ลำดับ input ไว้ (วางจาก Excel · เติมค่าเดิม ·
                     เติมวันที่ขาด) และฝั่ง JS ไม่มีตัวรันเทสต์คอยจับเวลาพัง
                     บนมือถือจึงยังเลื่อนแนวนอน แต่ `sticky-first-col` ตรึงคอลัมน์วันที่ไว้
                     ผู้ใช้จะได้รู้ตลอดว่ากำลังกรอกของวันไหน */ ?>
            <table class="sticky-first-col w-full min-w-[560px] text-sm">
                <thead>
                    <tr class="border-b border-white/10 text-left text-slate-400">
                        <th scope="col" class="px-2 py-2 w-10">#</th>
                        <th scope="col" class="px-2 py-2 min-w-[140px]">วันที่</th>
                        <th scope="col" class="px-2 py-2 min-w-[104px]">รายได้ (฿)</th>
                        <th scope="col" class="px-2 py-2 min-w-[104px]">ค่าแอด (฿)</th>
                        <th scope="col" class="px-2 py-2 min-w-[140px]">โน้ต</th>
                        <th scope="col" class="px-2 py-2 w-12"></th>
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
                <?php /* ⚠️ ช่อง <input type="month"> เขียนเดือนเป็นภาษาอังกฤษเสมอ ("August 2026")
                         ตามภาษาของเบราว์เซอร์/เครื่อง — **แก้ที่ตัวช่องไม่ได้** เป็นข้อจำกัดของ
                         ช่องมาตรฐาน · หน้าเดียวกันเขียน "8 ส.ค. 2569" ในตาราง ผู้ใช้จึงต้อง
                         แปลงวันที่ในหัวไปมา → เขียนเดือนแบบไทยกำกับไว้ข้าง ๆ ให้อ่านตรงกัน
                         (สคริปต์อัปเดตข้อความนี้เมื่อผู้ใช้เปลี่ยนเดือน) */ ?>
                <span id="bulk-month-thai" class="text-sm text-slate-300"><?= e(formatThaiMonth(date('Y-m'))) ?></span>
            </div>

            <button type="button" id="bulk-add-row" class="btn-ghost px-4 py-2 text-sm">+ เพิ่มแถว</button>
            <button type="submit" class="btn-orange px-6 py-2.5 text-base shadow-sm">✓ บันทึกทั้งหมด</button>
        </div>
    </form>
    </div>
</details>

<template id="bulk-row-template">
    <tr class="border-b border-white/[0.06]">
        <td class="px-2 py-2 text-slate-400 bulk-row-number"></td>
        <td class="px-2 py-2">
            <input type="hidden" name="row_number[]" value="">
                <input name="record_date[]" aria-label="วันที่" data-a11y-base="วันที่" type="date" max="<?= e($todayDate) ?>" class="w-full rounded-lg px-2 py-1.5 text-sm">
        </td>
        <td class="px-2 py-2">
            <input name="revenue[]" aria-label="รายได้ (บาท)" data-a11y-base="รายได้ (บาท)" type="number" min="0" step="0.01" class="w-full rounded-lg px-2 py-1.5 text-sm" placeholder="0.00">
        </td>
        <td class="px-2 py-2">
            <input name="ad_cost[]" aria-label="ค่าแอด (บาท)" data-a11y-base="ค่าแอด (บาท)" type="number" min="0" step="0.01" class="w-full rounded-lg px-2 py-1.5 text-sm" placeholder="0.00">
        </td>
        <td class="px-2 py-2">
            <input name="note[]" aria-label="โน้ต" data-a11y-base="โน้ต" type="text" maxlength="255" class="w-full rounded-lg px-2 py-1.5 text-sm" placeholder="ไม่บังคับ">
            <?php /* ⚠️ ธง "แถวนี้ได้เทียบกับข้อมูลเดิมของวันนั้นแล้วหรือยัง" — JS ตั้งเป็น 1
                     เมื่อโหลดข้อมูลเดือนสำเร็จ · ถ้าโหลดไม่สำเร็จค่านี้ยังเป็นว่าง แล้วฝั่ง
                     เซิร์ฟเวอร์จะปฏิเสธการเขียนทับโน้ตที่มีอยู่ แทนที่จะลบทิ้งเงียบ ๆ
                     (กันอุบัติเหตุ ไม่ได้กันการโจมตี — เป็นข้อมูลของผู้ใช้เอง หลักเดียวกับ
                     shop_context_field) */ ?>
            <input type="hidden" name="note_checked[]" value="">
        </td>
        <td class="px-2 py-2 text-center">
            <?php /* ⚠️ วัดจริงบนมือถือ: ปุ่มนี้เคยมีขนาด 11×18px — เล็กกว่าเกณฑ์นิ้วมือ (44px)
                     สี่เท่า และเป็นปุ่มที่ลบข้อมูลทิ้ง · `tap-target` ขยายเฉพาะ "พื้นที่กด"
                     ด้วย ::after ที่มองไม่เห็น หน้าตาจึงไม่เปลี่ยน */ ?>
            <button type="button" class="bulk-remove-row tap-target text-red-400 hover:text-red-300 text-lg leading-none" aria-label="ลบแถวนี้" data-a11y-base="ลบ" title="ลบแถว">×</button>
        </td>
    </tr>
</template>

<script>
    // ── โหลดข้อมูลทั้งเดือนมาแก้ (AJAX read-only จุดเดียวของแอป) ──────────────
    // ⚠️ แอปนี้เป็น server-render + form POST เป็นหลัก — นี่เป็นข้อยกเว้นที่ตั้งใจ
    //    GET api/month-grid.php อ่านอย่างเดียว ไม่เปลี่ยน state จึงไม่ต้องมี CSRF
    //    (auth ผ่าน session cookie) · การบันทึกยังเป็น form POST + CSRF เหมือนเดิม
    // วันที่ "วันนี้" ตามเวลาของเครื่องผู้ใช้
    //
    // ⚠️ ห้ามใช้ `toISOString()` — มันคืนเวลา UTC ประเทศไทยเร็วกว่า 7 ชั่วโมง
    // ช่วงเที่ยงคืนถึงตี 7 วันที่ UTC จึงเป็น "เมื่อวาน" ผลคือวันนี้ถูกมองว่าเป็น
    // วันอนาคต แล้วขึ้นคำเตือนผิด ๆ ให้คนที่มาบันทึกยอดตอนดึก
    //
    // ⚠️⚠️ **ต้องประกาศตรงนี้ (นอก IIFE และในก้อน <script> แรก)** เพราะถูกเรียกจาก
    // 3 ที่ที่อยู่คนละ IIFE และคนละก้อน <script> · เคยประกาศไว้ใน IIFE เดียวแล้ว
    // จุดเรียกในก้อนอื่นได้ ReferenceError ทันที คำเตือน "วันอนาคต" ของตารางกรอก
    // หลายวันจึงหายไปทั้งหมด โดยขึ้นข้อความผิดว่า "โหลดข้อมูลเดิมไม่สำเร็จ" แทน
    // (error ถูกกลืนด้วย catch) — ตัวตรวจ JS จับไม่ได้เพราะมันเอาทุกก้อนมาต่อกัน
    // เป็นสตริงเดียวแล้วไม่รู้จัก scope
    const todayIso = () => {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');

        return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    };

    // ประกาศนอก IIFE เพราะใช้ 2 ที่: ตารางกรอกหลายวัน และฟอร์มหลัก (เติมค่าเดิม)
    const MONTH_GRID_URL = <?= json_encode(
        app_url('/api/month-grid.php'),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    // cache ข้อมูลรายเดือน ใช้ร่วมกันระหว่างฟอร์มเดี่ยวกับตารางกรอกหลายวัน
    // (ทั้งสองต้องเติมค่าเดิมของวันที่ผู้ใช้พิมพ์ ไม่งั้นการบันทึกทับจะลบโน้ตเดิมทิ้ง)
    const monthDataCache = new Map();   // 'YYYY-MM' → Map(วันที่ → ข้อมูล)

    const loadMonthData = async (month) => {
        if (monthDataCache.has(month)) {
            return monthDataCache.get(month);
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

        // ⚠️ วันล่วงหน้าที่มีข้อมูลอยู่แล้ว มาแยกคีย์ — ต้องรวมเข้าแผนที่ด้วย
        // ไม่งั้นเลือกวันนั้นในฟอร์มแล้วช่องว่างเปล่า พิมพ์ทับแล้วโน้ตเดิมหาย
        // (ตารางกรอกหลายวันยังไม่มีแถวของวันอนาคตเหมือนเดิม เพราะอ่านจาก `days`)
        const futureDays = (payload.data && payload.data.future_days_with_records)
            ? payload.data.future_days_with_records
            : [];
        futureDays.forEach((day) => { byDate.set(day.date, day); });
        monthDataCache.set(month, byDate);

        return byDate;
    };
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

                /* ⚠️⚠️ ชื่อของช่องต้องบอกด้วยว่าเป็น "แถวไหน"
                   เดิมทุกแถวชื่อเดียวกันเป๊ะ ("รายได้ (บาท)" เหมือนกันหมด) และปุ่มลบ
                   ทุกปุ่มชื่อ "ลบแถวนี้" เหมือนกันหมด · คนที่ใช้โปรแกรมอ่านหน้าจอกรอก
                   ย้อนหลังทั้งเดือนจะได้ยินชุดคำเดิม 31 รอบ โดยไม่มีอะไรบอกว่าอยู่แถวไหน
                   และปุ่มลบที่แยกกันไม่ออกคือปุ่มที่กดผิดแล้วเสียหาย
                   · เลขแถวมีอยู่แล้วในคอลัมน์ "#" ที่ผู้ใช้เห็น — เอาเลขเดียวกันมาใช้
                     จึงไม่มีทางที่เสียงกับหน้าจอจะบอกคนละแถว */
                const rowLabel = ' แถวที่ ' + (index + 1);
                row.querySelectorAll('[data-a11y-base]').forEach((field) => {
                    field.setAttribute('aria-label', field.getAttribute('data-a11y-base') + rowLabel);
                });
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
                // ถามก่อนล้าง เหมือนตอนโหลดเดือนใหม่ — เดิมลบสิ่งที่พิมพ์ค้างไว้ทันทีโดยไม่ถาม
                if (tableHasInput()
                    && !window.confirm('เติมวันที่ขาดจะล้างตารางปัจจุบัน ข้อมูลที่ยังไม่บันทึกจะหาย?')) {
                    return;
                }

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

        // ⚠️⚠️ คำเตือนตอนวางต้อง "ติดหนึบ" ไม่ให้ข้อความอื่นมาลบทิ้ง
        //
        // การวางจบด้วยการยิง event `change` ให้ทุกแถว → ตัวเติมค่าเดิมทำงานต่อ แล้วเรียก
        // `showBulkNotice()` ซึ่งเดิม **เขียนทับ** คำเตือนของการวางทั้งก้อน
        // วัดจริง: วางยอดรูปแบบไทย (12,500) ทับวันที่มีข้อมูลเดิม → คำเตือน
        // "มี 6 ช่องจำนวนเงินที่อ่านไม่ได้" หายไป เหลือแต่ "มีข้อมูลอยู่แล้ว …"
        // ซึ่งอ่านแล้วสบายใจ ทั้งที่ยอดที่ผู้ใช้วางถูกทิ้งไปหมดแล้ว
        //
        // ⚠️ ประกาศไว้ตรงนี้ (ก่อนตัววาง) โดยตั้งใจ — ตัววางอ่านค่านี้ ถ้าประกาศทีหลัง
        // จะพึ่งจังหวะการทำงานแทนที่จะพึ่งขอบเขต ซึ่งเป็นรูปแบบที่เคยพังมาแล้วในไฟล์นี้
        let stickyPasteMessage = '';

        const getRows = () => Array.from(tbody.querySelectorAll('tr'));
        const pad2 = (value) => String(value).padStart(2, '0');

        // "หน้าตาเหมือนวันที่ไหม" — ใช้แยกแถวหัวตารางออกจากข้อมูล ไม่สนว่าจะ parse ได้จริงหรือไม่
        const looksLikeDateCell = (raw) =>
            /^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{4})$/.test(String(raw).trim());

        // รองรับ YYYY-MM-DD และ D/M/YYYY (+ แปลง พ.ศ. 2400–2700) — นอกเหนือจากนี้คืน null
        const parseDateCell = (raw) => {
            // ⚠️ `.trim()` ของ JS ตัด NBSP และช่องว่างญี่ปุ่นให้อยู่แล้ว แต่ **ไม่ตัด**
            // ตัวอักษรความกว้างศูนย์ (U+200B–U+200D, U+FEFF) ซึ่งติดมาจากการก๊อปวางบ่อย
            // ฝั่ง PHP (`trim_unicode_whitespace`) ตัดให้ → ถ้าไม่ตัดตรงนี้ด้วย ไฟล์เดียวกัน
            // จะ "วางแล้วอ่านไม่ออก แต่นำเข้าเป็นไฟล์แล้วอ่านออก" ซึ่งอธิบายให้ผู้ใช้ไม่ได้
            const value = String(raw).replace(/[\u200B-\u200D\uFEFF]/g, '').trim();
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

        // "หน้าตาเหมือนตัวเลขไหม" — ใช้แยกแถวหัวตารางออกจากข้อมูลเท่านั้น ไม่ใช่ตัวอ่านค่า
        //
        // ⚠️ ต้องไม่พึ่ง `cleanAmountCell()` เพราะตัวนั้นคืน "ค่าดิบ" เมื่ออ่านไม่ชัด
        // (เช่น 1.234 ที่กำกวม) ซึ่งยังหน้าตาเหมือนตัวเลขอยู่ดี — ถ้าตอบว่าไม่ใช่ตัวเลข
        // แถวข้อมูลแรกจะถูกนับเป็นหัวตารางแล้วหายไป
        const isNumericCell = (raw) => {
            const value = String(raw).replace(/[฿\s\u00a0]/g, '').trim();

            return value !== '' && /^[-+]?\d+([.,]\d+)*$/.test(value);
        };

        // ตัวคั่นทศนิยมอาจเป็นจุดหรือจุลภาค แล้วแต่ภาษาของ Excel ที่สร้างไฟล์
        // ⚠️ ต้องใช้กติกาเดียวกับ normalize_money_string() ฝั่ง PHP เป๊ะ ๆ
        // อ่านได้หลายแบบ (เช่น 1.234 เป็นได้ทั้ง 1234 และ 1.234) = คืนค่าดิบ ให้ server ปฏิเสธ
        const cleanAmountCell = (raw) => {
            let value = String(raw).trim();
            if (value.startsWith("'")) {
                value = value.slice(1);
            }
            value = value.replace(/[฿\s\u00a0]/g, '').trim();

            if (value === '') {
                return '';
            }

            let sign = '';
            if (value[0] === '-' || value[0] === '+') {
                sign = value[0] === '-' ? '-' : '';
                value = value.slice(1);
            }

            if (/^\d+$/.test(value)) {
                return sign + value;
            }

            const decimal = value.match(/^(\d+)[.,](\d{1,2})$/);
            if (decimal) {
                return sign + decimal[1] + '.' + decimal[2];
            }

            // กำกวม: ตัวคั่นเดียวตามด้วย 3 หลักพอดี — คืนค่าดิบให้ server ปฏิเสธ
            if (/^\d+[.,]\d{3}$/.test(value)) {
                return String(raw).trim();
            }

            if (/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/.test(value)) {
                return sign + value.replace(/,/g, '');
            }

            if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(value)) {
                return sign + value.replace(/\./g, '').replace(',', '.');
            }

            return String(raw).trim();
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

        // พิมพ์วันที่ลงแถวในตารางเอง → เติมค่าเดิมของวันนั้นมาให้เห็น
        // ⚠️ จำเป็น เพราะการบันทึกเป็นการเขียนทับทุกช่อง ถ้าไม่เติมโน้ตเดิมกลับมา
        // การกรอกแค่ยอดจะลบโน้ตของวันนั้นทิ้ง (เหมือนที่ฟอร์มเดี่ยวทำอยู่แล้ว)
        const bulkRowRequestIds = new WeakMap();

        tbody.addEventListener('change', async (event) => {
            const input = event.target;
            if (!input || input.getAttribute('name') !== 'record_date[]') {
                return;
            }

            const value = input.value;
            const row = input.closest('tr');
            if (!row || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                return;
            }

            const revenueInput = row.querySelector('input[name="revenue[]"]');
            const adCostInput = row.querySelector('input[name="ad_cost[]"]');
            const noteInput = row.querySelector('input[name="note[]"]');

            // ⚠️⚠️⚠️ ค่าที่ **ระบบเติมให้ของวันเก่า** ต้องถูกล้างก่อนโหลดวันใหม่
            //
            // เดิมไม่ล้าง แล้วด่าน "กรอกครบ 3 ช่องแล้วให้ออกทันที" ด้านล่างก็ทำงานพอดี
            // เพราะการเติมของวันเก่าทำให้ครบทั้ง 3 ช่องเสมอ → เปลี่ยนวันแล้วไม่มีอะไรเกิดขึ้น
            //
            // วัดจริงจนถึงฐานข้อมูล (เลือกผิดเป็น 1 ส.ค. แล้วรู้ตัวแก้เป็น 3 ส.ค. แล้วกดบันทึก):
            //   บนจอ  : วันที่ 3 ส.ค. · ยอด 5,000 / 2,000 · โน้ต "โน้ตเดิมวันที่ 1"
            //   ข้อความ: "วันที่ **2026-08-01** มีข้อมูลอยู่แล้ว …" (ชี้วันเก่าที่ไม่ได้เลือกแล้ว)
            //   ผลลัพธ์: 3 ส.ค. จากเดิม 4,000 / 3,000 / ไม่มีโน้ต **ถูกทับด้วยข้อมูลของ 1 ส.ค.**
            //
            // ธง `data-prefilled` แยก "ค่าที่ระบบเติม" ออกจาก "ค่าที่ผู้ใช้พิมพ์เอง"
            // ผู้ใช้แตะช่องไหนเมื่อไหร่ ธงของช่องนั้นหลุดทันที (ดูตัวฟัง `input` ด้านล่าง)
            [revenueInput, adCostInput, noteInput].forEach((field) => {
                if (field && field.dataset.prefilled === '1') {
                    field.value = '';
                    delete field.dataset.prefilled;
                }
            });

            // ⚠️⚠️ เดิมออกทันทีเมื่อมีช่องไหนกรอกไว้แล้ว ผลคือคนที่พิมพ์ยอดก่อนแล้วค่อย
            // เลือกวันที่ (ท่าที่ใช้จริงบ่อย) **ไม่เคยได้โน้ตเดิมกลับมาเลย** แล้วการบันทึก
            // ซึ่งเขียนทับทุกช่อง ก็ลบโน้ตของวันนั้นทิ้งพร้อมข้อความว่า "บันทึกเรียบร้อยแล้ว"
            //
            // ตอนนี้เติมเฉพาะช่องที่ยังว่าง — ของที่ผู้ใช้พิมพ์เองไม่ถูกทับ
            // และช่องที่ไม่ได้แตะก็ได้ค่าเดิมกลับมาครบ
            if (revenueInput && adCostInput && noteInput
                && revenueInput.value !== '' && adCostInput.value !== '' && noteInput.value !== '') {
                return;
            }

            // เปลี่ยนวันที่แล้ว ธงของวันเก่าใช้ไม่ได้อีก — ล้างก่อนเริ่มโหลดใหม่เสมอ
            const pendingFlag = row.querySelector('input[name="note_checked[]"]');
            if (pendingFlag) { pendingFlag.value = ''; }

            const requestId = (bulkRowRequestIds.get(row) || 0) + 1;
            bulkRowRequestIds.set(row, requestId);

            try {
                const byDate = await loadMonthData(value.slice(0, 7));

                // เปลี่ยนวันของแถวนี้อีกครั้งระหว่างรอ → ทิ้งผลลัพธ์เก่า
                if (bulkRowRequestIds.get(row) !== requestId || input.value !== value) {
                    return;
                }

                const checkedFlag = row.querySelector('input[name="note_checked[]"]');
                const day = byDate.get(value);
                if (!day) {
                    // ตารางเดือนไม่ครอบวันอนาคต — บอกตรง ๆ ว่าตรวจให้ไม่ได้
                    // ดีกว่าปล่อยช่องว่างไว้เฉย ๆ แล้วผู้ใช้กดบันทึกทับของเดิม
                    if (value > todayIso()) {
                        showBulkNotice('วันที่ ' + value + ' อยู่ในอนาคต — ระบบตรวจข้อมูลเดิมให้ไม่ได้ '
                            + 'ถ้าวันนั้นเคยบันทึกไว้แล้ว การกดบันทึกจะเขียนทับ');
                    }

                    return;
                }

                // เทียบกับข้อมูลเดิมของวันนั้นสำเร็จแล้ว — ช่องโน้ตที่ว่างหลังจากนี้
                // แปลว่า "ผู้ใช้ตั้งใจล้าง" จริง ๆ ไม่ใช่ "ยังไม่รู้ว่ามีของเดิมอยู่"
                if (checkedFlag) { checkedFlag.value = '1'; }

                // ตารางเดือนคืนทุกวันจนถึงวันนี้ รวมวันที่ยังไม่ได้กรอก — ต้องแยกให้ออก
                const dayHasData = day.revenue !== null || day.ad_cost !== null || (day.note || '') !== '';
                if (!dayHasData) {
                    return;
                }

                // เติมเฉพาะช่องที่ยังว่าง — ห้ามทับสิ่งที่ผู้ใช้เพิ่งพิมพ์เอง
                //
                // ⚠️⚠️ "ว่าง" ไม่พอ ต้องเป็น "ว่างเพราะผู้ใช้ไม่ได้กรอก" ด้วย
                // ช่องที่ธง `unreadable` ติดอยู่คือช่องที่ผู้ใช้ **วางค่ามาแล้ว** แต่เบราว์เซอร์
                // ทิ้งทันทีเพราะอ่านไม่ออก (เช่น 12,500 ในช่อง type="number")
                // ถ้าเติมค่าเดิมทับลงไป แถวจะดูเหมือนกรอกครบ ผู้ใช้กดบันทึก แล้ว
                // **ยอดที่ตั้งใจวางไม่เคยถูกบันทึกเลย โดยไม่มีอะไรบอก** (วัดจริงถึงฐานข้อมูลแล้ว)
                const canFill = (field) => field && field.value === '' && field.dataset.unreadable !== '1';

                let filledAnything = false;
                if (canFill(revenueInput) && day.revenue !== null) {
                    revenueInput.value = String(day.revenue);
                    revenueInput.dataset.prefilled = '1';
                    filledAnything = true;
                }
                if (canFill(adCostInput) && day.ad_cost !== null) {
                    adCostInput.value = String(day.ad_cost);
                    adCostInput.dataset.prefilled = '1';
                    filledAnything = true;
                }
                if (canFill(noteInput) && (day.note || '') !== '') {
                    noteInput.value = day.note;
                    noteInput.dataset.prefilled = '1';
                    filledAnything = true;
                }

                if (!filledAnything) {
                    showBulkNotice('วันที่ ' + value + ' มีข้อมูลอยู่แล้ว — กดบันทึกจะเป็นการแก้ไขทับ');
                    return;
                }

                refresh();
                showBulkNotice('วันที่ ' + value + ' มีข้อมูลอยู่แล้ว — ระบบเติมค่าเดิมไว้ให้ กดบันทึกจะเป็นการแก้ไขทับ');
            } catch (error) {
                // ⚠️ ธงต้องเป็นว่างไว้ — เซิร์ฟเวอร์จะได้ปฏิเสธแทนที่จะลบโน้ตเดิมทิ้งเงียบ ๆ
                const failedFlag = row.querySelector('input[name="note_checked[]"]');
                if (failedFlag) { failedFlag.value = ''; }
                showBulkNotice('โหลดข้อมูลเดิมของวันที่ ' + value + ' ไม่สำเร็จ — ตรวจสอบก่อนกดบันทึก');
            }
        });

        // ผู้ใช้พิมพ์เองเมื่อไหร่ ค่านั้นเลิกเป็น "ค่าที่ระบบเติม" ทันที
        // (เปลี่ยนวันแล้วจะได้ไม่ถูกล้างทิ้ง — ตัวล้างด้านบนแตะเฉพาะของที่ระบบเติม)
        tbody.addEventListener('input', (event) => {
            const field = event.target;
            if (field && field.dataset && field.dataset.prefilled === '1') {
                delete field.dataset.prefilled;
            }

            // ผู้ใช้แก้ช่องที่อ่านไม่ออกเองแล้ว — เลิกกันไม่ให้เติมค่าเดิม
            if (field && field.dataset && field.dataset.unreadable === '1') {
                delete field.dataset.unreadable;
            }
        });

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

            // ข้ามแถวหัวตาราง — เดิมเช็กเฉพาะตอนวางเริ่มที่คอลัมน์วันที่
            // แต่ท่าที่ใช้จริงบ่อยคือ "กดเติมวันที่ขาด แล้ววางเฉพาะคอลัมน์ยอด" ซึ่งเริ่มที่
            // คอลัมน์รายได้ หัวตารางจาก Excel จึงกินแถวแรกไป แล้วยอดทุกตัวเลื่อนไปผิดวัน
            // → ถ้าเริ่มที่คอลัมน์ตัวเลข ให้ดูว่าแถวแรกเป็นตัวเลขไหม ไม่ใช่ก็คือหัวตาราง
            // ⚠️ ใช้ได้เฉพาะคอลัมน์ที่ "ค่าจริงต้องเป็นวันที่/ตัวเลข" เท่านั้น
            // คอลัมน์โน้ตเป็นข้อความล้วน ทุกแถวจึงเข้าเกณฑ์ "ไม่ใช่ตัวเลข = หัวตาราง"
            // → วางโน้ต 3 แถวแล้วแถวแรกหายและทุกแถวเลื่อนขึ้น (เคยเกิดจริง)
            const NOTE_COLUMN_INDEX = 3;
            const firstCellLooksLikeHeader = startCol === NOTE_COLUMN_INDEX
                ? false
                : (startCol === 0
                    ? !looksLikeDateCell(grid[0][0])
                    : (grid[0] || []).every((cell) => String(cell).trim() !== '' && !isNumericCell(cell)));

            if (grid.length > 1 && firstCellLooksLikeHeader) {
                grid.shift();
            }

            let placedRows = 0;
            let truncatedRows = 0;
            let unreadableDates = 0;
            let unreadableAmounts = 0;
            let shiftedColumns = 0;

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
                        // เกินคอลัมน์สุดท้าย (โน้ต) — นับไว้เพื่อเตือน เดิมทิ้งเงียบ ๆ
                        if (String(cell).trim() !== '') {
                            shiftedColumns++;
                        }
                        return;
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
                        input.dataset.unreadable = '1';
                        return;
                    }

                    input.value = normalizeCell(columnIndex, cell);

                    // ⚠️⚠️ ช่องจำนวนเงินเป็น `<input type="number">` ซึ่ง **ทิ้งค่าที่ไม่ใช่
                    // ตัวเลขเงียบ ๆ** — กติกาของ `cleanAmountCell()` คือ "อ่านไม่ออกให้คืน
                    // ค่าดิบ แล้วให้เซิร์ฟเวอร์ปฏิเสธพร้อมข้อความ" แต่ค่าดิบไปไม่ถึงเซิร์ฟเวอร์
                    // เพราะเบราว์เซอร์ลบทิ้งก่อน · ผู้ใช้จึงเห็นแค่ช่องว่าง ไม่มีคำอธิบายอะไรเลย
                    //
                    // วัดจริง: Excel ที่ตั้งรูปแบบเงินแบบมีจุลภาคไม่มีสตางค์ (12,500 / 9,800)
                    // ซึ่งเป็นรูปแบบที่ใช้กันปกติที่สุด → ก๊อปทั้งคอลัมน์มาวาง **ทุกช่องว่างหมด**
                    // ถ้าผู้ใช้ไม่ทันสังเกตแล้วกดบันทึก วันใหม่จะถูกบันทึกเป็นรายได้ ฿0
                    if (input.value === '' && String(cell).trim() !== '') {
                        unreadableAmounts++;

                        // ⚠️⚠️ ช่องนี้ "ว่างเพราะเบราว์เซอร์ทิ้งค่าที่ผู้ใช้วาง" ไม่ใช่
                        // "ว่างเพราะผู้ใช้ไม่ได้กรอก" — ตัวเติมค่าเดิมต้องแยกสองอย่างนี้ให้ออก
                        //
                        // วัดจริงจนถึงฐานข้อมูล: วาง 12,500 / 4,200 ทับวันที่ 1–3 ส.ค.
                        // → ช่องยอดว่าง → ตัวเติมเห็นว่าว่างเลยเติม **ค่าเดิม** กลับเข้าไป
                        // (5,000 / 2,000) → บนจอดูเหมือนกรอกครบ ผู้ใช้กดบันทึก
                        // → ยอดที่วางมาไม่เคยถูกบันทึกเลย และไม่มีอะไรบอกว่าหายไป
                        input.dataset.unreadable = '1';
                    } else {
                        delete input.dataset.unreadable;
                    }
                });

                placedRows++;
            });

            refresh();

            // ⚠️⚠️ `input.value = …` **ไม่ทำให้ event `change` ทำงาน** ตัวเติมค่าเดิมของวัน
            // (ซึ่งฟัง `change` ของ `record_date[]`) จึงไม่เคยทำงานเลยตอนวางจาก Excel
            // ผลคือวางทับวันที่เคยมีโน้ตอยู่ แล้วกดบันทึก โน้ตหายพร้อมข้อความว่าสำเร็จ
            //
            // ยิง event เองหลังวางเสร็จ เพื่อให้ทางวางกับทางเลือกวันเองเดินโค้ดชุดเดียวกัน
            // (`loadMonthData` มี cache ต่อเดือนอยู่แล้ว หลายแถวจึงไม่ยิง request ซ้ำ)
            // ⚠️ ต้องเรียก `getRows()` ตรงนี้ — `rows` ที่ประกาศไว้ข้างบนอยู่ **ข้างใน**
            // callback ของ `grid.forEach` จึงมองไม่เห็นจากตรงนี้ · เดิมเขียน `rows.forEach`
            // เฉย ๆ ทำให้เกิด ReferenceError ทุกครั้งที่วาง แล้วโค้ดหลังจากนี้ตายทั้งหมด
            getRows().forEach((row) => {
                const dateInput = row.querySelector('input[name="record_date[]"]');
                if (dateInput && /^\d{4}-\d{2}-\d{2}$/.test(dateInput.value)) {
                    dateInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });

            if (pasteNotice) {
                const messages = [];
                const stickyParts = [];

                if (truncatedRows > 0) {
                    messages.push('วางได้ ' + placedRows + ' แถว · ส่วนที่เกิน '
                        + MAX_ROWS + ' แถว (' + truncatedRows + ' แถว) ถูกตัด');
                }

                if (shiftedColumns > 0) {
                    messages.push('ข้อมูลที่วางกว้างเกินตาราง ' + shiftedColumns + ' ช่องจึงตกหล่น — '
                        + 'ตรวจสอบว่าเริ่มวางตรงคอลัมน์ที่ถูกต้องหรือไม่');
                }

                if (unreadableAmounts > 0) {
                    stickyParts.push('มี ' + unreadableAmounts + ' ช่องจำนวนเงินที่อ่านไม่ได้ '
                        + '(เช่น 12,500 อ่านได้ทั้ง 12500 และ 12.5) — เว้นว่างไว้ '
                        + 'กรุณาใช้ตัวเลขล้วน เช่น 12500 หรือใส่สตางค์ให้ครบ เช่น 12,500.00');
                }

                if (unreadableDates > 0) {
                    stickyParts.push('มี ' + unreadableDates + ' ช่องวันที่ที่อ่านไม่ได้หรือกำกวม '
                        + '(เช่น 05/03/2026 อ่านได้ทั้งวัน/เดือน และเดือน/วัน) — เว้นว่างไว้ '
                        + 'กรุณาเลือกวันที่เอง หรือใช้รูปแบบ ปี-เดือน-วัน');
                }

                // เก็บไว้เป็นข้อความ "ติดหนึบ" เพื่อไม่ให้ตัวเติมค่าเดิม (ซึ่งทำงานต่อจาก
                // event `change` ที่เพิ่งยิงไป) มาเขียนทับคำเตือนของการวางทั้งก้อน
                // ⚠️ ข้อความ 2 ชนิดนี้อายุไม่เท่ากัน:
                //  · "วางเกิน 31 แถว" / "กว้างเกินตาราง" = เล่าเหตุการณ์ครั้งนั้น จบในตัว
                //  · "อ่านยอด/วันที่ไม่ได้" = อธิบาย **ช่องว่างที่ยังคาอยู่บนจอ** จึงต้องอยู่ต่อ
                //    จนกว่าผู้ใช้จะแก้ครบ ไม่งั้นตัวเติมค่าเดิม (ซึ่งทำงานทันทีหลังวาง)
                //    จะเขียนทับมันหายไป แล้วช่องว่างก็ไม่มีคำอธิบายอะไรเลย
                stickyPasteMessage = stickyParts.join(' · ');
                const shown = [stickyPasteMessage, messages.join(' · ')].filter((part) => part !== '');
                pasteNotice.textContent = shown.join(' · ');
                pasteNotice.classList.toggle('hidden', shown.length === 0);
            }
        });

        const monthInput = document.getElementById('bulk-month');
        let loadedMonth = monthInput ? monthInput.value : '';

        /* เขียนเดือนแบบไทยกำกับช่องเลือกเดือน — ช่องมาตรฐานของเบราว์เซอร์เขียนเป็น
           "August 2026" เสมอ ขณะที่ทั้งหน้าใช้ "ส.ค. 2569" (ดูคอมเมนต์ที่ตัว <span>)
           ⚠️ รายชื่อเดือนย่อและการบวก 543 ต้องตรงกับ `formatThaiMonth()` ฝั่ง PHP */
        const THAI_MONTH_NAMES = [
            'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
            'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
        ];

        const showThaiMonth = (month) => {
            const target = document.getElementById('bulk-month-thai');
            if (!target) {
                return;
            }

            const matched = /^(\d{4})-(\d{2})$/.exec(month || '');
            if (!matched) {
                target.textContent = '';
                return;
            }

            const monthIndex = Number(matched[2]) - 1;
            const name = THAI_MONTH_NAMES[monthIndex] || '';
            target.textContent = name === '' ? '' : name + ' ' + (Number(matched[1]) + 543);
        };

        const showBulkNotice = (message) => {
            if (!pasteNotice) {
                return;
            }

            // แก้ครบแล้วต้องเงียบเอง — ไม่งั้นคำเตือนเก่าจะโผล่ซ้ำทุกครั้งที่มีข้อความใหม่
            if (tbody.querySelector('[data-unreadable="1"]') === null) {
                stickyPasteMessage = '';
            }

            const parts = [stickyPasteMessage, message].filter((part) => part !== '');
            if (parts.length === 0) {
                pasteNotice.classList.add('hidden');
                return;
            }

            pasteNotice.textContent = parts.join(' · ');
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

                    // ⚠️ เซิร์ฟเวอร์ปรับเดือนอนาคตกลับเป็นเดือนปัจจุบันเงียบ ๆ (resolve_calendar_month)
                    // ถ้าไม่เช็ก ตารางจะเต็มไปด้วยข้อมูล "เดือนนี้" ขณะที่ช่องเลือกยังโชว์เดือนอนาคต
                    // แล้วผู้ใช้แก้ตัวเลขทับเดือนนี้โดยไม่รู้ตัว
                    const resolvedMonth = (payload.data && payload.data.month) ? payload.data.month : selectedMonth;
                    const days = (payload.data && payload.data.days) ? payload.data.days : [];

                    populateFromDays(days);
                    loadedMonth = resolvedMonth;
                    monthInput.value = resolvedMonth;
                    showThaiMonth(resolvedMonth);

                    if (resolvedMonth !== selectedMonth) {
                        showBulkNotice('ยังกรอกล่วงหน้าไม่ได้ — แสดงข้อมูลของเดือน ' + resolvedMonth + ' ให้แทน');
                    } else {
                        showBulkNotice(days.length === 0 ? 'เดือนนี้ยังไม่ถึงกำหนดกรอก' : '');
                    }
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

<?php /* ยุบเก็บด้วยเหตุผลเดียวกับ "กรอกหลายวัน" — นำเข้าไฟล์เป็นงานที่ทำนาน ๆ ครั้ง */ ?>
<details class="section-card mt-6 p-5">
    <summary class="cursor-pointer list-none text-lg font-semibold text-slate-100">
        นำเข้าไฟล์ CSV <span class="text-sm font-normal text-slate-400">— ย้ายข้อมูลจาก Excel เข้ามาทั้งไฟล์</span>
    </summary>
    <p class="mt-3 text-sm text-slate-400">
        รองรับคอลัมน์ <span class="text-slate-300">วันที่ · รายได้ · ค่าแอด · โน้ต</span>
        (หัวภาษาอังกฤษ date/revenue/ad_cost/note ก็ได้) ·
        คอลัมน์ที่คำนวณเอง เช่น กำไร/ROAS และแถว "รวม" จะถูกข้ามให้อัตโนมัติ
    </p>
    <p class="mt-1 text-xs text-slate-400">
        ไฟล์ที่ดาวน์โหลดจากหน้าประวัติ นำกลับเข้ามาได้เลย · วันเดียวกันจะอัปเดตทับ ·
        <span class="text-slate-300">ช่องรายได้/ค่าแอดต้องมีตัวเลขทุกแถว — วันที่ไม่มีให้ใส่ 0</span> ·
        ถ้ามีแถวใดผิด ระบบจะไม่บันทึกทั้งไฟล์ · สูงสุด <?= e((string)RecordService::IMPORT_MAX_ROWS) ?> แถว / 2MB
    </p>

    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" enctype="multipart/form-data"
        class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <?= csrf_field() ?>
        <?= shop_context_field($shopId) ?>
        <input type="hidden" name="action" value="import_csv">

        <?php /* ⚠️ ช่องนี้เคยเป็นช่องเดียวใน 27 ช่องทั้งระบบที่ไม่มีชื่อ —
                 โปรแกรมอ่านหน้าจอพูดแค่ "เลือกไฟล์" โดยไม่บอกว่าไฟล์อะไร */ ?>
        <label for="csv-file" class="sr-only">เลือกไฟล์ CSV ที่จะนำเข้า</label>
        <input
            id="csv-file"
            type="file"
            name="csv"
            accept=".csv,text/csv"
            required
            class="w-full rounded-xl border border-white/10 bg-[#070c18] px-3 py-2 text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-1.5 file:text-sm file:text-slate-200">

        <button type="submit" class="btn-teal shrink-0 px-6 py-2.5 text-sm">↑ นำเข้า</button>
    </form>
</details>

<section class="section-card mt-6 p-5">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-100">รายการล่าสุด 7 วัน</h2>
        <a href="<?= e(app_url('/history.php')) ?>" class="text-sm text-indigo-400 hover:text-indigo-300 font-medium">ดูประวัติทั้งหมด</a>
    </div>

    <div class="overflow-x-auto">
        <table class="table-cards min-w-full text-sm">
            <thead>
                <tr class="border-b border-white/10 text-left text-slate-400">
                    <th scope="col" class="px-3 py-2">วันที่</th>
                    <th scope="col" class="px-3 py-2">รายได้</th>
                    <th scope="col" class="px-3 py-2">ค่าแอด</th>
                    <th scope="col" class="px-3 py-2">กำไร</th>
                    <th scope="col" class="px-3 py-2">ROAS</th>
                    <th scope="col" class="px-3 py-2">โน้ต</th>
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
                            <?php /* เพดานความกว้าง + ตัดบรรทัด เหตุผลเดียวกับตารางในหน้าประวัติ
                                     (โน้ตยาวเคยดันตารางจนคอลัมน์ท้าย ๆ หลุดออกนอกจอ) */ ?>
                            <td class="px-3 py-2 text-slate-400 whitespace-normal break-words lg:max-w-[16rem] lg:align-top"><?= $note !== '' ? e($note) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
    // เตือนให้ตรงกับ server-side validation: daily_records เป็นยอดจริงเท่านั้น
    (() => {
        const dateInput = document.getElementById('record-date');
        const warning = document.getElementById('future-date-warning');
        if (!dateInput || !warning) {
            return;
        }

        // ⚠️ ใช้ helper กลางตัวเดียวกับที่อื่น — เดิมมีชื่อ `todayIso` ซ้ำกันแต่เป็น
        // คนละชนิด (ที่นี่เป็นสตริง อีกที่เป็นฟังก์ชัน) อ่านแล้วสับสนและแก้ผิดได้ง่าย
        const sync = () => {
            warning.classList.toggle('hidden', !(dateInput.value && dateInput.value > todayIso()));
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

        let lastAppliedDate = dateInput.value; // ค่าที่เซิร์ฟเวอร์เติมมาให้ตอนโหลดหน้า
        const HINT_DEFAULT_TEXT = hint.textContent;
        let pendingRequestId = 0;              // กันผลลัพธ์เก่ามาทับผลลัพธ์ใหม่

        const applyDay = (day) => {
            if (day && (day.revenue !== null || day.ad_cost !== null || day.note)) {
                revenueInput.value = day.revenue === null ? '' : String(day.revenue);
                adCostInput.value = day.ad_cost === null ? '' : String(day.ad_cost);
                noteInput.value = day.note || '';
                // คืนข้อความปกติเสมอ — เดิมข้อความ error ค้างอยู่ตลอดอายุหน้า
                // ทำให้การโหลดที่สำเร็จครั้งต่อ ๆ ไปยังขึ้นว่า "โหลดไม่สำเร็จ"
                hint.textContent = HINT_DEFAULT_TEXT;
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

            const requestId = ++pendingRequestId;

            try {
                const byDate = await loadMonthData(value.slice(0, 7));

                // เปลี่ยนวันอีกครั้งระหว่างรอ → ทิ้งผลลัพธ์เก่า ไม่งั้นตัวเลขของวันก่อนหน้า
                // จะถูกเติมลงในวันใหม่
                if (requestId !== pendingRequestId) {
                    return;
                }

                const existing = byDate.get(value) || null;
                applyDay(existing);

                // ⚠️⚠️ วันอนาคตไม่อยู่ในตารางเดือน (ตารางตัดที่วันนี้) ระบบจึงตรวจ
                // ข้อมูลเดิมของวันนั้นให้ไม่ได้ · เดิมโค้ดล้างทุกช่องแล้วซ่อนป้ายเตือน
                // ผู้ใช้ที่เคยลงข้อมูลล่วงหน้าไว้จึงเห็นฟอร์มว่างเปล่า เข้าใจว่ายังไม่เคย
                // บันทึก แล้วพิมพ์ใหม่ทับของเดิม โน้ตหาย ยอดถูกเขียนทับ พร้อมข้อความ "สำเร็จ"
                //
                // ตารางกรอกหลายวันเตือนเคสนี้อยู่แล้ว ฟอร์มหลักตกหล่นจุดเดียว
                if (existing === null && value > todayIso()) {
                    hint.textContent = 'วันที่ ' + value + ' อยู่ในอนาคต — ระบบตรวจข้อมูลเดิมให้ไม่ได้ '
                        + 'ถ้าวันนั้นเคยบันทึกไว้แล้ว การกดบันทึกจะเขียนทับ';
                    hint.classList.remove('hidden');
                }

                lastAppliedDate = value;
            } catch (error) {
                if (requestId !== pendingRequestId) {
                    return;
                }

                // โหลดไม่ได้ → ล้างช่องแล้วเตือน ดีกว่าปล่อยค่าของวันก่อนหน้าค้างไว้
                // แล้วผู้ใช้กดบันทึกทับวันใหม่ด้วยตัวเลขของวันเก่า
                applyDay(null);
                lastAppliedDate = value;
                hint.textContent = '⚠️ โหลดข้อมูลเดิมของวันที่ ' + value + ' ไม่สำเร็จ — ตรวจสอบก่อนกดบันทึก';
                hint.classList.remove('hidden');
            }
        });
    })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
