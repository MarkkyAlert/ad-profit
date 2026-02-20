<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$wantsJson = str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';

$userId = (int)($_SESSION['user_id'] ?? 0);
$shopId = (int)($_SESSION['current_shop_id'] ?? 0);

$recordRepository = new RecordRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$parseAmount = static function (string $raw): ?float {
    $normalized = trim($raw);
    if ($normalized === '') {
        return null;
    }

    $normalized = str_replace(',', '', $normalized);
    if (!is_numeric($normalized)) {
        return null;
    }

    return (float)$normalized;
};

$normalizeMonth = static function (?string $month): string {
    if (!is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        return date('Y-m');
    }

    return $month;
};

$respond = static function (array $payload, int $statusCode, string $redirectUrl) use ($wantsJson): never {
    if ($wantsJson) {
        jsonResponse($payload, $statusCode);
    }

    if (($payload['success'] ?? false) === true) {
        if (isset($payload['message'])) {
            set_flash('success', (string)$payload['message']);
        }
    } elseif (isset($payload['error'])) {
        set_flash('error', (string)$payload['error']);
    }

    redirect($redirectUrl);
};

if ($action === 'upsert') {
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, '/add-record.php');
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, '/add-record.php');
    }

    $recordDate = (string)($_POST['record_date'] ?? '');
    $revenue = $parseAmount((string)($_POST['revenue'] ?? ''));
    $adCost = $parseAmount((string)($_POST['ad_cost'] ?? ''));
    $note = isset($_POST['note']) ? (string)$_POST['note'] : null;

    if ($revenue === null || $adCost === null) {
        $respond([
            'success' => false,
            'error' => 'กรุณากรอกรายได้และค่าแอดให้ถูกต้อง',
        ], 422, '/add-record.php');
    }

    $result = $recordService->upsertRecord($userId, $shopId, $recordDate, $revenue, $adCost, $note);

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'บันทึกข้อมูลเรียบร้อยแล้ว'),
        ], 200, '/add-record.php');
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถบันทึกข้อมูลได้'),
    ], 422, '/add-record.php');
}

if ($action === 'update') {
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, '/history.php');
    }

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $month = $normalizeMonth(isset($_POST['month']) ? (string)$_POST['month'] : null);
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, '/history.php?month=' . $month);
    }

    $month = $normalizeMonth(isset($_POST['month']) ? (string)$_POST['month'] : null);
    $recordId = (int)($_POST['record_id'] ?? 0);
    $recordDate = (string)($_POST['record_date'] ?? '');
    $revenue = $parseAmount((string)($_POST['revenue'] ?? ''));
    $adCost = $parseAmount((string)($_POST['ad_cost'] ?? ''));
    $note = isset($_POST['note']) ? (string)$_POST['note'] : null;

    if ($recordId <= 0 || $revenue === null || $adCost === null) {
        $respond([
            'success' => false,
            'error' => 'ข้อมูลที่ส่งมาไม่ถูกต้อง',
        ], 422, '/history.php?month=' . $month);
    }

    $result = $recordService->updateRecord($userId, $shopId, $recordId, $recordDate, $revenue, $adCost, $note);

    if (($result['success'] ?? false) === true) {
        $respond([
            'success' => true,
            'message' => (string)($result['message'] ?? 'แก้ไขรายการเรียบร้อยแล้ว'),
        ], 200, '/history.php?month=' . $month);
    }

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถแก้ไขรายการได้'),
    ], 422, '/history.php?month=' . $month);
}

if ($action === 'delete') {
    if (!is_post_request()) {
        $respond([
            'success' => false,
            'error' => 'Method Not Allowed',
        ], 405, '/history.php');
    }

    $month = $normalizeMonth(isset($_POST['month']) ? (string)$_POST['month'] : null);

    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        $respond([
            'success' => false,
            'error' => 'Invalid CSRF token',
        ], 403, '/history.php?month=' . $month);
    }

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

    $respond([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถลบรายการได้'),
    ], 422, '/history.php?month=' . $month);
}

$respond([
    'success' => false,
    'error' => 'Invalid action',
], 404, '/add-record.php');
