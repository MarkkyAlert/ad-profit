<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$userRepository = new UserRepository($pdo);
$shopRepository = new ShopRepository($pdo);
$recordRepository = new RecordRepository($pdo);
$authService = new AuthService($pdo, $userRepository, $shopRepository);
$recordService = new RecordService($recordRepository, $shopRepository, $pdo);

$username = 'phase2_' . substr(bin2hex(random_bytes(4)), 0, 8);
$register = $authService->register($username, 'pass1234', 'pass1234', '127.0.0.1');
if (($register['success'] ?? false) !== true) {
    fwrite(STDERR, 'REGISTER_FAILED ' . json_encode($register, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$userId = (int)$register['user_id'];
$shopId = (int)$register['shop_id'];

$upserts = [
    ['2026-04-01', 1000.0, 200.0, 'Day 1'],
    ['2026-04-02', 1200.0, 250.0, 'Day 2'],
    ['2026-04-03', 1400.0, 300.0, 'Day 3'],
    ['2026-04-02', 2200.0, 350.0, 'Day 2 updated'],
];

foreach ($upserts as [$date, $revenue, $adCost, $note]) {
    $result = $recordService->upsertRecord($userId, $shopId, $date, $revenue, $adCost, $note);
    if (($result['success'] ?? false) !== true) {
        fwrite(STDERR, 'UPSERT_FAILED ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }
}

$monthly = $recordService->getMonthlyRecords($userId, $shopId, '2026-04');
if (($monthly['success'] ?? false) !== true) {
    fwrite(STDERR, 'MONTHLY_FAILED ' . json_encode($monthly, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$records = $monthly['data']['records'] ?? [];
if (count($records) !== 3) {
    fwrite(STDERR, 'ASSERT_FAILED expected 3 records after duplicate upsert, got ' . count($records) . PHP_EOL);
    exit(1);
}

$recordForUpdate = null;
foreach ($records as $row) {
    if (($row['record_date'] ?? '') === '2026-04-03') {
        $recordForUpdate = $row;
        break;
    }
}
if ($recordForUpdate === null) {
    fwrite(STDERR, 'ASSERT_FAILED update target not found' . PHP_EOL);
    exit(1);
}

$updateResult = $recordService->updateRecord(
    $userId,
    $shopId,
    (int)$recordForUpdate['id'],
    '2026-04-04',
    1600.0,
    400.0,
    'Day 4 (edited)'
);
if (($updateResult['success'] ?? false) !== true) {
    fwrite(STDERR, 'UPDATE_FAILED ' . json_encode($updateResult, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$afterUpdate = $recordService->getMonthlyRecords($userId, $shopId, '2026-04');
$dates = array_map(static fn(array $row): string => (string)$row['record_date'], $afterUpdate['data']['records'] ?? []);
if (!in_array('2026-04-04', $dates, true) || in_array('2026-04-03', $dates, true)) {
    fwrite(STDERR, 'ASSERT_FAILED update date mutation did not apply' . PHP_EOL);
    exit(1);
}

$recordForDelete = null;
foreach (($afterUpdate['data']['records'] ?? []) as $row) {
    if (($row['record_date'] ?? '') === '2026-04-01') {
        $recordForDelete = $row;
        break;
    }
}
if ($recordForDelete === null) {
    fwrite(STDERR, 'ASSERT_FAILED delete target not found' . PHP_EOL);
    exit(1);
}

$deleteResult = $recordService->deleteRecord($userId, $shopId, (int)$recordForDelete['id']);
if (($deleteResult['success'] ?? false) !== true) {
    fwrite(STDERR, 'DELETE_FAILED ' . json_encode($deleteResult, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

$afterDelete = $recordService->getMonthlyRecords($userId, $shopId, '2026-04');
$finalCount = count($afterDelete['data']['records'] ?? []);
if ($finalCount !== 2) {
    fwrite(STDERR, 'ASSERT_FAILED expected 2 records after delete, got ' . $finalCount . PHP_EOL);
    exit(1);
}

echo 'PHASE2_SMOKE_OK username=' . $username . ' final_records=' . $finalCount . PHP_EOL;
