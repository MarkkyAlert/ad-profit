<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse([
        'success' => false,
        'error' => 'Method Not Allowed',
    ], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$selectedMonth = resolve_calendar_month($_GET['month'] ?? null);
if (preg_match('/^\d{4}-\d{2}$/', $selectedMonth) !== 1) {
    $selectedMonth = date('Y-m');
}

$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$overviewService = new OverviewService($recordRepository, $shopRepository);

$result = $overviewService->buildOverview($userId, $selectedMonth);

if (($result['success'] ?? false) !== true) {
    jsonResponse([
        'success' => false,
        'error' => (string)($result['error'] ?? 'ไม่สามารถโหลดข้อมูลภาพรวมทุกร้านได้'),
    ], 422);
}

$data = (array)($result['data'] ?? []);

// ⚠️ มุมรวมร้านดูได้เมื่อมี ≥ 2 ร้านเท่านั้น — คืนเฉพาะสถานะ ไม่ต้องส่งตาราง/กราฟ
// ให้ตรงกับหน้าเว็บที่ไม่เรนเดอร์อะไรเลยในสถานการณ์นี้ และตรงกับ endpoint พี่น้อง
// (`overview` มุมรายวัน/รายปี ตัดจบที่ชั้น service อยู่แล้ว ส่วนมุมเดือนตัดที่นี่
//  เพราะ service ตัวนั้นถูกใช้ทดสอบตัวสร้างกราฟด้วยร้านเดียว)
if (($data['can_view'] ?? false) !== true) {
    $data = [
        'selected_month' => $data['selected_month'] ?? $selectedMonth,
        'shops_count' => (int)($data['shops_count'] ?? 0),
        'can_view' => false,
    ];
}

jsonResponse([
    'success' => true,
    'data' => $data,
]);
