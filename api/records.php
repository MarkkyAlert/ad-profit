<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

// Hardening: state-changing actions must come from POST only.
$action = (string)($_POST['action'] ?? '');
$wantsJson = wants_json_response();

// ทุก action ในไฟล์นี้เปลี่ยนสถานะทั้งหมด → ตรวจ method/content-type ก่อนอ่าน $action
// (เดิมตรวจอยู่ในแต่ละ branch ซึ่งไม่มีวันทำงาน เพราะ $action อ่านจาก $_POST ที่ว่างเปล่า
//  เมื่อเป็น GET หรือ JSON → ตกไป "Invalid action" 404 แทนที่จะเป็น 405/415)
ensure_post_request_or_respond($wantsJson, '/add-record.php');
ensure_form_content_type_or_respond($wantsJson, '/add-record.php');
ensure_post_body_not_truncated_or_respond($wantsJson, '/add-record.php');

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

// ⚠️ ต้องอยู่ "หลัง" $shopId ถูกกำหนดค่า — ฟอร์มถูกเรนเดอร์ให้ร้านไหน ต้องตรงกับร้าน
// ที่ session ชี้อยู่ตอนนี้ (เปิดหน้าค้างไว้ สลับร้านในอีกแท็บ แล้วกดบันทึก = ลงผิดร้าน)
ensure_shop_context_or_respond($wantsJson, '/add-record.php', $shopId);

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
    api_respond($payload, $statusCode, $redirectUrl, $wantsJson);
};

if ($action === 'upsert') {
    ensure_valid_csrf_or_respond($wantsJson, '/add-record.php', (string)($_POST['csrf_token'] ?? ''));

    $recordDate = (string)($_POST['record_date'] ?? '');
    $revenueParsed = parse_decimal_input($_POST['revenue'] ?? '', false);
    $adCostParsed = parse_decimal_input($_POST['ad_cost'] ?? '', false);
    $note = isset($_POST['note']) ? (string)$_POST['note'] : null;

    if (($revenueParsed['valid'] ?? false) !== true || ($adCostParsed['valid'] ?? false) !== true) {
        $respond([
            'success' => false,
            'error' => 'กรุณากรอกรายได้และค่าแอดให้ถูกต้อง',
        ], 422, '/add-record.php');
    }

    $revenue = (float)($revenueParsed['value'] ?? 0.0);
    $adCost = (float)($adCostParsed['value'] ?? 0.0);

    $result = $recordService->upsertRecord($userId, $shopId, $recordDate, $revenue, $adCost, $note);

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'บันทึกข้อมูลเรียบร้อยแล้ว'),
        ], 200, '/add-record.php');
    }

    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถบันทึกข้อมูลได้');
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    $respond([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode, '/add-record.php');
}

if ($action === 'bulk_upsert') {
    ensure_valid_csrf_or_respond($wantsJson, '/add-record.php', (string)($_POST['csrf_token'] ?? ''));

    $recordDates = isset($_POST['record_date']) && is_array($_POST['record_date']) ? $_POST['record_date'] : [];
    $revenues = isset($_POST['revenue']) && is_array($_POST['revenue']) ? $_POST['revenue'] : [];
    $adCosts = isset($_POST['ad_cost']) && is_array($_POST['ad_cost']) ? $_POST['ad_cost'] : [];
    $notes = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];

    if ($recordDates === []) {
        $respond([
            'success' => false,
            'error' => 'กรุณากรอกข้อมูลอย่างน้อย 1 แถว',
        ], 422, '/add-record.php');
    }

    // เลขแถวที่ผู้ใช้เห็นบนตาราง — แถวที่ไม่ได้กรอกจะไม่ถูกส่งมา ทำให้ลำดับใน $_POST
    // ไม่ตรงกับที่เห็นบนหน้าจอ ถ้าไม่มีค่านี้มาด้วย service จะนับเองแล้วชี้ผิดแถว
    $rowNumbers = isset($_POST['row_number']) && is_array($_POST['row_number']) ? $_POST['row_number'] : [];

    $rows = [];
    foreach (array_keys($recordDates) as $rowIndex) {
        $revenueParsed = parse_decimal_input($revenues[$rowIndex] ?? '', true);
        $adCostParsed = parse_decimal_input($adCosts[$rowIndex] ?? '', true);

        $rows[] = [
            'row_number' => (int)($rowNumbers[$rowIndex] ?? 0),
            'record_date' => (string)($recordDates[$rowIndex] ?? ''),
            // ส่งค่าที่ parse ไม่ผ่านเป็น string เดิม เพื่อให้ Service รายงานแถวที่ผิดได้
            'revenue' => ($revenueParsed['valid'] ?? false) === true
                ? $revenueParsed['value']
                : (string)($revenues[$rowIndex] ?? ''),
            'ad_cost' => ($adCostParsed['valid'] ?? false) === true
                ? $adCostParsed['value']
                : (string)($adCosts[$rowIndex] ?? ''),
            'note' => isset($notes[$rowIndex]) ? (string)$notes[$rowIndex] : null,
        ];
    }

    $result = $recordService->upsertManyRecords($userId, $shopId, $rows);

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'บันทึกข้อมูลเรียบร้อยแล้ว'),
            'data' => [
                'saved_count' => (int)($result['saved_count'] ?? 0),
            ],
        ], 200, '/add-record.php');
    }

    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถบันทึกข้อมูลได้');
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    $respond([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode, '/add-record.php');
}

