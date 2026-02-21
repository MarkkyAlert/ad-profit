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
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: rgb(8, 16, 40);
            min-height: 100vh
        }

        .glass {
            background: rgba(13, 21, 38, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px)
        }

        .text-gradient {
            background: linear-gradient(135deg, #f97316 0%, #d946ef 55%, #6366f1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text
        }

        .section-card {
            background-color: rgb(11, 23, 57);
            border: 1px solid #1e293b;
            border-radius: 14px;
            box-shadow: rgba(1, 5, 17, 0.3) 0px 8px 28px 0px
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
            background-color: rgb(11, 23, 57);
            border: 1px solid #1e293b;
            padding: 20px;
            transition: transform .2s, box-shadow .2s;
            box-shadow: rgba(1, 5, 17, 0.3) 0px 8px 28px 0px
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.35)
        }

        .s-revenue {
            background-color: rgb(11, 23, 57)
        }

        .s-revenue::before {
            background: linear-gradient(90deg, #f97316, #fb923c, #fbbf24)
        }

        .s-adcost {
            background-color: rgb(11, 23, 57)
        }

        .s-adcost::before {
            background: linear-gradient(90deg, #06b6d4, #22d3ee, #67e8f9)
        }

        .s-profit {
            background-color: rgb(11, 23, 57)
        }

        .s-profit::before {
            background: linear-gradient(90deg, #10b981, #34d399, #6ee7b7)
        }

        .s-roas {
            background-color: rgb(11, 23, 57)
        }

        .s-roas::before {
            background: linear-gradient(90deg, #8b5cf6, #a78bfa, #c4b5fd)
        }

        .s-neutral {
            background-color: rgb(11, 23, 57)
        }

        .s-neutral::before {
            background: linear-gradient(90deg, #6366f1, #818cf8, #a5b4fc)
        }

        .s-best {
            background-color: rgb(11, 23, 57)
        }

        .s-best::before {
            background: linear-gradient(90deg, #22c55e, #4ade80)
        }

        .s-worst {
            background-color: rgb(11, 23, 57)
        }

        .s-worst::before {
            background: linear-gradient(90deg, #ef4444, #f87171)
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            box-shadow: 0 4px 14px rgba(99, 102, 241, .40);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(99, 102, 241, .55)
        }

        .btn-orange {
            background: linear-gradient(135deg, #f97316, #ea580c);
            box-shadow: 0 4px 14px rgba(249, 115, 22, .40);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-orange:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(249, 115, 22, .55)
        }

        .btn-teal {
            background: linear-gradient(135deg, #06b6d4, #0284c7);
            box-shadow: 0 4px 14px rgba(6, 182, 212, .40);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-teal:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(6, 182, 212, .55)
        }

        .btn-ghost {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            color: #94a3b8;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all .15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.10);
            color: #e2e8f0;
            border-color: rgba(255, 255, 255, 0.18);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35);
            transform: translateY(-1px)
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #b91c1c);
            box-shadow: 0 4px 14px rgba(239, 68, 68, .40);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: transform .15s, box-shadow .15s;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(239, 68, 68, .55)
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(99, 102, 241, .65) !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .15), 0 0 20px rgba(99, 102, 241, .06) !important;
            outline: none !important
        }

        input,
        select,
        textarea {
            transition: border-color .2s, box-shadow .2s;
            background-color: #070c18;
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            color: #e2e8f0
        }

        input::placeholder,
        textarea::placeholder {
            color: #4b5870
        }

        tbody tr {
            transition: background .12s
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.03)
        }

        .nav-active {
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.30);
            box-shadow: 0 0 14px rgba(99, 102, 241, 0.20);
            color: #818cf8 !important;
            font-weight: bold;
            transform: translateY(-2px)
        }

        .modal-bg {
            background: rgba(3, 6, 14, .75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px)
        }

        .progress-orange {
            background: linear-gradient(90deg, #f97316, #fbbf24);
            box-shadow: 0 0 10px rgba(249, 115, 22, .40)
        }

        .progress-green {
            background: linear-gradient(90deg, #10b981, #34d399);
            box-shadow: 0 0 10px rgba(16, 185, 129, .40)
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px)
            }

            to {
                opacity: 1;
                transform: translateX(0)
            }
        }

        .toast-anim {
            animation: slideInRight .3s ease forwards
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px
        }

        ::-webkit-scrollbar-track {
            background: transparent
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 3px
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.22)
        }
    </style>
</head>

<body class="text-slate-200">

    <header class="sticky top-0 z-40 border-b border-white/[0.07] bg-[#0d1526]/90 backdrop-blur-md">
        <div class="mx-auto flex w-full max-w-6xl flex-wrap items-start justify-between gap-3 px-4 py-3 sm:items-center">
            <a href="<?= e(app_url('/dashboard.php')) ?>" class="flex items-center gap-2">
                <span class="text-xl">📊</span>
                <span class="text-gradient text-lg font-bold tracking-tight">วิเคราะห์ยอดขาย</span>
            </a>
            <div class="flex w-full flex-wrap items-center justify-end gap-2 text-xs sm:w-auto sm:text-sm">
                <?php if (!empty($headerShops)): ?>
                    <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" data-no-loading>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="switch">
                        <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">
                        <select name="shop_id" aria-label="เลือกร้านค้า" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-[#070c18] px-3 py-1.5 text-sm text-slate-300 font-medium shadow-sm hover:border-indigo-500/50 transition-colors">
                            <?php foreach ($headerShops as $shop): ?>
                                <?php $shopId = (int)($shop['id'] ?? 0); ?>
                                <option value="<?= e((string)$shopId) ?>" <?= $shopId === $currentShopId ? 'selected' : '' ?>>
                                    🏪 <?= e((string)($shop['name'] ?? 'ร้านค้า')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <span class="rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-sm font-medium text-slate-300 shadow-sm">🏪 <?= e($currentShopName) ?></span>
                <?php endif; ?>

                <button type="button" id="open-shop-modal" class="btn-primary px-3 py-1.5 text-sm">+ ร้านใหม่</button>
                <span class="rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-sm text-slate-400 shadow-sm">👤 <?= e($username) ?></span>
                <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" data-confirm="ยืนยันการออกจากระบบใช่หรือไม่?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn-ghost px-3 py-1.5 text-sm">ออกจากระบบ</button>
                </form>
            </div>
        </div>
    </header>

    <div id="shop-modal" class="modal-bg fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div class="section-card w-full max-w-md p-6 shadow-2xl shadow-black/50">
            <div class="mb-5 flex items-center justify-between">
                <h2 class="text-lg font-bold text-slate-100">🏪 จัดการร้านค้า</h2>
                <button type="button" id="close-shop-modal" class="btn-ghost rounded-lg px-3 py-1 text-sm">ปิด ✕</button>
            </div>

            <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

                <div>
                    <label for="new-shop-name" class="mb-1.5 block text-sm font-medium text-slate-300">ชื่อร้านใหม่</label>
                    <input id="new-shop-name" name="name" type="text" required maxlength="100"
                        class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                        placeholder="เช่น ร้านเสื้อออนไลน์">
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" id="cancel-shop-modal" class="btn-ghost px-4 py-2 text-sm">ยกเลิก</button>
                    <button type="submit" class="btn-primary px-4 py-2 text-sm">✨ สร้างร้าน</button>
                </div>
            </form>

            <div class="mt-5 border-t border-white/10 pt-5">
                <h3 class="text-sm font-semibold text-slate-200">⚠️ ลบร้านที่เลือกอยู่</h3>
                <p class="mt-1 text-xs text-slate-400">ข้อมูลทั้งหมดในร้าน (ยอดขาย, ค่าแอด, เป้าหมาย) จะถูกลบถาวร</p>

                <form id="delete-current-shop-form" action="<?= e(app_url('/api/shops.php')) ?>" method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="shop_id" value="<?= e((string)$currentShopId) ?>">
                    <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

                    <?php if ($canDeleteCurrentShop): ?>
                        <button type="submit" class="btn-danger px-4 py-2 text-sm">🗑️ ลบร้านปัจจุบัน</button>
                    <?php else: ?>
                        <button type="button" disabled class="cursor-not-allowed rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-500">🗑️ ลบร้านปัจจุบัน</button>
                    <?php endif; ?>
                </form>

                <?php if (!$canDeleteCurrentShop): ?>
                    <p class="mt-2 text-xs text-amber-400 font-medium">⚠ ต้องมีอย่างน้อย 2 ร้าน จึงจะลบร้านได้</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        (function() {
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
            <div id="app-toast" class="toast-anim fixed right-4 top-4 z-50 flex items-center gap-2 rounded-2xl border border-green-500/30 bg-[#071510] px-4 py-3 text-sm font-medium text-green-400 shadow-xl shadow-black/50 backdrop-blur-md">
                <span>✅</span><?= e($flashSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError !== null): ?>
            <div id="app-toast" class="toast-anim fixed right-4 top-4 z-50 flex items-center gap-2 rounded-2xl border border-red-500/30 bg-[#140808] px-4 py-3 text-sm font-medium text-red-400 shadow-xl shadow-black/50 backdrop-blur-md">
                <span>❌</span><?= e($flashError) ?>
            </div>
        <?php endif; ?>