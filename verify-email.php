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

// ⚠️⚠️ **การเปิดลิงก์ (GET) ต้องไม่เปลี่ยนอะไรเลย** — เปลี่ยนจริงที่ POST เท่านั้น
//
// เดิมการเปิดลิงก์ = เปลี่ยนอีเมล + เตะทุก session + ล้าง token รีเซ็ต ทั้งหมดจาก
// คำขอ GET เดียว · ระบบสแกนลิงก์ในอีเมล (Outlook Safe Links, Proofpoint,
// พร็อกซีของ Gmail) ดึง URL อัตโนมัติ **ก่อน** ผู้ใช้กด → ผู้ใช้ถูกเตะออกจากระบบ
// ทุกเครื่องโดยไม่ได้แตะอะไรเลย และอีเมลเปลี่ยนไปแล้ว
//
// หลักเดียวกับ `reset-password.php`: GET แสดงฟอร์ม · POST เป็นตัวเปลี่ยน
// ⚠️ จัดการ POST ในไฟล์นี้เอง (ไม่ส่งไป `api/`) โดยตั้งใจ — การเลือกคำแนะนำจาก
// `reason` อยู่ที่นี่ที่เดียว ถ้าแยกไปอีกไฟล์ต้องส่ง reason ข้ามผ่าน flash
// ซึ่งทำให้กติกา "ห้ามพิมพ์คำแนะนำตายตัว" กระจายเป็นสองที่
$isConfirmSubmit = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// ⚠️ ตัดช่องว่างยูนิโค้ด — เหตุผลเดียวกับ `reset-password.php`
$tokenRaw = $isConfirmSubmit ? ($_POST['token'] ?? null) : ($_GET['token'] ?? null);
$token = is_string($tokenRaw) ? trim_unicode_whitespace($tokenRaw) : '';

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
$failureReason = '';

// ลิงก์ยังดีอยู่และเป็นของคนที่กด — GET แค่แสดงหน้ายืนยัน ยังไม่เปลี่ยนอะไร
$pendingEmail = $pendingRequest === null ? '' : (string)($pendingRequest['new_email'] ?? '');
$awaitingConfirm = false;

if ($linkBelongsToAnotherAccount) {
    $errorMessage = 'ลิงก์นี้เป็นของบัญชีอื่น ไม่ใช่บัญชีที่คุณกำลังใช้งานอยู่ '
        . 'ระบบจึงไม่ได้เปลี่ยนแปลงอะไรเลย — ถ้าคุณเป็นเจ้าของลิงก์นี้จริง '
        . 'กรุณาออกจากระบบก่อนแล้วกดลิงก์อีกครั้ง';
    $failureReason = 'another_account';
} elseif ($isConfirmSubmit) {
    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $errorMessage = 'หมดเวลาทำรายการเพื่อความปลอดภัย กรุณาโหลดหน้าใหม่แล้วกดยืนยันอีกครั้ง';
        $failureReason = 'bad_link';
    } else {
        $result = $profileService->confirmEmailChange($token);
        $confirmed = ($result['success'] ?? false) === true;
        $newEmail = $confirmed ? (string)(($result['data'] ?? [])['email'] ?? '') : '';
        $errorMessage = $confirmed ? '' : (string)($result['error'] ?? 'ยืนยันอีเมลไม่สำเร็จ');
        $failureReason = $confirmed ? '' : (string)($result['reason'] ?? 'bad_link');
    }
} elseif ($pendingRequest !== null) {
    $awaitingConfirm = true;
} else {
    // ลิงก์ผิด/หมดอายุ/ถูกใช้ไปแล้ว — ให้ Service เป็นคนบอกสาเหตุเหมือนเดิม
    // (ไม่เปลี่ยน state เพราะไม่มีคำขอที่ตรงกับ token นี้อยู่แล้ว)
    $result = $profileService->confirmEmailChange($token);
    $errorMessage = (string)($result['error'] ?? 'ยืนยันอีเมลไม่สำเร็จ');
    $failureReason = (string)($result['reason'] ?? 'bad_link');
}

