<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
// ⚠️ ต้องซ่อม session ก่อนดึงข้อมูล — ร้านอาจถูกลบจากอุปกรณ์อื่นไปแล้ว
// (เดิมการซ่อมอยู่ใน header.php ซึ่ง include ท้ายไฟล์ หน้าจึงขึ้น "ไม่มีสิทธิ์" + ฿0 หนึ่งครั้ง)
$shopId = resolve_current_shop_id($pdo, $userId);

// รายงานยังหดเดือนอนาคตเพื่อไม่แสดงตัวเลขของช่วงที่ยังไม่เกิด แต่ History เป็นหน้าจัดการ
// รายการ จึงต้องเปิดเดือนอนาคตได้เมื่อมีข้อมูลอยู่จริง ไม่เช่นนั้นรายการที่ลงล่วงหน้าจะ
// แก้ไขหรือลบผ่านหน้าเว็บไม่ได้. เดือนอนาคตที่ว่างยังคงหดด้วย helper เดิม.
$requestedMonth = trim((string)($_GET['month'] ?? ''));
$selectedMonth = resolve_calendar_month($requestedMonth);

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$historyError = null;
$historyResult = $recordService->getMonthlyRecords($userId, $shopId, $selectedMonth);

// กติกากลาง — ปุ่ม Export ใช้ตัวเดียวกัน (เดิมเขียนแยกกัน แล้วได้ไฟล์คนละเดือนกับหน้าจอ)
$futureHistoryResult = null;
$selectedMonth = resolve_month_allowing_legacy_future(
    $requestedMonth,
    $selectedMonth,
    function (string $month) use (&$futureHistoryResult, $recordService, $userId, $shopId): bool {
        $futureHistoryResult = $recordService->getMonthlyRecords($userId, $shopId, $month);

        return ($futureHistoryResult['success'] ?? false) === true
            && !empty($futureHistoryResult['data']['records']);
    }
);

if ($selectedMonth === $requestedMonth && $futureHistoryResult !== null) {
    $historyResult = $futureHistoryResult;
}

