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

$token = is_string($_GET['token'] ?? null) ? trim((string)$_GET['token']) : '';

// ⚠️⚠️ ต้องรู้ก่อนว่าลิงก์นี้เป็นของบัญชีไหน **ก่อนแตะอะไรทั้งสิ้น**
//
// เดิมยืนยันทันทีแล้วล้าง `$_SESSION` ทิ้งโดยไม่ดูว่าคนที่กดเป็นเจ้าของลิงก์หรือเปล่า
// ผลคือใครก็ได้ส่งลิงก์ของตัวเองให้เหยื่อกด (ทาง LINE/อีเมล) แล้ว **เหยื่อถูกเตะออก
// จากระบบทุกเครื่องทันที** พร้อมหน้าจอที่สั่งให้ "เข้าสู่ระบบด้วย <อีเมลของผู้ส่ง>"
// ซึ่งเป็นทั้งการก่อกวนและฉากตั้งต้นของการหลอกเอารหัสผ่าน (ทำซ้ำได้จริง วัดแล้ว)
//
// นี่คือบั๊กคลาสเดียวกับที่ `reset-password.php` แก้ไปแล้ว และคอมเมนต์ในไฟล์นั้น
// อธิบายไว้ครบ — หน้านี้เขียนทีหลังแล้วพลาดซ้ำ
$pendingRequest = $token === ''
    ? null
    : (new EmailChangeRepository($pdo))->findByTokenHash(hash('sha256', $token));

$signedInUserId = (int)($_SESSION['user_id'] ?? 0);
$linkOwnerId = $pendingRequest === null ? 0 : (int)($pendingRequest['user_id'] ?? 0);

// ล็อกอินอยู่ แต่ลิงก์เป็นของบัญชีอื่น = ไม่ทำอะไรเลย ไม่ยืนยัน ไม่แตะ session
$linkBelongsToAnotherAccount = $signedInUserId > 0
    && $linkOwnerId > 0
    && $signedInUserId !== $linkOwnerId;

$confirmed = false;
$newEmail = '';
$errorMessage = '';

if ($linkBelongsToAnotherAccount) {
    $errorMessage = 'ลิงก์นี้เป็นของบัญชีอื่น ไม่ใช่บัญชีที่คุณกำลังใช้งานอยู่ '
        . 'ระบบจึงไม่ได้เปลี่ยนแปลงอะไรเลย — ถ้าคุณเป็นเจ้าของลิงก์นี้จริง '
        . 'กรุณาออกจากระบบก่อนแล้วกดลิงก์อีกครั้ง';
} else {
    $result = $profileService->confirmEmailChange($token);
    $confirmed = ($result['success'] ?? false) === true;
    $newEmail = $confirmed ? (string)(($result['data'] ?? [])['email'] ?? '') : '';
    $errorMessage = $confirmed ? '' : (string)($result['error'] ?? 'ยืนยันอีเมลไม่สำเร็จ');
}

if ($confirmed) {
    // เตะทุก session ออกรวมทั้งเครื่องนี้ — เจ้าของต้องล็อกอินใหม่ด้วยอีเมลใหม่
    // (มาถึงตรงนี้ได้เฉพาะกรณีที่ไม่ได้ล็อกอิน หรือล็อกอินอยู่ในบัญชีเจ้าของลิงก์)
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
