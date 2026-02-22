<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

requireAuth();

$userId = (int)($_SESSION['user_id'] ?? 0);

$userRepository = new UserRepository($pdo);
$profileService = new ProfileService($userRepository);
$profileResult = $profileService->getProfile($userId);

if (($profileResult['success'] ?? false) !== true) {
    set_flash('error', (string)($profileResult['error'] ?? 'ไม่สามารถโหลดข้อมูลส่วนตัวได้'));
    redirect('/dashboard.php');
}

$profile = is_array($profileResult['data'] ?? null) ? (array)$profileResult['data'] : [];
$displayName = (string)($profile['display_name'] ?? '');
$email = (string)($profile['email'] ?? ($_SESSION['email'] ?? ''));

$pageTitle = 'ข้อมูลส่วนตัว';
$currentPage = 'profile';

require __DIR__ . '/includes/header.php';
?>
<section class="section-card p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-100">ข้อมูลส่วนตัว</h1>
            <p class="mt-2 text-sm text-slate-400">จัดการชื่อที่แสดง, อีเมล และรหัสผ่านของบัญชีคุณได้จากหน้านี้</p>
        </div>

        <div class="rounded-2xl border border-indigo-400/20 bg-indigo-500/10 px-4 py-3 text-sm">
            <p class="text-slate-400">อีเมลที่ใช้งานปัจจุบัน</p>
            <p class="mt-1 font-semibold text-indigo-200"><?= e($email) ?></p>
        </div>
    </div>
</section>

<section class="mt-6 grid gap-6 lg:grid-cols-2">
    <article class="section-card p-5">
        <h2 class="text-lg font-semibold text-slate-100">ข้อมูลพื้นฐาน</h2>
        <p class="mt-2 text-sm text-slate-400">ชื่อนี้จะใช้แสดงตัวตนของคุณในระบบ</p>

        <form action="<?= e(app_url('/api/profile.php')) ?>" method="post" class="mt-4 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="redirect_to" value="/profile.php">

            <div>
                <label for="profile-display-name" class="mb-1.5 block text-sm text-slate-300">ชื่อที่แสดง</label>
                <input
                    id="profile-display-name"
                    name="display_name"
                    type="text"
                    maxlength="120"
                    required
                    value="<?= e($displayName) ?>"
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="เช่น มาร์ค">
            </div>

            <button type="submit" class="btn-primary w-full px-4 py-2.5 text-sm">💾 บันทึกข้อมูล</button>
        </form>
    </article>

    <article class="section-card p-5">
        <h2 class="text-lg font-semibold text-slate-100">เปลี่ยนอีเมล</h2>
        <p class="mt-2 text-sm text-slate-400">เพื่อความปลอดภัย ต้องยืนยันด้วยรหัสผ่านปัจจุบัน</p>

        <form action="<?= e(app_url('/api/profile.php')) ?>" method="post" class="mt-4 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_email">
            <input type="hidden" name="redirect_to" value="/profile.php">

            <div>
                <label for="current-email" class="mb-1.5 block text-sm text-slate-300">อีเมลปัจจุบัน</label>
                <input id="current-email" type="email" value="<?= e($email) ?>" disabled class="w-full rounded-xl px-4 py-2.5 text-sm opacity-75">
            </div>

            <div>
                <label for="new-email" class="mb-1.5 block text-sm text-slate-300">อีเมลใหม่</label>
                <input
                    id="new-email"
                    name="email"
                    type="email"
                    maxlength="255"
                    required
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="new@email.com"
                    autocomplete="email">
            </div>

            <div>
                <label for="email-current-password" class="mb-1.5 block text-sm text-slate-300">รหัสผ่านปัจจุบัน</label>
                <input
                    id="email-current-password"
                    name="current_password"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    required
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="กรอกรหัสผ่านเพื่อยืนยัน"
                    autocomplete="current-password">
            </div>

            <button type="submit" class="btn-teal w-full px-4 py-2.5 text-sm">📧 เปลี่ยนอีเมล</button>
        </form>
    </article>

    <article class="section-card p-5 lg:col-span-2">
        <h2 class="text-lg font-semibold text-slate-100">ความปลอดภัย (เปลี่ยนรหัสผ่าน)</h2>
        <p class="mt-2 text-sm text-slate-400">ต้องกรอกรหัสผ่านปัจจุบันก่อนตั้งรหัสผ่านใหม่ทุกครั้ง</p>

        <form action="<?= e(app_url('/api/profile.php')) ?>" method="post" class="mt-4 grid gap-3 md:grid-cols-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="redirect_to" value="/profile.php">

            <div>
                <label for="password-current" class="mb-1.5 block text-sm text-slate-300">รหัสผ่านปัจจุบัน</label>
                <input
                    id="password-current"
                    name="current_password"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    required
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="รหัสผ่านปัจจุบัน"
                    autocomplete="current-password">
            </div>

            <div>
                <label for="password-new" class="mb-1.5 block text-sm text-slate-300">รหัสผ่านใหม่</label>
                <input
                    id="password-new"
                    name="password"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    required
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="อย่างน้อย <?= PASSWORD_MIN_LENGTH ?> ตัวอักษร"
                    autocomplete="new-password">
            </div>

            <div>
                <label for="password-confirm" class="mb-1.5 block text-sm text-slate-300">ยืนยันรหัสผ่านใหม่</label>
                <input
                    id="password-confirm"
                    name="password_confirm"
                    type="password"
                    minlength="<?= PASSWORD_MIN_LENGTH ?>"
                    required
                    class="w-full rounded-xl px-4 py-2.5 text-sm transition-all"
                    placeholder="กรอกรหัสผ่านใหม่อีกครั้ง"
                    autocomplete="new-password">
            </div>

            <div class="md:col-span-3">
                <button type="submit" class="btn-orange w-full px-4 py-2.5 text-sm">🔐 เปลี่ยนรหัสผ่าน</button>
            </div>
        </form>
    </article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