// ⚠️ ขอเดือนอนาคตที่ไม่มีข้อมูล = ถูกหดกลับมาเป็นเดือนปัจจุบัน ต้องบอกผู้ใช้ด้วย
// ไม่งั้นตัวเลือกเดือนเด้งกลับมาเองโดยไม่มีคำอธิบาย (หน้าอื่นมี max= กันไว้ แต่หน้านี้
// ต้องเปิดเดือนอนาคตที่มีข้อมูลได้ จึงใส่ max ไม่ได้ — บอกด้วยข้อความแทน)
// ⚠️ เทียบกับ "เดือนที่กำลังแสดงจริง" ไม่ใช่เขียนเงื่อนไข "เดือนอนาคต" ขึ้นใหม่
// (กติกาว่าเดือนไหนเปิดได้อยู่ที่ resolve_month_allowing_legacy_future() ที่เดียว)
$historyMonthNotice = null;
if (
    preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth) === 1
    && $requestedMonth !== $selectedMonth
) {
    $historyMonthNotice = 'เดือน ' . formatThaiMonth($requestedMonth) . ' ยังไม่มีรายการ '
        . 'ระบบจึงแสดงเดือน ' . formatThaiMonth($selectedMonth) . ' ให้แทน';
}

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
    // แสดงเป็นแถบถาวรเหมือนทุกหน้า — เดิมเป็น toast ที่หายไปใน 3 วินาที
    // ผู้ใช้ที่พลาดจังหวะจะเห็นแค่ "ยังไม่มีข้อมูลในเดือนนี้" กับยอดรวม ฿0
    $historyError = (string)($historyResult['error'] ?? 'ไม่สามารถโหลดข้อมูลประวัติได้');
}

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'ประวัติรายการ';
$currentPage = 'history';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between sm:gap-4">
        <div>
            <h1 class="text-lg sm:text-xl font-semibold text-slate-100">ประวัติรายการ</h1>
            <p class="mt-1 text-xs sm:text-sm text-slate-400">ตารางรายการของเดือนที่เลือก พร้อมแก้ไข/ลบได้ทันที</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <form method="get" action="<?= e(app_url('/history.php')) ?>" class="flex flex-wrap items-center gap-2">
                <label for="month" class="text-sm text-slate-400">เลือกเดือน</label>
                <?php /* ⚠️ หน้านี้ **ตั้งใจไม่ใส่ `max`** ต่างจากแดชบอร์ด/หน้าบันทึก
                         เพราะต้องเปิดเดือนอนาคตที่มี "แถวเก่า" อยู่ให้ได้ ไม่งั้นข้อมูลที่ลงไว้
                         ก่อนกติกา "ห้ามบันทึกวันอนาคต" จะแก้/ลบผ่านหน้าเว็บไม่ได้อีกเลย
                         เดือนอนาคตที่ *ไม่มี* ข้อมูลยังถูกหดกลับ + แจ้งผู้ใช้ตามปกติ
                         (`resolve_month_allowing_legacy_future()` ด้านบน) */ ?>
                <input
                    id="month"
                    name="month"
                    type="month"
                    value="<?= e($selectedMonth) ?>"
                    class="rounded-xl px-3 py-2 text-sm transition-all">
                <button type="submit" class="btn-ghost px-4 py-2 text-sm">แสดงผล</button>
            </form>
            <?php if ($historyMonthNotice !== null): ?>
                <p class="mt-2 text-xs text-amber-400"><?= e($historyMonthNotice) ?></p>
            <?php endif; ?>

            <?php if ($historyError === null): ?>
                <a href="<?= e(app_url('/api/export.php?month=' . rawurlencode($selectedMonth))) ?>" data-loading-link="true" class="btn-teal px-4 py-2 text-sm">
                    📥 Export CSV
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($historyError !== null): ?>
        <div class="mt-4 rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-sm text-red-400">
            <?= e($historyError) ?>
        </div>
    <?php endif; ?>

    <div class="mt-5 overflow-x-auto">
        <?php /* ⚠️ `table-cards` = บนจอเล็กแถวจะกลายเป็นการ์ด 1 วัน/1 ใบ
                 · หน้าตาการ์ดอยู่ที่ CSS ใน `includes/header.php`
                 · ชื่อคอลัมน์ที่แปะหน้าค่าแต่ละช่อง มาจาก <thead> ของตารางนี้เอง
                   (สคริปต์ใน `includes/footer.php` เป็นคนแปะ) — **ห้ามพิมพ์ชื่อคอลัมน์
                   ซ้ำไว้ที่ <td>** ไม่งั้นวันที่ใครสลับคอลัมน์ ป้ายจะชี้ผิดช่องเงียบ ๆ */ ?>
        <table class="table-cards min-w-full text-sm">
            <thead>
                <tr class="border-b border-white/10 text-left text-slate-400">
                    <th class="px-3 py-2">วันที่</th>
                    <th class="px-3 py-2">รายได้</th>
                    <th class="px-3 py-2">ค่าแอด</th>
                    <th class="px-3 py-2">กำไร</th>
                    <th class="px-3 py-2">ROAS</th>
                    <th class="px-3 py-2">เทียบครั้งก่อน</th>
                    <th class="px-3 py-2">โน้ต</th>
                    <th class="px-3 py-2 text-center">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" class="px-3 py-4 text-center text-slate-400">
                            <?php /* ⚠️ ห้ามเขียน "เดือนนี้" ตายตัว — ผู้ใช้เปิดดูเดือนไหนก็ได้
                                     เปิด ?month=2025-03 แล้วขึ้นว่า "ยังไม่มีข้อมูลในเดือนนี้"
                                     ทั้งที่ตัวเลือกเดือนบนจอเดียวกันโชว์ มี.ค. 2568 อยู่
                                     (รูปแบบเดิมที่เคยแก้ไปแล้ว 5 จุด — หน้านี้ตกสำรวจ) */ ?>
                            <?= $historyError !== null
                                ? 'โหลดข้อมูลไม่สำเร็จ'
                                : 'ยังไม่มีข้อมูลในเดือน ' . e(formatThaiMonth($selectedMonth)) ?>
                        </td>
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
                        <tr class="border-b border-white/[0.06] table-row-hover whitespace-nowrap">
                            <td class="px-3 py-2 text-slate-300 font-medium"><?= e(formatThaiDate($recordDate)) ?></td>
                            <td class="px-3 py-2 text-orange-400 font-medium"><?= e(formatMoney($revenue)) ?></td>
                            <td class="px-3 py-2 text-cyan-400 font-medium"><?= e(formatMoney($adCost)) ?></td>
                            <td class="px-3 py-2 <?= $profit >= 0 ? 'text-green-400' : 'text-red-400' ?> font-bold"><?= e(formatMoney($profit)) ?></td>
                            <td class="px-3 py-2 text-violet-400 font-medium"><?= e(formatRoas($roas)) ?></td>
                            <td class="px-3 py-2 font-medium">
                                <?php $compareBadge = format_change_badge($comparePercent); ?>
                                <span class="<?= e($compareBadge['class']) ?>"><?= e($compareBadge['text']) ?></span>
                            </td>
                            <?php /* ⚠️⚠️ ช่องโน้ตต้องมีเพดานความกว้างและตัดบรรทัดได้
                                     แถวตั้ง `whitespace-nowrap` ไว้ให้ตัวเลขไม่ตัดคำ แต่โน้ตยาวได้ถึง 255 ตัว
                                     · วัดจริงบนจอ 1280: โน้ต 223 ตัวทำให้ช่องนี้กว้าง 1,396px ดันตารางเป็น
                                       2,249px ในกรอบ 1,078px → **ปุ่มแก้ไข/ลบไปอยู่ที่ x=2,212 คือนอกจอ**
                                       ผู้ใช้แก้หรือลบรายการนั้นไม่ได้จนกว่าจะเลื่อนตารางไปทางขวาไกลมาก
                                     · เป็นการปิดกั้นการกระทำหลักด้วยข้อมูลที่ระบบเองอนุญาตให้กรอก */ ?>
                            <td class="px-3 py-2 text-slate-400 whitespace-normal break-words lg:max-w-[16rem] lg:align-top"><?= $note !== '' ? e($note) : '-' ?></td>
                            <td class="px-3 py-2 text-center">
                                <div class="inline-flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn-ghost tap-target rounded-lg px-2 py-1 text-xs"
                                        data-edit-button
                                        data-record-id="<?= e((string)$recordId) ?>"
                                        data-record-date="<?= e($recordDate) ?>"
                                        data-revenue="<?= e(format_amount_for_input($revenue)) ?>"
                                        data-ad-cost="<?= e(format_amount_for_input($adCost)) ?>"
                                        data-note="<?= e($note) ?>">
                                        ✏️ แก้ไข
                                    </button>

                                    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" data-delete-form data-confirm="ยืนยันการลบรายการวันที่ <?= e(formatThaiDate($recordDate)) ?> ใช่หรือไม่?">
                                        <?= csrf_field() ?>
        <?= shop_context_field($shopId) ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="month" value="<?= e($selectedMonth) ?>">
                                        <input type="hidden" name="record_id" value="<?= e((string)$recordId) ?>">
                                        <button type="submit" class="btn-danger tap-target rounded-lg px-2 py-1 text-xs">
                                            🗑️ ลบ
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

            <?php // โหลดไม่สำเร็จ = ไม่รู้ยอดรวม → ไม่แสดงแถวรวม ไม่ใช่แสดง ฿0 ?>
            <?php if ($historyError === null): ?>
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
            <?php endif; ?>
        </table>
    </div>
