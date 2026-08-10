<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(true);

// read-only endpoint — GET เท่านั้น (ไม่เปลี่ยน state จึงไม่ต้องมี CSRF ตาม pattern ของ *-data.php)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Method Not Allowed',
    ], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
// อ่านอย่างเดียว → ซ่อม session ที่ชี้ร้านที่ถูกลบไปแล้วเหมือนที่ทุกเพจทำ
// ⚠️ ทางที่ "เขียน" ข้อมูลห้ามซ่อม — ต้องล้มดัง ๆ ไม่ใช่เงียบ ๆ เปลี่ยนร้านปลายทางให้
$shopId = resolve_current_shop_id($pdo, $userId);

/* ⚠️⚠️⚠️ **หน้าที่เปิดค้างไว้ตอนอยู่อีกร้าน ต้องไม่ได้ข้อมูลของร้านปัจจุบัน**
   ลำดับที่พังจริง: แท็บ A อยู่ร้าน A → แท็บ B สลับเป็นร้าน B → แท็บ A โหลดข้อมูลเดือน
   (ได้ของร้าน B เพราะ endpoint อ่านร้านจาก session) → แท็บ B สลับกลับร้าน A →
   แท็บ A กดบันทึก · ด่านตอน POST เทียบ "ร้านของหน้า" กับ "ร้านใน session" ซึ่งตอนนี้
   ตรงกันแล้ว จึงปล่อยผ่าน → **ยอดและโน้ตของร้าน B ถูกเขียนลงร้าน A**
   · ทางอ่านจึงต้องมีด่านเดียวกับทางเขียน ไม่ใช่เชื่อ session อย่างเดียว
   · ฟอร์มที่ไม่ส่งค่านี้มา (หน้าเก่าที่ค้างอยู่) ทำงานเหมือนเดิม — ผ่อนผันแบบเดียวกับ
     `ensure_shop_context_or_respond()` เพราะกันอุบัติเหตุ ไม่ได้กันการโจมตี */
$requestedShopId = isset($_GET['shop_context_id']) ? (int)$_GET['shop_context_id'] : 0;
if ($requestedShopId > 0 && $requestedShopId !== $shopId) {
    jsonResponse([
        'success' => false,
        'error' => 'หน้านี้เปิดค้างไว้ตอนที่คุณอยู่อีกร้านหนึ่ง '
            . 'กรุณาโหลดหน้านี้ใหม่แล้วลองอีกครั้ง',
    ], 409);
}

$selectedMonth = resolve_calendar_month($_GET['month'] ?? null);

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$result = $recordService->buildEditableMonthGrid($userId, $shopId, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลของเดือนนี้ได้');
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    jsonResponse([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode);
}

jsonResponse([
    'success' => true,
    'data' => $result['data'] ?? [],
]);
