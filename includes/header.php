<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;
$currentPage = $currentPage ?? '';
$username = (string)($_SESSION['username'] ?? '');
$currentShopId = (int)($_SESSION['current_shop_id'] ?? 0);
$currentShopName = (string)($_SESSION['current_shop_name'] ?? 'ร้านค้าของฉัน');
$headerShops = [];

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
            min-height: 100vh;
            position: relative;
            overflow-x: hidden
        }

        @keyframes shimmer {
            0% {
                background-position: -200% center
            }

            100% {
                background-position: 200% center
            }
        }

        @keyframes pulse-glow {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0.7
            }
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px)
            }

            50% {
                transform: translateY(-8px)
            }
        }

        @keyframes border-glow {

            0%,
            100% {
                border-color: rgba(99, 102, 241, 0.3)
            }

            50% {
                border-color: rgba(139, 92, 246, 0.5)
            }
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
            border: 1px solid rgba(99, 102, 241, 0.15);
            border-radius: 16px;
            box-shadow: rgba(1, 5, 17, 0.4) 0px 8px 32px 0px,
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1)
        }

        .section-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 16px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), transparent, rgba(139, 92, 246, 0.2));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease
        }

        .section-card:hover {
            transform: translateY(-2px);
            box-shadow: rgba(1, 5, 17, 0.5) 0px 12px 40px 0px,
                0 0 30px rgba(99, 102, 241, 0.1);
            border-color: rgba(99, 102, 241, 0.3)
        }

        .section-card:hover::before {
            opacity: 1
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            border-radius: 16px;
            background: linear-gradient(145deg, rgb(14, 28, 65) 0%, rgb(11, 23, 57) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 20px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: rgba(1, 5, 17, 0.4) 0px 8px 32px 0px,
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.03) 0%, transparent 70%);
            pointer-events: none
        }

        .stat-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: rgba(1, 5, 17, 0.5) 0px 20px 50px 0px,
                0 0 40px var(--card-glow, rgba(99, 102, 241, 0.15));
            border-color: rgba(255, 255, 255, 0.15)
        }

        .stat-card:hover::before {
            animation: shimmer 1.5s ease-in-out infinite
        }

        .s-revenue {
            --card-glow: rgba(249, 115, 22, 0.2)
        }

        .s-revenue::before {
            background: linear-gradient(90deg, #f97316, #fb923c, #fbbf24, #f97316);
            background-size: 200% 100%
        }

        .s-revenue:hover {
            border-color: rgba(249, 115, 22, 0.3)
        }

        .s-adcost {
            --card-glow: rgba(6, 182, 212, 0.2)
        }

        .s-adcost::before {
            background: linear-gradient(90deg, #06b6d4, #22d3ee, #67e8f9, #06b6d4);
            background-size: 200% 100%
        }

        .s-adcost:hover {
            border-color: rgba(6, 182, 212, 0.3)
        }

        .s-profit {
            --card-glow: rgba(16, 185, 129, 0.2)
        }

        .s-profit::before {
            background: linear-gradient(90deg, #10b981, #34d399, #6ee7b7, #10b981);
            background-size: 200% 100%
        }

        .s-profit:hover {
            border-color: rgba(16, 185, 129, 0.3)
        }

        .s-roas {
            --card-glow: rgba(139, 92, 246, 0.2)
        }

        .s-roas::before {
            background: linear-gradient(90deg, #8b5cf6, #a78bfa, #c4b5fd, #8b5cf6);
            background-size: 200% 100%
        }

        .s-roas:hover {
            border-color: rgba(139, 92, 246, 0.3)
        }

        .s-neutral {
            --card-glow: rgba(99, 102, 241, 0.2)
        }

        .s-neutral::before {
            background: linear-gradient(90deg, #6366f1, #818cf8, #a5b4fc, #6366f1);
            background-size: 200% 100%
        }

        .s-neutral:hover {
            border-color: rgba(99, 102, 241, 0.3)
        }

        .s-best {
            --card-glow: rgba(34, 197, 94, 0.2)
        }

        .s-best::before {
            background: linear-gradient(90deg, #22c55e, #4ade80, #86efac, #22c55e);
            background-size: 200% 100%
        }

        .s-best:hover {
            border-color: rgba(34, 197, 94, 0.3)
        }

        .s-worst {
            --card-glow: rgba(239, 68, 68, 0.2)
        }

        .s-worst::before {
            background: linear-gradient(90deg, #ef4444, #f87171, #fca5a5, #ef4444);
            background-size: 200% 100%
        }

        .s-worst:hover {
            border-color: rgba(239, 68, 68, 0.3)
        }

        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            box-shadow: 0 4px 14px rgba(99, 102, 241, .40);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 30%, rgba(255, 255, 255, 0.2) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.5s ease
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 30px rgba(99, 102, 241, .5), 0 0 20px rgba(99, 102, 241, 0.3)
        }

        .btn-primary:hover::before {
            transform: translateX(100%)
        }

        .btn-orange {
            background: linear-gradient(135deg, #f97316, #ea580c);
            box-shadow: 0 4px 14px rgba(249, 115, 22, .40);
            color: #fff;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden
        }

        .btn-orange::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 30%, rgba(255, 255, 255, 0.2) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.5s ease
        }

        .btn-orange:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 30px rgba(249, 115, 22, .5), 0 0 20px rgba(249, 115, 22, 0.3)
        }

        .btn-orange:hover::before {
            transform: translateX(100%)
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
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.10);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            color: #94a3b8;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-ghost:hover {
            background: rgba(99, 102, 241, 0.15);
            color: #e2e8f0;
            border-color: rgba(99, 102, 241, 0.4);
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.2), 0 0 15px rgba(99, 102, 241, 0.1);
            transform: translateY(-2px)
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
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1)
        }

        tbody tr:hover {
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.05), transparent);
            transform: scale(1.005)
        }

        /* Glowing number effects */
        .glow-orange {
            text-shadow: 0 0 20px rgba(249, 115, 22, 0.5), 0 0 40px rgba(249, 115, 22, 0.2)
        }

        .glow-cyan {
            text-shadow: 0 0 20px rgba(6, 182, 212, 0.5), 0 0 40px rgba(6, 182, 212, 0.2)
        }

        .glow-green {
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.5), 0 0 40px rgba(16, 185, 129, 0.2)
        }

        .glow-purple {
            text-shadow: 0 0 20px rgba(139, 92, 246, 0.5), 0 0 40px rgba(139, 92, 246, 0.2)
        }

        .glow-red {
            text-shadow: 0 0 20px rgba(239, 68, 68, 0.5), 0 0 40px rgba(239, 68, 68, 0.2)
        }

        .nav-active {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.2), rgba(139, 92, 246, 0.15));
            border: 1px solid rgba(99, 102, 241, 0.40);
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            color: #a5b4fc !important;
            font-weight: bold;
            transform: translateY(-2px);
            animation: pulse-glow 2s ease-in-out infinite
        }

        .modal-bg {
            background: rgba(3, 6, 14, .75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px)
        }

        .progress-orange {
            background: linear-gradient(90deg, #f97316, #fbbf24, #f97316);
            background-size: 200% 100%;
            box-shadow: 0 0 15px rgba(249, 115, 22, .50);
            animation: shimmer 2s ease-in-out infinite;
            border-radius: 999px
        }

        .progress-green {
            background: linear-gradient(90deg, #10b981, #34d399, #10b981);
            background-size: 200% 100%;
            box-shadow: 0 0 15px rgba(16, 185, 129, .50);
            animation: shimmer 2s ease-in-out infinite;
            border-radius: 999px
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

    <header class="sticky top-0 z-40 border-b border-white/[0.07] bg-[rgb(8,16,40)]/95 backdrop-blur-xl">
        <div class="mx-auto w-full max-w-6xl px-3 py-2.5 sm:px-4 sm:py-3">
            <div class="flex items-center justify-between gap-2">
                <a href="<?= e(app_url('/dashboard.php')) ?>" class="flex items-center gap-1.5 shrink-0">
                    <span class="text-lg sm:text-xl">📊</span>
                    <span class="text-gradient text-sm sm:text-lg font-bold tracking-tight">วิเคราะห์ยอดขาย</span>
                </a>
                <div class="flex items-center gap-1.5 sm:gap-2 text-xs">
                    <span class="hidden sm:inline-block rounded-xl border border-white/10 bg-white/5 px-2.5 py-1 text-xs text-slate-400 shadow-sm">👤 <?= e($username) ?></span>
                    <?php if ($currentPage === 'shops'): ?>
                        <a href="<?= e(app_url('/dashboard.php')) ?>" class="btn-ghost px-2.5 py-1.5 text-xs" data-loading-link="true">← กลับ</a>
                    <?php else: ?>
                        <a href="<?= e(app_url('/shops.php')) ?>" class="btn-primary px-2.5 py-1.5 text-xs" data-loading-link="true">🏪 จัดการร้าน</a>
                    <?php endif; ?>
                    <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" data-confirm="ยืนยันการออกจากระบบใช่หรือไม่?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn-ghost px-2.5 py-1.5 text-xs">ออกจากระบบ</button>
                    </form>
                </div>
            </div>
            <?php if (!empty($headerShops)): ?>
                <form action="<?= e(app_url('/api/shops.php')) ?>" method="post" data-no-loading class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="switch">
                    <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">
                    <select name="shop_id" aria-label="เลือกร้านค้า" onchange="this.form.submit()" class="w-full rounded-xl border border-white/10 bg-[#070c18] px-3 py-2 text-sm text-slate-300 font-medium shadow-sm hover:border-indigo-500/50 transition-colors">
                        <?php foreach ($headerShops as $shop): ?>
                            <?php $shopId = (int)($shop['id'] ?? 0); ?>
                            <option value="<?= e((string)$shopId) ?>" <?= $shopId === $currentShopId ? 'selected' : '' ?>>
                                🏪 <?= e((string)($shop['name'] ?? 'ร้านค้า')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <div class="mt-2">
                    <span class="inline-block w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-slate-300 shadow-sm">🏪 <?= e($currentShopName) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="mx-auto min-h-[calc(100vh-160px)] w-full max-w-6xl px-3 py-4 pb-24 sm:px-4 sm:py-6">
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