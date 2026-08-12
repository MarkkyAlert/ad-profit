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

    <?php /* ⭐ สีปุ่มมาจากไฟล์เดียวกันทั้งระบบ — ห้ามเขียนสีปุ่มเองในหน้าไหน
             เคยพลาดมาแล้ว: แก้คอนทราสต์ที่ header.php ที่เดียว แต่หน้ากลุ่มบัญชี
             นิยาม .btn-orange ซ้ำไว้เอง ปุ่ม "เข้าสู่ระบบ" จึงยังตกเกณฑ์อยู่ */ ?>
    <?php require __DIR__ . '/includes/brand-colors.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif
        }

        @keyframes gradientShift {

            0%,
            100% {
                background-position: 0% 50%
            }

            50% {
                background-position: 100% 50%
            }
        }

        .bg-animated {
            background-color: rgb(8, 16, 40);
            position: relative;
            overflow: hidden
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px)
            }

            50% {
                transform: translateY(-10px)
            }
        }

        @keyframes shimmer {
            0% {
                background-position: -200% center
            }

            100% {
                background-position: 200% center
            }
        }

        .glass-card {
            background: linear-gradient(145deg, rgba(14, 28, 65, 0.95) 0%, rgba(11, 23, 57, 0.9) 100%);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 20px;
            box-shadow: rgba(1, 5, 17, 0.5) 0px 20px 60px 0px,
                0 0 40px rgba(99, 102, 241, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            position: relative;
            z-index: 1;
            animation: float 6s ease-in-out infinite
        }

        .glass-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 20px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.3), transparent 50%, rgba(139, 92, 246, 0.3));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none
        }

        .text-gradient {
            background: linear-gradient(135deg, #f97316 0%, #d946ef 55%, #6366f1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text
        }

        .btn-orange {
            background: linear-gradient(135deg, var(--btn-orange-from), var(--btn-orange-to));
            box-shadow: 0 6px 18px rgba(249, 115, 22, .40);
            color: #fff;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            padding: 14px;
            font-size: 1rem;
            position: relative;
            overflow: hidden
        }

        .btn-orange::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 30%, rgba(255, 255, 255, 0.25) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.5s ease
        }

        .btn-orange:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 35px rgba(249, 115, 22, .5), 0 0 25px rgba(249, 115, 22, 0.3)
        }

        .btn-orange:hover::before {
            transform: translateX(100%)
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--btn-primary-from), var(--btn-primary-to));
            box-shadow: 0 6px 18px rgba(99, 102, 241, .40);
            color: #fff;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all .25s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            padding: 14px;
            font-size: 1rem;
            position: relative;
            overflow: hidden
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, transparent 30%, rgba(255, 255, 255, 0.25) 50%, transparent 70%);
            transform: translateX(-100%);
            transition: transform 0.5s ease
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 35px rgba(99, 102, 241, .5), 0 0 25px rgba(99, 102, 241, 0.3)
        }

        .btn-primary:hover::before {
            transform: translateX(100%)
        }

        input {
            transition: border-color .2s, box-shadow .2s;
            background-color: #070c18;
            border: 1px solid rgba(255, 255, 255, 0.10);
            color: #e2e8f0
        }

        input:focus {
            border-color: rgba(99, 102, 241, .65) !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .15) !important;
            outline: none !important
        }

        input::placeholder {
            color: #4b5870
        }

        .tab-active {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.25), rgba(139, 92, 246, 0.2));
            color: #a5b4fc;
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.40)
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
            width: 6px
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 3px
        }
    </style>
</head>

