<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;
$currentPage = $currentPage ?? '';
$username = (string)($_SESSION['username'] ?? '');
$currentShopId = (int)($_SESSION['current_shop_id'] ?? 0);
$currentShopName = (string)($_SESSION['current_shop_name'] ?? 'ร้านค้าของฉัน');
$headerShops = [];
$canDeleteCurrentShop = false;

if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    $headerUserId = (int)$_SESSION['user_id'];
    $headerShopRepository = new ShopRepository($pdo);
    $headerShopService = new ShopService($headerShopRepository);
    $shopContext = $headerShopService->getShopContext($headerUserId, $currentShopId > 0 ? $currentShopId : null);

    $headerShops = is_array($shopContext['shops'] ?? null) ? (array)$shopContext['shops'] : [];
    $currentShop = is_array($shopContext['current_shop'] ?? null) ? (array)$shopContext['current_shop'] : null;

    if ($currentShop !== null) {
        $currentShopId = (int)($currentShop['id'] ?? 0);
        $currentShopName = (string)($currentShop['name'] ?? $currentShopName);
        $_SESSION['current_shop_id'] = $currentShopId;
        $_SESSION['current_shop_name'] = $currentShopName;
    }

    $canDeleteCurrentShop = $headerShopService->canDeleteShop($headerUserId);
    $shopCount = count($headerShops);
}

$redirectTo = (string)($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 text-slate-200">
<header class="sticky top-0 z-40 border-b border-slate-700 bg-slate-950/95 backdrop-blur">
    <div class="mx-auto flex w-full max-w-6xl flex-wrap items-start justify-between gap-3 px-4 py-3 sm:items-center">
        <a href="<?= e(app_url('/dashboard.php')) ?>" class="text-base font-semibold text-orange-400">📊 วิเคราะห์ยอดขาย</a>
        <div class="flex w-full flex-wrap items-center justify-end gap-2 text-xs sm:w-auto sm:text-sm">
            <?php if (!empty($headerShops)): ?>
                <form action="<?= e(app_url('/api/shops.php')) ?>" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="switch">
                    <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">
                    <select name="shop_id" aria-label="เลือกร้านค้า" onchange="this.form.submit()" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-slate-100 focus:border-cyan-400 focus:outline-none">
                        <?php foreach ($headerShops as $shop): ?>
                            <?php $shopId = (int)($shop['id'] ?? 0); ?>
                            <option value="<?= e((string)$shopId) ?>" <?= $shopId === $currentShopId ? 'selected' : '' ?>>
                                <?= e((string)($shop['name'] ?? 'ร้านค้า')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <span class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5">🏪 <?= e($currentShopName) ?></span>
            <?php endif; ?>

            <button type="button" id="open-shop-modal" class="rounded-lg bg-cyan-500 px-3 py-1.5 font-medium text-slate-950 transition hover:bg-cyan-400">+ ร้านใหม่</button>
            <span class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5">👤 <?= e($username) ?></span>
            <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" data-confirm="ยืนยันการออกจากระบบใช่หรือไม่?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="rounded-lg bg-slate-700 px-3 py-1.5 text-slate-100 transition hover:bg-slate-600">Logout</button>
            </form>
        </div>
    </div>
</header>

<div id="shop-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4">
    <div class="w-full max-w-md rounded-xl border border-slate-700 bg-slate-800 p-5 shadow-xl">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold">จัดการร้านค้า</h2>
            <button type="button" id="close-shop-modal" class="rounded-md px-2 py-1 text-sm text-slate-300 hover:bg-slate-700">ปิด</button>
        </div>

        <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

            <label for="new-shop-name" class="block text-sm text-slate-300">ชื่อร้านใหม่</label>
            <input id="new-shop-name" name="name" type="text" required maxlength="100" class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 focus:border-cyan-400 focus:outline-none" placeholder="เช่น ร้านเสื้อออนไลน์">

            <div class="flex justify-end gap-2">
                <button type="button" id="cancel-shop-modal" class="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-600">ยกเลิก</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400">สร้างร้าน</button>
            </div>
        </form>

        <div class="mt-5 border-t border-slate-700 pt-4">
            <h3 class="text-sm font-semibold text-slate-100">ลบร้านที่เลือกอยู่</h3>
            <p class="mt-1 text-xs text-slate-400">เมื่อลบร้าน ข้อมูลทั้งหมดในร้าน (ยอดขาย, ค่าแอด, เป้าหมาย) จะถูกลบถาวร</p>

            <form id="delete-current-shop-form" action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="shop_id" value="<?= e((string)$currentShopId) ?>">
                <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

                <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold <?= $canDeleteCurrentShop ? 'bg-red-500 text-white hover:bg-red-400' : 'cursor-not-allowed bg-slate-700 text-slate-400' ?>" <?= $canDeleteCurrentShop ? '' : 'disabled' ?>>
                    ลบร้านปัจจุบัน
                </button>
            </form>

            <?php if (!$canDeleteCurrentShop): ?>
                <p class="mt-2 text-xs text-amber-300">ต้องมีอย่างน้อย 2 ร้าน จึงจะลบร้านได้</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('shop-modal');
        const openButton = document.getElementById('open-shop-modal');
        const closeButton = document.getElementById('close-shop-modal');
        const cancelButton = document.getElementById('cancel-shop-modal');
        const deleteForm = document.getElementById('delete-current-shop-form');

        if (!modal || !openButton) {
            return;
        }

        const openModal = () => {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        };

        const closeModal = () => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };

        openButton.addEventListener('click', openModal);

        [closeButton, cancelButton].forEach((button) => {
            if (!button) {
                return;
            }

            button.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        if (deleteForm) {
            deleteForm.addEventListener('submit', (event) => {
                const accepted = window.confirm('ยืนยันการลบร้านนี้ใช่หรือไม่? ข้อมูลทั้งหมดในร้านจะถูกลบถาวร');
                if (!accepted) {
                    event.preventDefault();
                }
            });
        }
    })();
</script>

<main class="mx-auto min-h-[calc(100vh-160px)] w-full max-w-6xl px-4 py-6 pb-24">
<?php if ($flashSuccess !== null): ?>
    <div id="app-toast" class="fixed right-4 top-4 z-50 rounded-lg bg-green-500 px-4 py-2 text-sm font-medium text-white shadow-lg">
        <?= e($flashSuccess) ?>
    </div>
<?php endif; ?>
<?php if ($flashError !== null): ?>
    <div id="app-toast" class="fixed right-4 top-4 z-50 rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white shadow-lg">
        <?= e($flashError) ?>
    </div>
<?php endif; ?>
