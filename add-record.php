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

$shopCount = $shopRepository->countByUserId($userId);

$pageTitle = 'บันทึกข้อมูลรายวัน';
$currentPage = 'add-record';

require __DIR__ . '/includes/header.php';
?>
<section class="rounded-xl border border-slate-700 bg-slate-800 p-5">
    <h1 class="text-xl font-semibold">บันทึกข้อมูลรายวัน</h1>
    <p class="mt-2 text-sm text-slate-300">กรอกวันซ้ำจะอัปเดตทับอัตโนมัติ</p>

    <form action="<?= e(app_url('/api/records.php')) ?>" method="post" class="mt-5 grid gap-4 md:grid-cols-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upsert">

        <div>
            <label for="record-date" class="mb-1 block text-sm text-slate-300">วันที่</label>
            <input
                id="record-date"
                name="record_date"
                type="date"
                value="<?= e(date('Y-m-d')) ?>"
                required
                class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none"
            >
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
                class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none"
            >
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
                class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none"
            >
        </div>

        <div class="md:col-span-2">
            <label for="note" class="mb-1 block text-sm text-slate-300">โน้ต (ไม่บังคับ)</label>
            <textarea
                id="note"
                name="note"
                rows="3"
                maxlength="255"
                class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none"
                placeholder="เช่น แอดชุดใหม่เริ่มวิ่ง"
            ></textarea>
        </div>

        <div class="md:col-span-2">
            <button type="submit" class="rounded-lg bg-orange-500 px-5 py-2.5 font-semibold text-slate-950 transition hover:bg-orange-400">
                บันทึกข้อมูล
            </button>
        </div>
    </form>
</section>

<section class="mt-6 rounded-xl border border-slate-700 bg-slate-800 p-5">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-lg font-semibold">รายการล่าสุด 7 วัน</h2>
        <a href="<?= e(app_url('/history.php')) ?>" class="text-sm text-cyan-400 hover:text-cyan-300">ดูประวัติทั้งหมด</a>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
            <tr class="border-b border-slate-700 text-left text-slate-400">
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
                    <tr class="border-b border-slate-700/70">
                        <td class="px-3 py-2 text-slate-200"><?= e(formatThaiDate((string)($record['record_date'] ?? ''))) ?></td>
                        <td class="px-3 py-2 text-orange-400"><?= e(formatMoney($revenue)) ?></td>
                        <td class="px-3 py-2 text-cyan-400"><?= e(formatMoney($adCost)) ?></td>
                        <td class="px-3 py-2 <?= $profit >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= e(formatMoney($profit)) ?></td>
                        <td class="px-3 py-2 text-violet-400"><?= e(formatRoas($roas)) ?></td>
                        <td class="px-3 py-2 text-slate-300"><?= $note !== '' ? e($note) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