<body class="bg-animated min-h-screen text-slate-200">
    <!-- Floating ambient orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <div class="flex min-h-screen w-full items-center justify-center px-4 py-10 relative z-10">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <div class="mb-3 inline-flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-3xl shadow-sm">📊</div>
                <h1 class="text-3xl font-bold"><span class="text-gradient">วิเคราะห์ยอดขาย</span></h1>
                <p class="mt-2 text-sm text-slate-400">ระบบติดตามรายได้ &amp; ค่าแอดแบบหลายร้าน</p>
            </div>

            <div class="glass-card p-7 shadow-2xl shadow-indigo-900/5">
                <div class="mb-6 grid grid-cols-2 gap-1 rounded-xl border border-white/10 bg-white/5 p-1 text-sm">
                    <?php /* ⚠️ ปุ่มสองอันนี้ทำหน้าที่เป็นแท็บ แต่เดิมบอก "กำลังเลือกอันไหน" ด้วยสีอย่างเดียว
                             คนที่ใช้โปรแกรมอ่านหน้าจอจะได้ยินแค่ "ปุ่ม เข้าสู่ระบบ" กับ "ปุ่ม สมัครสมาชิก"
                             โดยไม่รู้ว่าตอนนี้ฟอร์มที่เห็นอยู่เป็นของอันไหน — ซึ่งเป็นหน้าจอแรกสุดที่ผู้ใช้เจอ
                             `aria-selected` ถูกอัปเดตโดยสคริปต์ทุกครั้งที่สลับแท็บ */ ?>
                    <button type="button" role="tab" aria-selected="false" data-tab-trigger="login" class="tab-trigger rounded-lg px-3 py-2.5 font-semibold transition-all duration-150 text-slate-400">เข้าสู่ระบบ</button>
                    <button type="button" role="tab" aria-selected="false" data-tab-trigger="register" class="tab-trigger rounded-lg px-3 py-2.5 font-semibold transition-all duration-150 text-slate-400">สมัครสมาชิก</button>
                </div>

                <section data-tab-panel="login" class="tab-panel space-y-4">
                    <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="login">
                        <div>
                            <label for="login-email" class="mb-1.5 block text-sm font-medium text-slate-300">อีเมล</label>
                            <input id="login-email" name="email" type="email" autocomplete="username email" required maxlength="255"
                                class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                                placeholder="your@email.com">
                        </div>
                        <div>
                            <label for="login-password" class="mb-1.5 block text-sm font-medium text-slate-300">รหัสผ่าน</label>
                            <input id="login-password" name="password" type="password" autocomplete="current-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                                placeholder="••••••••">
                        </div>
                        <button type="submit" class="btn-orange mt-2">เข้าสู่ระบบ →</button>
                        <div class="mt-3 text-center">
                            <a href="<?= e(app_url('/forgot-password.php')) ?>" class="text-sm text-indigo-400 hover:text-indigo-300 transition-colors">ลืมรหัสผ่าน?</a>
                        </div>
                    </form>
                </section>

                <section data-tab-panel="register" class="tab-panel hidden space-y-4">
                    <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">
                        <div>
                            <label for="register-email" class="mb-1.5 block text-sm font-medium text-slate-300">อีเมล</label>
                            <input id="register-email" name="email" type="email" autocomplete="username email" required maxlength="255"
                                class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                                placeholder="your@email.com">
                        </div>
                        <div>
                            <label for="register-password" class="mb-1.5 block text-sm font-medium text-slate-300">รหัสผ่าน</label>
                            <input id="register-password" name="password" type="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                aria-describedby="register-password-rule"
                                class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                                placeholder="••••••••">
                            <?php /* เกณฑ์ต้องอยู่ถาวร — placeholder หายทันทีที่เริ่มพิมพ์ */ ?>
                            <p id="register-password-rule" class="mt-1.5 text-xs text-slate-400">ต้องยาวอย่างน้อย <?= PASSWORD_MIN_LENGTH ?> ตัวอักษร</p>
                        </div>
                        <div>
                            <label for="register-password-confirm" class="mb-1.5 block text-sm font-medium text-slate-300">ยืนยันรหัสผ่าน</label>
                            <input id="register-password-confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                                class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                                placeholder="••••••••">
                        </div>
                        <?php /* ส้ม = ปุ่มหลักของทั้งระบบ · เดิมแท็บนี้เป็นม่วงขณะที่แท็บ "เข้าสู่ระบบ"
                                 ในการ์ดเดียวกันเป็นส้ม กดสลับแท็บแล้วปุ่มเปลี่ยนสี */ ?>
                        <button type="submit" class="btn-orange mt-2">✨ สมัครสมาชิก</button>
                    </form>
                </section>
            </div>

            <p class="mt-6 text-center text-xs text-slate-400">รองรับหลายร้านค้า · Export CSV · ROAS Dashboard</p>
        </div>
    </div>

    <?php /* ⚠️⚠️ หน้านี้ไม่ได้ใช้ header/footer ร่วม จึงเคยมีแถบแจ้งผลคนละแบบกับทั้งระบบ:
             ไม่มี role ให้โปรแกรมอ่านหน้าจอประกาศ · แตะปิดไม่ได้ · หายใน 3 วินาที
             ทั้งที่หน้าอื่นให้ข้อความผิดพลาดอยู่ 10 วินาที
             · หน้านี้คือที่ที่ข้อความผิดพลาดสำคัญที่สุด ("อีเมลหรือรหัสผ่านไม่ถูกต้อง",
               "ลองเข้าสู่ระบบบ่อยเกินไป กรุณารอ 1 นาที") — คนที่พลาดข้อความจะกดซ้ำแล้ว
               ไปชนตัวจำกัดจำนวนครั้งพอดี
             · และทั้งสองแถบเคยใช้ id เดียวกัน วางทับกัน (เหตุผลเต็มอยู่ใน header.php) */ ?>
    <?php if ($flashSuccess !== null || $flashError !== null): ?>
    <div class="fixed right-4 top-4 z-50 flex max-w-[calc(100vw-2rem)] flex-col items-end gap-2">
        <?php if ($flashSuccess !== null): ?>
            <div data-app-toast data-toast-kind="success" role="status" tabindex="0" title="แตะเพื่อปิด" class="toast-anim flex cursor-pointer items-center gap-2 rounded-2xl border border-green-500/30 bg-[#071510] px-4 py-3 text-sm font-medium text-green-400 shadow-xl shadow-black/50 backdrop-blur-md">
                <span>✅</span><?= e($flashSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError !== null): ?>
            <div data-app-toast data-toast-kind="error" role="alert" tabindex="0" title="แตะเพื่อปิด" class="toast-anim flex cursor-pointer items-center gap-2 rounded-2xl border border-red-500/30 bg-[#140808] px-4 py-3 text-sm font-medium text-red-400 shadow-xl shadow-black/50 backdrop-blur-md">
                <span>❌</span><?= e($flashError) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <script>
        (function() {
            const activeTab = <?= json_encode($activeTab, JSON_UNESCAPED_UNICODE) ?>;
            const triggers = document.querySelectorAll('[data-tab-trigger]');
            const panels = document.querySelectorAll('[data-tab-panel]');

            function render(tab) {
                triggers.forEach((trigger) => {
                    const isActive = trigger.dataset.tabTrigger === tab;
                    // ⚠️ ต้องอัปเดตด้วย ไม่งั้นโปรแกรมอ่านหน้าจอจะบอกแท็บที่เลือกอยู่ผิด
                    trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    trigger.classList.toggle('tab-active', isActive);
                    trigger.classList.toggle('text-indigo-300', isActive);
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

            /* ⚠️ ใช้กติกาเดียวกับทั้งระบบ (footer.php) — ผิดพลาดอยู่ 10 วินาที · สำเร็จ 6 วินาที ·
               แตะเพื่อปิดได้ · ข้อความผิดพลาดดึงโฟกัสมาให้ · และจัดการ **ทุกแถบ** ไม่ใช่ตัวแรก */
            document.querySelectorAll('[data-app-toast]').forEach((toast) => {
                const dismissToast = () => {
                    if (!toast.isConnected) {
                        return;
                    }

                    toast.classList.add('opacity-0', 'transition', 'duration-300');
                    setTimeout(() => toast.remove(), 300);
                };

                const isError = toast.getAttribute('data-toast-kind') === 'error';
                setTimeout(dismissToast, isError ? 10000 : 6000);
                toast.addEventListener('click', dismissToast);

                if (isError) {
                    toast.focus({ preventScroll: true });
                }
            });
        })();
    </script>

    <?php require __DIR__ . '/includes/auth-form-guard.php'; ?>
</body>

</html>