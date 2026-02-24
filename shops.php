<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);
$currentSessionShopId = (int)($_SESSION['current_shop_id'] ?? 0);

$shopRepository = new ShopRepository($pdo);
$userRepository = new UserRepository($pdo);
$shopService = new ShopService($shopRepository, $userRepository);

$shopContext = $shopService->getShopContext($userId, $currentSessionShopId > 0 ? $currentSessionShopId : null);
$shops = is_array($shopContext['shops'] ?? null) ? array_values((array)$shopContext['shops']) : [];
$currentShop = is_array($shopContext['current_shop'] ?? null) ? (array)$shopContext['current_shop'] : null;

if ($currentShop !== null) {
    $_SESSION['current_shop_id'] = (int)($currentShop['id'] ?? 0);
    $_SESSION['current_shop_name'] = (string)($currentShop['name'] ?? '');
}

$currentShopId = (int)($_SESSION['current_shop_id'] ?? 0);
$currentShopName = (string)($_SESSION['current_shop_name'] ?? 'ร้านค้าของฉัน');
$shopCount = count($shops);
$canDeleteShop = $shopService->canDeleteShop($userId);

$pageTitle = 'จัดการร้านค้า';
$currentPage = 'shops';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-100">จัดการร้านค้า</h1>
            <p class="mt-2 text-sm text-slate-400">สร้างร้านใหม่, เปลี่ยนร้านที่ใช้งาน, แก้ไขชื่อร้าน และลบร้านจากหน้านี้ได้ทันที</p>
        </div>

        <div class="rounded-2xl border border-indigo-400/20 bg-indigo-500/10 px-4 py-3 text-sm max-w-[50%] sm:max-w-[16rem]">
            <p class="text-slate-400">ร้านที่กำลังใช้งาน</p>
            <p class="mt-1 font-semibold text-indigo-200 truncate" title="<?= e($currentShopName) ?>">🏪 <?= e($currentShopName) ?></p>
            <p class="mt-1 text-xs text-slate-500">ทั้งหมด <?= e((string)$shopCount) ?> ร้าน</p>
        </div>
    </div>
</section>

