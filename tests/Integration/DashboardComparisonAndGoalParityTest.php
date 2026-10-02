<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/ControllerTestCase.php';

use AnnualService;
use DashboardService;
use GoalRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RecordRepository;
use ShopRepository;

final class DashboardComparisonAndGoalParityTest extends ControllerTestCase
{
    private function dashboard(int $userId, int $shopId, string $month, string $today): array
    {
        $result = (new DashboardService(new RecordRepository($this->pdo), new ShopRepository($this->pdo), new GoalRepository($this->pdo)))
            ->buildDashboard($userId, $shopId, 'month_pick', null, null, $month, $today);
        $this->assertTrue($result['success']);

        return $result['data'];
    }

    public function testEmptyComparedDaysDoNotBecomeADeclineButRecordedZeroDoes(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $this->createRecord($shopId, '2026-09-01', 200.0, 100.0);
        // แถวอนาคตเก่าในเดือนเดียวกัน ต้องไม่ถูกใช้เป็นหลักฐานว่าช่วง 1..3 กรอกแล้ว
        $this->createRecord($shopId, '2026-10-20', 900.0, 200.0);
        $empty = $this->dashboard($userId, $shopId, '2026-10', '2026-10-03');
        $this->assertSame(0, $empty['statistics']['days_count']);
        foreach (['total_revenue', 'total_ad_cost', 'profit', 'roas'] as $metric) {
            $this->assertNull($empty['comparison']['change'][$metric]);
        }

        $this->createRecord($shopId, '2026-10-01', 0.0, 0.0);
        $zero = $this->dashboard($userId, $shopId, '2026-10', '2026-10-03');
        $this->assertSame(1, $zero['statistics']['days_count']);
        $this->assertSame(-100.0, $zero['comparison']['change']['profit']);
    }

    public function testCentGoalProgressMatchesDashboardAnnualAndDownloadedWorkbook(): void
    {
        $userId = $this->createUser();
        $shopId = $this->createShop($userId);
        $session = $this->startSession($userId, $shopId);
        $csrf = $this->csrfTokenFor($session);
        $record = $this->postJson('/api/records.php', [
            'action' => 'upsert', 'csrf_token' => $csrf, 'shop_context_id' => (string)$shopId,
            'record_date' => '2026-01-01', 'revenue' => '100.02', 'ad_cost' => '0', 'note' => '',
        ], $session);
        $this->assertSame(200, $record['status'], $record['body']);
        $goal = $this->postJson('/api/goals.php', [
            'action' => 'upsert', 'csrf_token' => $csrf, 'goal_month' => '2026-01',
            'target_revenue' => '1000.20', 'target_profit' => '1000.20',
        ], $session);
        $this->assertSame(200, $goal['status'], $goal['body']);

        $dashboard = $this->dashboard($userId, $shopId, '2026-01', '2026-10-03');
        $annual = (new AnnualService(new RecordRepository($this->pdo), new ShopRepository($this->pdo), new GoalRepository($this->pdo)))
            ->buildYearlySummary($userId, $shopId, 2026, '2026-10-03');
        $this->assertTrue($annual['success']);
        $this->assertSame(10.0, $dashboard['goal']['progress_revenue']);
        $this->assertSame(10.0, $dashboard['goal']['progress_profit']);
        $this->assertSame(10.0, $annual['data']['goal_progress'][0]['revenue_progress']);
        $this->assertSame(10.0, $annual['data']['goal_progress'][0]['profit_progress']);

        $response = $this->get('/api/export-xlsx.php?year=2569', $session);
        $this->assertSame(200, $response['status']);
        $path = tempnam(sys_get_temp_dir(), 'goal-parity-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, $response['body']);
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('เป้าหมาย');
            $this->assertNotNull($sheet);
            foreach (['D5', 'H5'] as $cell) {
                $this->assertSame('n', $sheet->getCell($cell)->getDataType());
                $this->assertSame(10.0, (float)$sheet->getCell($cell)->getValue());
                $this->assertSame('10.0%', $sheet->getCell($cell)->getFormattedValue());
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