// ⚠️⚠️ คำแนะนำต้องมาจากสาเหตุจริง ห้ามเป็นข้อความตายตัว
//
// เดิมทุกกรณีพิมพ์บรรทัดเดียวกัน: "ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว … ขอลิงก์ใหม่"
// ซึ่งขัดกับหัวข้อที่อยู่เหนือมันบนจอเดียวกันใน 2 กรณีจาก 6:
//   · ลิงก์ของบัญชีอื่น — หัวข้อบอกว่าลิงก์ยังดี ให้ออกจากระบบแล้วกดใหม่
//     แต่บรรทัดล่างบอกว่าลิงก์เสียแล้ว ให้ไปขอใหม่ (ซึ่งขอไม่ได้ เพราะเป็นของคนอื่น)
//   · อีเมลปลายทางถูกใช้แล้ว — ขอลิงก์ใหม่ไปที่อีเมลเดิมจะล้มเหมือนเดิมทุกครั้ง
//     สิ่งที่ต้องทำคือเปลี่ยนอีเมลปลายทาง
// หลักเดียวกับ `extremes_not_comparable_text()` — ห้ามเดาสาเหตุแทนข้อมูล
$hintMessage = match ($failureReason) {
    'another_account' => 'ลิงก์นี้ยังใช้ได้อยู่ เพียงแต่ต้องกดจากบัญชีที่เป็นเจ้าของลิงก์ '
        . '— อีเมลของบัญชีที่คุณใช้อยู่ตอนนี้ไม่ได้ถูกแตะเลย',
    'email_taken' => 'ลิงก์ไม่ได้หมดอายุ — อีเมลปลายทางถูกใช้ไปแล้ว '
        . 'กรุณาไปที่หน้าโปรไฟล์แล้วขอเปลี่ยนเป็นอีเมลอื่น',
    'system_unavailable' => 'อีเมลของบัญชียังเป็นอันเดิม กรุณาติดต่อผู้ดูแลระบบ',
    'failed' => 'อีเมลของบัญชียังเป็นอันเดิม กรุณาลองกดลิงก์อีกครั้งในอีกสักครู่',
    default => 'ลิงก์อาจหมดอายุหรือถูกใช้ไปแล้ว — อีเมลของบัญชียังเป็นอันเดิม '
        . 'ขอลิงก์ใหม่ได้จากหน้าโปรไฟล์',
};

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
        <?php if ($awaitingConfirm): ?>
            <h1 class="text-lg font-bold text-slate-100">ยืนยันการเปลี่ยนอีเมล</h1>
            <p class="mt-2 text-sm text-slate-300">
                อีเมลของบัญชีจะเปลี่ยนเป็น
                <strong class="text-slate-100"><?= e($pendingEmail) ?></strong>
            </p>
            <p class="mt-1 text-xs text-slate-500">
                เพื่อความปลอดภัย ระบบจะออกจากระบบให้ทุกเครื่องหลังยืนยัน
                แล้วให้เข้าสู่ระบบใหม่ด้วยอีเมลนี้
            </p>

            <form method="post" action="<?= e(app_url('/verify-email.php')) ?>" class="mt-5">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <button type="submit" class="btn-primary inline-flex px-4 py-2 text-sm">
                    ยืนยันเปลี่ยนอีเมล
                </button>
            </form>
        <?php elseif ($confirmed): ?>
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
            <p class="mt-1 text-xs text-slate-500"><?= e($hintMessage) ?></p>
        <?php endif; ?>

        <a href="<?= e(app_url('/login.php')) ?>"
           class="btn-primary mt-5 inline-flex px-4 py-2 text-sm">ไปหน้าเข้าสู่ระบบ</a>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
