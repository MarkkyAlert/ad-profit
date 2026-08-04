<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

// token เดินทางผ่าน URL → hidden field ของฟอร์มเท่านั้น "ห้าม" เก็บลง session
//
// เดิมหน้านี้เอา token จาก ?token= ไปเก็บใน $_SESSION แล้วฟอร์มไม่มีช่อง token เลย
// ทำให้ใครก็ได้ส่งลิงก์ /reset-password.php?token=<ของตัวเอง> ให้เหยื่อกด แล้ว:
//   1) เหยื่อถูกเตะออกจากระบบทันที (GET ที่ไม่มี CSRF แต่เปลี่ยน state)
//   2) รหัสผ่านที่เหยื่อพิมพ์ต่อจากนั้นไปตกที่บัญชี "ของผู้ส่งลิงก์" ไม่ใช่ของเหยื่อ
// ทั้งสองข้อทำซ้ำได้จริง จึงต้องแก้พร้อมกัน: ไม่แตะ session, ไม่เตะใครออก,
// และแสดงให้ชัดว่าลิงก์นี้เป็นของบัญชีไหน เพื่อให้เหยื่อรู้ตัวก่อนพิมพ์
$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    set_flash('error', 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง');
    redirect('/forgot-password.php');
}

// ตรวจ token ก่อนแสดงฟอร์ม — ต้องตอบให้ได้ว่าลิงก์นี้เป็นของอีเมลไหน
$resetTokenRow = (new PasswordResetRepository($pdo))->findByTokenHash(hash('sha256', $token));

if ($resetTokenRow === null) {
    set_flash('error', 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอลิงก์ใหม่');
    redirect('/forgot-password.php');
}

$resetTargetEmail = (string)($resetTokenRow['email'] ?? '');

// ผู้ที่ยังล็อกอินค้างอยู่ไม่ถูกเตะออก — แค่เตือนว่ากำลังจะตั้งรหัสให้บัญชีไหน
// (เดิมเด้งไป /dashboard.php ทิ้ง token เงียบ ๆ ต่อมาแก้เป็นเตะออกซึ่งเปิดช่องโจมตี)
$signedInEmail = isset($_SESSION['user_id']) ? (string)($_SESSION['email'] ?? '') : '';
$resetIsForAnotherAccount = $signedInEmail !== ''
    && strcasecmp($signedInEmail, $resetTargetEmail) !== 0;

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

            <?php // บอกให้ชัดว่ากำลังตั้งรหัสให้บัญชีไหน — คนที่ถูกส่งลิงก์ของคนอื่นมาจะได้รู้ตัว ?>
            <div class="mb-4 rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm text-slate-300">
                กำลังตั้งรหัสผ่านใหม่ให้บัญชี
                <span class="font-semibold text-slate-100"><?= e($resetTargetEmail) ?></span>
            </div>

            <?php if ($resetIsForAnotherAccount): ?>
                <div class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
                    ⚠️ ขณะนี้คุณเข้าสู่ระบบด้วยบัญชี
                    <span class="font-semibold"><?= e($signedInEmail) ?></span>
                    ซึ่ง<strong>ไม่ใช่บัญชีเดียวกับลิงก์นี้</strong>
                    <p class="mt-1 text-xs text-amber-100/80">
                        ถ้าคุณไม่ได้เป็นคนขอลิงก์นี้ ให้ปิดหน้านี้ทิ้ง — อย่ากรอกรหัสผ่านของคุณลงไป
                    </p>
                </div>
            <?php endif; ?>

            <form action="<?= e(app_url('/api/auth.php')) ?>" method="post" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <?php // token ต้องมากับฟอร์ม ไม่ใช่มาจาก session ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
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
