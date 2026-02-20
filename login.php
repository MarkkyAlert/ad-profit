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
        body { font-family: 'Sarabun', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="mx-auto flex min-h-screen w-full max-w-md items-center px-4 py-8">
    <div class="w-full rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-xl">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-bold">📊 วิเคราะห์ยอดขาย</h1>
            <p class="mt-2 text-sm text-slate-400">เข้าสู่ระบบหรือสมัครสมาชิกเพื่อเริ่มใช้งาน</p>
        </div>

        <div class="mb-6 grid grid-cols-2 rounded-xl bg-slate-800 p-1 text-sm">
            <button type="button" data-tab-trigger="login" class="tab-trigger rounded-lg px-3 py-2 font-medium">เข้าสู่ระบบ</button>
            <button type="button" data-tab-trigger="register" class="tab-trigger rounded-lg px-3 py-2 font-medium">สมัครสมาชิก</button>
        </div>

        <section data-tab-panel="login" class="tab-panel space-y-4">
            <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="login">
                <div>
                    <label for="login-username" class="mb-1 block text-sm text-slate-300">ชื่อผู้ใช้</label>
                    <input id="login-username" name="username" type="text" required maxlength="50" class="w-full rounded-lg border border-slate-600 bg-slate-950 px-3 py-2 focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label for="login-password" class="mb-1 block text-sm text-slate-300">รหัสผ่าน</label>
                    <input id="login-password" name="password" type="password" required minlength="4" class="w-full rounded-lg border border-slate-600 bg-slate-950 px-3 py-2 focus:border-cyan-400 focus:outline-none">
                </div>
                <button type="submit" class="w-full rounded-lg bg-orange-500 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-orange-400">เข้าสู่ระบบ</button>
            </form>
        </section>

        <section data-tab-panel="register" class="tab-panel hidden space-y-4">
            <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <div>
                    <label for="register-username" class="mb-1 block text-sm text-slate-300">ชื่อผู้ใช้</label>
                    <input id="register-username" name="username" type="text" required maxlength="50" class="w-full rounded-lg border border-slate-600 bg-slate-950 px-3 py-2 focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label for="register-password" class="mb-1 block text-sm text-slate-300">รหัสผ่าน</label>
                    <input id="register-password" name="password" type="password" required minlength="4" class="w-full rounded-lg border border-slate-600 bg-slate-950 px-3 py-2 focus:border-cyan-400 focus:outline-none">
                </div>
                <div>
                    <label for="register-password-confirm" class="mb-1 block text-sm text-slate-300">ยืนยันรหัสผ่าน</label>
                    <input id="register-password-confirm" name="password_confirm" type="password" required minlength="4" class="w-full rounded-lg border border-slate-600 bg-slate-950 px-3 py-2 focus:border-cyan-400 focus:outline-none">
                </div>
                <button type="submit" class="w-full rounded-lg bg-cyan-500 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-400">สมัครสมาชิก</button>
            </form>
        </section>
    </div>
</div>

<?php if ($flashSuccess !== null): ?>
    <div id="app-toast" class="fixed right-4 top-4 rounded-lg bg-green-500 px-4 py-2 text-sm font-medium text-white shadow-lg">
        <?= e($flashSuccess) ?>
    </div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
    <div id="app-toast" class="fixed right-4 top-4 rounded-lg bg-red-500 px-4 py-2 text-sm font-medium text-white shadow-lg">
        <?= e($flashError) ?>
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
                trigger.classList.toggle('bg-slate-700', isActive);
                trigger.classList.toggle('text-white', isActive);
                trigger.classList.toggle('text-slate-300', !isActive);
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
