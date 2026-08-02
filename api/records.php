<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

// Hardening: state-changing actions must come from POST only.
$action = (string)($_POST['action'] ?? '');
$wantsJson = wants_json_response();

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
    api_respond($payload, $statusCode, $redirectUrl, $wantsJson);
};

if ($action === 'upsert') {
    ensure_post_request_or_respond($wantsJson, '/add-record.php');
    ensure_form_content_type_or_respond($wantsJson, '/add-record.php');
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
    ensure_post_request_or_respond($wantsJson, '/add-record.php');
    ensure_form_content_type_or_respond($wantsJson, '/add-record.php');
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

    $rows = [];
    foreach (array_keys($recordDates) as $rowIndex) {
        $revenueParsed = parse_decimal_input($revenues[$rowIndex] ?? '', true);
        $adCostParsed = parse_decimal_input($adCosts[$rowIndex] ?? '', true);

        $rows[] = [
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

if ($action === 'update') {
    $month = normalize_month_input(isset($_POST['month']) ? (string)$_POST['month'] : null);
    ensure_post_request_or_respond($wantsJson, '/history.php');
    ensure_form_content_type_or_respond($wantsJson, '/history.php?month=' . $month);
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
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'แก้ไขรายการเรียบร้อยแล้ว'),
        ], 200, '/history.php?month=' . $month);
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
    ensure_post_request_or_respond($wantsJson, '/history.php');
    ensure_form_content_type_or_respond($wantsJson, '/history.php?month=' . $month);
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