if ($action === 'import_csv') {
    // อัปโหลดมาเป็น multipart/form-data ซึ่ง guard ระดับไฟล์รับอยู่แล้ว
    // CSRF ยังตรวจตามปกติ เพราะ token มาใน field ของ multipart
    ensure_valid_csrf_or_respond($wantsJson, '/add-record.php', (string)($_POST['csrf_token'] ?? ''));

    $uploadedFile = $_FILES['csv'] ?? null;
    if (!is_array($uploadedFile) || (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $respond([
            'success' => false,
            'error' => 'กรุณาเลือกไฟล์ CSV ที่ต้องการนำเข้า',
        ], 422, '/add-record.php');
    }

    $uploadError = (int)($uploadedFile['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadErrorMessage = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้'
            : 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';

        $respond([
            'success' => false,
            'error' => $uploadErrorMessage,
        ], 422, '/add-record.php');
    }

    $maxUploadBytes = 2 * 1024 * 1024; // 2MB
    if ((int)($uploadedFile['size'] ?? 0) > $maxUploadBytes) {
        $respond([
            'success' => false,
            'error' => 'ไฟล์ใหญ่เกิน 2MB',
        ], 422, '/add-record.php');
    }

    $originalName = (string)($uploadedFile['name'] ?? '');
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== '' && !in_array($extension, ['csv', 'txt'], true)) {
        $respond([
            'success' => false,
            'error' => 'รองรับเฉพาะไฟล์ .csv',
        ], 422, '/add-record.php');
    }

    $temporaryPath = (string)($uploadedFile['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        $respond([
            'success' => false,
            'error' => 'ไม่พบไฟล์ที่อัปโหลด',
        ], 422, '/add-record.php');
    }

    // อ่านเป็น string อย่างเดียว ไม่เก็บไฟล์ลงเซิร์ฟเวอร์
    $csvContent = file_get_contents($temporaryPath);
    if ($csvContent === false) {
        $respond([
            'success' => false,
            'error' => 'ไม่สามารถอ่านไฟล์ที่อัปโหลดได้',
        ], 422, '/add-record.php');
    }

    $parsed = $recordService->parseImportCsv($csvContent);
    if (($parsed['success'] ?? false) !== true) {
        $respond([
            'success' => false,
            'error' => (string)($parsed['error'] ?? 'ไฟล์ CSV ไม่ถูกต้อง'),
        ], 422, '/add-record.php');
    }

    $result = $recordService->upsertManyRecords(
        $userId,
        $shopId,
        (array)$parsed['rows'],
        RecordService::IMPORT_MAX_ROWS
    );

    if (($result['success'] ?? false) === true) {
        $savedCount = (int)($result['saved_count'] ?? 0);

        $respond([
            'success' => true,
            'message' => 'นำเข้า ' . $savedCount . ' แถวสำเร็จ',
            'data' => [
                'saved_count' => $savedCount,
            ],
        ], 200, '/add-record.php');
    }

    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถนำเข้าข้อมูลได้');
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    $respond([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode, '/add-record.php');
}

if ($action === 'update') {
    $month = normalize_month_input(isset($_POST['month']) ? (string)$_POST['month'] : null);
    ensure_valid_csrf_or_respond($wantsJson, '/history.php?month=' . $month, (string)($_POST['csrf_token'] ?? ''));

    $recordId = (int)($_POST['record_id'] ?? 0);
    $recordDate = (string)($_POST['record_date'] ?? '');
    $revenueParsed = parse_decimal_input($_POST['revenue'] ?? '', false);
    $adCostParsed = parse_decimal_input($_POST['ad_cost'] ?? '', false);
    $note = isset($_POST['note']) ? (string)$_POST['note'] : null;

    if ($recordId <= 0 || ($revenueParsed['valid'] ?? false) !== true || ($adCostParsed['valid'] ?? false) !== true) {
        $respond([
            'success' => false,
            'error' => 'ข้อมูลที่ส่งมาไม่ถูกต้อง',
        ], 422, '/history.php?month=' . $month);
    }

    $revenue = (float)($revenueParsed['value'] ?? 0.0);
    $adCost = (float)($adCostParsed['value'] ?? 0.0);

    $result = $recordService->updateRecord($userId, $shopId, $recordId, $recordDate, $revenue, $adCost, $note);

    if (($result['success'] ?? false) === true) {
        // แก้วันที่ข้ามเดือนได้ → ต้องพากลับไปเดือนใหม่ ไม่งั้นผู้ใช้เห็นข้อความ "แก้ไขเรียบร้อย"
        // พร้อมตารางที่ไม่มีรายการนั้น แล้วเข้าใจว่าโดนลบ
        $savedMonth = normalize_month_input(substr(trim($recordDate), 0, 7), $month);

        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'แก้ไขรายการเรียบร้อยแล้ว'),
        ], 200, '/history.php?month=' . $savedMonth);
    }

    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถแก้ไขรายการได้');
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    $respond([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode, '/history.php?month=' . $month);
}

if ($action === 'delete') {
    $month = normalize_month_input(isset($_POST['month']) ? (string)$_POST['month'] : null);
    ensure_valid_csrf_or_respond($wantsJson, '/history.php?month=' . $month, (string)($_POST['csrf_token'] ?? ''));

    $recordId = (int)($_POST['record_id'] ?? 0);
    if ($recordId <= 0) {
        $respond([
            'success' => false,
            'error' => 'ไม่พบรายการที่ต้องการลบ',
        ], 422, '/history.php?month=' . $month);
    }

    $result = $recordService->deleteRecord($userId, $shopId, $recordId);

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'ลบรายการเรียบร้อยแล้ว'),
        ], 200, '/history.php?month=' . $month);
    }

    $errorMessage = (string)($result['error'] ?? 'ไม่สามารถลบรายการได้');
    $statusCode = infer_http_status_from_error($errorMessage, 422);

    $respond([
        'success' => false,
        'error' => $errorMessage,
    ], $statusCode, '/history.php?month=' . $month);
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, '/add-record.php');