</section>

<div id="edit-modal" aria-labelledby="edit-modal-title" class="modal-bg fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="section-card w-full max-w-lg p-6 shadow-2xl shadow-indigo-900/10">
        <div class="mb-5 flex items-center justify-between">
            <h2 id="edit-modal-title" class="text-lg font-bold text-slate-100">✏️ แก้ไขรายการ</h2>
            <button type="button" id="close-edit-modal" class="btn-ghost rounded-lg px-3 py-1 text-sm">ปิด ✕</button>
        </div>

        <form action="<?= e(app_url('/api/records.php')) ?>" method="post" class="grid gap-4 md:grid-cols-2">
            <?= csrf_field() ?>
        <?= shop_context_field($shopId) ?>
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
                <?php /* ส้ม = ปุ่มหลักของทั้งระบบ (หน้าต่างนี้ตกสำรวจตอนรวมสีปุ่ม) */ ?>
                <button type="submit" class="btn-orange px-4 py-2 text-sm">💾 บันทึกการแก้ไข</button>
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

        /* ⚠️ กติกา "หน้าต่างซ้อนต้องใช้กับแป้นพิมพ์ได้" อยู่ที่ `setupAccessibleModal()`
           ใน includes/header.php ที่เดียว — ห้ามเขียนการดักโฟกัส/Escape ซ้ำตรงนี้
           (`SharedHelperContractTest::testEveryModalUsesTheSharedKeyboardRules` กวาดไว้) */
        const editModalA11y = setupAccessibleModal(modal, () => closeModal());

        const openModal = () => {
            if (!modal) {
                return;
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            editModalA11y.opened(fields.recordDate);
        };

        const closeModal = () => {
            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
            editModalA11y.closed();
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
