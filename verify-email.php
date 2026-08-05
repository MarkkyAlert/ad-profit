<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

/**
 * หน้ายืนยันอีเมลใหม่ — ปลายทางของลิงก์ที่ส่งไปยังกล่องจดหมายใหม่
 *
 * ⚠️ **ไม่บังคับว่าต้องล็อกอินอยู่** — ผู้ใช้มักกดลิงก์จากมือถือที่ยังไม่ได้ล็อกอิน
 * หลักฐานคือ "ถือ token ที่ส่งไปยังกล่องจดหมายนั้น" ซึ่งเพียงพอ (ท่าเดียวกับลิงก์รีเซ็ต)
 *
 * ⚠️ การยืนยันสำเร็จจะ bump `session_version` ทำให้ session ทุกเครื่องถูกเตะออก
 * รวมทั้งเครื่องที่กำลังเปิดหน้านี้อยู่ — ตั้งใจ เพราะอีเมลคือช่องทางกู้บัญชี
 * คนที่ยึด session ไว้ได้ต้องไม่ค้างอยู่ในบัญชีต่อหลังเจ้าของเปลี่ยนอีเมล
 */
$pdo = db();
$profileService = new ProfileService(
    new UserRepository($pdo),
    $pdo,
    new PasswordResetRepository($pdo),
    new EmailChangeRepository($pdo),
    new EmailService()
);

$result = $profileService->confirmEmailChange((string)($_GET['token'] ?? ''));
$confirmed = ($result['success'] ?? false) === true;
$newEmail = $confirmed ? (string)(($result['data'] ?? [])['email'] ?? '') : '';
$errorMessage = $confirmed ? '' : (string)($result['error'] ?? 'ยืนยันอีเมลไม่สำเร็จ');

if ($confirmed) {
    // เตะทุก session ออกรวมทั้งเครื่องนี้ — เจ้าของต้องล็อกอินใหม่ด้วยอีเมลใหม่
    $_SESSION = [];
    session_regenerate_id(true);
    set_flash('success', 'เปลี่ยนอีเมลเรียบร้อยแล้ว กรุณาเข้าสู่ระบบด้วยอีเมลใหม่');
}

$pageTitle = 'ยืนยันอีเมล';
require __DIR__ . '/includes/header.php';
?>

<main class="mx-auto w-full max-w-lg px-4 py-10">
    <section class="section-card px-5 py-6">
        <?php if ($confirmed): ?>
            <h1 class="text-lg font-bold text-green-400">เปลี่ยนอีเมลเรียบร้อยแล้ว</h1>
            <p class="mt-2 text-sm text-slate-300">
                ต่อไปนี้ให้เข้าสู่ระบบด้วย
                <strong class="text-slate-100"><?= e($newEmail) ?></strong>
            </p>
            <p class="mt-1 text-xs text-slate-500">
                เพื่อความปลอดภัย ระบบออกจากระบบให้ทุกเครื่องแล้ว
            </p>
        <?php else: ?>
            <h1 class="text-lg font-bold text-red-400">ยืนยันอีเมลไม่สำเร็จ</h1>
            <p class="mt-2 text-sm text-slate-300"><?= e($errorMessage) ?></p>
            <p class="mt-1 text-xs text-slate-500">
                ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว — อีเมลของบัญชียังเป็นอันเดิม
                ขอลิงก์ใหม่ได้จากหน้าโปรไฟล์
            </p>
        <?php endif; ?>

        <a href="<?= e(app_url('/login.php')) ?>"
           class="btn-primary mt-5 inline-flex px-4 py-2 text-sm">ไปหน้าเข้าสู่ระบบ</a>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
