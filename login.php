<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireGuest();

$activeTab = (string)($_GET['tab'] ?? 'login');
if (!in_array($activeTab, ['login', 'register'], true)) {
    $activeTab = 'login';
}

$flashSuccess = get_flash('success');
$flashError = get_flash('error');
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> - เข้าสู่ระบบ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body{font-family:'Sarabun',sans-serif}
        @keyframes gradientShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
        .bg-animated{background:linear-gradient(-45deg,#07071a,#0e0b2e,#07071a,#0a0f1e,#12071a);background-size:400% 400%;animation:gradientShift 12s ease infinite}
        .glass-card{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.10);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-radius:24px}
        .text-gradient{background:linear-gradient(135deg,#f97316 0%,#e879f9 55%,#818cf8 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .btn-orange{background:linear-gradient(135deg,#f97316,#dc2626);box-shadow:0 4px 20px rgba(249,115,22,.40);color:#fff;font-weight:700;border:none;border-radius:12px;cursor:pointer;transition:transform .15s,box-shadow .15s;width:100%;padding:12px;font-size:1rem}
        .btn-orange:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(249,115,22,.55)}
        .btn-primary{background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 4px 20px rgba(99,102,241,.40);color:#fff;font-weight:700;border:none;border-radius:12px;cursor:pointer;transition:transform .15s,box-shadow .15s;width:100%;padding:12px;font-size:1rem}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(99,102,241,.55)}
        input{transition:border-color .2s,box-shadow .2s}
        input:focus{border-color:rgba(99,102,241,.65)!important;box-shadow:0 0 0 3px rgba(99,102,241,.18)!important;outline:none!important}
        .tab-active{background:rgba(255,255,255,0.10);color:#fff;box-shadow:0 2px 12px rgba(0,0,0,0.3)}
        @keyframes slideInRight{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
        .toast-anim{animation:slideInRight .3s ease forwards}
        ::-webkit-scrollbar{width:5px}
        ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.14);border-radius:3px}
    </style>
</head>
<body class="bg-animated min-h-screen text-slate-100">

<div class="flex min-h-screen w-full items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <div class="mb-3 inline-flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.06] text-3xl shadow-xl">📊</div>
            <h1 class="text-3xl font-bold"><span class="text-gradient">วิเคราะห์ยอดขาย</span></h1>
            <p class="mt-2 text-sm text-slate-400">ระบบติดตามรายได้ &amp; ค่าแอดแบบหลายร้าน</p>
        </div>

        <div class="glass-card p-7 shadow-2xl shadow-black/50">
            <div class="mb-6 grid grid-cols-2 rounded-xl border border-white/[0.07] bg-white/[0.04] p-1 text-sm">
                <button type="button" data-tab-trigger="login" class="tab-trigger rounded-lg px-3 py-2.5 font-semibold transition-all duration-150">เข้าสู่ระบบ</button>
                <button type="button" data-tab-trigger="register" class="tab-trigger rounded-lg px-3 py-2.5 font-semibold transition-all duration-150">สมัครสมาชิก</button>
            </div>

            <section data-tab-panel="login" class="tab-panel space-y-4">
                <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label for="login-username" class="mb-1.5 block text-sm font-medium text-slate-300">ชื่อผู้ใช้</label>
                        <input id="login-username" name="username" type="text" required maxlength="50"
                            class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm placeholder-slate-500"
                            placeholder="กรอกชื่อผู้ใช้">
                    </div>
                    <div>
                        <label for="login-password" class="mb-1.5 block text-sm font-medium text-slate-300">รหัสผ่าน</label>
                        <input id="login-password" name="password" type="password" required minlength="4"
                            class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm placeholder-slate-500"
                            placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn-orange mt-2">เข้าสู่ระบบ →</button>
                </form>
            </section>

            <section data-tab-panel="register" class="tab-panel hidden space-y-4">
                <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="register">
                    <div>
                        <label for="register-username" class="mb-1.5 block text-sm font-medium text-slate-300">ชื่อผู้ใช้</label>
                        <input id="register-username" name="username" type="text" required maxlength="50"
                            class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm placeholder-slate-500"
                            placeholder="ตั้งชื่อผู้ใช้">
                    </div>
                    <div>
                        <label for="register-password" class="mb-1.5 block text-sm font-medium text-slate-300">รหัสผ่าน</label>
                        <input id="register-password" name="password" type="password" required minlength="4"
                            class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm placeholder-slate-500"
                            placeholder="อย่างน้อย 4 ตัวอักษร">
                    </div>
                    <div>
                        <label for="register-password-confirm" class="mb-1.5 block text-sm font-medium text-slate-300">ยืนยันรหัสผ่าน</label>
                        <input id="register-password-confirm" name="password_confirm" type="password" required minlength="4"
                            class="w-full rounded-xl border border-white/10 bg-white/[0.06] px-4 py-3 text-sm placeholder-slate-500"
                            placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn-primary mt-2">✨ สมัครสมาชิก</button>
                </form>
            </section>
        </div>

        <p class="mt-6 text-center text-xs text-slate-500">รองรับหลายร้านค้า · Export CSV · ROAS Dashboard</p>
    </div>
</div>

<?php if ($flashSuccess !== null): ?>
    <div id="app-toast" class="toast-anim fixed right-4 top-4 z-50 flex items-center gap-2 rounded-2xl border border-green-500/30 bg-green-500/15 px-4 py-3 text-sm font-medium text-green-300 shadow-xl backdrop-blur-md">
        <span>✅</span><?= e($flashSuccess) ?>
    </div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
    <div id="app-toast" class="toast-anim fixed right-4 top-4 z-50 flex items-center gap-2 rounded-2xl border border-red-500/30 bg-red-500/15 px-4 py-3 text-sm font-medium text-red-300 shadow-xl backdrop-blur-md">
        <span>❌</span><?= e($flashError) ?>
    </div>
<?php endif; ?>

<script>
    (function () {
        const activeTab = <?= json_encode($activeTab, JSON_UNESCAPED_UNICODE) ?>;
        const triggers = document.querySelectorAll('[data-tab-trigger]');
        const panels = document.querySelectorAll('[data-tab-panel]');

        function render(tab) {
            triggers.forEach((trigger) => {
                const isActive = trigger.dataset.tabTrigger === tab;
                trigger.classList.toggle('tab-active', isActive);
                trigger.classList.toggle('text-white', isActive);
                trigger.classList.toggle('text-slate-400', !isActive);
            });

            panels.forEach((panel) => {
                const isActive = panel.dataset.tabPanel === tab;
                panel.classList.toggle('hidden', !isActive);
            });
        }

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => render(trigger.dataset.tabTrigger));
        });

        render(activeTab);

        const toast = document.getElementById('app-toast');
        if (toast) {
            setTimeout(() => {
                toast.classList.add('opacity-0', 'transition', 'duration-300');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    })();
</script>
</body>
</html>