<section class="mt-6 grid gap-6 lg:grid-cols-3">
    <article class="section-card p-5 lg:col-span-1">
        <h2 class="text-lg font-semibold text-slate-100">+ สร้างร้านใหม่</h2>
        <p class="mt-2 text-sm text-slate-400">หลังสร้างสำเร็จ ระบบจะสลับไปยังร้านใหม่ให้อัตโนมัติ</p>

        <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="mt-4 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="redirect_to" value="/shops.php">

            <div>
                <label for="new-shop-name" class="mb-1.5 block text-sm text-slate-300">ชื่อร้านค้า</label>
                <input
                    id="new-shop-name"
                    name="name"
                    type="text"
                    maxlength="100"
                    required
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="เช่น ร้านเสื้อออนไลน์">
            </div>

            <button type="submit" class="btn-primary w-full px-4 py-2.5 text-sm">✨ สร้างร้าน</button>
        </form>

        <?php if (!$canDeleteShop): ?>
            <div class="mt-4 rounded-xl border border-amber-400/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-300">
                ต้องมีอย่างน้อย 2 ร้านก่อน จึงจะลบร้านได้
            </div>
        <?php endif; ?>
    </article>

    <article class="section-card p-5 lg:col-span-2">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold text-slate-100">รายการร้านทั้งหมด</h2>
            <span class="text-xs text-slate-500">แตะการ์ดเพื่อจัดการแต่ละร้าน</span>
        </div>

        <?php if (empty($shops)): ?>
            <div class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-5 text-center text-sm text-slate-400">
                ยังไม่มีร้านค้าในระบบ กรุณาสร้างร้านแรกของคุณ
            </div>
        <?php else: ?>
            <div class="grid gap-4">
                <?php foreach ($shops as $shop): ?>
                    <?php
                    $shopId = (int)($shop['id'] ?? 0);
                    $shopName = (string)($shop['name'] ?? 'ร้านค้า');
                    $isCurrent = $shopId === $currentShopId;

                    $createdDate = (string)($shop['created_at'] ?? '');
                    $createdDate = $createdDate !== '' ? substr($createdDate, 0, 10) : '';
                    $createdDateText = $createdDate !== '' ? formatThaiDate($createdDate) : '-';
                    ?>
                    <article class="overflow-hidden rounded-2xl border p-4 shadow-sm transition-all <?= $isCurrent ? 'border-indigo-400/35 bg-indigo-500/10 shadow-indigo-900/20' : 'border-white/10 bg-white/[0.03] hover:border-white/20' ?>">
                        <div class="flex flex-nowrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-base font-semibold text-slate-100" title="<?= e($shopName) ?>"><?= e($shopName) ?></p>
                                <p class="mt-1 text-xs text-slate-500">สร้างเมื่อ <?= e($createdDateText) ?></p>
                            </div>

                            <?php if ($isCurrent): ?>
                                <span class="shrink-0 rounded-lg border border-emerald-400/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-300">กำลังใช้งาน</span>
                            <?php else: ?>
                                <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="shrink-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="switch">
                                    <input type="hidden" name="shop_id" value="<?= e((string)$shopId) ?>">
                                    <input type="hidden" name="redirect_to" value="/shops.php">
                                    <button type="submit" class="btn-teal px-3 py-1.5 text-xs">สลับมาใช้ร้านนี้</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 flex flex-wrap items-end gap-2">
                            <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="min-w-0 flex-1 grid gap-2 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="shop_id" value="<?= e((string)$shopId) ?>">
                                <input type="hidden" name="redirect_to" value="/shops.php">

                                <div class="min-w-0 overflow-hidden">
                                    <label for="shop-name-<?= e((string)$shopId) ?>" class="text-xs text-slate-400">แก้ไขชื่อร้าน</label>
                                    <input
                                        id="shop-name-<?= e((string)$shopId) ?>"
                                        name="name"
                                        type="text"
                                        maxlength="100"
                                        required
                                        value="<?= e($shopName) ?>"
                                        class="mt-1 block w-full max-w-full rounded-xl px-3 py-2 text-sm transition-all">
                                </div>

                                <button type="submit" class="btn-primary px-3 py-2 text-xs">บันทึกชื่อ</button>
                            </form>

                            <?php
                            $shopNameForConfirm = mb_strlen($shopName) > 20 ? mb_substr($shopName, 0, 20) : $shopName;
                            ?>
                            <form
                                action="<?= e(app_url('/api/shops.php')) ?>"
                                method="post"
                                data-confirm="ยืนยันการลบร้านนี้ใช่หรือไม่? ข้อมูลทั้งหมดในร้านจะถูกลบถาวร (ระบบจะให้พิมพ์ชื่อร้านยืนยันอีกครั้ง)"
                                data-confirm-typed-expected="<?= e($shopNameForConfirm) ?>"
                                data-confirm-typed-prompt="เพื่อยืนยันการลบร้าน กรุณาพิมพ์<?= mb_strlen($shopName) > 20 ? ' 20 ตัวแรกของ' : '' ?>ชื่อร้านให้ตรงตามนี้:"
                                data-confirm-typed-input="confirm_shop_name">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="shop_id" value="<?= e((string)$shopId) ?>">
                                <input type="hidden" name="redirect_to" value="/shops.php">
                                <input type="hidden" name="confirm_shop_name" value="">

                                <?php if ($canDeleteShop): ?>
                                    <button type="submit" class="btn-danger px-3 py-2 text-xs">ลบร้าน</button>
                                <?php else: ?>
                                    <button type="button" disabled class="cursor-not-allowed rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-500">ลบร้าน</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>