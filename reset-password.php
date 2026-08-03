<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// รับ token ก่อน requireGuest() เสมอ
// ผู้ที่ยังล็อกอินค้างในเบราว์เซอร์แล้วกดลิงก์จากอีเมล เดิมจะถูกเด้งไป /dashboard.php
// ตั้งแต่บรรทัดถัดไป โดยที่ token ถูกทิ้งเงียบ ๆ และไม่มีข้อความบอกว่าเกิดอะไรขึ้น
$tokenFromQuery = trim((string)($_GET['token'] ?? ''));
if ($tokenFromQuery !== '') {
    // ออกจากระบบให้อัตโนมัติ — คนที่มาถึงหน้านี้ตั้งใจจะตั้งรหัสผ่านใหม่
    // (ล้าง session ทั้งก้อน + เปลี่ยน session id ผ่าน clearAuthSession)
    if (isset($_SESSION['user_id'])) {
        clearAuthSession();
    }

    $_SESSION['password_reset_token'] = $tokenFromQuery;
    redirect('/reset-password.php');
}

requireGuest();

$token = trim((string)($_SESSION['password_reset_token'] ?? ''));

if ($token === '') {
    set_flash('error', 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง');
    redirect('/forgot-password.php');
}

$pageTitle = 'รีเซ็ตรหัสผ่าน - ' . APP_NAME;
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
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 text-slate-200">
    <div class="w-full max-w-md">
        <div class="glass-card rounded-2xl p-8">
            <div class="mb-6 text-center">
                <h1 class="mb-2 text-2xl font-bold text-white">รีเซ็ตรหัสผ่าน</h1>
                <p class="text-sm text-slate-400">กรอกรหัสผ่านใหม่ของคุณ</p>
            </div>

            <?php if ($flashError = get_flash('error')): ?>
                <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    <?= e($flashError) ?>
                </div>
            <?php endif; ?>

            <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-slate-300">รหัสผ่านใหม่</label>
                    <input id="password" name="password" type="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                        class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                        placeholder="อย่างน้อย <?= PASSWORD_MIN_LENGTH ?> ตัวอักษร">
                </div>
                <div>
                    <label for="password_confirm" class="mb-1.5 block text-sm font-medium text-slate-300">ยืนยันรหัสผ่านใหม่</label>
                    <input id="password_confirm" name="password_confirm" type="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>"
                        class="w-full rounded-xl px-4 py-3 text-sm transition-all"
                        placeholder="กรอกรหัสผ่านอีกครั้ง">
                </div>
                <button type="submit" class="btn-orange mt-2">รีเซ็ตรหัสผ่าน</button>
            </form>

            <div class="mt-6 text-center">
                <a href="<?= e(app_url('/login.php')) ?>" class="text-sm text-indigo-400 hover:text-indigo-300 transition-colors">← กลับไปหน้าเข้าสู่ระบบ</a>
            </div>
        </div>
    </div>
</body>
</html>
