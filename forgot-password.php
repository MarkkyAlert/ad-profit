<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireGuest();

$sent = isset($_GET['sent']) && $_GET['sent'] === '1';

$pageTitle = 'ลืมรหัสผ่าน - ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Noto Sans Thai', sans-serif; background-color: rgb(8, 16, 40); }
        input {
            background-color: #070c18;
            border: 1px solid rgba(255,255,255,0.10);
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
            color: #e2e8f0;
        }
        input:focus {
            outline: none;
            border-color: rgba(99,102,241,0.65);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15), 0 0 20px rgba(99,102,241,0.06);
        }
        input::placeholder { color: #4b5870; }
        .btn-orange {
            display: block;
            width: 100%;
            padding: 0.75rem 1.5rem;
            text-align: center;
            font-weight: 600;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #f97316, #ea580c);
            box-shadow: 0 4px 14px rgba(249, 115, 22, .40);
            color: #fff;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-orange:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 30px rgba(249, 115, 22, .5), 0 0 20px rgba(249, 115, 22, 0.3);
        }
        .glass-card {
            background: rgba(13, 21, 38, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(16px);
        }

        /* ชื่อแอปแบบเดียวกับหน้าเข้าสู่ระบบ — สองหน้านี้มี <style> ของตัวเอง
           ไม่ได้ใช้ header.php จึงต้องนิยามคลาสนี้ซ้ำที่นี่ */
        .text-gradient {
            background: linear-gradient(135deg, #f97316 0%, #d946ef 55%, #6366f1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text
        }

    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 text-slate-200">
    <div class="w-full max-w-md">
        <div class="glass-card rounded-2xl p-8">
            <?php /* ⚠️ ต้องมีชื่อแอปเหมือนหน้าเข้าสู่ระบบ — คนที่มาถึงหน้านี้มักกดมาจาก
                     ลิงก์ในกล่องจดหมาย ซึ่งเป็นจังหวะที่ระวังเรื่องลิงก์ปลอมที่สุดพอดี
                     การ์ดเปล่า ๆ ที่ไม่บอกว่าเป็นเว็บอะไร ทำให้ยืนยันไม่ได้ว่ามาถูกที่ */ ?>
            <div class="mb-6 text-center">
                <div class="mb-3 inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-2xl shadow-sm">📊</div>
                <p class="text-xl font-bold"><span class="text-gradient">วิเคราะห์ยอดขาย</span></p>
            </div>

            <div class="mb-6 text-center">
                <h1 class="mb-2 text-2xl font-bold text-white">ลืมรหัสผ่าน</h1>
                <p class="text-sm text-slate-400">กรอกอีเมลที่ใช้สมัครเพื่อรับลิงก์รีเซ็ตรหัสผ่าน</p>
            </div>

            <?php if ($flashError = get_flash('error')): ?>
                <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    <?= e($flashError) ?>
                </div>
            <?php endif; ?>

            <?php if ($sent): ?>
                <div class="mb-4 rounded-xl border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-300">
                    หากอีเมลนี้มีอยู่ในระบบ คุณจะได้รับลิงก์รีเซ็ตรหัสผ่าน
                </div>
                <?php if (APP_ENV === 'development' && EXPOSE_DEV_RESET_LINK && ($resetLink = get_flash('reset_link'))): ?>
                    <div class="mb-4 rounded-xl border border-orange-500/30 bg-orange-500/10 px-4 py-3 text-sm text-orange-300">
                        <strong>🔧 Dev Mode:</strong> คลิกลิงก์ด้านล่างเพื่อรีเซ็ตรหัสผ่าน<br>
                        <a href="<?= e($resetLink) ?>" class="mt-2 inline-block text-orange-400 hover:text-orange-300 underline break-all"><?= e($resetLink) ?></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="forgot_password">
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-slate-300">อีเมล</label>
                    <input id="email" name="email" type="email" required maxlength="255"
                        class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                        placeholder="your@email.com">
                </div>
                <button type="submit" class="btn-orange mt-2">ส่งลิงก์รีเซ็ตรหัสผ่าน</button>
            </form>

            <div class="mt-6 text-center">
                <a href="<?= e(app_url('/login.php')) ?>" class="text-sm text-indigo-400 hover:text-indigo-300 transition-colors">← กลับไปหน้าเข้าสู่ระบบ</a>
            </div>
        </div>
    </div>
</body>
</html>
